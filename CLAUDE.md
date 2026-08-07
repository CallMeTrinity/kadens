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
tracking cardio** (Strava couvre déjà ça) ; le réalisé de la muscu, lui, se logue
série par série — règle révisée, cf. §3.

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
- **`endroid/qr-code`** pour le QR d'appairage (KL-47), en **writer SVG** :
  aucune extension PHP requise (`ext-gd` ne sert qu'aux writers image), donc
  rien à demander au mutualisé, et le QR est dessiné côté serveur — pas de
  bibliothèque JavaScript dans l'importmap.
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
  par un `ROLE_ADMIN`. Elle s'alimente par l'import console **et** par
  `/exercise/new` : un `ROLE_ADMIN` qui crée un exercice le crée **global**
  (`owner = null`), jamais en perso — c'est ce qui donne son sens au rôle. Avec
  `owner` = perso, réservé à son propriétaire. Voir `ROADMAP.md §1.3`.
  **Exception coaching, en lecture seule** : `ExerciseVoter::VIEW` traverse une
  relation acceptée **dans les deux sens** (le coach lit les exercices perso de
  son athlète, l'athlète ceux de son coach). C'est la seule règle symétrique du
  projet, et elle existe parce que le compositeur croise les deux bibliothèques
  (`WorkoutController::libraryOwners()` = propriétaire de la séance + utilisateur
  courant) : ce qu'on pose dans une séance doit rester ouvrable par l'autre.
  `EDIT`/`DELETE` restent au propriétaire, et l'index `/exercise` reste scopé sur
  soi + la globale — lire n'est pas s'approprier.
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
  (daté). `ScheduledWorkout.workout` est une **référence vivante**, mais la clé
  étrangère est en **`SET NULL`**, pas en `CASCADE` (voir la puce suivante).
- **Le réalisé se logue en muscu, jamais en cardio (règle révisée le 29/07/2026).**
  Une séance de force écrit son réalisé série par série sur la **séance datée** ;
  une sortie course, vélo ou natation se contente du `ScheduledStatus`. Cadrage
  complet, principes et invariants à ne pas casser :
  [`docs/feature-live-tracking.md`](./docs/feature-live-tracking.md) §0.2-0.3.
- **Statistiques : chaque chiffre vient de la source qui fait autorité sur lui.**
  `TrainingStats` est le moteur unique (fenêtre de temps = `StatsPeriod`), et
  `ProfileStats` n'en est que le résumé « depuis le début » — le profil et
  `/profile/stats` ne peuvent donc pas afficher deux tonnages différents. La
  **salle** se lit sur le RÉALISÉ (`LoggedSet`) : tonnage, séries, régions,
  records. L'**endurance** se lit sur le PRESCRIT des séances faites, et ce n'est
  pas un repli — le cardio ne se logue jamais, son prescrit est sa seule trace.
  L'**observance** se lit sur le statut. Corollaire à ne pas « corriger » : une
  séance cochée faite sans réalisé compte en assiduité et ne porte aucun
  tonnage ; le combler avec le prescrit ferait passer une intention pour un
  fait. Contrainte de coût qui va avec : hors de cette unique passe hydratante
  d'endurance (bornée), tout passe par des agrégats scalaires — sans quoi
  « depuis le début » remonterait l'historique entier à chaque affichage.
  **Ce qui entre dans le volume de salle est défini une fois**, par
  `LoggedSet::countsAsWorking()`, son pendant SQL `LoggedSetRepository::measured()`
  et son pendant mobile `isMeasured()` (`kadens-mobile/src/session/summary.ts`) —
  trois écritures d'une seule règle, elles bougent ensemble : type de travail
  (échauffement exclu) **et** série chiffrée — au moins une répétition ou au
  moins une seconde. Une série cochée
  sans valeur (« ? ») a eu lieu mais ne mesure rien ; la charge seule ne la sauve
  pas (140 kg × 0 rep n'est pas un record). Frontière assumée, des deux côtés :
  `LogComparator` et son pendant mobile `exerciseOutcome()` la comptent quand
  même, parce qu'ils comparent ce qui a été fait à ce qui était prévu, et l'en
  retirer ferait passer une séance tenue pour « allégée ». À l'écran de clôture,
  elle se dit en légende (« + 1 sans valeur »), comme l'échauffement : hors du
  chiffre, mais jamais escamotée.
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
  **L'éditeur de trame ne fait pas exception** : sa palette, la garde de pose,
  l'owner des copies locales forkées, la duplication et l'owner passé à
  `PlanScheduler::rescheduleItem()` dérivent tous de `PlanTemplateController::ownerOf()`
  (= `$template->getOwner()`). Un coach y compose donc avec la bibliothèque de
  **l'athlète**, et tout ce qu'il crée reste à l'athlète. Même logique pour
  `WorkoutController::duplicate` : la copie appartient au propriétaire de la
  source. Une copie qui atterrirait chez le coach serait inerte — ni posable au
  calendrier de l'athlète ni dans son plan, qui n'acceptent que son contenu.
  **Portée des index (`CoachedLibrary`)** : les bibliothèques `/workout` et
  `/plan-template` listent **soi + ses athlètes en relation acceptée**, pour qu'un
  coach retrouve ce qu'il a composé (le contenu appartenant à l'athlète en
  sortait). La relation reste dirigée — les bibliothèques de mes coachs ne me
  regardent pas — et c'est une portée **de consultation seulement** : les
  sélecteurs de pose gardent `findLibraryForOwnerWithContent` (un seul
  propriétaire), celui de l'entité qu'on garnit — soi pour son calendrier,
  `$template->getOwner()` pour une trame. Pas de
  champ « créé par » : le coach voit/édite déjà tout le contenu de son athlète.
  À l'écran, une facette `owner` avec **« Moi » actif par défaut** et un badge de
  propriétaire sur les cartes des autres.
- **Le bloc-notes privé est la seule exception à « le coach voit tout ».**
  `Workout.notes` et `PlanTemplate.notes` (TEXT nullable) sont un fourre-tout du
  **propriétaire seul** : brouillon, déroulé en vrac. À distinguer de
  `description`, qui s'adresse à un lecteur (coach, partage public, export). La
  portée est doublée : le composant `components/_private_notes.html.twig` ne se
  rend que pour l'owner (comparaison **par `id`** — `owner` peut être un proxy que
  Twig comparerait attribut par attribut), et `updateMeta` refuse `field=notes` en
  403 hors propriétaire — l'attribut `EDIT` de la route ne suffit pas, c'est
  justement celui que le coach possède, et l'endpoint renvoie la valeur persistée
  (donc écrire, ce serait lire). À ne pas casser : le champ n'entre **jamais** dans
  `PlanFlattener` — c'est ce qui garantit qu'aucune vue de consultation, page
  publique, export Excel ou flux ICS ne le laisse fuiter. `WorkoutCloner` ne le
  copie pas non plus (le fork à la pose le dupliquerait dans chaque case d'un plan).
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
- **Un exercice a une identité (`refKey`) et des libellés (`name`, `nameEn`),
  et ce sont deux choses.** `app:import-exercises` apparie sur la clé, pas sur le
  nom : renommer une entrée de `data/exercises.json` la **met à jour** au lieu
  d'en créer une seconde — ce qui détacherait tout l'historique, qui indexe sur
  `Exercise.id`. La clé est réservée à la globale (unique en base, donc jamais
  posée en `--owner`) et **ne change plus une fois posée**. Le nom anglais n'est
  pas de l'i18n Symfony mais une donnée métier : l'UI reste française en dur,
  seuls les noms d'exercices suivent `User.exerciseLanguage`, et l'anglais
  retombe sur le français quand `nameEn` est vide. **Un seul endroit décide du
  libellé** — `ExerciseNaming`, via `exercise_name()` en Twig ; écrire
  `exercise.name` dans un template court-circuite la préférence. Cadrage complet,
  règles d'adoption (`formerNames`), parité `TextNormalizer` ↔ `assets/search.js`
  et marche à suivre en prod :
  [`docs/feature-exercise-naming.md`](./docs/feature-exercise-naming.md).

---

## 4. Conventions de rangement (ne jamais improviser l'emplacement)

- Enum PHP → `src/Enum/` (backed enum `enum X: string`)
- Service métier → `src/Service/` (autowiring)
- Brique HTTP transverse (forme d'une réponse d'erreur ou de succès, garde-fou de
  format, **charge utile entrante d'une API**) → `src/Http/`, comme
  `src/Doctrine/` pour les briques ORM : ce n'est ni du métier ni un contrôleur
- Écouteur d'événement (kernel ou Doctrine) → `src/EventListener/`
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
   détaillée se réduisent à deux axes (encre/rouge, plein/contour). **Une seule
   exception, bornée à `/profile/history`** : les cinq groupes musculaires
   (`--color-muscle-*`), qui ne tiennent pas dans quatre gris sur une pastille de
   6 px — cadrage et portée dans `docs/design-system.md` §2, à ne pas étendre.
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

Toutes les phases du `ROADMAP.md` sont livrées : socle Symfony (Docker,
MariaDB, CI/CD), design tokens posés, identité **« Presse »** déployée sur
toutes les vues, relation coach ↔ athlète, paramètres de compte.

**Chantier ouvert — Kadens Live (suivi de séance en direct).** État des
tickets, cadrage complet et invariants à ne pas casser :
[`docs/feature-live-tracking.md`](./docs/feature-live-tracking.md).
Historique narratif lot par lot, y compris ce chantier :

---

**Ne pas mettre à jour CLAUDE.md pour un ticket livré.** Un ticket qui avance
ou se termine se documente à deux endroits seulement :
- [`docs/feature-live-tracking.md`](./docs/feature-live-tracking.md) : coche la
  case du ticket, ajoute/actualise ses invariants (« ce qui a été tranché en le
  faisant ») ;

CLAUDE.md doit rester consultable en entier à chaque session sans faire
exploser le contexte : s'il redevient long, c'est le signe qu'un détail de
feature s'y est réinstallé — à déplacer, pas à laisser grossir.
