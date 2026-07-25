# Feature — Vue progression / évolution

> Spécification autosuffisante à implémenter dans une session dédiée. À lire avec
> `CLAUDE.md` (§3 règles verrouillées) et `ROADMAP.md` (§1). Ne rien décider ici
> qui contredise ces deux fichiers sans le signaler explicitement.

---

## 0. Résumé et recommandation

**Le manque** : l'app est un excellent *éditeur* de plans mais un mauvais *miroir*.
Un plan fait monter la charge / descendre l'allure semaine après semaine, mais rien
ne visualise cette trajectoire. Le profil stocke squat/bench/5K comme des valeurs
figées, sans historique.

**La tension** (à garder en tête en permanence) : la règle verrouillée est **« pas
de tracking détaillé, Strava le fait »** (`CLAUDE.md §1`, `ROADMAP.md §1.5`). Toute
la feature doit vivre entre « logguer chaque série » (interdit) et « ne rien voir
évoluer » (le problème actuel).

**Reco** : livrer la feature **en deux temps nettement séparés**.

1. **Lot A — progression PRÉVUE (recommandé en premier, ZÉRO migration, zéro
   entorse à la règle).** Tout est déjà en base : le *fork à la pose* fait que
   chaque case d'un plan porte sa propre copie de séance, donc ses propres
   `PrescribedExercise` (charges/allures). On lit cette rampe existante et on la
   trace. C'est de la pure lecture agrégée. **C'est le vrai cœur de la valeur et
   ça ne demande aucune décision d'archi.**
2. **Lot B — progression RÉALISÉE (optionnel, DÉCISION REQUISE).** Comparer le
   prévu au *réalisé chiffré* suppose de capter une valeur de réalisé. Ça touche la
   règle « pas de tracking ». Plusieurs options, **à trancher avec l'utilisateur
   avant de coder** (§3). Ne pas démarrer le lot B sans arbitrage explicite.

Faire le lot A d'abord. Il est safe, utile seul, et sert de socle visuel au lot B.

---

## 1. Ce qui existe déjà et qu'on RÉUTILISE (ne rien réimplémenter)

- **`PlanFlattener`** — source unique de mise à plat (séance / plan / planning).
  Fournit déjà, par exercice prescrit, un `summary` lisible + les valeurs
  normalisées (kg, mètres, secondes). **Toute lecture de contenu passe par lui.**
- **`WorkoutMetrics`** — `volume()` (tonnage, séries par `targetArea`, distance /
  durée par activité), `distinctActivities()`, `exerciseCount()`.
- **`PlanVolumeAggregator::byWeek`** — **agrège déjà le volume par semaine et par
  activité pour un `PlanTemplate`** (salle = séries par groupe musculaire × tours +
  tonnage ; course/vélo/natation = distance/durée). C'est 80 % du lot A pour la vue
  « volume par semaine ». La feature l'étend/le consomme, ne le duplique pas.
- **`UnitFormatter`** — km / mm:ss / allure (min/km, km/h, min/100m) / durée. Source
  unique de formatage. `PaceUnit` / `DistanceUnit` portent la conversion par activité.
- **`HeartRateZones`**, `IntensityZone` — si on trace l'intensité cardio.
- **Unités normalisées en base** (kg / m / s) : les séries temporelles sont donc des
  nombres propres, pas du texte à parser.

---

## 2. Lot A — Progression PRÉVUE (MVP, zéro migration)

### 2.1 Ce qu'on montre

Trois angles, du plus simple au plus riche :

1. **Volume par semaine d'un plan** (quasi gratuit via `PlanVolumeAggregator::byWeek`) :
   courbe/barres du tonnage et des distances semaine par semaine. Montre la
   périodisation (montée en charge, semaine de décharge). À poser sur
   `plan_template/show`.
2. **Trajectoire d'un exercice donné à travers les semaines d'un plan** : pour un
   `Exercise` (ex. « Squat barre »), lire dans chaque semaine du plan la valeur
   prescrite (charge pour `SETS_REPS`, allure pour `DISTANCE_PACE`…) et tracer la
   rampe. C'est la vue « progression » au sens strict de l'athlète. Nécessite de
   retrouver, à travers les copies locales des cases, les `PrescribedExercise`
   pointant le même `Exercise`.
3. **Vue transversale calendrier** (optionnel, plus tard) : même trajectoire mais
   sur l'axe temps réel (dates), tous plans confondus, pour un exercice. Utile quand
   on enchaîne les plans.

### 2.2 Service à créer : `ProgressionAggregator`

`src/Service/ProgressionAggregator.php` (autowiring). Responsabilités :

- `plannedByWeekForExercise(PlanTemplate $plan, Exercise $exercise): array` — pour
  chaque semaine (1..durationWeeks), la ou les valeurs prescrites de cet exercice
  (charge max, tonnage, allure, distance selon `PrescriptionType`). Retourne une
  structure plate `{week, weight?, tonnage?, sets?, reps?, distanceMeters?, paceSecondsPerKm?, ...}`
  déjà prête à tracer (unités normalisées + labels via `UnitFormatter`).
- `exercisesInPlan(PlanTemplate $plan): list<Exercise>` — les exercices distincts
  présents dans le plan, pour peupler le sélecteur de la vue.
- **Consomme `PlanFlattener` / `WorkoutMetrics`**, ne relit pas les entités à la main.

Contrainte anti-N+1 : précharger le contenu du plan en fetch-join (cf.
`WorkoutRepository::findLibraryForOwnerWithContent` pour le pattern). Un plan a peu
de semaines, mais chaque case = une séance avec blocs/exercices.

### 2.3 Rendu (pas de lib de charts lourde — AssetMapper, pas de bundling)

- **Pas de dépendance JS externe** (offline-safe, cohérent AssetMapper). Options :
  - **SVG server-side** : générer les barres/lignes en Twig (comme les barres
    `.kd-actbar` / `.kd-obar` déjà en place au profil). Suffisant pour barres de
    volume et petites sparklines. **Recommandé pour le MVP.**
  - Si courbes multi-points nécessaires, un mini-contrôleur Stimulus dessinant un
    `<svg>` (aucune lib), données passées en `data-*`. Reste auto-suffisant.
- Réutiliser les tokens et le vocabulaire visuel existant (`.kd-obar`, `.kd-actbar`,
  couleurs d'activité run/gym, neutre ailleurs). **Jamais de couleur en dur.**

### 2.4 Où l'exposer

- `plan_template/show` : bloc « Progression prévue » (volume/semaine + sélecteur
  d'exercice → trajectoire).
- Éventuellement `exercise/show` : mini-aperçu « où cet exo apparaît et comment il
  monte » à travers les plans.
- Nav : **ne pas** ajouter d'onglet dédié pour le lot A seul ; le brancher sur les
  pages existantes. Un onglet « Progression » ne se justifie qu'avec le lot B.

### 2.5 Ce que le lot A NE fait PAS

- Aucune donnée de réalisé. On trace ce qui est **planifié**. Le titre doit le dire
  (« Progression prévue ») pour ne pas induire en erreur.

---

## 3. Lot B — Progression RÉALISÉE (DÉCISION REQUISE avant de coder)

Comparer prévu vs réalisé chiffré suppose de stocker un réalisé. C'est l'entorse
potentielle à la règle « pas de tracking ». **Poser la question à l'utilisateur** et
choisir UNE option. Ne pas cumuler.

### Option 1 — Rien de neuf, on reste au binaire (défend la règle à la lettre)
On ne trace jamais de réalisé chiffré. La « progression » reste 100 % prévue (lot A)
+ l'observance déjà existante (`done/(done+missed)`). **Avantage** : zéro entorse,
zéro migration. **Inconvénient** : ne répond pas à « ai-je vraiment progressé ».

### Option 2 — Réalisé « léger » au niveau de la séance datée (compromis recommandé si lot B)
Ajouter sur `ScheduledWorkout` un petit jeu de champs de résultat **agrégés**, pas
série par série : ex. `actualLoadNote` structuré, ou mieux, une poignée de valeurs
optionnelles réutilisant les unités normalisées (ex. « charge top set réalisée » sur
l'exercice clé). **Reste « léger »** = une valeur, pas un journal.
- **Migration** : colonnes nullable sur `ScheduledWorkout` (ou une petite table
  `WorkoutResult` liée 1-1, à trancher).
- **Philosophie** : c'est la zone grise. À valider explicitement comme « écart léger
  chiffré », dans l'esprit du `completionNotes` déjà accepté, pas comme du tracking
  Strava.

### Option 3 — Snapshots datés de records au profil (orthogonal, athlète-friendly)
Une table `PerformanceLog` (ou `BodyMetric`) : `{owner, date, metric, valueKg|seconds|…}`
pour historiser squat/bench/5K/poids de corps dans le temps. Découplé des séances
(donc **pas** du tracking de séance). Alimente une courbe de PR au profil et
recalcule DOTS/IMC à la date. **Migration** : nouvelle table. **Avantage** : très
lisible, ne touche pas la boucle séance. **Inconvénient** : saisie manuelle
supplémentaire.

### Recommandation lot B
Si l'utilisateur veut du réalisé : **Option 3** (records datés au profil) d'abord —
c'est le plus utile, le moins ambigu vis-à-vis de la règle, et visuellement fort.
Réserver l'Option 2 (réalisé par séance) à un besoin explicite et l'assumer comme
extension de `completionNotes`.

---

## 4. Fichiers touchés (prévision, lot A)

- **Créer** : `src/Service/ProgressionAggregator.php` ; template(s)
  `templates/components/_progression.html.twig` (bloc réutilisable) ; contrôleur
  Stimulus `progression_controller.js` **seulement si** courbes SVG interactives.
- **Étendre** : `PlanTemplateController::show` (passer l'agrégat) ;
  `plan_template/show.html.twig` (bloc). Éventuellement `ExerciseController::show`.
- **Réutiliser sans toucher** : `PlanFlattener`, `WorkoutMetrics`,
  `PlanVolumeAggregator`, `UnitFormatter`.
- **CSS** : couche `.kd-prog*` dans `components.css`, tokenisée.
- **Tests** : `ProgressionAggregatorTest` (rampe de charge sur un plan multi-semaines
  avec valeurs distinctes par semaine → série temporelle attendue).
- **Migration** : **aucune** pour le lot A.

---

## 5. Conventions à respecter (rappel)

- Rangement : service → `src/Service/`, enum → `src/Enum/`, form → `src/Form/`,
  Stimulus → `assets/controllers/*_controller.js`, fragment → `templates/components/`.
- **Pages de consultation auto-suffisantes**, zéro AJAX post-chargement (données
  injectées au rendu).
- **Source unique de mise à plat = `PlanFlattener`**. Ne jamais reparser des valeurs.
- **Unités normalisées** en base (kg/m/s), formatage via `UnitFormatter` uniquement.
- **Tokens obligatoires**, aucune couleur/police en dur ; la couleur porte du sens
  (activité run/gym, statuts) — une courbe de progression est neutre par défaut.
- Icônes Lucide importées localement (`php bin/console ux:icons:import lucide:<nom>`).

---

## 6. Décisions à faire trancher par l'utilisateur (avant lot B)

1. Fait-on le lot B, ou le lot A suffit-il ? (Le lot A seul est déjà une vraie
   valeur.)
2. Si lot B : Option 1 / 2 / 3 ? (Reco : 3.)
3. Portée de la vue prévue : par plan seulement, ou aussi transversale sur le
   calendrier (tous plans confondus dans le temps) ?
4. Métriques prioritaires à tracer : charge (tonnage / top set), volume (séries),
   allure/distance, intensité (zone/RPE) ? Ordre de priorité.
