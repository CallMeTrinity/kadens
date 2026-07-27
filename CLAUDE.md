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
  (daté). `ScheduledWorkout.workout` est une **référence vivante**.
- **Le réalisé est SÉPARÉ de la prescription, et ne l'écrit jamais.**
  `PrescribedSet` dit ce qu'il fallait faire, `LoggedSet` dit ce qui a été fait, à
  une date. C'est ce qui rend le pointage sûr alors qu'une séance peut être posée
  sur dix dates *et* rester en bibliothèque : sans cette séparation, cocher la
  séance d'aujourd'hui réécrirait les neuf autres (une séance posée par
  `/schedule/place` pointe **directement** la séance de biblio — le « fork à la
  pose » ne couvre que les plans).
  **Pointage par `(prescribedExercise, setIndex)`, jamais par lien vers un
  `PrescribedSet`** : en mode scalaire, aucune ligne `PrescribedSet` n'existe en
  base alors que la vue en déroule N (`PlanFlattener::setLines`). Un lien aurait
  obligé à matérialiser le détail au premier clic, donc à écrire dans la
  prescription pour enregistrer du réalisé. Corollaires à ne pas casser : un
  `setIndex` au-delà du prescrit est une série faite **en plus** (acceptée, mais
  **hors du total** — la progression ne dépasse pas 100 %) ; un log excédentaire
  après réduction de la prescription est **ignoré en lecture, jamais supprimé en
  silence**. `WorkoutLogger` est la seule autorité des mutations, et `log` est
  **idempotent** (la file hors ligne rejoue des gestes).
  **`SessionSheet` est la seule autorité sur « qu'est-ce qu'une ligne
  validable »** : une par série pour `SETS_REPS`/`SETS_TIME`, **une seule**
  (index 1, « l'exercice est fait ») pour tous les autres types — sans quoi une
  séance de course serait impointable.
  Conséquence sur la clôture : « effacer les séries vides » **n'existe pas**. Une
  série non validée est déjà absente du réalisé, et la prescription doit rester
  intacte pour que l'écart se lise en face. Le choix est « tout valider comme
  prévu » (sans réécrire les séries déjà corrigées à la main) ou « terminer tel
  quel ».
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

Dernier lot (pointage d'une séance en cours) : nouvelle page
**`GET /schedule/{id}/execute`**, la séance qu'on tient en main *pendant* qu'on la
fait — distincte de `/schedule/{id}`, qui la donne à lire. On y valide série par
série, avec les valeurs **réelles** (reps / charge / durée) pré-remplies par le
prévu. La règle de propriété est en §3 et ne se rediscute pas : **le réalisé
(`LoggedSet`) n'écrit jamais dans la prescription**.

Forme de la page, calquée sur les apps de salle : **un exercice à la fois**, rail
de navigation à gauche, minuteur de repos en barre basse. À ne pas casser :

- **`SessionSheet.stops`** = liste **plate** des exercices dans l'ordre où on fait
  la séance, chaque étape emportant son contexte de bloc. La page d'exécution
  consomme cette suite, pas l'arbre `blocks`.
- **Tous les panneaux sont rendus côté serveur**, le contrôleur n'en montre qu'un.
  C'est la condition du hors ligne : on ne va pas chercher l'exercice suivant sur
  le réseau au milieu d'une séance. Sans JS, tout s'affiche à la suite et le rail
  devient une liste d'ancres. Après un Turbo Stream, le contrôleur **réapplique**
  la sélection (le panneau remplacé revient visible par défaut).
- **Pas de vignettes d'exercice dans le rail.** `Exercise.mediaUrl` est distant,
  le service worker ne l'intercepte pas (cross-origin) : ce serait un carré vide
  hors ligne. Numéro + icône d'activité.
- **Le chrono de séance dérive du `completedAt` de la première série loggée**, pas
  d'une colonne `startedAt` : ouvrir la page ne veut rien dire (on l'ouvre parfois
  la veille), et la valeur reste juste après un rechargement ou sur un autre
  appareil.
- **Le minuteur de repos est purement client** et disparaît sans JS — c'est le
  seul élément de la page dans ce cas, le pointage lui reste entier. Il se lance
  tout seul à la validation d'une série.
- **Une seule ligne de série = badge, effort, charge, coche.** Un seul badge : le
  type quand la série en a un (pastilles W/D/F/DS existantes), le rang sinon.

Sur la fiche datée `/schedule/{id}`, **le réalisé remplace le prévu** une fois la
séance pointée, sans que la prescription bouge : `_workout_read` accepte une carte
`logs` optionnelle qu'il fait descendre jusqu'à `_workout_sets_table`. Le prévu ne
subsiste qu'en repère **là où il diffère**. La page de bibliothèque et la page
publique ne passent rien et affichent la prescription — elles décrivent une séance
sans date, donc sans réalisé.

À ne pas casser non plus :

- **Une ligne = un `<form>`**, bouton de validation = vrai submit portant
  `name="op"`. Sans JS, taper la coche poste la ligne avec ses valeurs. Tout
  le socle est testé sans JavaScript (`ScheduledWorkoutExecutionTest`).
  **`op` et jamais `action`** : un contrôle nommé `action` masque la propriété
  `action` du `<form>`, donc `form.action` renvoie le bouton (ou un
  `RadioNodeList` quand la ligne en a deux) au lieu de l'URL. Tous les POST
  partaient sur `/schedule/114/[object HTMLButtonElement]`. Un test garantit
  qu'aucun contrôle de la page ne porte ce nom.
- **La file distingue « pas de réseau » de « le serveur refuse ».** Une exception
  `fetch` met en file et retentera ; une réponse non-2xx s'affiche et n'est PAS
  mise en file — sinon un bug serveur gonfle la file en silence derrière un
  message rassurant. Au rejeu, une entrée refusée est **abandonnée** : sans ça
  un seul geste invalide bloque définitivement tout ce qui le suit.
  La clé de file est **versionnée** (`kd-execlog-v2-{id}`), à incrémenter quand
  la forme d'un geste change : c'est le seul moyen d'abandonner une file déjà
  écrite dans `localStorage`.
- **Rien ne bouge sous le doigt** : valider ne change ni la hauteur de la ligne
  ni l'ordre, seulement des couleurs. Les deux icônes (cercle / coche) sont dans
  le DOM, le CSS choisit selon `.is-done` — c'est ce qui permet à l'affichage
  optimiste de basculer en changeant **une seule classe**, sans manipuler de SVG,
  et garde le rendu serveur et le rendu optimiste identiques.
- **L'écart ne s'affiche que s'il existe.** Sur 16 séries, une légende
  systématique coûte un écran.
- **Hors ligne, une seule exception dans `sw.js`** : `/schedule/<id>/execute`
  reste **network-first** (la règle « en ligne, le réseau gagne toujours pour du
  HTML » tient), seul son repli change — sa propre copie en cache, jamais
  `offline.html`. La branche est placée **avant** le test
  `request.mode === 'navigate'` : arriver par un lien de l'app est une visite
  Turbo Drive, donc un `fetch()` dont le mode n'est pas `navigate`.
- **La file d'écriture vit dans le contrôleur `execlog`, pas dans le SW** (qui ne
  touche à aucun POST) : un service worker qui rejouerait des mutations en
  arrière-plan serait invisible le jour où il se tromperait. `localStorage`, pas
  IndexedDB — quelques dizaines de champs par séance. Les gestes visant la même
  série s'écrasent dans la file : ce qui compte à la reconnexion est l'état final.
  Le conteneur porte `data-turbo="false"`, sinon Turbo posterait les validations
  en court-circuitant la file.
- **Hors périmètre assumé** : ajouter un exercice **imprévu** pendant la séance.
  Ça demanderait un réalisé autonome (non indexé sur la prescription). Logger
  plus ou moins de séries que prévu, en revanche, est couvert.

Lot précédent (une ligne par série) : sur la page de consultation d'une séance,
Dernier lot (éditeur de plan au téléphone) : la mécanique du compositeur est
appliquée telle quelle à l'éditeur de trame. *Une ligne ne porte que ce qu'elle est
et un menu ; le reste se déduit du geste.*

- **La feuille de bibliothèque devient un mécanisme unique, `kd-libsheet`.** Le
  conteneur des deux volets la porte (compositeur **et** `.kd-planeditor`), son
  contrôleur y pose `kd-libsheet--open` : voile, `.kd-noscroll`, bouton de
  fermeture et chaîne de hauteurs défilables ont une seule définition. `kd-composer--sheet`
  ne garde que ce qui est propre au compositeur. Ne pas re-scoper par écran.
- **Le « + » d'un jour ouvre la palette SUR cette case** : taper une carte y pose
  la séance. C'est le pendant du « + Ajouter un exercice » d'un bloc. Le mode
  tampon (armer puis tamponner) reste à la souris mais n'est plus le seul chemin,
  et viser une case le **désarme** — deux intentions de pose concurrentes rendraient
  le prochain clic imprévisible. Le bouton vit **hors** de la cellule triée
  (SortableJS indexe ses enfants directs).
- **Plus de poignée : la carte de séance entière est la prise**, tap et appui long
  départagés par le temps (mêmes valeurs que le compositeur). Le reste passe en
  kebab : Édition complète / Déplacer vers / Retirer, et Copier vers / Retirer la
  semaine pour l'en-tête. `.kd-kebab__form` sert les deux actions qui demandent un
  choix avant d'agir. **« Déplacer vers » est le premier repli du glisser-déposer
  de trame** (clavier et sans JS) : ne pas le retirer.
- Conséquences à ne pas casser : **plus d'`overflow: clip` sur `.kd-planeditor`**
  (il ampute les menus, exactement comme `hidden` sur `.kd-cblock` — le rayon est
  porté par la palette), et pas de `.kd-planitem form { display: inline-flex }`
  (plus spécifique que `.kd-kebab__form`, il remettrait les menus en ligne).
  `_menu_form.html.twig` a migré dans `templates/components/`, il sert les deux
  éditeurs. Les formulaires de la trame restent soumis **par Turbo** : les
  intercepter en `fetch` avalerait leurs réponses en erreur.

Lot précédent (compositeur au téléphone) : l'éditeur de séance passe d'une rangée de
neuf éléments par ligne à **deux zones et un geste**. Ce qui tient tout : *une ligne
ne porte que ce qu'elle est et un menu ; le reste se déduit du geste.*

- **Toute la carte d'exercice est le bouton de dépliage** (`.kd-cexo__main`), et
  c'est aussi la prise du glisser-déposer. Les deux gestes se départagent par le
  **temps** (SortableJS `delay: 320` + `delayOnTouchOnly: true`) : tap = déplier,
  appui long = soulever. Il n'y a donc plus ni poignée ni bouton « paramètres ».
  Au pointeur fin le délai retombe à zéro, la souris garde son drag immédiat.
- **Tout le reste passe en menu kebab** (`.kd-kebab--row`, `<details>` + `dismiss`) :
  enchaîner/détacher, monter, descendre, retirer — en toutes lettres, via le nouveau
  `workout/_menu_form.html.twig`. Même chose pour l'en-tête de bloc. Conséquence à
  ne pas casser : **plus d'`overflow: hidden` sur `.kd-cblock`** (il clippait ces
  calques), le rayon est porté par l'en-tête.
- **L'état déplié vit sur `.kd-cexo--open` (la carte), jamais sur la ligne.** Le
  stream ciblé qui réécrit `#cexo-row-{id}` la rendrait « repliée » alors que le
  panneau, son frère, est resté ouvert. `aria-expanded` est resynchronisé après
  chaque flux, dans un `requestAnimationFrame`.
- **Sous 900px la bibliothèque est une feuille**, pas un volet empilé au-dessus des
  blocs (on la traversait à chaque défilement). Ouverte par un « + Ajouter un
  exercice » attaché à chaque bloc — qui désigne du même geste la destination. La
  carte est un vrai `<button>` (`button.kd-libx`, pour ne pas toucher la palette de
  trame ni la barre du calendrier) : taper ajoute, le « + » n'est plus qu'un
  pictogramme. Portée `.kd-composer--sheet`, parce que `.kd-composer__lib` /
  `__main` servent aussi à l'éditeur de trame.
- **Dans `.kd-cexo__params`, un champ n'est qu'une valeur sur un filet** et ne
  redevient une boîte qu'au focus. Scopé au compositeur : le même formulaire sert au
  panneau rapide du calendrier, qui garde des champs pleins. Les largeurs inline du
  formulaire prescrit sont devenues `.kd-fieldrow__cell`, donc surchargeables.
- Nouveau token `--color-scrim` (repris par `.kd-modal::backdrop`). Rappel :
  `--kd-navbar-h` n'existe que sous 560px, l'utiliser au-dessus demande un repli.

Lot précédent (une ligne par série) : sur la page de consultation d'une séance,
Dernier lot (pointage d'une séance en cours) : nouvelle page
**`GET /schedule/{id}/execute`**, la séance qu'on tient en main *pendant* qu'on la
fait — distincte de `/schedule/{id}`, qui la donne à lire. On y valide série par
série, avec les valeurs **réelles** (reps / charge / durée) pré-remplies par le
prévu. La règle de propriété est en §3 et ne se rediscute pas : **le réalisé
(`LoggedSet`) n'écrit jamais dans la prescription**.

Forme de la page, calquée sur les apps de salle : **un exercice à la fois**, rail
de navigation à gauche, minuteur de repos en barre basse. À ne pas casser :

- **`SessionSheet.stops`** = liste **plate** des exercices dans l'ordre où on fait
  la séance, chaque étape emportant son contexte de bloc. La page d'exécution
  consomme cette suite, pas l'arbre `blocks`.
- **Tous les panneaux sont rendus côté serveur**, le contrôleur n'en montre qu'un.
  C'est la condition du hors ligne : on ne va pas chercher l'exercice suivant sur
  le réseau au milieu d'une séance. Sans JS, tout s'affiche à la suite et le rail
  devient une liste d'ancres. Après un Turbo Stream, le contrôleur **réapplique**
  la sélection (le panneau remplacé revient visible par défaut).
- **Pas de vignettes d'exercice dans le rail.** `Exercise.mediaUrl` est distant,
  le service worker ne l'intercepte pas (cross-origin) : ce serait un carré vide
  hors ligne. Numéro + icône d'activité.
- **Le chrono de séance dérive du `completedAt` de la première série loggée**, pas
  d'une colonne `startedAt` : ouvrir la page ne veut rien dire (on l'ouvre parfois
  la veille), et la valeur reste juste après un rechargement ou sur un autre
  appareil.
- **Le minuteur de repos est purement client** et disparaît sans JS — c'est le
  seul élément de la page dans ce cas, le pointage lui reste entier. Il se lance
  tout seul à la validation d'une série.
- **Une seule ligne de série = badge, effort, charge, coche.** Un seul badge : le
  type quand la série en a un (pastilles W/D/F/DS existantes), le rang sinon.

Sur la fiche datée `/schedule/{id}`, **le réalisé remplace le prévu** une fois la
séance pointée, sans que la prescription bouge : `_workout_read` accepte une carte
`logs` optionnelle qu'il fait descendre jusqu'à `_workout_sets_table`. Le prévu ne
subsiste qu'en repère **là où il diffère**. La page de bibliothèque et la page
publique ne passent rien et affichent la prescription — elles décrivent une séance
sans date, donc sans réalisé.

À ne pas casser non plus :

- **Une ligne = un `<form>`**, bouton de validation = vrai submit portant
  `name="op"`. Sans JS, taper la coche poste la ligne avec ses valeurs. Tout
  le socle est testé sans JavaScript (`ScheduledWorkoutExecutionTest`).
  **`op` et jamais `action`** : un contrôle nommé `action` masque la propriété
  `action` du `<form>`, donc `form.action` renvoie le bouton (ou un
  `RadioNodeList` quand la ligne en a deux) au lieu de l'URL. Tous les POST
  partaient sur `/schedule/114/[object HTMLButtonElement]`. Un test garantit
  qu'aucun contrôle de la page ne porte ce nom.
- **La file distingue « pas de réseau » de « le serveur refuse ».** Une exception
  `fetch` met en file et retentera ; une réponse non-2xx s'affiche et n'est PAS
  mise en file — sinon un bug serveur gonfle la file en silence derrière un
  message rassurant. Au rejeu, une entrée refusée est **abandonnée** : sans ça
  un seul geste invalide bloque définitivement tout ce qui le suit.
  La clé de file est **versionnée** (`kd-execlog-v2-{id}`), à incrémenter quand
  la forme d'un geste change : c'est le seul moyen d'abandonner une file déjà
  écrite dans `localStorage`.
- **Rien ne bouge sous le doigt** : valider ne change ni la hauteur de la ligne
  ni l'ordre, seulement des couleurs. Les deux icônes (cercle / coche) sont dans
  le DOM, le CSS choisit selon `.is-done` — c'est ce qui permet à l'affichage
  optimiste de basculer en changeant **une seule classe**, sans manipuler de SVG,
  et garde le rendu serveur et le rendu optimiste identiques.
- **L'écart ne s'affiche que s'il existe.** Sur 16 séries, une légende
  systématique coûte un écran.
- **Hors ligne, une seule exception dans `sw.js`** : `/schedule/<id>/execute`
  reste **network-first** (la règle « en ligne, le réseau gagne toujours pour du
  HTML » tient), seul son repli change — sa propre copie en cache, jamais
  `offline.html`. La branche est placée **avant** le test
  `request.mode === 'navigate'` : arriver par un lien de l'app est une visite
  Turbo Drive, donc un `fetch()` dont le mode n'est pas `navigate`.
- **La file d'écriture vit dans le contrôleur `execlog`, pas dans le SW** (qui ne
  touche à aucun POST) : un service worker qui rejouerait des mutations en
  arrière-plan serait invisible le jour où il se tromperait. `localStorage`, pas
  IndexedDB — quelques dizaines de champs par séance. Les gestes visant la même
  série s'écrasent dans la file : ce qui compte à la reconnexion est l'état final.
  Le conteneur porte `data-turbo="false"`, sinon Turbo posterait les validations
  en court-circuitant la file.
- **Hors périmètre assumé** : ajouter un exercice **imprévu** pendant la séance.
  Ça demanderait un réalisé autonome (non indexé sur la prescription). Logger
  plus ou moins de séries que prévu, en revanche, est couvert.

Lot précédent (une ligne par série) : sur la page de consultation d'une séance,
**une ligne = une série, quel que soit le mode de saisie**. « 3 × 15 @ 130 kg »
s'affiche en trois lignes identiques, comme trois lignes saisies à la main. Les
séries sont donc exposées par `PlanFlattener` sous **deux formes complémentaires**,
sur le modèle de `summary`/`values` :

- `sets` — vue **condensée** (`detailedSetGroups`), inchangée, réservée au mode
  détaillé : séries consécutives identiques fusionnées, rang réel conservé. Elle
  sert les contextes compacts (résumé, aperçu au survol, export, pastille).
- `setLines` — vue **déroulée**, une entrée par série, dérivée de la collection
  détaillée ou **synthétisée depuis le scalaire** (tout en `SetType::NORMAL`).
  C'est ce que consomme `_workout_sets_table`.

À ne pas casser : le déroulé est réservé à `SETS_REPS`/`SETS_TIME` (le `sets` d'un
`DISTANCE_PACE` compte des **intervalles**, pas des séries) et `sets` scalaire nul
retombe sur `values` — pas de tableau de « ? reps » pour un exercice pas encore
paramétré. L'en-tête de la ligne d'exercice se réduit au compte (« 4 séries ») dès
qu'un tableau le suit. Côté largeur, **deux colonnes sont conditionnelles**
(« % du max » si les charges varient, « Type » si une série est qualifiée), le
cadre est plafonné à `34rem` et sous 560px le tableau **se comprime au lieu de
défiler** — un défilement horizontal imbriqué dans une page qui ne défile pas
n'a aucun repère visuel.

Lot précédent (PWA installable) : la **Phase 9 est réactivée, mais amputée**. Ce
qu'on veut, c'est l'installabilité (icône, nom, écran de démarrage, plein écran) ;
ce qu'on ne veut plus, c'est le mode hors connexion complet, qui servait des pages
périmées *en ligne* et avait fait suspendre la phase. Règle qui tient tout :
**en ligne, le réseau gagne toujours pour du HTML.**

- **Le service worker reste indispensable** : Chrome n'offre l'installation que si
  un SW avec gestionnaire `fetch` est enregistré. `public/sw.js` est donc réécrit
  et n'intercepte que trois choses : `/assets/*` et `/pwa/*` en **cache-first**
  (URL digestées ou immuables), et les **navigations** (`request.mode === 'navigate'`)
  en **network-first** avec repli `offline.html`. Tout le reste sort du handler.
- **Corollaire à ne pas casser : Turbo n'est jamais intercepté.** Une visite Turbo
  Drive ou un Turbo Stream est un `fetch()` dont le `mode` n'est **pas** `navigate` ;
  un handler qui traiterait « tout le reste » en cache-first servirait des fragments
  périmés. C'était exactement le piège de la Phase 9.
- **Enregistrement conditionné côté serveur**, dans `base.html.twig` et non dans
  `app.js` : `app.environment == 'prod'` enregistre, tout autre environnement
  **désenregistre** ce qui traîne (un SW laissé par un test `APP_ENV=prod` sur
  localhost masquerait les modifications en dev). Tester en local = `APP_ENV=prod`.
- **Les visuels vivent dans `public/pwa/`, jamais `public/icons/`.** Apache déclare
  par défaut un `Alias /icons/` vers ses icônes d'autoindex, qu'on ne peut pas
  retirer sur mutualisé : `/icons/*` n'atteindrait jamais `public/`. `public/icons/`
  est supprimé.
- **`tools/build-pwa-icons.php` est la source des visuels** (comme
  `tools/fetch-fonts.sh` l'est des polices) : il part de `assets/icons/kadens.png`,
  isole le K **par composantes connexes** (les traits de vitesse chevauchent le K
  en abscisse, aucun recadrage rectangulaire ne les sépare) pour les icônes, garde
  le lockup complet pour les écrans de démarrage, et **génère aussi**
  `templates/components/_pwa_splash.html.twig`. Ce fragment est **généré, ne pas
  l'éditer à la main** : iOS exige une correspondance **exacte** de la media query,
  un lien sans fichier (ou l'inverse) donne un écran de lancement blanc. Un test
  (`PwaHeadTest`) vérifie que les deux n'ont pas divergé.
- **`viewport-fit=cover` ajouté à la `<meta viewport>`** : sans lui
  `env(safe-area-inset-bottom)` vaut 0, donc `--kd-navbar-h` ignore la barre
  gestuelle iOS et la nav basse passe dessous en mode standalone.
- Manifest repeint « Presse » (`theme_color` encre `#0b0b0b`, `background_color`
  papier `#ffffff`) + trois `shortcuts` (Calendrier / Séances / Plans).

Lot précédent (utilisabilité au téléphone) : la couche mobile existait mais était
annulée par **une déclaration CSS**. `backdrop-filter` sur `.kd-header` en faisait
le bloc conteneur de ses descendants `position: fixed` : la barre de nav basse se
calait sur le header (52px) au lieu du viewport — rognée en haut de l'écran, et
peinte par-dessus l'avatar, ce qui rendait tout le menu de compte inatteignable.
Neutralisé sous 560px, avec `--kd-navbar-h` comme source unique de la hauteur de
barre. Conséquences à ne pas casser :

- **Nav à 3 entrées** (Séances / Plans / Calendrier) : c'est le fil de la
  planification, et sous 560px chaque entrée doit rester tapotable. Les exercices
  vivent dans le menu de compte.
- **`GET /schedule/{id}`** : la séance dans son contexte **daté**, distincte de
  `app_workout_show` (bibliothèque, sans date). C'est la seule page qui porte la
  boucle prévu vs réalisé — bascule « fait », note d'écart, déplacer, retirer —
  et la cible du clic sur une pastille de calendrier. Elle réutilise
  `_workout_read` en `embed` ; attention, `only` **isole** le composant : ce que
  le bloc `actions` consomme doit passer par le `with`.
- **Le survol n'est jamais un chemin.** L'aperçu `popover="manual"` n'a pas de
  light-dismiss : au doigt, un tap émet un `mouseenter` sans `mouseleave` et le
  panneau reste collé. `preview` et `plangrid` se gardent derrière
  `(hover: hover) and (pointer: fine)`. La pastille de calendrier est donc un
  **lien** vers `/schedule/{id}`, intercepté par `dialog#openFine` au pointeur fin
  seulement (modale sur ordinateur, navigation au doigt), plus un œil à droite qui
  n'est jamais intercepté.
- **Rien de collant qui masque du contenu** : la barre « Éditer » revient en tête
  du hero.
- **Repli mobile en `<details>` rendu ouvert côté serveur**, refermé par le
  contrôleur `collapse` (filtres d'index). Sans JS, rien n'est caché.
- **Vue semaine par défaut au téléphone** : le cookie `kd_calview` est `httpOnly`,
  c'est le serveur qui expose `viewRemembered` et le contrôleur `calview`
  n'aiguille que la première visite.
- **Une surcharge responsive vit APRÈS la définition de son composant.** Une
  `@media` n'ajoute aucune spécificité : regroupées en tête de feuille avec la
  nav, trois surcharges étaient purement décoratives, écrasées par la règle de
  base du composant située plus bas (`.kd-page` par le raccourci `padding` de
  « Mise en page », `.kd-editform__bar` par son `bottom: 0`, `.kd-calday__add` par
  son `align-self: stretch`). Le dégagement de fin de page et la barre
  « Enregistrer » passaient donc sous la nav alors que le CSS semblait les
  traiter. Détail et règle dans `docs/design-system.md §5`.
- **`--kd-navbar-h` = place occupée, pas hauteur du dessin** : elle inclut
  l'`env(safe-area-inset-bottom)` que la barre prend en padding.

Lot précédent (portée coach des index) : `/workout` et `/plan-template` listent
aussi le contenu des athlètes suivis (cf. §3), via le service `CoachedLibrary` et
les variantes multi-propriétaires des repositories. Facette `owner` (« Moi » par
défaut) et badge de propriétaire sur les cartes des autres. Deux effets de bord
utiles : `_filterbar` accepte un `default` par groupe de facette, et le
contrôleur Stimulus `filter` lit désormais l'état initial des puces au lieu de
repartir d'un filtre vide.

Lot précédent (superset réel, intra-bloc) : le superset cesse d'être un effet de
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
