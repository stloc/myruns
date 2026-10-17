# MyRuns — guide de démarrage

MyRuns rassemble vos sorties de course à pied sur votre ordinateur : tableau de bord, carte et
temps de passage de chaque sortie, chances de réussir un objectif chrono et programme
d'entraînement.

Ce guide est fait pour vous si vous avez une **montre de sport** (Garmin, Coros, Suunto, Polar…)
et un **ordinateur**. Pas besoin de compte Strava. Comptez 15 minutes, une fois.

Vos données restent sur votre ordinateur : MyRuns n'a pas de compte en ligne.

---

## Comment ça marche

```
Montre  →  appli de la montre  →  intervals.icu  →  MyRuns
           (Garmin Connect…)       (gratuit)         (votre ordi)
```

Votre montre envoie déjà vos sorties à son application (Garmin Connect, Coros, Suunto…).
**intervals.icu** est un site gratuit qui les récupère automatiquement. MyRuns va ensuite les
chercher chez intervals.icu.

---

## Étape 1 — Créer un compte intervals.icu

1. Aller sur **intervals.icu** et cliquer sur **Sign up** (inscription gratuite).
2. À la fin de l'inscription, le site propose de **connecter votre montre** : choisir votre
   marque (par exemple *Garmin*), puis se connecter avec le compte de l'appli de votre montre.
   Plus tard, vous retrouverez ces connexions dans **Settings** (Paramètres), en haut de la page.
3. Faire une sortie (ou attendre la prochaine) : quelques minutes après la synchronisation de la
   montre avec son appli, elle apparaît dans intervals.icu.

> **Conseil** : le site peut s'afficher en français (menu de votre profil → langue).

## Étape 2 — Récupérer vos anciennes sorties (facultatif)

La connexion ne transmet que les **nouvelles** sorties. Pour avoir aussi votre historique dans
intervals.icu :

**Avec Garmin**
1. Sur le site Garmin, connecté à votre compte : *Compte* → *Gestion des données* →
   *Exporter vos données* → **Demander l'export**.
2. Garmin envoie un e-mail avec un lien de téléchargement. Cela peut prendre **plusieurs jours**.
3. Dans intervals.icu, *Settings* → connexion Garmin → **Importer toutes les données depuis
   Garmin** : coller le lien reçu par e-mail, cocher les activités (et, si vous voulez, poids,
   FC au repos, sommeil), valider.
4. intervals.icu vous prévient par e-mail quand c'est terminé.

**Avec une autre marque** : regarder dans *Settings* d'intervals.icu, sur la connexion de votre
montre, s'il y a une option d'import de l'historique.

> Le lien envoyé par Garmin donne accès à toutes vos données personnelles : ne le transmettez à
> personne.

## Étape 3 — Installer MyRuns

1. Télécharger l'installeur correspondant à votre ordinateur depuis le lien qu'on vous a envoyé.
2. L'ouvrir et suivre l'installation : aucun mot de passe administrateur n'est demandé.
   Votre système peut prévenir que l'application vient d'un éditeur inconnu (elle n'est pas
   signée par un éditeur payant) : confirmer que vous voulez l'ouvrir quand même.
3. Ouvrir **MyRuns** comme n'importe quelle application. La première ouverture prend quelques
   secondes.

> **Windows** : fichier `MyRuns_x.y.z_x64-setup.exe`. Si « *Windows a protégé votre
> ordinateur* » s'affiche : *Informations complémentaires* → *Exécuter quand même*.
>
> **macOS / Linux** : l'installeur n'est pas encore disponible.

## Étape 4 — Relier MyRuns à intervals.icu

1. Dans intervals.icu : *Settings*, descendre **tout en bas** jusqu'à *Developer Settings*,
   puis copier la **API Key** (une longue suite de lettres et de chiffres ; la générer si
   besoin).
2. Dans MyRuns : menu **Réglages**, coller la clé dans *Clé API intervals.icu*, puis
   **Enregistrer**.

> Cette clé permet de lire vos sorties : gardez-la pour vous. Elle reste enregistrée sur votre
> ordinateur uniquement.

## Étape 5 — Récupérer vos sorties

Dans MyRuns : menu **Import** → **Synchroniser maintenant**. Un message indique combien de
sorties ont été ajoutées.

À refaire après chaque sortie (ou quand vous voulez) : seules les nouvelles sorties sont
ajoutées, jamais en double.

> **Limite actuelle** : la toute première synchronisation ne remonte que les **30 derniers
> jours**. La reprise de tout l'historique depuis MyRuns est en préparation.

## Étape 6 — Profiter de MyRuns

| Menu | Pour quoi faire |
|---|---|
| **Tableau de bord** | Votre forme, vos kilomètres par semaine, vos temps potentiels du 5 km au marathon |
| **Activités** | Toutes vos sorties ; cliquer sur une sortie pour voir sa carte et ses temps au kilomètre |
| **Profil** | Indiquer votre FC max, votre FC de repos et vos dernières courses officielles : les prédictions deviennent plus justes |
| **Objectif** | « Quelles sont mes chances de faire 10 km en 45:00 le 15 décembre ? » |
| **Programme** | Un plan d'entraînement semaine par semaine jusqu'à votre objectif |

Tous les champs du profil sont facultatifs : MyRuns fonctionne sans, mais la **FC max**
(mesurée, pas la formule 220 − âge) améliore nettement les prédictions.

---

## Vous avez déjà un compte Strava ?

Vous pouvez y récupérer tout votre historique en une fois :

1. Sur strava.com : *Paramètres* → *Mon compte* → *Télécharger ou supprimer votre compte* →
   **Demander une archive**.
2. Strava envoie un e-mail avec un lien vers un fichier `export_XXXX.zip` : le télécharger.
3. Dans MyRuns : menu **Import** → *Envoyer un fichier* → choisir le ZIP → **Importer**.

Ensuite, suivre les étapes 1, 4 et 5 pour les nouvelles sorties. Les sorties présentes à la fois
dans l'archive Strava et dans intervals.icu ne sont comptées qu'une fois.

## En cas de souci

| Problème | Que faire |
|---|---|
| « Synchro non configurée » | La clé intervals.icu n'est pas enregistrée : refaire l'étape 4 |
| Une sortie n'arrive pas | Vérifier qu'elle est bien visible sur intervals.icu (la montre s'est-elle synchronisée avec son appli ?), puis relancer **Synchroniser maintenant** |
| Une sortie apparaît sur intervals.icu avec la mention Strava mais pas dans MyRuns | Normal : MyRuns ignore les sorties passées par Strava. Connecter directement la montre à intervals.icu (étape 1) |
| Pas de carte ni de temps au kilomètre | La sortie a été enregistrée sans GPS (tapis, salle…) |
| Autre problème | Contacter la personne qui vous a envoyé MyRuns |
