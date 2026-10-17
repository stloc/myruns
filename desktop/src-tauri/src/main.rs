// Application de bureau MyRuns : une fenêtre qui affiche l'app Symfony servie par un PHP embarqué.
//
// Au lancement : migrations de la base (dossier de données de l'utilisateur), préchauffage du cache,
// serveur web interne de PHP sur un port libre de 127.0.0.1, puis la fenêtre bascule sur l'app.
// À la fermeture, le serveur PHP est arrêté.
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use std::fs::{self, File};
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::sync::Mutex;
use std::thread;
use std::time::{Duration, Instant};

use tauri::{AppHandle, Manager, RunEvent, Url, WebviewUrl, WebviewWindow, WebviewWindowBuilder};

/// Serveur PHP lancé par l'application, arrêté à la fermeture.
struct PhpServer(Mutex<Option<Child>>);

const STARTUP_TIMEOUT: Duration = Duration::from_secs(60);

fn main() {
    let app = tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .manage(PhpServer(Mutex::new(None)))
        .setup(|app| {
            let window = WebviewWindowBuilder::new(app, "main", WebviewUrl::App("index.html".into()))
                .title("MyRuns")
                .inner_size(1280.0, 860.0)
                .min_inner_size(800.0, 600.0)
                .on_navigation(|url| {
                    // Pages de l'app dans la fenêtre, liens externes dans le navigateur par défaut.
                    if is_internal(url) {
                        return true;
                    }
                    let _ = tauri_plugin_opener::open_url(url.as_str(), None::<&str>);
                    false
                })
                .build()?;

            let handle = app.handle().clone();
            thread::spawn(move || match start(&handle) {
                Ok(url) => {
                    let _ = window.navigate(url);
                }
                Err(message) => show_error(&window, &message),
            });

            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("impossible de démarrer MyRuns");

    app.run(|handle, event| {
        if let RunEvent::Exit = event {
            stop_server(handle);
        }
    });
}

fn start(handle: &AppHandle) -> Result<Url, String> {
    let resources = handle.path().resource_dir().map_err(|e| e.to_string())?;
    let data_dir = handle.path().app_data_dir().map_err(|e| e.to_string())?;
    let app_dir = resources.join("app");
    let logs = data_dir.join("logs");
    let caches = data_dir.join("cache");
    let version = handle.package_info().version.to_string();

    adopt_mystrava_data(&data_dir)?;
    fs::create_dir_all(&logs).map_err(|e| format!("Dossier {} : {e}", logs.display()))?;
    remove_old_caches(&caches, &version);

    let port = free_port()?;
    let env = [
        ("APP_ENV", "prod".to_string()),
        ("APP_DEBUG", "0".to_string()),
        ("MYRUNS_DATA_DIR", path_string(&data_dir)),
        // Cache par version : une mise à jour repart d'un cache neuf.
        ("APP_CACHE_DIR", path_string(&caches.join(&version))),
        ("APP_LOG_DIR", path_string(&logs)),
        ("APP_SHARE_DIR", path_string(&data_dir.join("share"))),
        ("STRAVA_EXPORT_PATH", String::new()),
        ("DEFAULT_URI", format!("http://127.0.0.1:{port}")),
    ];

    let php = Php::locate(&resources);
    php.run(&app_dir, &env, &["bin/console", "doctrine:migrations:migrate", "--no-interaction", "--allow-no-migration"], &logs.join("migrations.log"))?;
    php.run(&app_dir, &env, &["bin/console", "cache:warmup"], &logs.join("cache.log"))?;

    let address = format!("127.0.0.1:{port}");
    let router = path_string(&app_dir.join("router.php"));
    // -t public : racine des fichiers que le routeur laisse servir tels quels (assets compilés).
    let server = php.spawn(&app_dir, &env, &["-S", &address, "-t", "public", &router], &logs.join("server.log"))?;
    handle.state::<PhpServer>().0.lock().unwrap().replace(server);

    wait_until_listening(port)?;
    Url::parse(&format!("http://{address}/")).map_err(|e| e.to_string())
}

/// PHP embarqué (Windows), sinon celui de MYRUNS_PHP ou du PATH (développement).
struct Php {
    exe: PathBuf,
    args: Vec<String>,
}

impl Php {
    fn locate(resources: &Path) -> Self {
        let dir = resources.join("php");
        let exe = dir.join(if cfg!(windows) { "php.exe" } else { "php" });
        if !exe.is_file() {
            let exe = std::env::var_os("MYRUNS_PHP").map_or_else(|| PathBuf::from("php"), PathBuf::from);
            return Self { exe, args: Vec::new() };
        }

        let mut args = vec![
            "-c".to_string(),
            path_string(&dir.join("php.ini")),
            "-d".to_string(),
            format!("extension_dir=\"{}\"", path_string(&dir.join("ext"))),
        ];
        let certificates = dir.join("cacert.pem");
        if certificates.is_file() {
            for setting in ["curl.cainfo", "openssl.cafile"] {
                args.push("-d".to_string());
                args.push(format!("{setting}=\"{}\"", path_string(&certificates)));
            }
        }

        Self { exe, args }
    }

    fn command(&self, app_dir: &Path, env: &[(&str, String)], args: &[&str], log: &Path) -> Result<Command, String> {
        let out = File::create(log).map_err(|e| format!("Journal {} : {e}", log.display()))?;
        let err = out.try_clone().map_err(|e| e.to_string())?;

        let mut command = Command::new(&self.exe);
        command
            .args(&self.args)
            .args(args)
            .current_dir(app_dir)
            .envs(env.iter().map(|(name, value)| (name, value)))
            .stdin(Stdio::null())
            .stdout(out)
            .stderr(err);

        #[cfg(windows)]
        {
            use std::os::windows::process::CommandExt;
            const CREATE_NO_WINDOW: u32 = 0x0800_0000;
            command.creation_flags(CREATE_NO_WINDOW);
        }

        Ok(command)
    }

    fn run(&self, app_dir: &Path, env: &[(&str, String)], args: &[&str], log: &Path) -> Result<(), String> {
        let status = self
            .command(app_dir, env, args, log)?
            .status()
            .map_err(|e| format!("PHP introuvable ({}) : {e}", self.exe.display()))?;

        if status.success() {
            Ok(())
        } else {
            Err(format!("« {} » a échoué. Détails : {}", args.join(" "), log.display()))
        }
    }

    fn spawn(&self, app_dir: &Path, env: &[(&str, String)], args: &[&str], log: &Path) -> Result<Child, String> {
        self.command(app_dir, env, args, log)?
            .spawn()
            .map_err(|e| format!("PHP introuvable ({}) : {e}", self.exe.display()))
    }
}

fn free_port() -> Result<u16, String> {
    let listener = TcpListener::bind("127.0.0.1:0").map_err(|e| format!("Aucun port libre : {e}"))?;
    listener.local_addr().map(|address| address.port()).map_err(|e| e.to_string())
}

fn wait_until_listening(port: u16) -> Result<(), String> {
    let address = SocketAddr::from(([127, 0, 0, 1], port));
    let deadline = Instant::now() + STARTUP_TIMEOUT;
    while Instant::now() < deadline {
        if TcpStream::connect_timeout(&address, Duration::from_millis(250)).is_ok() {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(100));
    }

    Err("Le serveur PHP ne répond pas. Détails : logs/server.log dans le dossier de données.".to_string())
}

/// L'application s'appelait MyStrava : on reprend son dossier de données et sa base, une seule fois.
fn adopt_mystrava_data(data_dir: &Path) -> Result<(), String> {
    let old_dir = data_dir.with_file_name("local.mystrava.desktop");
    if !data_dir.exists() && old_dir.is_dir() {
        fs::rename(&old_dir, data_dir).map_err(|e| format!("Dossier {} : {e}", old_dir.display()))?;
        // Le cache compilé pointe encore vers l'ancien dossier.
        let _ = fs::remove_dir_all(data_dir.join("cache"));
    }

    let old_db = data_dir.join("mystrava.db");
    let db = data_dir.join("myruns.db");
    if old_db.is_file() && !db.exists() {
        fs::rename(&old_db, &db).map_err(|e| format!("Base {} : {e}", old_db.display()))?;
    }

    Ok(())
}

fn remove_old_caches(caches: &Path, version: &str) {
    let Ok(entries) = fs::read_dir(caches) else {
        return;
    };
    for entry in entries.flatten() {
        if entry.file_name() != version {
            let _ = fs::remove_dir_all(entry.path());
        }
    }
}

fn stop_server(handle: &AppHandle) {
    if let Some(mut server) = handle.state::<PhpServer>().0.lock().unwrap().take() {
        let _ = server.kill();
        let _ = server.wait();
    }
}

fn is_internal(url: &Url) -> bool {
    url.scheme() == "tauri" || matches!(url.host_str(), Some("127.0.0.1" | "localhost" | "tauri.localhost"))
}

fn show_error(window: &WebviewWindow, message: &str) {
    let message = serde_json::to_string(message).unwrap_or_default();
    let _ = window.eval(format!("window.showError && window.showError({message})"));
}

fn path_string(path: &Path) -> String {
    path.to_string_lossy().into_owned()
}
