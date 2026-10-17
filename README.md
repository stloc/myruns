# MyRuns

Importe vos activités Strava **sans l'API Strava** et prédit vos chances de réussir un objectif
chrono, à la manière de Kiprun Pacer (« 55 % de réussir un 10 km en 45:00 le 15/12 »), avec une
analyse complémentaire par IA ([Symfony AI](https://ai.symfony.com/)).

Trois façons de l'utiliser :

- une **application Windows** à installer (fenêtre Tauri, PHP embarqué, données sur le PC de
  chacun) : voir [Application de bureau Windows](#application-de-bureau-windows) ;
- un **dashboard web** : <https://mystrava.ddev.site> ;
- une **interface terminal** ([symfony/tui](https://symfony.com/packages/tui)) : `ddev exec bin/console tui`.

Stack : Symfony 8.1, PHP 8.4, SQLite (Doctrine ORM 3), Twig + AssetMapper (sans build JS),
Symfony AI 0.14 + Anthropic ; développement sous DDEV, application de bureau Tauri 2.

## Installation

```bash
ddev start                     # demande sudo la première fois (entrée /etc/hosts)
ddev composer install
ddev exec bin/console doctrine:migrations:migrate -n   # crée var/myruns.db
```

Clés d'API : page *Réglages* (`/reglages`, enregistrées dans `var/settings.json`) ou `.env.local`
(`ANTHROPIC_API_KEY`, `INTERVALS_API_KEY`), qui reste prioritaire. Les réglages sont injectés comme
variables d'environnement par `src/Settings/SettingsEnvVarLoader.php` : Symfony ne l'interroge que
pour une variable vide.

Données : `MYRUNS_DATA_DIR` (défaut `var/`) contient `myruns.db`, `settings.json` et
`uploads/`. Tests (base `var/test.db`, données dans `var/test-data/`) :

```bash
ddev exec bin/console --env=test doctrine:migrations:migrate -n
ddev exec vendor/bin/phpunit
```

## Récupérer ses activités

Deux sources, combinables. Dédoublonnage : même identifiant, ou même sortie venant de deux
sources différentes (départ à ±1 minute) ; deux lignes du CSV ne sont jamais fusionnées.

1. **Export de compte Strava** (officiel, gratuit) : Paramètres → Mon compte → *Télécharger ou
   supprimer votre compte* → *Demander une archive*. Strava envoie par e-mail un lien vers
   `export_XXXX.zip`. On lit `activities.csv` (en-têtes anglais ou français), qui fait foi pour
   le résumé, puis les traces `.fit`/`.gpx`/`.tcx` (`.gz` compris), rattachées à leur ligne du CSV
   par la colonne « Nom du fichier ». Les traces donnent les **temps de passage au km**, les
   **tours de la montre** et la **FC max tenue 10 s** (qui écarte les pics du capteur optique).
   Le format FIT est décodé par `src/Import/Fit/FitDecoder.php`, sans dépendance externe.
2. **Dossier de traces GPX/TCX** alimenté régulièrement : Tapiriik vers Dropbox/Google Drive,
   extension « Strava Bulk GPX Downloader »…

3. **Au quotidien : intervals.icu** (gratuit). La montre (Suunto, Garmin, Coros…) se synchronise
   avec intervals.icu, qui garde le fichier FIT d'origine. `app:sync` récupère les nouvelles
   séances via l'API intervals.icu (clé personnelle, authentification basique `API_KEY:<clé>`),
   télécharge le FIT seulement si les temps de passage manquent, et ignore les séances arrivées
   dans intervals.icu par Strava. Clé dans *Réglages* ou `.env.local` (`INTERVALS_API_KEY`) ;
   `INTERVALS_ATHLETE_ID` vide ou `0` = propriétaire de la clé. La FC de repos (données de santé) remplit
   le profil si elle n'y est pas encore.

   ```bash
   ddev exec bin/console app:sync                    # depuis 3 jours avant la dernière séance connue
   ddev exec bin/console app:sync --since=2026-09-01
   ```

   L'API Strava n'est pas utilisée : depuis juin 2026 elle exige un abonnement et sa politique
   interdit d'utiliser ses données avec une IA.

Import depuis le dashboard (page *Import* : synchro intervals.icu, envoi d'un fichier, ou bouton
« Importer le dossier », affiché seulement si `STRAVA_EXPORT_PATH` est renseigné : jamais dans
l'app de bureau) ou en ligne de commande :

```bash
ddev exec bin/console app:import imports/export_12345678.zip
ddev exec bin/console app:import            # dossier STRAVA_EXPORT_PATH (défaut : imports/)
```

La première lecture d'une archive complète est longue (une à quelques secondes par trace à cause
des appels IGN, soit 10 à 25 minutes pour 600 traces ; les réimports sont quasi instantanés grâce
au cache). Depuis la page web, l'import avance donc **par étapes** (`src/Import/ImportJobs.php`) :
la requête d'envoi importe le CSV (toutes les activités apparaissent) puis lit les traces pendant
3 s (`app.import_step_seconds`) ; s'il en reste, la page d'avancement `/import/{id}` enchaîne
des étapes de 3 s en `fetch` (barre de progression, bouton « Arrêter »). L'état de l'import et l'archive
envoyée restent dans `<données>/uploads/` jusqu'à la fin : un import interrompu (page fermée,
coupure) se reprend depuis la page *Import* sans renvoyer le fichier ; abandonné plus de 7 jours,
il est effacé. `app:import` affiche une barre de progression et enregistre en base toutes les 30 s.

Pendant l'import, chaque trace GPS reçoit :

- une **trace simplifiée** pour la carte de la page détail (`ActivityRoute`, un point tous les
  20 m, 2 000 points au plus ; jamais pour la natation). Carte Leaflet sur fond OpenStreetMap,
  tronçons de 100 m colorés selon l'allure (vitesse hors course à pied) en 5 classes de même
  effectif, arrêts en pointillés (`src/Chart/RouteMap.php`). Activités importées avant cette
  version : relancer l'import de l'archive (ou une synchro) pour obtenir leur carte ;
- un **D+ recalculé sur le terrain** (`src/Elevation/`), partout dans le monde : un point tous
  les 10 m est envoyé au service d'altimétrie de l'IGN (RGE ALTI, France, sans clé) ; les points
  qu'il ne couvre pas (étranger, mer) sont demandés à l'API d'altitude d'**Open-Meteo** (modèle
  Copernicus GLO-90, maille de 90 m, sans clé ; un point sur 5, les autres interpolés, 1,5 s entre
  deux requêtes pour respecter son quota par minute). Altitudes lissées (moyenne sur 5 points,
  seuil de 1 m), puis interpolées sur toute la trace (D+ au km compris). Réglages calibrés sur
  des sorties dont Strava avait corrigé le D+. Réponses mises en cache dans
  `<données>/elevation-cache` : un réimport ne refait aucun appel. Sans réseau (ou quota
  Open-Meteo atteint), le D+ de la source est gardé et la trace est complétée au prochain import.
  Après 3 échecs réseau de suite, un service n'est plus appelé jusqu'à la fin de l'étape ou de la
  commande (`src/Http/NetworkGuard.php` : pas d'attente du délai réseau pour chaque trace).
- la **ville** du départ (`src/Geocoding/`) : commune de la parcelle cadastrale la plus proche du
  premier point GPS, d'après le géocodage inverse de l'IGN (France, sans clé) ; ailleurs, ville
  d'après **Nominatim** (OpenStreetMap, sans clé, 1 appel par seconde au plus comme l'exige sa
  charte). Réponses en cache par position arrondie à ~100 m dans `<données>/place-cache` ; colonne
  « Ville » de la liste des activités, recherche par nom **ou** ville. En mer ou sans GPS : « – ». Activités
  importées avant cette version : relancer l'import de l'archive pour obtenir leur ville ;
- un type **Trail** si la source le déclare (intervals.icu `TrailRun`, GPX/TCX) ou si le nom
  contient « trail » : le CSV Strava ne donne que l'ancien type « Course à pied », mais le nom
  automatique (« Trail le matin ») garde le type précis. Le sous-sport FIT « trail » est ignoré
  (mode montre utilisé aussi sur route).

Sous DDEV, seul le dossier du projet est visible depuis le conteneur : déposer les archives dans
`imports/` (ignoré par git) ou y faire un lien vers le dossier synchronisé. Un chemin relatif est
résolu depuis la racine du projet. L'import est idempotent, donc planifiable (cron de l'hôte) :

```cron
0 7 * * * cd /chemin/vers/mystrava && ddev exec bin/console app:sync -q
```

## Utilisation

| Écran | Accès |
|---|---|
| Tableau de bord (forme, volume 26 semaines, potentiel, dernières sorties) | `/` |
| Liste des activités : recherche, tri, carte des départs (regroupés selon le zoom) | `/activites` |
| Détail d'une activité : carte du parcours (allure), temps de passage au km, tours de la montre, km rapides | `/activites/{id}` |
| Objectif & prédiction (+ analyse IA) | `/objectif` |
| Programme d'entraînement jusqu'au dernier objectif calculé | `/programme` |
| Import | `/import` |
| Profil (FC, âge, ancienneté, poids, courses officielles) | `/profil` |
| Réglages (clés intervals.icu et Anthropic) | `/reglages` |
| Interface terminal | `ddev exec bin/console tui` |
| Prédiction en ligne de commande | `ddev exec bin/console app:predict 10k 45:00 2026-12-15 --ai` |

Pas d'authentification : l'application est prévue pour un usage local (DDEV ou application de
bureau, qui n'écoute que sur `127.0.0.1`).

## Application de bureau Windows

Pour des utilisateurs sans DDEV ni Docker : un installeur Windows (NSIS, sans droits
administrateur) qui embarque l'app Symfony et un PHP 8.4 officiel.

- `desktop/src-tauri/src/main.rs` : au lancement, dossier de données `%APPDATA%\local.myruns.desktop`
  (`MYRUNS_DATA_DIR`, cache Symfony par version, `logs/` ; reprend une fois `local.mystrava.desktop`
  et `mystrava.db` de l'ancien nom de l'app), migrations, `cache:warmup`, puis
  `php -S 127.0.0.1:<port libre> router.php` ; la fenêtre bascule sur l'app quand le port répond
  et PHP est arrêté à la fermeture. Les liens externes s'ouvrent dans le navigateur.
- `desktop/php.ini` : extensions (`pdo_sqlite`, `intl`, `zip`, `curl`…), envois jusqu'à 2 Go ;
  `extension_dir` et certificats (`cacert.pem`) passés en `-d` par Tauri.
- `desktop/router.php` : routeur du serveur interne de PHP (fichiers de `public/`, sinon Symfony).
- `bin/prepare-desktop-app` : copie l'app dans `desktop/build/app` (`composer install --no-dev`,
  `asset-map:compile`, sans `var/`, `imports/`, `tests/` ni `.env.local`).
- `.github/workflows/desktop.yml` : sur un tag `v*`, runner Windows → PHP Windows figé
  (version + SHA256), `cacert.pem` vérifié, installeur Tauri joint à une release GitHub brouillon.

```bash
git tag v0.2.0 && git push origin v0.2.0      # puis publier la release brouillon sur GitHub
```

Essai local sous Linux (PHP du poste avec `pdo_sqlite`, ou `MYRUNS_PHP=/chemin/php`) :

```bash
COMPOSER_HOME="$(mktemp -d)" bin/prepare-desktop-app
cd desktop/src-tauri && cargo tauri dev
```

L'interface terminal n'est pas livrée sous Windows (`symfony/tui` s'appuie sur `stty`).

## Comment la probabilité est calculée

1. **Forme actuelle** (VDOT de Daniels), trois sources combinées :
   - *performances* : moyenne des 3 meilleures courses ≥ 3 km des 12 dernières semaines
     (dénivelé converti en distance équivalente, trails exclus), pénalisées par leur ancienneté ;
   - *cardio* (si la FC max est renseignée) : médiane, sur 6 semaines, de la VO2max estimée par
     sortie à partir de la FC moyenne (réserve cardiaque de Karvonen ≈ réserve de VO2 de Swain ;
     FC de repos supposée à 60 si elle n'est pas renseignée). Retenue à 70 % de l'écart avec les
     performances, +8 maximum. Très sensible à la FC de repos (1-2 bpm ≈ 1,5 point) ;
   - *courses officielles* du profil (26 semaines, pénalisées par l'ancienneté) : plancher. Une
     course de moins de 12 semaines **fait foi** : l'estimation cardiaque n'est alors pas utilisée.
2. **Projection** à la date de l'objectif : progression selon la régularité (sorties/semaine) et
   la tendance récente, avec rendements décroissants et plafonds (+4 / −3 VDOT), modulée par
   l'âge (×1 avant 30 ans → ×0,5 après 60) et l'ancienneté (×1,3 avant 2 ans, ×0,8 après 5).
   Ancienneté non saisie : année de la première course de l'historique, si celui-ci couvre au
   moins 2 ans (minimum : on courait peut-être avant l'archive ; un historique court ne dit pas
   si l'on débute, aucun effet).
   Un poids visé différent du poids actuel ajuste le VDOT de la moitié de l'effet théorique,
   plafonné à ±3 %.
3. **Pénalité d'endurance** au-delà de 10 km : volume hebdomadaire et sortie longue comparés à
   ceux qu'exige la distance, en supposant une montée en charge prudente.
4. **Probabilité** = Φ((VDOT effectif − VDOT requis) / σ), où σ grandit avec l'horizon, la
   progression supposée, le manque de données et la distance, et diminue avec une course
   officielle récente cohérente.
5. **Coach IA** (optionnel) : le modèle reçoit l'historique de 12 semaines et le résultat
   statistique, puis renvoie une réponse structurée (probabilité ajustée de ±15 points au plus
   sauf justification, temps prédit, points forts, risques, recommandations).

## Comment le programme d'entraînement est construit

Déterministe (`src/Training/TrainingPlanBuilder.php`), recalculé à chaque affichage à partir du
dernier objectif enregistré et de la forme du moment :

1. **Semaines** de la semaine en cours (lundi) à celle de l'objectif, en quatre phases : foncier,
   développement (≈ moitié du reste, 8 sem. max), spécifique (≈ 30 %, 6 sem. max), affûtage
   (1 semaine jusqu'au 10 km, 2 jusqu'au semi, 3 au-delà). Une semaine allégée (×0,75) toutes les
   quatre à partir de 5 semaines de préparation.
2. **Volume** : départ au kilométrage des 4 dernières semaines (4 km par séance au minimum),
   +10 %/semaine au plus, cible `20 + distance` km bornée entre 9 et 14 km par séance.
   Sortie longue : +1,5 km par semaine jusqu'à `8 + 0,55 × distance` (32 km max), moitié du
   volume au plus. Affûtage : 80 / 65 / 45 % du pic.
3. **Allures** (Daniels, en fraction de VO2max) : footing 62–70 %, seuil 88 %, VMA 97,5 %,
   marathon = allure du marathon prédit ; allure objectif = temps visé / distance. Sans
   historique, VDOT requis − 3.
4. **Séances de qualité** : 1 (2-3 sorties/sem.) ou 2 (4 et plus), progressives et plafonnées en
   part du volume (seuil 15 %, VMA 12 %, allure objectif 25 %, 30 % au marathon). Si la semaine
   déborde, la 2ᵉ séance de qualité (hors club et allure objectif) devient un footing ; la sortie
   longue ne perd pas plus de 20 %.
5. **Réglages** (sur le profil) : sorties par semaine (2 à 6, séance club comprise ; par défaut
   selon les 4 dernières semaines), jour et heure de la séance club. Le club (≈ 20 min d'endurance
   puis fractionné ou côtes) remplace la VMA, sinon la dernière séance de qualité, mais jamais le
   travail à l'allure objectif ; il réduit de 20 % la part d'intensité du reste de la semaine. En
   semaine de course, il n'est gardé (« en retenue ») que 2 jours ou plus avant l'objectif.
6. **Ajustements par semaine** (`training_week_override`) : club oui/non, nombre de sorties. Moins
   de sorties → volume réduit d'autant ; la progression générale n'est pas modifiée.
7. **Jours** : sortie longue le dimanche (samedi si le club est le dimanche), club à son jour,
   puis chaque séance de qualité et chaque footing le plus loin possible des séances déjà placées.

## Bilan prévu / réalisé

Déterministe (`src/Training/PlanReviewer.php`, `SessionEvaluator`, `WeekReviewer`), rien n'est
stocké :

1. **Prévu figé au lundi** : la semaine en cours (et la précédente) est le programme construit
   avec la forme du lundi ; les semaines suivantes sont recalculées avec la forme du jour. Les
   écarts sur la semaine prochaine (volume, sortie longue, indice) sont listés dans « Bilan et
   conseils », avec la probabilité de l'objectif comparée à celle du lundi.
2. **Rattachement** : sorties du jour de la séance, sinon d'un jour voisin (±1) une fois le jour de
   la séance passé ; le reste est « hors programme ».
3. **Statut** : manquée (jour passé sans sortie), incomplète (< 80 % de la distance), réussie,
   largement réussie (> 120 % à la bonne intensité : positif). Endurance trop intense si la FC
   dépasse 80 % de la réserve cardiaque (Karvonen, si FC max connue), sinon si l'allure est plus
   rapide que le footing le plus rapide − 15 s/km (allure ignorée en trail). Seuil, VMA et allure
   objectif « faites en footing » sans aucun tour (à défaut km) à l'allure marathon + 5 s/km.
4. **Semaine** : au-delà de 130 % du volume prévu, conseil de garder la semaine suivante calme.

## Organisation du code

| Répertoire | Rôle |
|---|---|
| `src/Entity/`, `src/Repository/`, `migrations/` | Activités, profil, courses officielles (Doctrine), schéma SQLite |
| `src/Doctrine/UtcDateTimeImmutableType.php` | Dates de départ stockées en UTC (SQLite ne garde pas le fuseau) |
| `src/Settings/` | Réglages saisis dans l'app (`settings.json`) exposés comme variables d'environnement |
| `src/Import/` | Parseurs CSV Strava, GPX, TCX et import idempotent |
| `src/Prediction/` | VDOT, forme, prédiction d'objectif |
| `src/Training/` | Programme d'entraînement (phases, volumes, allures, séances) et bilan prévu / réalisé |
| `src/Ai/` | Coach IA (sortie structurée `CoachAnalysis`) |
| `src/Controller/`, `src/Form/`, `templates/`, `assets/` | Dashboard web |
| `src/Chart/ColumnChart.php` | Géométrie de l'histogramme SVG (rendu sans JS) |
| `src/Tui/MyRunsApp.php` | Interface terminal |
| `desktop/` | Application de bureau Windows (Tauri) |

## Qualité

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse                         # niveau max, sur l'hôte
php bin/console lint:twig templates/ && php bin/console lint:yaml config/ && php bin/console lint:container
ddev exec bin/console doctrine:schema:validate
ddev exec vendor/bin/phpunit
```

Les tests isolent chaque cas dans une transaction (DAMA). L'IA est remplacée par
`tests/Double/FakeCoachPlatform` dans l'environnement de test : aucun appel réseau.
