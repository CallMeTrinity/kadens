# Feature — Vue progression / évolution

> Spécification autosuffisante à implémenter dans une session dédiée. À lire avec
> `CLAUDE.md` (§3 règles verrouillées) et `ROADMAP.md` (§1). Ne rien décider ici
> qui contredise ces deux fichiers sans le signaler explicitement.

---

> **État (2026-07-29)** : **Lot A livré** (`ProgressionAggregator`, bloc
> « Progression prévue » sur `plan_template/show`, contrôleur Stimulus
> `progression`, `ProgressionAggregatorTest`). **Lot B tranché** (KL-01) : la
> décision qui bloquait §3 est prise, le réalisé se lit sur `LoggedSet` et le lot B
> est absorbé par [`docs/feature-live-tracking.md`](./feature-live-tracking.md)
> (tickets KL-49 à KL-51). Plus rien à arbitrer ici.

## 0. Résumé et recommandation

**Le manque** : l'app est un excellent *éditeur* de plans mais un mauvais *miroir*.
Un plan fait monter la charge / descendre l'allure semaine après semaine, mais rien
ne visualise cette trajectoire. Le profil stocke squat/bench/5K comme des valeurs
figées, sans historique.

**La tension** (résolue depuis, voir §3) : la règle verrouillée était **« pas de
tracking détaillé, Strava le fait »** (`CLAUDE.md §3`, `ROADMAP.md §1.5`), ce qui
plaçait la feature entre « logguer chaque série » (interdit) et « ne rien voir
évoluer » (le problème actuel). **Depuis le 29/07/2026, la règle est reformulée en
« pas de tracking cardio »** : logguer une série de muscu est désormais autorisé, et
même prévu. La tension qui a dicté le découpage en deux lots n'existe plus.

**Reco** : livrer la feature **en deux temps nettement séparés**.

1. **Lot A — progression PRÉVUE (recommandé en premier, ZÉRO migration, zéro
   entorse à la règle).** Tout est déjà en base : le *fork à la pose* fait que
   chaque case d'un plan porte sa propre copie de séance, donc ses propres
   `PrescribedExercise` (charges/allures). On lit cette rampe existante et on la
   trace. C'est de la pure lecture agrégée. **C'est le vrai cœur de la valeur et
   ça ne demande aucune décision d'archi.**
2. **Lot B — progression RÉALISÉE (TRANCHÉ le 29/07/2026, ne plus arbitrer).** La
   source du réalisé est `LoggedSet`, et le lot est absorbé par
   [`docs/feature-live-tracking.md`](./feature-live-tracking.md). Détail en §3.

Le lot A a été livré d'abord, et il sert de socle visuel au lot B : la courbe du
réalisé se superpose à celle du prévu, dans le même bloc (ticket KL-49).

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

## 3. Lot B — Progression RÉALISÉE (TRANCHÉ le 29/07/2026)

**La décision est prise, il n'y a plus rien à arbitrer ici.** La règle « pas de
tracking détaillé » a été reformulée en **« pas de tracking cardio »**
(`ROADMAP.md §1.5`, `CLAUDE.md §3`) : ce qui rendait ce lot ambigu a disparu.

**La source du réalisé est `LoggedSet`.** Le réalisé s'écrit série par série dans
`LoggedExercise` / `LoggedSet`, portés par la **séance datée** (`ScheduledWorkout`),
et il est alimenté par l'app mobile. Le modèle, l'API et les écrans sont spécifiés
dans [`docs/feature-live-tracking.md`](./feature-live-tracking.md) — ce fichier-ci ne
redécrit rien.

Ce que devient concrètement le lot B, en trois tickets de cette autre spec :

| Ce que voulait le lot B | Où c'est traité |
|---|---|
| La courbe du réalisé superposée à la progression prévue de `plan_template/show` | **KL-49** (dépend de KL-05 et KL-07) |
| La trajectoire réelle d'un exercice, tous plans confondus | **KL-50** (dépend de KL-04, `PerformanceHistory`) |
| Le tri de la bibliothèque par usage réel | **KL-51** |

**Les trois options d'origine sont caduques**, et il est utile de savoir pourquoi
plutôt que de les voir réapparaître :

- **Option 1 (rester au binaire)** — écartée : elle ne répondait pas à « ai-je
  vraiment progressé », qui est le besoin même de la feature.
- **Option 2 (réalisé « léger » agrégé sur `ScheduledWorkout`)** — dépassée par plus
  précis. L'intuition était bonne (le réalisé se porte bien sur la séance datée),
  mais on garde la série entière plutôt qu'une valeur résumée : agréger reste
  possible après coup, le contraire non.
- **Option 3 (snapshots datés de records au profil)** — devenue redondante. Un record
  se **dérive** des `LoggedSet` (`PerformanceHistory`, KL-04) au lieu de se ressaisir
  à la main, ce qui supprime la saisie supplémentaire qui était son seul défaut.

**Ce qui ne change pas** : le lot A reste la progression **prévue**, il garde son
titre et sa lecture propre. Le réalisé s'y **superpose**, il ne le remplace pas —
sans la rampe prévue, on ne sait plus si un écart est un dépassement ou un retard.

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

## 6. Décisions — toutes tranchées (29/07/2026)

1. ~~Fait-on le lot B, ou le lot A suffit-il ?~~ **Oui, le lot B se fait**, sous la
   forme des tickets KL-49 à KL-51 de `feature-live-tracking.md`.
2. ~~Si lot B : Option 1 / 2 / 3 ?~~ **Aucune des trois** : le réalisé vient des
   `LoggedSet` écrits par l'app mobile (cf. §3).
3. ~~Portée de la vue prévue : par plan, ou transversale sur le calendrier ?~~
   **Les deux**, et la coupure suit l'entité : par plan sur `plan_template/show`
   (KL-49), transversale par exercice sur `/exercise/{id}` (KL-50).
4. ~~Métriques prioritaires ?~~ **Charge d'abord** (top set et tonnage), c'est ce que
   `PerformanceHistory` expose (KL-04) et ce que l'app affiche en séance. Le volume
   en séries suit. L'allure et la distance ne sont **pas** au programme : c'est du
   cardio, donc hors périmètre du réalisé (§3).
