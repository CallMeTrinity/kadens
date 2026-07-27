# CLAUDE.md — Kadens

Guide de travail pour ce dépôt. À maintenir à jour à chaque évolution
structurante (décision d'archi, nouveau pattern, changement de design).

Deux références plus détaillées existent et priment sur ce fichier sur leur
périmètre :
- [`ROADMAP.md`](./ROADMAP.md) — vision, modèle de données complet, phases. **La
  référence produit/archi.**
- [`docs/design-system.md`](./docs/design-system.md) — identité visuelle et
  usage des tokens.

---

## 1. Le projet en une phrase

Webapp de **planification** d'entraînements sportifs (muscu, course/trail, vélo,
natation, mobilité). L'objectif est l'amont : bibliothèque d'exercices → séances
→ plans multi-semaines → calendrier daté → boucle prévu vs réalisé. **Pas de
tracking détaillé** (Strava couvre déjà ça).

---

## 2. Stack

- **Symfony 8.1** full-stack, rendu serveur, PHP 8.4
- **Twig** (vues) + **Stimulus** (interactivité) + **Turbo** (navigation/updates
  partiels). Pas de SPA.
- **AssetMapper** (pas de Webpack Encore, pas de bundling). Conséquence : le
  service worker PWA (Phase 9) sera écrit **à la main**.
- **Symfony UX Icons** (`symfony/ux-icons`) pour les icônes, jeu **Lucide**
  (traits fins, cohérent « Carnet clair »). Icônes **figées en local** dans
  `assets/icons/lucide/` (`php bin/console ux:icons:import lucide:<nom>`) : pas de
  fetch réseau en prod/offline. Toute nouvelle icône doit être importée localement.
- **Doctrine ORM** + **MariaDB 10.4** (même version dev et prod).
- **Docker** en dev uniquement. Prod = **hébergement mutualisé Infomaniak**
  (`kadens.antoninpamart.fr`), pas de conteneurs, pas de root.
- CI/CD GitHub Actions, déploiement manuel validé (rsync + migrations + cache).

---

## 3. Règles d'architecture verrouillées (ne pas rediscuter en cours de route)

Détail complet dans `ROADMAP.md §1`. L'essentiel :

- **`Exercise` = définition réutilisable SANS paramètres** (nom, description,
  activité, zones, média). Jamais de séries/reps/charge/distance ici.
- **Bibliothèque globale vs perso** : `Exercise` sans `owner` (null) = biblio
  globale de l'app, visible par tous en lecture, éditable/supprimable uniquement
  par un `ROLE_ADMIN` (sinon alimentée par l'import console). Avec `owner` =
  perso, réservé à son propriétaire. Voir `ROADMAP.md §1.3`.
- **Variantes = entrées distinctes, pas de champ `equipment`** : l'équipement,
  la prise, la posture sont dans le nom de l'exercice. Regroupement `family`
  différé, alternatives dérivées des `targetAreas`. Détail dans `ROADMAP.md §2.3`.
- **Les paramètres vivent sur `PrescribedExercise`** (le lien bloc↔exercice).
  C'est ce qui rend un exercice réutilisable avec des paramètres différents.
  **Extension (séries détaillées) :** un exercice de force peut, en option, éclater
  son compteur scalaire `sets`/`reps`/`weightKg` en une collection `PrescribedSet`
  (une ligne = une série, type `SetType` + valeurs propres). Le scalaire reste le
  défaut (mode simple) ; dès qu'il y a des `PrescribedSet`, ils **priment** (helpers
  dérivés `getWorkingSetCount`/`getTonnageKg`/`getTopWeightKg` sur `PrescribedExercise`,
  consommés par tous les services de calcul). L'échauffement (`SetType::WARMUP`) est
  exclu du volume de travail. Muscu uniquement (`SETS_REPS`/`SETS_TIME`).
  **Synchro des deux modes (`SetSynchronizer`)** : le scalaire `sets` et la
  collection décrivent la même chose, ils sont tenus d'accord **dans les deux sens**.
  Référence commune = le nombre de séries **de travail** (échauffement exclu, aligné
  sur `getWorkingSetCount()`). Toute mutation de la collection réécrit le scalaire ;
  modifier le scalaire en mode détaillé ajoute/retire des lignes `NORMAL` en fin (et
  ne touche jamais un échauffement). Conséquence : le champ « séries » reste **visible
  et éditable** en mode détaillé, et revenir au mode simple repart du nombre réellement
  décrit. Corollaire : les champs pilotés par la collection (`reps`/`weightKg`/
  `durationSeconds`) ne sont **pas déclarés** dans `PrescribedExerciseType` quand
  l'option `detailed` est vraie — les sauter dans le template ne suffit pas,
  `form_end()` appelle `form_rest()` et les re-rendrait.
- **Modèle d'exercice unique et flexible**, piloté par l'enum `PrescriptionType`
  (champs de valeurs nullable, seul le sous-ensemble pertinent est rempli). Pas
  d'héritage par activité.
- **Blocs avec `rounds` + `role`** (`BlockRole`: WARMUP/MAIN/COOLDOWN). Le bloc est
  une **section** de la séance : séance plate, tours d'un circuit complet,
  échauffement.
- **Le superset est une liaison DANS un bloc, pas un bloc.** Un bloc de deux
  exercices n'est pas un superset ; un superset, ce sont deux exercices **liés**
  à l'intérieur d'un bloc. Un bloc peut donc mélanger des exercices isolés et
  plusieurs groupes liés (A1/A2, puis B1/B2/B3). Porté par
  `PrescribedExercise.supersetGroup` (nullable), dont **`SupersetGrouper` est la
  seule autorité** : il tient deux invariants — membres **contigus** en position,
  groupe d'**au moins deux** membres — et les rétablit après chaque mutation
  (`normalize`, appelé par `linkToPrevious` / `detach` / `settleAfterMove`). Les
  numéros sont renumérotés 1..n, ils se comparent mais ne s'interprètent pas ; les
  libellés A1/A2 sont **dérivés** de l'ordre, jamais stockés. Le groupe ne porte
  **ni tours ni repos propres** (le nombre de tours est déjà dans le `sets` de
  chaque exercice, `Block.rounds` reste au bloc). Conséquences à ne pas casser :
  changer de bloc détache (le numéro n'a de sens que dans son bloc) ; déposer un
  exercice **strictement à l'intérieur** d'un groupe l'y fait entrer ; le DOM du
  compositeur reste **plat** (SortableJS ne trie que ses enfants directs), le
  groupe se dessine au rail de gauche, jamais par un conteneur.
- **Unités normalisées en base** : charges en **kg**, distances en **mètres**,
  durées en **secondes**. Jamais de texte mixte type « 5km ». Rend l'export Excel
  (Phase 8) trivial.
- **`PlanFlattener` = source unique de mise à plat.** Le rendu Twig ET l'export
  Excel le consomment. Ne jamais dupliquer cette logique dans un contrôleur.
- **Pages de consultation auto-suffisantes** : aucun AJAX post-chargement. C'est
  la condition du cache offline (Phase 9).
- **Template vs instance datée** : `PlanTemplate` (sans dates) ≠ `ScheduledWorkout`
  (daté). `ScheduledWorkout.workout` est une **référence vivante**.
- **Progression = fork à la pose (règle ajustée).** Poser une séance dans un plan en
  crée une **copie privée** (`Workout.planLocal = true`), portée par le `PlanItem`.
  Éditer une séance placée (progression) ne touche ni la séance de bibliothèque ni
  les autres cases. Ces copies sont **exclues de la bibliothèque**
  (`WorkoutRepository::findLibraryForOwner`). Une séance datée issue d'un plan
  référence la **copie locale** (pas la séance biblio) : ses modifications se
  reflètent donc d'office au calendrier. Nuance qui remplace l'ancien « les items
  pointent la même séance partagée ».
- **Plan vivant sur le calendrier.** L'instanciation est désormais **idempotente**
  (`PlanScheduler`, ex-`PlanInstantiator`) : la relancer resynchronise au lieu de
  dupliquer. `resync` est **add-only** — il ajoute au calendrier les cases posées
  après l'instanciation (`ScheduledWorkout.sourcePlanItem` + `planAnchorDate`) et ne
  touche jamais une séance datée existante (préserve dates/statuts, décision
  « préserver le réalisé »). Retirer une case supprime ses séances datées `PLANNED`
  et **préserve** les `DONE`/`MISSED`.
- **Coaching : le contenu appartient toujours à l'athlète.** Une relation
  `Coaching` (coach ↔ athlète, demande + acceptation, `CoachingStatus`) ne dit pas
  « qui possède » mais « qui a le droit d'aider ». Ce que le coach crée est posé
  avec `setOwner($athlete)` ; il n'est que **co-éditeur**, via une branche « coach
  accepté du propriétaire » ajoutée dans `WorkoutVoter`, `PlanTemplateVoter` et
  `ScheduledWorkoutVoter` (mémoïsée par `CoachingResolver`). Conséquences à ne pas
  casser : les repos owner-scoped, le repli `PlanScheduler::resync()` →
  `$template->getOwner()` et l'affichage chez l'athlète restent corrects sans
  condition ; l'athlète **garde son contenu** quand la relation se termine. Les
  actions coach ne forkent aucun éditeur : elles créent la coquille et redirigent
  vers le compositeur / l'éditeur de plan normaux. `ROLE_COACH` s'accorde par
  commande (`app:user:promote-coach`), jamais depuis l'app. `GoalVoter` porte la
  même branche : la page athlète affiche ses échéances, elles doivent être
  ouvrables. Corollaire : toute vue accessible au coach doit se scoper sur
  **`$entity->getOwner()`**, jamais sur `$this->getUser()` (`GoalController::show`
  et les rattachements objectif↔plan suivent cette règle).
- **Objectif ↔ Plan : relation N:N, libre et réversible.** Table de jointure
  `plan_template_goal`, côté propriétaire sur `PlanTemplate` (`addGoal`/`removeGoal`,
  qui maintiennent **les deux côtés** — sinon un fragment re-rendu par Turbo Stream
  dans la même requête montre un état périmé). Plusieurs plans par objectif (prépa
  en blocs : base puis spécifique) et plusieurs objectifs par plan. Le lien
  **documente l'intention**, il ne contraint ni les dates ni le contenu : l'ancrage
  réel au calendrier reste `app_goal_prepare` (qui rattache au passage, pour que le
  lien ne se perde plus). Se pose et se défait des deux côtés : bandeau `#plan-goals`
  dans l'éditeur de plan, section « Plans de préparation » sur la fiche objectif.
  Jamais affiché sur la page publique d'un plan (les objectifs sont privés).
- **Création sans écran intermédiaire.** « Nouvelle séance » / « Nouveau plan » sont
  des **POST** qui créent un brouillon titré par défaut (plan : 4 semaines) et
  redirigent vers l'éditeur avec `rename=1`, qui ouvre le titre en édition en ligne.
  Il n'y a plus de `WorkoutType` ni de `PlanTemplateType`. Le slug d'un brouillon est
  régénéré au **premier** vrai renommage seulement (`SlugGenerator::derivesFrom`) :
  une entité déjà nommée garde le sien, sinon son lien de partage public casserait.
- **Métadonnées : édition en ligne uniquement.** Titre, description et nombre de
  semaines n'ont plus de formulaire de repli — c'est un choix assumé : sans JS, ces
  trois champs ne sont pas modifiables (le reste des éditeurs garde son repli par
  redirection). Les routes `edit` de séance et de plan sont donc en **GET seul**.
- **Pas d'inscription publique.** Les comptes se créent en console
  (`app:user:create`, rôle ROLE_USER), comme les promotions (`app:user:promote`,
  `app:user:promote-coach`). L'app n'expose que ce que le titulaire du compte peut
  changer lui-même : son mot de passe, dans `/profile/settings`. L'email reste un
  identifiant fixe côté console.
- **Aucune IA dans l'app.** Le remplissage de la biblio passe par une commande
  d'import JSON (Phase 3), pas d'API en prod.

---

## 4. Conventions de rangement (ne jamais improviser l'emplacement)

- Enum PHP → `src/Enum/` (backed enum `enum X: string`)
- Service métier → `src/Service/` (autowiring)
- Repository → `src/Repository/` (un par entité)
- Form type → `src/Form/`
- Commande console → `src/Command/`
- Contrôleur Stimulus → `assets/controllers/`, suffixe `_controller.js`
- Fragment Twig réutilisable → `templates/components/`

Arborescence cible complète : `ROADMAP.md §3`.

---

## 5. Design system

Identité **« Presse »** : papier froid, encre quasi noire, **un seul accent
rouge**, rayon 0, aucune ombre, titres en condensé capitales. Issue de la
maquette Claude Design « Séance — Refonte », qui a servi de test avant
généralisation. Remplace l'ancienne identité « Carnet clair ».

- **Source de vérité des tokens** : [`assets/styles/tokens.css`](./assets/styles/tokens.css)
  (primitives `--kd-*` + tokens sémantiques `--color-*`, `--font-*`).
- **Guide d'usage, responsive, accessibilité, patterns** :
  [`docs/design-system.md`](./docs/design-system.md).
- **Socle transverse** (a11y, focus, tactile, impression) :
  [`assets/styles/base.css`](./assets/styles/base.css), importé avant
  `components.css` — ses règles sont des défauts surchargeables.

Règles non négociables :
1. **Jamais de couleur ou de police en dur** dans un template/composant. Toujours
   un token sémantique.
2. **La couleur porte du sens, et il n'y a qu'une couleur.** Le rouge est
   réservé aux actions primaires, à l'intensité et à l'échec. Toute **catégorie**
   (activité, région musculaire, rôle de bloc) se code par son rang dans
   l'échelle de gris `--color-cat-1..4`, jamais par une teinte inventée — c'est
   ce qui permet de couvrir les cinq activités là où l'ancienne palette n'en
   codait que deux. Les statuts gardent leurs tokens dédiés ; les types de série
   détaillée se réduisent à deux axes (encre/rouge, plein/contour).
3. Nouvelle valeur → primitive `--kd-*` d'abord, puis token sémantique.
4. **Le condensé capitales ne touche pas au contenu saisi.** Titre de page,
   bouton, onglet, rôle de bloc : Barlow Condensed uppercase. Nom d'exercice, de
   séance, d'athlète, intitulé d'objectif : Barlow, casse normale.
5. Polices (Barlow Condensed / Barlow / IBM Plex Mono) **self-hostées**,
   régénérées par [`tools/fetch-fonts.sh`](./tools/fetch-fonts.sh) —
   `assets/styles/fonts.css` est généré, ne jamais l'éditer à la main.
6. **Trois points de rupture, et rien d'autre : 560 / 900 / 1200.** Ils ne
   peuvent pas être tokenisés (`@media` n'accepte pas `var()`, et il n'y a pas
   de build CSS) : c'est une convention documentée à tenir à la main.

---

## 6. État d'avancement

L'historique détaillé phase par phase (socle Symfony, Phases 1 à 9, et tous les
lots de design/finitions) vit désormais dans
[`docs/journal-de-bord.md`](./docs/journal-de-bord.md) : il n'est plus rechargé à
chaque session. S'y référer pour le détail d'une fonctionnalité livrée, et y
consigner chaque nouveau lot terminé (cf. §7).

Résumé : toutes les phases du ROADMAP sont livrées. Socle Symfony en place
(Docker, MariaDB, CI/CD), design tokens posés, et couche visuelle « Carnet
clair » déployée sur toutes les vues. Au-delà du ROADMAP : objectifs datés,
séries détaillées, **relation coach ↔ athlète** (page unique `/coaching` pour les
deux sens, fiche de travail par athlète sous `/coach`, `ROLE_COACH`) — cf. §3 pour
la règle de propriété — et **paramètres de compte** (`/profile/settings` :
changement de mot de passe, création de compte par `app:user:create`).

Dernier lot (superset réel, intra-bloc) : le superset cesse d'être un effet de
bord du nombre d'exercices d'un bloc pour devenir une **liaison stockée entre
exercices d'un même bloc** (cf. §3).

- **Modèle** : `PrescribedExercise.supersetGroup` (nullable) + service
  `SupersetGrouper` (découpage en segments, `linkToPrevious` / `detach` /
  `settleAfterMove` / `normalize`). Migration `Version20260727120000` : la colonne,
  puis reprise des données — chaque bloc à 2 exercices ou plus devient un groupe
  unique, ce qui préserve à l'identique l'affichage de l'ancienne règle.
- **Compositeur** : une bascule par ligne (lier au précédent / détacher), rail de
  groupe à gauche, rang A1/A2 et intitulé « Superset A » en tête de groupe. Le
  glisser-déposer décide de l'appartenance (déposé dans un groupe, on le rejoint).
- **Lecture** : `PlanFlattener` expose désormais, par bloc, `segments` (isolés et
  groupes) en plus de `exercises` (liste plate) et un `groupLabel` par exercice.
  La page séance groupe les enchaînements, `WorkoutMetrics` compte les supersets
  (2 liés) et circuits (3+) **au groupe** et non plus au bloc. Le rang suit
  jusque dans l'aperçu au survol, l'export Excel et le flux ICS.

Lot précédent (refonte graphique « Presse » & page séance) : bascule complète de
l'identité (cf. §5), refonte de la page de consultation d'une séance sur le
modèle de la maquette, et première vraie couche mobile/accessibilité.

- **Identité** : `tokens.css` réécrit (primitives seules ; les noms sémantiques
  n'ont pas bougé, c'est ce qui a repeint les 4 400 lignes de `components.css`
  sans les toucher). Polices migrées vers Barlow / Barlow Condensed / IBM Plex
  Mono via le nouveau `tools/fetch-fonts.sh`.
- **Page séance** : hero encre, bandeau de KPI, onglets Programme / Analyse,
  blocs en accordéon `<details>`, tableau de séries avec plage de rangs et % du
  max, menu kebab d'actions. Le composant `_workout_read` est éclaté en
  `_workout_program` / `_workout_sets_table` / `_workout_analysis`, et expose un
  bloc Twig `actions` (d'où un `embed` côté `workout/show`) que la page publique
  laisse vide.
- **Services** : `WorkoutMetrics::summary()` et `::blockBreakdown()`,
  `WorkoutEstimator::estimateBlockSeconds()`, nouvel enum `TargetRegion`.
  `PlanFlattener` expose désormais `values` (le résumé sans le suffixe RPE) et,
  par groupe de séries, `effort` / `firstIndex` / `lastIndex` / `weightKg` — le
  regroupement condense l'affichage sans faire perdre la numérotation, et chaque
  valeur a sa propre colonne au lieu d'une chaîne pré-assemblée.
- **Mobile / a11y** : `<meta viewport>` rétablie (elle était commentée : aucune
  media query ne se déclenchait sur téléphone), points de rupture ramenés de neuf
  valeurs à trois, `base.css` (skip link, `.kd-sr-only`, `:focus-visible` global,
  `prefers-reduced-motion`, cibles tactiles 44 px, impression), nav en barre
  basse et calendrier en agenda vertical sous 560 px, contrôleur Stimulus `tabs`
  en amélioration progressive.

Lot précédent (aperçu au survol & pastilles de série) : le survol d'une case de
plan rend une ligne par groupe de séries comme la vue séance, et le type de série
s'affiche en pastille sigle (`W`/`D`/`F`/`DS`, tokens `--color-set-*`).

Lot précédent (fusion Coaching / Mes athlètes) : le tableau de bord coach disparaît,
`/coaching` porte les deux sens de la relation (demandes mutualisées, sections
ordonnées selon le rôle, formulaire d'invitation réservé aux coachs), et `/coach`
ne garde que la fiche athlète.

Lot antérieur (fluidité d'édition & navigation) : création en un clic, éditeurs sans
formulaire de métadonnées, semaines ajoutées une par une ou par paquet, compteur de
séries synchronisé dans les deux sens, **nav réduite à 4 entrées + menu de compte**
sur l'avatar, profil complet de l'athlète visible par son coach, et **relation
Objectif ↔ Plan (N:N)** navigable des deux côtés.

---

## 7. Maintenance de ce fichier

Mettre à jour CLAUDE.md quand :
- une décision d'archi change ou s'ajoute (répercuter aussi dans `ROADMAP.md`) ;
- une phase est terminée (mettre à jour §6) ;
- l'identité visuelle ou les tokens évoluent (répercuter dans
  `docs/design-system.md` et `assets/styles/tokens.css`).
