# CLAUDE.md — MyRuns

Application personnelle : import d'activités Strava (export de compte ZIP, dossiers GPX/TCX),
dashboard web, commandes console et application de bureau Windows et Linux (Tauri), prédiction de
réussite d'un objectif chrono (VDOT + coach IA).

## Stack (vérifiée dans composer.lock)

- PHP 8.4, Symfony **8.1**, Doctrine ORM **3.7** / DBAL 4, **SQLite** (`pdo_sqlite`), dev sous **DDEV**
- Application de bureau : **Tauri 2** (`desktop/`, Rust), PHP embarqué ; installeur NSIS (Windows),
  `.deb` + AppImage (Linux, PHP statique static-php-cli « common », sans intl), construits par
  GitHub Actions (`.github/workflows/desktop.yml`, sur tag `v*`)
  Mise à jour automatique (`tauri-plugin-updater`, `check_for_update()` dans `main.rs`) : Windows et
  AppImage avec accord de l'utilisateur, `.deb` seulement prévenu (bouton vers la page de la release) ; `latest.json` signé joint à la
  release par le workflow (secrets `TAURI_SIGNING_PRIVATE_KEY[_PASSWORD]`, clé publique dans
  `tauri.conf.json`) ; dépôt public requis (endpoint `releases/latest/download/`). Clé privée perdue =
  plus aucune mise à jour possible des apps installées
- `symfony/ai-bundle` + `symfony/ai-anthropic-platform` **0.14** (pré-1.0 : vérifier l'API dans `vendor/`)
- Twig + AssetMapper (pas de build JS) + **Stimulus** (`symfony/stimulus-bundle` 3.6) + **Turbo**
  (`symfony/ux-turbo` 2.36, Turbo 8 : navigation et formulaires sans rechargement). Tout comportement
  JS est un contrôleur de `assets/controllers/` (`app.js` ne fait que les importer) : `disconnect()`
  libère ce que `connect()` crée, et `connect()` doit supporter une page remontrée depuis le cache
  de Turbo (copie du DOM déjà modifié : vider le conteneur, remettre l'état initial) ; contrôleurs
  lourds (cartes, profil) en `/* stimulusFetch: 'lazy' */`. Un POST traité par Turbo **redirige**,
  ou répond 422 s'il est invalide (jamais 200) ; seule exception, le formulaire d'`/objectif`
  (résultat affiché en réponse au POST) en `data-turbo="false"`. Page sans cache :
  `{% block head %}<meta name="turbo-cache-control" content="no-cache">{% endblock %}`. Graphiques en SVG rendus côté serveur, sauf le
  profil de la page détail (Chart.js, chargé à la demande comme Leaflet)

## Préfixe de commandes

L'hôte n'a pas `pdo_sqlite` : tout ce qui touche la base passe par **`ddev exec`**
(`ddev exec bin/console …`, `ddev exec vendor/bin/phpunit`). PHPStan, php-cs-fixer et les lints
tournent aussi sur l'hôte. `ddev start` ne demande `sudo` qu'au tout premier démarrage du projet.

## Règles du projet

- **Ne pas réintroduire l'API Strava** : payante depuis juin 2026 et sa politique interdit l'usage
  de ses données avec une IA. Sources : archive Strava ou Garmin Connect (historique) + **intervals.icu** au quotidien
  (`src/Sync/`, client HTTP `intervals.client` dans `framework.yaml`, séances `source = STRAVA`
  ignorées). Tests de synchro avec `MockHttpClient` uniquement, jamais la vraie clé.
- Imports **idempotents** (`ActivityRepository::upsert()`) : même `id` d'abord ; sinon même sortie
  d'une autre source à ±1 min (`Activity::$startKey`, index non unique). **Jamais de fusion entre
  deux lignes d'un même export** (bug corrigé : 3 activités perdues), rien ne remplace une ligne
  d'export de compte (CSV Strava, résumé Garmin : la première importée reste).
  `tests/Integration/DeduplicationTest.php` fige ces règles. Activité supprimée par l'utilisateur
  (`ActivityRepository::delete()`) : retenue dans `DeletedActivity`, `upsert()` renvoie
  `UpsertOutcome::Deleted` (activité `null`) pour elle, avec les mêmes règles ; jamais recréée.
- Export Garmin Connect (`GarminExportParser`) : `*_summarizedActivities.json` (cm, ms) puis les
  FIT des zips imbriqués `UploadedFiles_*.zip` (extraits dans le dossier temporaire, effacés par
  `ActivityImporter::cleanUp()`) ; pas de nom de fichier dans le résumé → rattachement par l'heure
  de départ. Figé par `tests/Integration/GarminImportTest.php`.
- Traces : CSV d'abord, puis chaque trace (`FitDecoder` maison, GPX, TCX) est rattachée à sa ligne
  via `Activity::$trackFile` (colonne « Nom du fichier ») → `ActivitySplit` (km calculés, tours de
  montre) + `sustainedMaxHeartRate`. Ne jamais versionner une trace réelle (GPS de l'utilisateur) :
  fichiers FIT de test générés par `tests/Fit/FitFileBuilder.php`.
- D+ : `Activity::$elevationGain` = valeur de la source (jamais modifiée), `$groundElevationGain` =
  terrain (`src/Elevation/`, posé par `replaceTrackData()`) ; lire `effectiveElevationGain()`.
  IGN en France ; chaque point sans donnée IGN (étranger, mer) est complété par Open-Meteo
  (`OpenMeteoElevation`, Copernicus 90 m, 1 point sur 5 puis interpolation, quota par minute →
  1,5 s entre requêtes). **Jamais limité à la France.** Cache persistant `app.elevation_cache`
  (dossier de données, hors `cache:clear`) ; ancien format IGN (`null` = trace hors zone) encore lu.
  En test, tout client HTTP du conteneur répond 503 (`tests/Double/NoNetworkResponseFactory`) :
  les tests du D+ construisent `IgnAltimetry` / `OpenMeteoElevation` avec leur `MockHttpClient`.
- Import web **par étapes** (`ImportJobs`, état JSON + archive dans `<données>/uploads/`) :
  `ActivityImporter::begin()` (CSV) puis `continue()` depuis une position, jusqu'à une échéance
  (`app.import_step_seconds`, 3 s ; 0 en test = une trace par étape) ; l'ordre des traces doit rester
  stable (position = reprise). Page `/import/{id}` pilotée par `fetch` (`import_job_controller.js`), bouton
  « Continuer » sans JS. Services publics (IGN, Open-Meteo, Nominatim) derrière `App\Http\NetworkGuard` :
  plus d'appel après 3 échecs de suite, budget réseau en web, intervalle minimal entre appels.
- Sauvegarde (`src/Backup/DatabaseBackup`, section de la page `/import`) : zip = `myruns.db` (copie
  `VACUUM INTO`) + `manifest.json`, `settings.json` (clés d'API) seulement si demandé. Restauration :
  contrôle SQLite + table `activity`, refus si une migration est inconnue (sauvegarde plus récente
  que l'app), ancienne base gardée en `myruns.db.bak`, migrations manquantes appliquées ; chemin de
  la base lu dans la connexion DBAL (≠ dossier de données en test). Figé par
  `tests/Integration/DatabaseBackupTest.php` (base temporaire : DAMA interdit VACUUM en transaction).
- Ville : commune du 1er point GPS (`src/Geocoding/CityFinder` : `IgnReverseGeocoder`, index
  `parcel`, puis `NominatimReverseGeocoder` hors de France — 1 appel/s et User-Agent imposés par
  la charte OSM, client `nominatim.client`), cache persistant `app.place_cache` par position arrondie à 3 décimales ;
  posée par `replaceTrackData()`, jamais effacée si le service ne répond pas.
- Météo (`src/Weather/OpenMeteoWeather`) : historique Open-Meteo (client `openmeteo_archive.client`)
  ou API de prévision pour les 7 derniers jours, au 1er point GPS ; valeurs de l'heure la plus
  proche du milieu de la sortie (`Weather`, JSON `Activity::$weather`, lu par `weather()`), posée à
  l'import par `replaceWeather()` (jamais effacée), complétée par `app:weather`. Cache persistant
  `app.weather_cache` (jour + position à 2 décimales) ; heures pas encore publiées jamais en cache.
- Carte : trace simplifiée (`RouteSimplifier`, 20 m, ≤ 2 000 points) dans `ActivityRoute` (table
  à part, OneToOne porté par `Activity` avec `orphanRemoval` : SQLite n'applique pas les clés
  étrangères), posée par `replaceTrackData()`. Allure par tronçon calculée en PHP
  (`src/Chart/RouteMap.php`), dessin Leaflet chargé à la demande (`route_map_controller.js`), tuiles
  OSM = seul appel réseau du navigateur (autorisé dans la CSP Tauri `img-src`).
- Carte des départs (`/activites`) : `ActivityRoute::$startLatitude/$startLongitude` (posées par le
  constructeur ; migration `Version20261017180000` les calcule en PHP pour l'existant) ;
  `ActivityRepository::startPositions()` reprend les critères de la recherche, toutes pages
  confondues ; `src/Chart/StartMap.php` → `assets/activities_map.js` (importé à la 1ʳᵉ ouverture par
  `activities_map_controller.js`) : regroupement maison par
  distance (60 px, fusion des groupes trop proches, pas de plugin Leaflet), liste au zoom 17.
- Profil de la sortie (page détail) : `StreamSampler` (~600 points : distance, temps, altitude du
  terrain, FC, cadence) → `ActivityStream` (table à part comme `ActivityRoute`, remplie même sans
  GPS), posé par `replaceTrackData()`. Cadence = cycles/min de la source (FIT champ 4 + 53, GPX
  `cad`, TCX `Cadence`/`RunCadence`), ×2 à l'affichage à pied (pas/min). `src/Chart/ActivityProfile`
  (allure lissée sur 200 m, arrêts exclus) → `activity_profile_controller.js` : **Chart.js direct via
  l'importmap** (pas de Symfony UX Chart.js : options Chart.js pilotées en JS, couleurs CSS), un panneau par série sur des
  axes Y empilés (jamais deux échelles sur une même zone), couleurs `--profile-*` d'`app.css`.
- Analyse de l'effort (`src/Effort/`, page détail) : rien n'est stocké, tout part de `ActivityStream`.
  Allure ajustée à la pente = coût de Minetti (`GradeAdjustment`, pente sur 100 m, bornée à ±45 %),
  appliquée en **rapport** à l'allure réelle (sortie et km : colonne « Ajustée » de `SplitTable`) ;
  pas de normalisation à la puissance 4 (la NGP de Suunto n'en a pas). Meilleures allures dès 1 min
  (30 s faussé par le GPS sur la série échantillonnée). Zones cardiaques Karvonen, seulement avec
  une FC max dans le profil. Figé par `tests/Unit/Effort/` et `tests/Functional/EffortAnalysisTest.php`.
- Même parcours (`src/Route/`, page détail) : rien n'est stocké ; candidats en SQL (départ à < 300 m
  via `ActivityRoute::$startLatitude/$startLongitude`, même famille de sport, distance ±10 %), puis
  `RouteMatcher` : chaque trace longe l'autre à 60 m près sur ≥ 90 %, **dans le même sens** (boucle
  à l'envers = autre parcours). Comparaison au temps de déplacement. Figé par
  `tests/Unit/Route/RouteMatcherTest.php` et `tests/Functional/SameRouteTest.php`.
- Trail : type déclaré par la source ou nom contenant « trail » (`Sport::refinedByName()`), jamais
  le sous-sport FIT (mode montre utilisé aussi sur route).
- Estimation cardiaque : Karvonen (FC repos par défaut 60) ; ignorée si une course < 12 semaines.
- Entité `Activity` : propriétés `public private(set)` (lecture publique), modification via
  `replaceWith()` uniquement. Toute évolution de schéma → migration relue (`migrations/`).
- `orderBy()` avec `\SortDirection::Descending` (les chaînes 'DESC' sont dépréciées en ORM 3.7).
- Calcul de probabilité **déterministe** (`src/Prediction/`) ; l'IA ne fait qu'affiner. Idem pour le
  programme d'entraînement (`src/Training/TrainingPlanBuilder`, figé par `tests/Unit/Training/`) :
  construit depuis `AthleteProfile::lastGoal()` (dernier objectif calculé sur `/objectif`), réglages
  sur le profil (`planSessionsPerWeek`) et ajustements par semaine (`TrainingWeekOverride` :
  sorties, jours échangés par glisser-déposer `plan_week_controller.js` → `$days`, appliqués par
  `moveDays()` ; séances réécrites → `$edits` par jour prévu, appliquées par `applyEdits()` avant les
  échanges ; jamais l'objectif ; ligne supprimée quand elle revient au réglage général). Pas de
  séance club (retirée : trop personnelle).
- Bilan prévu / réalisé (`src/Training/PlanReviewer`, règles dans `SessionEvaluator`, figées par
  `tests/Unit/Training/SessionEvaluatorTest.php` et `tests/Integration/PlanReviewTest.php`) : rien
  n'est stocké, le prévu d'une semaine = `CurrentPlan::planAt(lundi)` (forme du lundi), les
  semaines suivantes = forme du jour. Plus long que prévu à la bonne intensité est **positif**
  (« Largement réussie ») ; seule l'intensité donne un avertissement. Pas d'écart de probabilité
  par séance (le modèle de tendance le rend trompeur) : seulement l'écart depuis lundi.
- Base **SQLite** : chemin `%app.data_dir%/myruns.db` (`MYRUNS_DATA_DIR`, défaut `var/`).
  SQLite compare les dates comme du texte et oublie le fuseau : `Activity::$startDate` utilise le
  type `utc_datetime_immutable` (`src/Doctrine/`), les paramètres DQL de date se lient avec leur
  type (`UtcDateTimeImmutableType::NAME`, `Types::DATE_IMMUTABLE`) ; aucune fonction SQL propre à
  un SGBD. Figé par `tests/Integration/SqliteDatesTest.php`.
- Clés d'API : page `/reglages` → `settings.json` du dossier de données, injecté par
  `SettingsEnvVarLoader` (seulement pour une variable vide : `.env.local` reste prioritaire).
  Ne jamais écrire dans le dossier de l'app (lecture seule une fois installée) : données, uploads,
  cache et logs vont dans le dossier de données.
- Application de bureau : `bin/prepare-desktop-app` (liste blanche de fichiers : jamais `imports/`,
  `var/`, `.env.local`) ; PHP Windows et PHP Linux figés par version + SHA256 dans le workflow ; aucune
  extension PHP au-delà de celles du build Linux (pas d'`intl` : polyfills Symfony).
- Profil (`AthleteProfile`, une seule ligne, `AthleteProfileRepository::current()`) : tous les
  champs facultatifs ; **sans profil, les résultats doivent rester identiques** (tests existants).
  Les effets du profil sont figés par `tests/Integration/ProfileImpactTest.php` (valeurs calculées
  à la main) : toute retouche des coefficients doit les justifier. Seule exception : l'ancienneté
  vide est déduite de la 1ʳᵉ course (`RunningExperience`) si l'historique couvre ≥ 2 ans.
- Textes en français. Modèle IA : `AI_MODEL` (défaut `claude-sonnet-5`). En test, la plateforme IA
  est `tests/Double/FakeCoachPlatform` (aucun appel réseau).
- Front : couleurs uniquement en variables dans `assets/styles/app.css`, mode clair + sombre
  (`prefers-color-scheme`) obligatoires.
- Nom **MyRuns** (ex-MyStrava ; le dépôt et le projet DDEV `mystrava.ddev.site` gardent l'ancien
  nom). Pas de charte Strava : accent sarcelle, jamais l'orange Strava. L'app de bureau reprend une
  fois les données de l'ancien nom (`adopt_mystrava_data()` dans `desktop/src-tauri/src/main.rs`).
- Pas d'authentification (usage local : DDEV, ou app de bureau sur `127.0.0.1`) : à ajouter
  avant toute exposition.

## Commandes

```bash
ddev exec bin/console app:import [chemin] | app:predict <distance> <temps> <date> [--ai]
vendor/bin/php-cs-fixer fix && vendor/bin/phpstan analyse
php bin/console lint:twig templates/ && php bin/console lint:yaml config/ && php bin/console lint:container
ddev exec bin/console doctrine:schema:validate && ddev exec vendor/bin/phpunit
bin/prepare-desktop-app && (cd desktop/src-tauri && cargo build)   # app de bureau
```

PHPStan au niveau **max**, sans baseline ni `@phpstan-ignore` (phpstan-doctrine charge l'EntityManager
via `tests/object-manager.php`).

## Environnement local

Le token GitHub global de Composer est invalide sur cette machine (401) : sur l'hôte, lancer
Composer avec un `COMPOSER_HOME` vide temporaire ; ou utiliser `ddev composer`.
