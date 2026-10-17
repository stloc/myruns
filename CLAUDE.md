# CLAUDE.md — MyRuns

Application personnelle : import d'activités Strava (export de compte ZIP, dossiers GPX/TCX),
dashboard web, interface terminal et application de bureau Windows (Tauri), prédiction de
réussite d'un objectif chrono (VDOT + coach IA).

## Stack (vérifiée dans composer.lock)

- PHP 8.4, Symfony **8.1**, Doctrine ORM **3.7** / DBAL 4, **SQLite** (`pdo_sqlite`), dev sous **DDEV**
- Application de bureau : **Tauri 2** (`desktop/`, Rust), PHP Windows embarqué, installeur NSIS
  construit par GitHub Actions (`.github/workflows/desktop.yml`, sur tag `v*`)
- `symfony/tui` 8.1 (**expérimental**, hors promesse de rétrocompatibilité)
- `symfony/ai-bundle` + `symfony/ai-anthropic-platform` **0.14** (pré-1.0 : vérifier l'API dans `vendor/`)
- Twig + AssetMapper (pas de build JS, pas de Stimulus) ; graphiques en SVG rendus côté serveur

## Préfixe de commandes

L'hôte n'a pas `pdo_sqlite` : tout ce qui touche la base passe par **`ddev exec`**
(`ddev exec bin/console …`, `ddev exec vendor/bin/phpunit`). PHPStan, php-cs-fixer et les lints
tournent aussi sur l'hôte. `ddev start` ne demande `sudo` qu'au tout premier démarrage du projet.

## Règles du projet

- **Ne pas réintroduire l'API Strava** : payante depuis juin 2026 et sa politique interdit l'usage
  de ses données avec une IA. Sources : archive Strava (historique) + **intervals.icu** au quotidien
  (`src/Sync/`, client HTTP `intervals.client` dans `framework.yaml`, séances `source = STRAVA`
  ignorées). Tests de synchro avec `MockHttpClient` uniquement, jamais la vraie clé.
- Imports **idempotents** (`ActivityRepository::upsert()`) : même `id` d'abord ; sinon même sortie
  d'une autre source à ±1 min (`Activity::$startKey`, index non unique). **Jamais de fusion entre
  deux lignes du CSV** (bug corrigé : 3 activités perdues), une trace ne remplace jamais le CSV.
  `tests/Integration/DeduplicationTest.php` fige ces règles.
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
  stable (position = reprise). Page `/import/{id}` pilotée par `fetch` (`assets/app.js`), bouton
  « Continuer » sans JS. Services publics (IGN, Open-Meteo, Nominatim) derrière `App\Http\NetworkGuard` :
  plus d'appel après 3 échecs de suite, budget réseau en web, intervalle minimal entre appels.
- Ville : commune du 1er point GPS (`src/Geocoding/CityFinder` : `IgnReverseGeocoder`, index
  `parcel`, puis `NominatimReverseGeocoder` hors de France — 1 appel/s et User-Agent imposés par
  la charte OSM, client `nominatim.client`), cache persistant `app.place_cache` par position arrondie à 3 décimales ;
  posée par `replaceTrackData()`, jamais effacée si le service ne répond pas.
- Carte : trace simplifiée (`RouteSimplifier`, 20 m, ≤ 2 000 points) dans `ActivityRoute` (table
  à part, OneToOne porté par `Activity` avec `orphanRemoval` : SQLite n'applique pas les clés
  étrangères), posée par `replaceTrackData()`. Allure par tronçon calculée en PHP
  (`src/Chart/RouteMap.php`), dessin Leaflet chargé à la demande (`assets/route_map.js`), tuiles
  OSM = seul appel réseau du navigateur (autorisé dans la CSP Tauri `img-src`).
- Trail : type déclaré par la source ou nom contenant « trail » (`Sport::refinedByName()`), jamais
  le sous-sport FIT (mode montre utilisé aussi sur route).
- Estimation cardiaque : Karvonen (FC repos par défaut 60) ; ignorée si une course < 12 semaines.
- Entité `Activity` : propriétés `public private(set)` (lecture publique), modification via
  `replaceWith()` uniquement. Toute évolution de schéma → migration relue (`migrations/`).
- `orderBy()` avec `\SortDirection::Descending` (les chaînes 'DESC' sont dépréciées en ORM 3.7).
- Calcul de probabilité **déterministe** (`src/Prediction/`) ; l'IA ne fait qu'affiner. Idem pour le
  programme d'entraînement (`src/Training/TrainingPlanBuilder`, figé par `tests/Unit/Training/`) :
  construit depuis `AthleteProfile::lastGoal()` (dernier objectif calculé sur `/objectif`), réglages
  sur le profil (`planSessionsPerWeek`, `clubWeekday`, `clubTime`) et ajustements par semaine
  (`TrainingWeekOverride`, ligne supprimée quand elle revient au réglage général).
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
  `var/`, `.env.local`) ; PHP Windows figé par version + SHA256 dans le workflow. Pas de TUI sous
  Windows.
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
- Widgets TUI : `SelectListWidget` tronque le libellé à 30 colonnes → métriques dans `description`.
- Pas d'authentification (usage local : DDEV, ou app de bureau sur `127.0.0.1`) : à ajouter
  avant toute exposition.

## Commandes

```bash
ddev exec bin/console tui | app:import [chemin] | app:predict <distance> <temps> <date> [--ai]
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
