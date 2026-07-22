# ROADMAP — Webapp de gestion d'entraînements sportifs

Document autosuffisant. Il contient tout le nécessaire pour construire l'application de bout en bout : décisions d'architecture, modèle de données complet, structure de fichiers Symfony, et étapes détaillées par phase. Aucune information externe ne doit être nécessaire.

---

## 0. Vision du projet

Webapp de **planification** d'entraînements (pas de suivi détaillé : Strava couvre déjà le tracking). L'objectif est l'amont : concevoir une bibliothèque d'exercices, composer des séances, bâtir des plans multi-semaines réutilisables, les poser sur un calendrier, et boucler sur le prévu vs réalisé.

Activités à couvrir dès la conception : muscu/salle, course/trail, vélo, natation, renforcement, mobilité/échauffement.

Priorité assumée : **construire proprement**, pas vite. Les fondations passent avant la vélocité de mise en ligne.

---

## 1. Décisions d'architecture (VERROUILLÉES)

Ces choix conditionnent tout le reste. Ils ne se rediscutent pas en cours de route.

### 1.1 Stack
- **Symfony full-stack** (dernière version stable). Rendu serveur.
- **Twig** pour les vues, **Stimulus** pour l'interactivité, **Turbo** pour la navigation fluide et les mises à jour partielles.
- **Pas de SPA.** Pas de framework front lourd.
- **AssetMapper** pour la gestion des assets (défaut Symfony moderne, non déprécié). **Pas Webpack Encore.**
  - Conséquence importante : AssetMapper ne bundle pas (importmap + HTTP/2, fichiers servis en direct). Le **service worker PWA sera écrit manuellement** (pas de Workbox intégré). C'est assumé et suffisant pour le niveau offline visé (consultation en lecture seule).

### 1.2 Infrastructure
- **MariaDB 10.4** comme SGBD (fourni par le mutualisé Infomaniak). Utiliser la même version en dev local pour éviter tout écart prod/dev.
  - **Piège version** : 10.4 est relativement ancien. Le type `json` de Doctrine y est émulé via `LONGTEXT` + contrainte `CHECK (json_valid(...))`. Ça fonctionne, mais tester explicitement les colonnes JSON (`User.roles`, `Exercise.targetAreas`). En cas de friction, se rabattre sur du texte sérialisé ou une table liée pour `targetAreas`.
  - Fixer `server_version` dans `DATABASE_URL` à `mariadb-10.4.x` pour que Doctrine génère le bon SQL.
- **Docker** pour l'environnement de dev **uniquement** (php-fpm, MariaDB 10.4). La prod mutualisée ne fait pas tourner Docker : le dev reste proche de la prod, mais le déploiement dépose du code, pas des conteneurs.
- **Déploiement** : hébergement **mutualisé Infomaniak**, domaine `kadens.antoninpamart.fr` (schéma déjà éprouvé sur les autres projets ; pas de VPS, choix de coût assumé).
  - Conséquences à garder en tête : pas de contrôle root, pas de conteneurs en prod, pas de services persistants custom, exécution PHP en contexte mutualisé.
  - Déploiement par le pipeline habituel (Git + build/dump assets + upload), tâches planifiées via le cron d'Infomaniak si besoin.
  - **AssetMapper est un bon fit sur mutualisé** : pas de bundling lourd, `asset-map:compile` dumpe les assets servis statiquement.
  - Vérifier la version PHP disponible sur l'hébergement et aligner `composer.json` / la version de Symfony dessus **avant de démarrer**.

### 1.3 Règles de modélisation (le cœur, à ne jamais violer)

- **Exercise = définition réutilisable, SANS paramètres.** La bibliothèque décrit *ce que c'est* (nom, description, zones travaillées, média), jamais *combien* (séries, reps, charge, distance).
- **Bibliothèque globale vs perso via `owner`.** Un `Exercise` **sans owner (null)** est la bibliothèque globale de l'app : visible par tous en lecture, éditable/supprimable uniquement par un **`ROLE_ADMIN`** (sinon alimentée par l'import console). Un `Exercise` **avec owner** est perso : visible/éditable par son seul propriétaire. La liste d'un utilisateur = ses exos perso + le global. *(Cette règle "global = éditable par l'admin" vaudra aussi pour les futures ressources de bibliothèque : Workout, PlanTemplate.)*
- **Les paramètres vivent sur le lien séance↔exercice**, porté par l'entité `PrescribedExercise`. C'est ce qui permet de réutiliser le même exercice dans plusieurs séances avec des paramètres différents (même squat, charge différente).
- **Modèle d'exercice unique et flexible.** Pas d'héritage par activité. Un enum `PrescriptionType` décrit le format d'effort ; les champs de valeurs sont nullable et seul le sous-ensemble pertinent est rempli. Cela absorbe muscu, isométrie, AMRAP, "30 burpees en 1 min", course distance/allure, vélo durée/zone, natation, etc. sans jamais créer de nouvelle classe.
- **Blocs avec `rounds` et `role`.** Une séance est une liste ordonnée de blocs. Un bloc contient des exercices prescrits ordonnés et a un nombre de tours (`rounds`) et un rôle (`WARMUP`/`MAIN`/`COOLDOWN`). Une seule mécanique couvre séance plate (blocs à 1 exercice, rounds=1), superset (1 bloc, 2 exos, rounds=N), circuit (1 bloc, N exos, rounds=N) et échauffement (bloc de rôle WARMUP).
- **Unités normalisées en base.** Charges en **kg**, distances en **mètres**, durées en **secondes**. Jamais de texte mixte type "5km" ou "45s". Toujours numérique + unité implicite figée. Cette discipline rend l'export Excel (Phase 8) trivial ; la violer transforme l'export en enfer de parsing.
- **Sérialisation découplée du rendu.** Dès qu'on affiche un plan/une séance, on passe par un service qui produit une structure "plate" traversable. Le rendu Twig ET le futur export Excel consomment ce même service. Ne jamais dupliquer la logique de mise à plat.
- **Pages de consultation auto-suffisantes.** Les pages de lecture d'une séance/d'un plan ne chargent aucun contenu en AJAX après le rendu initial. Tout est dans la réponse HTML. C'est la condition pour que le cache offline (Phase 9) n'ait pas de trous.

### 1.4 Séparation planning : template vs instance datée
- `PlanTemplate` = modèle abstrait multi-semaines, **sans dates** ("plan 5k 8 semaines"). Réutilisable, dupliquable.
- `ScheduledWorkout` = instance **datée** posée sur le calendrier, née d'un template ou d'une séance isolée.
- Instancier un plan = boucler sur le template et créer N `ScheduledWorkout`. Le template reste intact.

### 1.5 Prévu vs réalisé (pas de tracking)
- `ScheduledWorkout` porte un `status` (prévu/fait/manqué) et un champ de notes/écart léger.
- **Aucun log détaillé de séries réalisées.** Strava fait le suivi. Ici on boucle sur la prévision : a-t-on tenu le plan ?

### 1.6 IA hors application
- Aucune IA intégrée à l'app. Aucune dépendance API en prod, aucun coût token.
- Le remplissage de la bibliothèque se fait via des sessions de chat externes qui génèrent du JSON d'exercices, ingéré par une commande console d'import (Phase 3).

---

## 2. Modèle de données complet

### 2.1 Vue d'ensemble des relations

```
User (1) ──< (N) Exercise            bibliothèque, sans params
User (1) ──< (N) Workout             séance, slug public pour partage lecture
User (1) ──< (N) PlanTemplate        plan multi-semaines, sans dates
User (1) ──< (N) ScheduledWorkout    séance datée sur calendrier

Workout (1) ──< (N) Block            blocs ordonnés
Block (1)   ──< (N) PrescribedExercise   exercices prescrits ordonnés
PrescribedExercise (N) >── (1) Exercise  référence vers la bibliothèque

PlanTemplate (1) ──< (N) PlanItem    trame semaine/jour référençant un Workout
PlanItem (N) >── (1) Workout

ScheduledWorkout (N) >── (1) Workout  la séance planifiée (référence vivante)
ScheduledWorkout (N) >── (0..1) PlanTemplate  d'où vient l'instanciation (nullable)
```

### 2.2 Enums

**`PrescriptionType`** — format d'effort d'un exercice prescrit :
- `SETS_REPS` — séries × répétitions (± charge). Ex : 4×8 @ 60kg. Champs : `sets`, `reps`, `weightKg`.
- `SETS_TIME` — séries × durée. Ex : 3× 45s gainage. Champs : `sets`, `durationSeconds`, éventuellement `weightKg`.
- `AMRAP` — as many reps/rounds as possible dans un temps donné. Champs : `durationSeconds`, `targetReps` (optionnel).
- `FOR_TIME` — un volume fixe à faire le plus vite possible. Ex : 30 burpees for time. Champs : `targetReps`, `capSeconds` (limite optionnelle).
- `DISTANCE_PACE` — distance à une allure cible. Ex : 5km @ 5:00/km. Champs : `distanceMeters`, `paceSecondsPerKm`.
- `DURATION` — effort continu par durée (± zone). Ex : 40min Z2. Champs : `durationSeconds`, `intensityZone` (optionnel, string ou petit enum).

**`BlockRole`** — rôle d'un bloc dans la séance :
- `WARMUP` — échauffement.
- `MAIN` — corps de séance.
- `COOLDOWN` — retour au calme.

**`ScheduledStatus`** — état d'une séance planifiée :
- `PLANNED` — prévue, pas encore faite.
- `DONE` — réalisée.
- `MISSED` — manquée.

### 2.3 Détail des entités

> Convention : `id` = entier auto-incrémenté (ou UUID si préférence portfolio). Toutes les dates en UTC. Toutes les entités liées à un utilisateur ont un `owner` (ManyToOne vers `User`).

**`User`**
- `id`
- `email` (unique)
- `password` (hashé)
- `roles` (JSON, Symfony security)
- `createdAt`
- Relations : possède exercises, workouts, planTemplates, scheduledWorkouts.

**`Exercise`** (bibliothèque, sans paramètres)
- `id`
- `owner` → User
- `name` (string)
- `description` (text, nullable)
- `activity` (string ou enum léger : gym / running / cycling / swimming / mobility / other) — catégorie d'activité, utile pour filtrer.
- `targetAreas` (JSON ou table liée : muscles/zones travaillées, nullable)
- `mediaUrl` (string, nullable — lien vidéo/image de démonstration)
- `createdAt`, `updatedAt`
- **Aucun champ de séries/reps/charge/distance ici.** C'est la règle absolue.

> **Variantes = entrées distinctes (choix de modèle assumé).** La biblio est
> faite de variantes déjà spécifiées : l'équipement, la prise, la posture sont
> **dans le nom** de l'exercice ("Curl biceps poulie basse", "Front squat
> kettlebell"), pas dans un champ structuré. Conséquences décidées :
> - **Pas de champ `equipment`.** Redondant avec le nom, et il ne capturerait
>   qu'une facette des variantes. Rejeté sur `Exercise` comme sur
>   `PrescribedExercise`.
> - **Regroupement `family` différé** (petite entité `ExerciseFamily`, FK
>   nullable sur `Exercise`) pour replier les variantes d'un même mouvement :
>   non bloquant, ajoutable plus tard sans rien casser. Pour l'usage actuel, le
>   filtrage `activity` + `targetAreas` suffit.
> - La feature "proposer des alternatives en séance" se dérivera des
>   `targetAreas`, **pas** d'un lien variant.
> - La latéralité (unilatéral/bilatéral) n'est **pas** une variante : soit
>   exercices distincts, soit un futur booléen si ça pèse sur la prescription.

**`Workout`** (séance)
- `id`
- `owner` → User
- `title` (string)
- `description` (text, nullable)
- `slug` (string, unique — pour le partage lecture publique, généré à la création)
- `estimatedDurationMinutes` (int, nullable — calculable ou saisi)
- `createdAt`, `updatedAt`
- Relations : `blocks` (OneToMany, ordonnés par `position`).

**`Block`** (bloc d'une séance)
- `id`
- `workout` → Workout
- `role` (enum `BlockRole`)
- `rounds` (int, défaut 1 — nombre de tours du bloc)
- `position` (int — ordre dans la séance)
- `label` (string, nullable — ex : "Superset A")
- Relations : `prescribedExercises` (OneToMany, ordonnés par `position`).

**`PrescribedExercise`** (exercice prescrit dans un bloc — porte les paramètres)
- `id`
- `block` → Block
- `exercise` → Exercise (référence vers la bibliothèque)
- `position` (int — ordre dans le bloc)
- `prescriptionType` (enum `PrescriptionType`)
- Champs de valeurs (tous nullable, seul le sous-ensemble pertinent est rempli selon le type) :
  - `sets` (int)
  - `reps` (int)
  - `weightKg` (float)
  - `durationSeconds` (int)
  - `distanceMeters` (int)
  - `paceSecondsPerKm` (int)
  - `targetReps` (int)
  - `capSeconds` (int)
  - `intensityZone` (string)
- `restSeconds` (int, nullable — repos après l'exercice)
- `notes` (text, nullable)

**`PlanTemplate`** (plan multi-semaines abstrait, sans dates)
- `id`
- `owner` → User
- `title` (string)
- `description` (text, nullable)
- `durationWeeks` (int — nombre de semaines de la trame)
- `slug` (string, unique — partage lecture, optionnel)
- `createdAt`, `updatedAt`
- Relations : `items` (OneToMany vers `PlanItem`).

**`PlanItem`** (case de la trame d'un plan)
- `id`
- `planTemplate` → PlanTemplate
- `workout` → Workout (la séance prévue à cette position)
- `weekNumber` (int — 1..durationWeeks)
- `dayOfWeek` (int — **convention figée : 1=lundi .. 7=dimanche**, alignée sur ISO-8601 / `DateTime::format('N')`)
- `notes` (text, nullable)

**`ScheduledWorkout`** (séance datée sur le calendrier)
- `id`
- `owner` → User
- `workout` → Workout (la séance planifiée)
- `sourcePlanTemplate` → PlanTemplate (nullable — d'où vient l'instanciation)
- `scheduledDate` (date)
- `status` (enum `ScheduledStatus`, défaut `PLANNED`)
- `completionNotes` (text, nullable — écart léger prévu/réalisé)
- `createdAt`, `updatedAt`

> **Décision figée : référence vivante.** `ScheduledWorkout.workout` pointe vers le `Workout` vivant ; toute modification ultérieure de la séance se répercute sur les instances planifiées. Simplicité maximale. Si un besoin d'historique fidèle (garder la séance telle qu'elle était le jour prévu) apparaît plus tard, on ajoutera un mécanisme de snapshot à ce moment-là, pas avant.

---

## 3. Structure de fichiers Symfony

Arborescence cible. Chaque type d'objet a sa place déterminée : ne jamais improviser l'emplacement d'une enum, d'un service ou d'un repository.

```
project/
├── assets/
│   ├── app.js                        point d'entrée AssetMapper
│   ├── controllers/                  contrôleurs Stimulus
│   │   ├── workout_editor_controller.js
│   │   ├── block_controller.js
│   │   └── calendar_controller.js
│   ├── styles/
│   │   └── app.css
│   ├── manifest.json                 PWA (Phase 9)
│   └── sw.js                         service worker manuel (Phase 9)
│
├── config/
│   ├── packages/                     config des bundles
│   ├── routes.yaml
│   └── services.yaml
│
├── migrations/                       migrations Doctrine (générées)
│
├── src/
│   ├── Controller/
│   │   ├── ExerciseController.php
│   │   ├── WorkoutController.php
│   │   ├── PlanTemplateController.php
│   │   ├── ScheduledWorkoutController.php
│   │   ├── CalendarController.php
│   │   ├── PublicShareController.php   pages slug lecture seule
│   │   └── ExportController.php        export Excel (Phase 8)
│   │
│   ├── Entity/
│   │   ├── User.php
│   │   ├── Exercise.php
│   │   ├── Workout.php
│   │   ├── Block.php
│   │   ├── PrescribedExercise.php
│   │   ├── PlanTemplate.php
│   │   ├── PlanItem.php
│   │   └── ScheduledWorkout.php
│   │
│   ├── Enum/                          ← TOUTES les enums PHP ici
│   │   ├── PrescriptionType.php
│   │   ├── ActivityType.php
│   │   ├── TargetArea.php
│   │   ├── BlockRole.php
│   │   └── ScheduledStatus.php
│   │
│   ├── Repository/                    ← un repository par entité
│   │   ├── ExerciseRepository.php
│   │   ├── WorkoutRepository.php
│   │   ├── PlanTemplateRepository.php
│   │   └── ScheduledWorkoutRepository.php
│   │
│   ├── Service/                       ← logique métier réutilisable
│   │   ├── PlanFlattener.php          sérialisation "plate" d'un plan/séance (rendu + export)
│   │   ├── PlanInstantiator.php       transforme un PlanTemplate en N ScheduledWorkout
│   │   ├── SlugGenerator.php          génération de slugs uniques
│   │   └── ExcelExporter.php          export xlsx via PhpSpreadsheet (Phase 8)
│   │
│   ├── Form/                          ← types de formulaires Symfony
│   │   ├── ExerciseType.php
│   │   ├── WorkoutType.php
│   │   ├── BlockType.php
│   │   ├── PrescribedExerciseType.php
│   │   └── PlanTemplateType.php
│   │
│   ├── Command/                       ← commandes console
│   │   └── ImportExercisesCommand.php   ingestion du JSON d'exercices (Phase 3)
│   │
│   └── Security/
│       └── (voter éventuel pour l'accès propriétaire vs lecture publique)
│
├── templates/
│   ├── base.html.twig
│   ├── exercise/
│   ├── workout/
│   ├── plan_template/
│   ├── calendar/
│   ├── public_share/                 vues lecture seule (slug)
│   └── components/                    fragments Twig réutilisables
│
├── tests/
│   ├── Entity/
│   ├── Service/
│   └── Controller/
│
├── compose.yaml                      Docker
├── importmap.php                     AssetMapper
└── .github/workflows/                CI/CD
```

**Règles de rangement à retenir :**
- Une **enum** → toujours dans `src/Enum/`, en PHP backed enum (`enum X: string`).
- Un **service métier** (mise à plat, instanciation, export) → `src/Service/`, injecté par autowiring.
- Un **repository** → `src/Repository/`, une classe par entité, pour les requêtes custom.
- Un **contrôleur Stimulus** → `assets/controllers/`, suffixe `_controller.js`.
- Un **form type** → `src/Form/`.
- Une **commande console** → `src/Command/`.

---

## 4. Phases de développement

Chaque phase se termine par un jalon vérifiable. On ne passe à la suivante qu'une fois le jalon atteint et le code propre.

### Phase 1 — Fondation & bibliothèque d'exercices

**But :** environnement prêt, gestion complète de la bibliothèque.

Étapes :
1. Initialiser le projet Symfony full-stack. Installer Twig, Stimulus, Turbo, AssetMapper.
2. Mettre en place Docker (`compose.yaml`) : service PHP, service PostgreSQL. Vérifier que l'app tourne.
3. Configurer la connexion Doctrine à PostgreSQL.
4. Créer l'entité `User` avec la sécurité Symfony (authentification email/mot de passe, `make:user`, `make:auth` ou form login manuel).
5. Créer l'enum `PrescriptionType` dans `src/Enum/` (poser tous les cas dès maintenant, même si non utilisés avant Phase 2).
6. Créer l'entité `Exercise` (sans aucun champ de paramètre). Générer la migration, migrer.
7. Créer `ExerciseRepository` avec au moins une méthode de filtrage par `activity`.
8. Créer `ExerciseType` (form) et `ExerciseController` : CRUD complet (liste, création, édition, suppression) avec templates Twig.
9. Mettre en place un voter ou un simple contrôle `owner === user` pour que chacun ne voie/édite que ses exercices.
10. Configurer la CI/CD de base (lint, tests vides qui passent) et un premier déploiement sur le VPS.

**Jalon :** je peux gérer entièrement ma base d'exercices en ligne.

---

### Phase 2 — Séances

**But :** composer une séance complète multi-activités.

Étapes :
1. Créer les enums restantes si pas déjà faites : `BlockRole`.
2. Créer les entités `Workout`, `Block`, `PrescribedExercise` avec leurs relations ordonnées (`position`). Migrer.
3. Créer `SlugGenerator` (service) et l'utiliser à la création d'un `Workout` pour remplir `slug`.
4. Créer `WorkoutController` : CRUD des séances.
5. Construire l'**éditeur de séance** (le morceau le plus riche en interactivité) :
   - Ajouter/supprimer/réordonner des blocs (Stimulus + Turbo Streams).
   - Définir `role` et `rounds` par bloc.
   - Ajouter des `PrescribedExercise` à un bloc en choisissant un `Exercise` de la bibliothèque.
   - Afficher dynamiquement les bons champs selon le `prescriptionType` choisi (un contrôleur Stimulus qui montre/masque les champs pertinents).
   - Réordonner les exercices dans un bloc.
6. Gérer l'échauffement simplement : un bloc de rôle `WARMUP`. Aucune entité dédiée.
7. Vue de consultation d'une séance : **auto-suffisante, sans AJAX post-chargement** (préparer le terrain pour l'offline).
8. Créer `PlanFlattener` (service) qui produit la structure plate d'une séance. La vue de consultation le consomme. **Ne pas coder la mise à plat directement dans le contrôleur.**

**Jalon :** je peux construire une séance complète mêlant muscu, cardio, circuits et échauffement.

---

### Phase 3 — Import de la bibliothèque via IA (hors app)

**But :** remplir la bibliothèque en masse sans saisie manuelle.

Étapes :
1. Définir un **format JSON d'échange** stable pour un exercice (name, description, activity, targetAreas, mediaUrl). Documenter ce format ici même une fois figé.
2. Créer `ImportExercisesCommand` dans `src/Command/` : lit un fichier JSON, valide, crée les `Exercise` pour un `owner` donné, ignore/skip les doublons par nom.
3. Générer le JSON via des sessions de chat externes, l'importer avec la commande.

**Format JSON figé** (fichier de référence : `data/exercises.json`) :
```json
[
  {
    "name": "Squat",
    "description": "...",
    "activity": "gym",
    "targetAreas": ["legs", "core"],
    "mediaUrl": null
  }
]
```
- `activity` : valeur de l'enum `ActivityType` (`gym`, `running`, `swimming`,
  `cycling`, `mobility`, `other`).
- `targetAreas` : valeurs de l'enum `TargetArea`, granularité muscle par muscle —
  `chest`, `back`, `lower_back`, `traps`, `shoulders`, `biceps`, `triceps`,
  `forearms`, `abs`, `obliques`, `glutes`, `quadriceps`, `hamstrings`,
  `adductors`, `calves`, `full_body`. Nullable.

**Commande :** `php bin/console app:import-exercises [fichier] [--owner=email]`.
Sans argument, lit `data/exercises.json`. Sans `--owner`, crée une biblio
globale (`owner` null). Ignore les doublons par `name`, idempotente.

**Jalon :** je remplis ma biblio en masse via import, aucune IA dans l'app.

---

### Phase 4 — Partage lecture publique

**But :** partager une séance par lien slug.

Étapes :
1. Créer `PublicShareController` : route publique `/s/{slug}` servant une vue **lecture seule** de la séance.
2. Templates dans `templates/public_share/` : réutiliser `PlanFlattener` pour l'affichage, sans aucune action d'édition.
3. Contrôle d'accès : la page publique ne nécessite pas d'authentification, mais n'expose que la lecture. L'édition reste réservée au propriétaire (voter).
4. Ajouter le bouton "copier le lien de partage" dans l'interface propriétaire.

**Jalon :** je partage une séance par lien public en lecture seule.

---

### Phase 5 — Templates de plans multi-semaines

**But :** concevoir un plan type abstrait, sans dates.

Étapes :
1. Créer les entités `PlanTemplate` et `PlanItem`. Migrer.
2. Créer `PlanTemplateController` : CRUD des plans.
3. Construire l'**éditeur de plan** : une trame de `durationWeeks` semaines × 7 jours, où on place des `Workout` existants dans des cases (`weekNumber`, `dayOfWeek`).
4. Permettre la duplication d'un `PlanTemplate` (utile pour itérer sur un plan sans repartir de zéro).
5. Étendre `PlanFlattener` pour aplatir aussi un plan complet (toutes ses séances), en vue de l'affichage et du futur export.

**Jalon :** je conçois un plan type "5k 8 semaines" abstrait et réutilisable.

---

### Phase 6 — Calendrier & instanciation

**But :** transformer un plan abstrait en planning daté.

Étapes :
1. Créer l'entité `ScheduledWorkout` et l'enum `ScheduledStatus`. Relation `workout` en **référence vivante** (décision déjà figée, cf. §2.3). Migrer.
2. Créer `PlanInstantiator` (service) : prend un `PlanTemplate` et une date de départ, génère N `ScheduledWorkout` en mappant `weekNumber`/`dayOfWeek` sur des dates réelles.
3. Créer `CalendarController` et une vue calendrier (Stimulus pour la navigation mois/semaine).
4. Permettre aussi de poser une séance isolée sur une date (sans passer par un plan).
5. Gérer le déplacement d'une séance planifiée (changer `scheduledDate`).

**Jalon :** mon plan devient un planning daté et navigable.

---

### Phase 7 — Prévu vs réalisé

**But :** boucler sur la prévision, sans tracking détaillé.

Étapes :
1. Ajouter l'usage de `status` sur `ScheduledWorkout` : cocher fait / manqué depuis le calendrier ou la vue jour.
2. Ajouter la saisie de `completionNotes` (écart léger, ressenti court).
3. Vue de synthèse : sur une période ou un plan instancié, proportion de séances tenues vs manquées.
4. **Ne pas** ajouter de log de séries réalisées. La frontière est nette : c'est de la prévision, pas du suivi.

**Jalon :** je vois ce que j'ai tenu de mon plan.

---

### Phase 8 — Export Excel

**But :** sortir un plan ou un planning en .xlsx.

Étapes :
1. Installer PhpSpreadsheet.
2. Créer `ExcelExporter` (service) qui consomme la sortie de `PlanFlattener` (déjà découplée depuis la Phase 2). **Ne pas réimplémenter la mise à plat.**
3. Créer `ExportController` : exporter un `Workout`, un `PlanTemplate`, ou un planning daté sur une période, qu'il soit fini ou en cours.
4. Vérifier que grâce aux unités normalisées (kg/m/s), l'export est un simple mapping sans parsing. Formater lisiblement (ex : convertir secondes en mm:ss à l'affichage, mais les données sources restent numériques).

**Jalon :** je sors n'importe quel plan/planning en Excel.

---

### Phase 9 — PWA

**But :** app installable, plans consultables hors ligne (lecture).

Étapes :
1. Créer `manifest.json` (nom, icônes multi-tailles, `display: standalone`, couleurs).
2. Le lier dans `base.html.twig`.
3. Écrire `sw.js` **manuellement** (pas de Workbox) :
   - Stratégie **cache-first** sur les routes de consultation de séances et de plans.
   - Précache du shell applicatif (CSS, JS, base).
   - Cache dynamique des pages de consultation déjà visitées.
4. Enregistrer le service worker depuis `app.js`.
5. Vérifier que les pages de consultation sont bien **auto-suffisantes** (discipline tenue depuis Phase 2) : sinon le cache aura des trous.
6. Tester l'installabilité et la consultation offline en lecture.

**Jalon :** app installable, mes séances et plans consultables sans réseau.

---

## 5. Points de vigilance transversaux (à garder en tête en permanence)

- **Unités normalisées (kg / mètres / secondes) dès la Phase 1.** Toute dérive vers du texte libre casse l'export Phase 8.
- **`PlanFlattener` est la source unique de mise à plat.** Créé en Phase 2, réutilisé Phases 4, 5, 8. Ne jamais dupliquer cette logique dans un contrôleur.
- **Pages de consultation sans AJAX post-chargement.** Discipline tenue de la Phase 2 à la Phase 9, condition du cache offline.
- **AssetMapper = service worker artisanal.** Prévu, non bloquant, ~100 lignes en Phase 9. Ne pas chercher un bundle magique.
- **Ordre séance → template → calendrier volontaire.** On ne planifie pas ce qu'on ne sait pas encore composer. Ne pas inverser.
- **Params jamais sur l'Exercise.** Toujours sur `PrescribedExercise`. C'est la règle qui garde la bibliothèque réutilisable.
- **Une enum va dans `src/Enum/`, un service dans `src/Service/`, un repository dans `src/Repository/`.** Ne jamais improviser l'emplacement.
- **Chaque phase se termine propre.** Pas de dette reportée d'une phase à l'autre : le projet est explicitement "proprement plutôt que vite".

---

## 6. Ordre de construction résumé (checklist rapide)

1. [x] Setup Symfony + AssetMapper + Docker + PostgreSQL + CI/CD
2. [x] User + sécurité
3. [x] Enum PrescriptionType
4. [x] Exercise + CRUD + import filtrage
5. [x] Enum BlockRole + Workout/Block/PrescribedExercise
6. [x] SlugGenerator + éditeur de séance
7. [x] PlanFlattener + vue consultation auto-suffisante
8. [x] Format JSON + ImportExercisesCommand
9. [x] PublicShareController + vues slug lecture seule
10. [x] PlanTemplate/PlanItem + éditeur de plan + duplication
11. [x] Enum ScheduledStatus + ScheduledWorkout + PlanInstantiator + calendrier
12. [ ] Statut prévu/réalisé + synthèse
13. [ ] PhpSpreadsheet + ExcelExporter + ExportController
14. [ ] manifest.json + sw.js manuel + installabilité + offline lecture
