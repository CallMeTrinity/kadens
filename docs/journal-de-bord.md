# Journal de bord — Kadens

Historique détaillé de l'avancement, extrait de `CLAUDE.md` §6 pour ne pas
être rechargé à chaque session. Consigner ici chaque lot terminé (cf.
`CLAUDE.md` §7). Les règles d'architecture verrouillées restent dans `CLAUDE.md`.

---

## 6. État d'avancement

Socle Symfony en place (Docker, MariaDB, CI/CD). Design tokens posés.

- **Phase 1 — bibliothèque d'exercices : faite.** `Exercise` + CRUD, `ExerciseVoter`
  (perso vs global), auth, `ImportExercisesCommand`.
- **Phase 2 — séances : faite.** `Workout`/`Block`/`PrescribedExercise` (cascade +
  `orphanRemoval`), `SlugGenerator`, `WorkoutVoter`, éditeur de séance server-driven
  (mutations blocs/exercices via **Turbo Streams**, réordonnancement monter/descendre,
  affichage dynamique des champs par `prescriptionType` via le contrôleur Stimulus
  `prescription_fields`), et `PlanFlattener` (source unique de mise à plat) consommé
  par la vue de consultation auto-suffisante. Source unique des champs pertinents par
  type : `PrescriptionType::fields()`.
- **Phase 4 — partage lecture publique : faite.** `PublicShareController` (route
  publique `/s/{slug}`, hors `WorkoutVoter` : le lien slug vaut autorisation de
  lecture), rendu lecture seule extrait dans `templates/components/_workout_read.html.twig`
  (partagé par `workout/show` et `public_share/workout`), bouton « copier le lien »
  via le contrôleur Stimulus `clipboard`.
- **Phase 5 — templates de plans multi-semaines : faite.** `PlanTemplate`/`PlanItem`
  (cascade + `orphanRemoval` + `OrderBy` semaine/jour, lifecycle `createdAt`/`updatedAt`),
  `PlanTemplateVoter` (même logique perso/global que `WorkoutVoter`),
  `PlanTemplateController` (CRUD + duplication). Éditeur de trame server-driven :
  grille dense `durationWeeks` × 7 jours (ISO 1=lundi..7=dimanche), placement/retrait
  de `Workout` par case via **Turbo Streams** (position semaine/jour portée par la
  route, séances préchargées une fois pour toutes les cases). `PlanFlattener::flattenPlanTemplate`
  produit la grille dense (source unique consommée par le rendu et le futur export),
  rendu lecture dans `templates/components/_plan_read.html.twig`. Pas de migration :
  les tables existaient déjà, les ajouts sont purement ORM.
- **Phase 6 — calendrier & instanciation : faite.** `ScheduledWorkout` (lifecycle
  `createdAt`/`updatedAt` ajoutés) + enum `ScheduledStatus`, `ScheduledWorkoutVoter`
  (owner-only, pas de biblio globale ici). `PlanInstantiator` (service) projette une
  trame `PlanTemplate` sur des dates réelles : **ancrage au lundi ISO** de la semaine
  contenant la date de départ (un item « mercredi » retombe un mercredi) ; non
  idempotent (déclenchement explicite). `CalendarController` (vue mois navigable
  `/calendar/{year}/{month}`, grille dense semaines ISO lundi→dimanche construite
  côté contrôleur) + `ScheduledWorkoutController` (poser une séance isolée,
  instancier un plan, déplacer, retirer — chaque mutation redirige vers le mois
  concerné, pas de Turbo Stream ici). Forms `ScheduleWorkoutType` / `PlanInstantiationType`.
  Nav + `access_control` (`^/calendar`, `^/schedule` en `ROLE_USER`).
  **Migration** `Version20260722163844` : FK `ScheduledWorkout` en `ON DELETE` —
  `owner`/`workout` CASCADE (une séance datée n'a pas de sens sans eux),
  `sourcePlanTemplate` SET NULL (supprimer un plan garde le planning matérialisé,
  oublie juste la provenance).
- **Phase 7 — prévu vs réalisé : faite.** Boucle sur la prévision, pas de tracking
  détaillé. Depuis chaque case du calendrier : formulaire de statut (`PLANNED`/`DONE`/
  `MISSED` via `<select>`) + `completionNotes` (écart léger), posté vers
  `ScheduledWorkoutController::updateStatus` (`POST /schedule/{id}/status`, CSRF,
  redirect vers le mois — pas de Turbo Stream, cohérent avec le reste du calendrier).
  Vue de synthèse `SummaryController` (`/summary`, `/summary/{year}/{month}`,
  `access_control` `^/summary` en `ROLE_USER`) : observance du mois + par plan
  instancié (bucket « hors plan » pour `sourcePlanTemplate` null). Agrégats SQL dans
  `ScheduledWorkoutRepository` (`countByStatusForOwnerBetween`, `statusCountsByPlanForOwner`,
  `GROUP BY` — pas d'hydratation d'entités). Observance = `done / (done + missed)`,
  les séances encore prévues sont exclues du ratio. Fragment réutilisable
  `templates/components/_status_stats.html.twig` (barre proportionnelle + compteurs).
  Pas de migration : `status` et `completionNotes` existaient déjà sur l'entité.
- **Phase 8 — export Excel : faite.** PhpSpreadsheet installé. `ExcelExporter`
  (service) consomme `PlanFlattener` (aucune mise à plat réimplémentée) et produit
  un `Spreadsheet` pour une séance, un plan ou un planning daté. Le champ `summary`
  du flattener porte déjà le rendu lisible (mm:ss, allure, distance) grâce aux
  unités normalisées : l'export est un pur mapping, pas de parsing. Un unique
  writer privé (`writeWorkoutSection`) est réutilisé par les trois exports.
  Couleurs de l'identité « Carnet clair » reprises en dur en ARGB (les tokens CSS
  ne s'appliquant pas à un classeur). `ExportController` (mince) autorise via les
  voters existants (`WorkoutVoter::VIEW`, `PlanTemplateVoter::VIEW`) puis streame
  via `StreamedResponse` (writer -> `php://output`, pas de fichier temporaire) :
  `/export/workout/{id}`, `/export/plan-template/{id}`, `/export/schedule/{year}/{month}`
  (planning owner-only sur un mois, borné comme calendrier/synthèse). `access_control`
  `^/export` en `ROLE_USER`. Liens « Exporter en Excel » sur `workout/show`,
  `plan_template/show` et l'en-tête du calendrier. Pas de migration (aucun changement
  de schéma).
- **Phase 9 — PWA : SUSPENDUE (mode hors connexion mis de côté).** La contrainte
  offline (« pages auto-suffisantes, zéro AJAX post-chargement ») bridait la
  dynamisation des vues : on la lève pour l'instant. `app.js` **n'enregistre plus**
  de service worker et **désenregistre** ceux déjà installés + purge leurs caches
  `kadens-*` (un SW obsolète servait une page en cache et donnait l'illusion qu'il
  fallait recharger). `manifest`/métas PWA retirées de `base.html.twig` (seule la
  `theme-color` reste). Les fichiers `public/sw.js`, `public/manifest.json`,
  `public/offline.html`, `public/icons/` restent sur disque, inertes, pour une
  réactivation ultérieure. Les polices self-hostées et le reste sont conservés.
  *Ci-dessous, la description d'origine, à titre de référence pour la reprise.*
- **Phase 9 — PWA (référence, inactive).** App installable + consultation hors ligne. Fichiers
  statiques servis à la racine (hors AssetMapper, pour le scope) : `public/manifest.json`
  (nom, icônes 192/512 `any` + `maskable`, `theme_color` terracotta, `background_color`
  page, `display: standalone`), `public/sw.js` (service worker **écrit à la main**,
  pas de Workbox) et `public/offline.html` (repli autonome, styles inline). Icônes
  `public/icons/` : monogramme « K » crème sur terracotta, générées via GD (script
  jetable, non commité). **Service worker** : les assets digestés `/assets/*` sont
  immuables → **cache-first** ; les pages de consultation (`/workout/{id}`,
  `/plan-template/{id}`, `/s/{slug}`) → **stale-while-revalidate** (instantané +
  fraîcheur en fond, cohérent avec la « référence vivante ») ; autres navigations →
  **network-first** avec repli `offline.html` ; non-GET et cross-origin jamais
  interceptés. S'appuie sur la discipline « pages auto-suffisantes » tenue depuis la
  Phase 2. Enregistrement dans `app.js` (contexte sécurisé uniquement). **Polices
  self-hostées** : `assets/fonts/*.woff2` (subsets latin + latin-ext, 3 familles ×
  graisses déclarées) + `assets/styles/fonts.css` (`@font-face`, importé par `app.css`,
  `url()` réécrites par AssetMapper) ; plus aucune dépendance Google Fonts (liens
  retirés de `base.html.twig`, remplacés par les métas PWA). Pas de migration.

Toutes les phases du ROADMAP sont livrées. Prochaines pistes hors-roadmap : premier
déploiement PWA sur `kadens.antoninpamart.fr` (HTTPS requis pour le service worker) et
vérification manuelle Lighthouse/installabilité + navigation offline réelle en
navigateur (non automatisable ici).

- **Design & finitions (en cours).** Ouverture de la couche visuelle « Carnet
  clair » sur des vues jusqu'ici brutes. **Fondation CSS réutilisable** :
  `assets/styles/components.css` (importé par `app.css`) porte les composants
  partagés — header, boutons (`.kd-btn--primary/secondary/ghost`), cartes
  (`.kd-card`), badges/statuts (`.kd-badge--run/gym/done/planned/missed`), stats,
  cartes de nav (`.kd-navcard`), grilles (`.kd-grid--2/3/4`), page (`.kd-page`).
  Tout piloté par les tokens, zéro couleur/police en dur. **Header réutilisable**
  `templates/components/_header.html.twig` (marque + nav à icônes Lucide + état
  actif déduit du préfixe de route + user/déconnexion), inclus par `base.html.twig`
  sous `if app.user`. **Page d'accueil** : `HomeController` (route `/` = `app_home`,
  redirige vers login si anonyme), dashboard `templates/home/index.html.twig`
  (prochaines séances sur 14 j via `findByOwnerBetween`, observance du mois via
  `countByStatusForOwnerBetween`, compteurs biblio, raccourcis sections). Rendu
  auto-suffisant (cachable offline). **Bibliothèque d'exercices stylée** : index
  (grille de `.kd-libcard`, recherche client offline-safe via contrôleur Stimulus
  `filter`), show (`.kd-deflist`), new/edit + suppression. **Formulaires stylés
  globalement** : thème `templates/form/kadens_theme.html.twig` (enregistré dans
  `config/packages/twig.yaml`) applique les classes `.kd-*` à tous les champs du
  site — les nouvelles vues n'ont plus à styler leurs champs. Nouveau composant
  Twig transverse `templates/components/_activity.html.twig` (macros `badge`/`icon`/
  `modifier`, source unique icône↔couleur par `ActivityType`). Classes ajoutées à
  `components.css` : `.kd-libcard`, `.kd-tag(s)`, `.kd-deflist`, `.kd-toolbar`/
  `.kd-search`/`.kd-count`, `.kd-flash`, `.kd-backlink`, `.kd-btn--danger`, la
  couche formulaire. **Page de connexion stylée** : `<main>` à classe surchargeable
  (bloc `main_class` dans `base.html.twig`, défaut `kd-page`) pour sortir le login du
  chrome applicatif ; couche `.kd-auth` dans `components.css` (écran centré plein
  hauteur, carte, champ à icône `.kd-inputgroup`, case `.kd-check`, `.kd-btn--block`,
  bloc erreur d'auth) ; template `security/login.html.twig` réécrit. **Séances stylées** :
  index (grille de `.kd-libcard` + recherche offline-safe via `filter`), consultation
  (`_workout_read.html.twig` refait : en-tête `.kd-workouthead` avec badges durée/blocs/
  activités distinctes, blocs en cartes `.kd-block` + liste d'exercices numérotée
  `.kd-exlist`, rôle de bloc différencié par icône seule — couleur neutre pour ne pas
  empiéter sur terracotta/olive ; partagé par `workout/show` et la page publique),
  `show` (barre `.kd-actionbar` : retour + éditer/Excel/copier-lien/page publique),
  new (carte formulaire), éditeur (`.kd-editblock`, champs role/rounds/label alignés
  `.kd-fieldrow`, actions déplacer/supprimer en boutons-icônes `.kd-iconbtn` via
  `_action_form` refait icône+variant, ajout d'exercice en `<details>` `.kd-adddetails`,
  carte d'ajout de bloc `.kd-addblock`). Icônes importées : `flame`/`activity`/`wind`
  (rôles), `clock`, `chevron-up`/`chevron-down`, `x`, `save`, `link-2`, `file-down`.
  **Plans stylés** : index (grille de `.kd-libcard` + recherche offline-safe via
  `filter`), consultation (`_plan_read.html.twig` refait : en-tête `.kd-workouthead`
  avec badges durée/nb séances, puis trame en cartes `.kd-planweek` → grille dense
  7 jours `.kd-plangrid` de cases `.kd-planday`, séance placée en lien
  `.kd-planday__item`, jour sans séance affiché « Repos » ; partagé par `show`),
  `show` (barre `.kd-actionbar` : retour + éditer/Excel), new (carte formulaire),
  éditeur (`_grid.html.twig` : même grille en variante `.kd-plangrid--edit`, séance en
  `.kd-planitem` avec retrait `.kd-planitem__del`, ajout par case en `<details>`
  `.kd-planadd`, sections infos/dupliquer/zone dangereuse en `.kd-editsection`,
  suppression `.kd-btn--danger`). La grille bascule en agenda vertical (jour en ligne)
  à 900px en lecture ; en édition elle l'est désormais toujours (cf. commentaire de
  `.kd-plangrid--edit`). Le breakpoint 1024px mentionné ici n'a jamais existé dans le
  CSS, et les valeurs ont été ramenées aux trois paliers du design system lors de la
  refonte « Presse ». Couleur neutre (trame multi-activités). Icônes
  importées : `copy`, `calendar-range`.
  **Calendrier stylé** : `calendar/index.html.twig` refait. En-tête `.kd-pagehead`
  (eyebrow « Planning » + mois) avec nav prev/aujourd'hui/suivant + export en
  `.kd-btn`. Ajout (poser une séance / instancier un plan) en deux replis
  `.kd-caladd` (`.kd-calbar`). Grille mensuelle `.kd-cal__grid` (7 colonnes, cadre
  `overflow-x` défilable + `min-width` : la structure hebdo tient sur mobile plutôt
  que de s'écraser) : en-têtes `.kd-cal__dow`, cases `.kd-calday` (`--out` hors mois,
  `--today` = numéro en pastille terracotta). Séances datées en pastilles
  `.kd-calevent--planned/done/missed` (filet gauche = statut : **seul cas où la
  couleur code l'état, pas l'activité** ; fait = titre barré). Chaque pastille est
  un bouton ouvrant la **modale** d'édition (statut prévu/fait/manqué +
  `completionNotes`, déplacer, retirer) — les cases restent lisibles malgré la
  densité. **Composant modale réutilisable créé** : élément natif `<dialog>` +
  contrôleur Stimulus `dialog` (`assets/controllers/dialog_controller.js` :
  open/close/backdrop, promotion en top-layer donc non rognée par l'`overflow` de la
  grille) ; purement client, aucun AJAX, formulaires déjà dans la page (offline-safe).
  Classes `.kd-modal*` dans `components.css`. Suppression toujours par `confirm()`
  natif (dans la modale). Icône importée : `calendar-clock` (déplacer). Variante
  `.kd-flash--error` ajoutée.
  **Pastille de séance en deux zones (raffinage).** La pastille `.kd-calevent`
  n'est plus un seul bouton mais un conteneur à deux zones cliquables : **gauche**
  (`.kd-calevent__toggle`, dans un `<form>` `.kd-calevent__statusform`) = cycle
  rapide du statut prévu → fait → manqué → prévu ; **droite**
  (`.kd-calevent__open`) = ouvre la modale. Le cycle passe par un endpoint dédié
  `ScheduledWorkoutController::cycleStatus` (`POST /schedule/{id}/cycle-status`,
  CSRF `cycle{id}`) qui avance le statut via `ScheduledStatus::next()` (nouvel
  enum method) et **préserve la note d'écart** (contrairement à `updateStatus`) —
  c'est un geste express. Repli sans JS : vrai bouton de formulaire. L'icône du
  toggle et son filet gauche codent le statut (`circle-dot`/`check`/`x`).
  **Modale améliorée** : en-tête + `.kd-modal__meta` (badge de statut + lien
  « Voir la séance » en `.kd-btn--ghost`), puis section Statut refaite en
  **segmenté** `.kd-segmented`/`.kd-segbtn--planned/done/missed` : trois boutons
  submit (`name="status"`) marquant fait/manqué/prévu en un clic, la note
  `completionNotes` partagée part avec (plus de `<select>` + bouton Enregistrer).
  Sections Déplacer et Retirer inchangées. Icônes déjà locales (`check`, `x`,
  `circle-dot`). Pas de migration.
  **Aperçu au survol au calendrier.** Comme dans l'éditeur de plan, survoler une
  pastille affiche le contenu de la séance (blocs/exercices) via le composant
  partagé `_plan_preview.html.twig` + le contrôleur Stimulus `preview` (Popover
  API, top-layer, échappe à l'`overflow-x` de la grille, aucun AJAX). La pastille
  porte `data-controller="dialog preview"` + `mouseenter/leave`. `CalendarController`
  construit une map `flattened` (id de `Workout` → `PlanFlattener::flattenWorkout`,
  une par séance distincte du mois, `??=` anti-doublon) passée au template ;
  le panneau reçoit `fw: flattened[scheduled.workout.id]`. Source unique de mise à
  plat inchangée. Pas de migration.
  **Synthèse stylée** : `summary/index.html.twig` refait. En-tête `.kd-pagehead`
  (eyebrow « Synthèse » + titre + `.kd-lead`) avec nav prev/ce mois/suivant +
  retour calendrier en `.kd-btn`. Observance du mois en carte mise en avant
  `.kd-summonth` (filet terracotta), détail par plan instancié en grille
  `.kd-grid--2` de cartes `.kd-summplan` (bucket « hors plan » différencié).
  **Composant `_status_stats` refait** : passe des styles inline aux classes
  `.kd-observance*` (grand pourcentage `--hero` pour le mois, barre proportionnelle
  `.kd-obar` dimensionnée par `flex`, légende `.kd-olegend` pastille+compteur ;
  couleur = statut fait/prévu/manqué, seul cas hors activité). Icônes importées :
  `calendar-check`, `calendar-off`, `calendar`. Toutes les vues sont désormais stylées.
  **Compositeur de séance (éditeur refait, maquette 3a « création rapide par
  glisser-déposer »).** L'éditeur `workout/edit` passe d'une pile de formulaires à
  un compositeur deux volets, **sans changer le modèle server-driven**. Mise à jour
  dynamique **par Turbo Stream appliqué à la main** : la `<section>` porte
  `data-turbo="false"` (Turbo n'intercepte aucun formulaire du compositeur), et le
  contrôleur `composer` capte toute soumission (bouton réel OU `requestSubmit` des
  formulaires cachés), fait un `fetch` explicite en `Accept: text/vnd.turbo-stream.html`,
  puis `renderStreamMessage` applique le flux au DOM. On ne dépend PAS du routage de
  formulaire de Turbo (les frames échouaient sur les formulaires hors conteneur : une
  soumission out-of-frame dégénérait en visite de page). `blocksResponse` renvoie un
  `<turbo-stream action="update" target="workout-blocks">` (`update` = on remplace le
  CONTENU du `<div id="workout-blocks">`, l'id survit à chaque mutation) quand
  `getPreferredFormat()` vaut stream, sinon redirection (repli sans JS).
  **Piège majeur corrigé (« il faut recharger pour voir l'ajout ») :** les endpoints
  d'ajout ne posaient que le côté propriétaire (`$prescribed->setBlock($block)`,
  `$block->setWorkout($workout)`), donc la collection inverse en mémoire restait
  périmée et le stream re-rendu dans la foulée ne montrait pas l'élément (visible
  seulement après rechargement, quand Doctrine relit la base). On passe désormais par
  `Block::addPrescribedExercise` / `Workout::addBlock` qui maintiennent **les deux
  côtés**. Vaut pour `addBlock`, `addPrescribed` et `quickAddPrescribed`. Volet gauche = bibliothèque
  (`_composer_library.html.twig`, exercices perso+globaux via `findLibraryForUser`) :
  recherche + filtres d'activité **100 % client offline-safe** (portés par le
  contrôleur Stimulus `composer`, pas par `filter`), et ajout par bouton `+` (bloc
  actif) **ou glisser-déposer** dans un bloc. Volet droit = les blocs en cartes
  `.kd-cblock` : en-tête inline (rôle `<select>` + libellé auto-soumis sur `change`,
  stepper de tours `− ↻ N +`, monter/descendre, supprimer) ; exercices en lignes
  compactes `.kd-cexo` (poignée, code 2 lettres teinté par activité, nom, pastille
  résumé issue de `PlanFlattener`, `⚙` dépliant le panneau de paramètres = form
  prescrit inline + réordonner). Deux nouveaux endpoints minces sur `WorkoutController`,
  tous deux renvoyant le stream des blocs : `prescribed_quick_add` (POST exerciseId+
  blockId, type par défaut `SETS_REPS`, à affiner ensuite) et `prescribed_reorder`
  (POST prescribedId+targetBlockId+afterId, gère le déplacement intra/inter-blocs et
  renumérote les positions de 0..n). Le glisser-déposer et le stepper sont de la
  **progressive enhancement** (contrôleur `composer_controller.js`, deux formulaires
  cachés hors `#workout-blocks` — donc préservés par la mise à jour — porteurs du
  jeton CSRF + URL, soumis par le JS) : sans JS, monter/descendre
  et les boutons de sauvegarde restent le repli fonctionnel. `PlanFlattener` reste la
  source unique du résumé (aucune mise à plat réimplémentée). Couche CSS `.kd-composer*`
  / `.kd-libpanel*` / `.kd-libx*` / `.kd-cblock*` / `.kd-cexo*` + `.kd-page--wide`
  ajoutée à `components.css`, tout tokenisé. Icônes importées : `repeat`,
  `grip-vertical`, `sliders-horizontal`, `eye`, `settings-2`. Pas de migration.
  **Affinages éditeur (lot d'ergonomie).** Sans changement de schéma ni de modèle
  server-driven : (1) **durée estimée dérivée du contenu** — nouveau service
  `WorkoutEstimator` (10 reps ≈ 1 min, repos par défaut 2 min si absent, sommée par
  bloc × tours ; distance×allure via mètres/allure). Le champ `estimatedDurationMinutes`
  n'est plus saisi (retiré de `WorkoutType` et de l'éditeur) : il est recalculé et
  persisté à chaque mutation dans `blocksResponse`. Toutes les vues lisant
  `workout.estimatedDurationMinutes` restent valides. (2) **Allure saisie en min/km**
  (`m:ss`, ex. `5:30`) via un `PaceType` (form type, `CallbackTransformer` vers/depuis
  les secondes/km stockées) — l'utilisateur ne convertit plus en secondes. (3) **Type
  d'effort par défaut déduit de l'activité** à l'ajout express (`defaultPrescriptionType` :
  course/vélo/natation → `DISTANCE_PACE`, sinon `SETS_REPS`). (4) **Duplication de
  séance** : route `app_workout_duplicate` (POST + CSRF) copie profonde blocs→exercices
  (cascade persist), nouveau slug `… (copie)`, redirige vers l'éditeur ; boutons sur
  `show` et l'index. (5) **Ordre des blocs re-rendu dynamiquement** : `_blocks.html.twig`
  trie les blocs par position en mémoire (même piège `#[OrderBy]` que les exercices, cf.
  mémoire projet). (6) **Suppressions sans `confirm()` et asynchrones** (bloc + exercice) :
  paramètre `confirm` retiré de `_action_form`. (7) **Correctif glisser-déposer** :
  relâcher immédiatement une carte de bibliothèque ne la fait plus disparaître
  (`onLibDrop` ne retirait plus `evt.item` — la carte d'origine — dans le cas « pas de
  dépôt réel »). (8) Libellé repos « Repos » (au lieu de « Repos après ») et repos
  exposé dans la pastille résumé de l'éditeur. (9) Fix CSS `.kd-cblock__role` (padding
  droit pour la flèche du `<select>`, libellé de rôle plus tronqué). Icônes : `copy`
  déjà importée. Pas de migration.
  **Éditeur de plan (refonte, notion de progression).** L'éditeur de trame passe au
  compositeur : glisser-déposer, duplication de semaine, édition rapide, progression
  indépendante par case, et plan vivant sur le calendrier. **Modèle** :
  `Workout.planLocal` (copie privée d'une case, exclue de la biblio),
  `ScheduledWorkout.sourcePlanItem` (case source, `ON DELETE SET NULL`) +
  `planAnchorDate` (ancre d'instanciation). Migration `Version20260724120000`.
  **Services** : `WorkoutCloner` (copie profonde unique, réutilisée par la
  duplication de séance, la pose dans un plan et la duplication de semaine ;
  recalcule la durée estimée) ; `PlanScheduler` (remplace `PlanInstantiator`,
  instanciation **idempotente** + `resync` add-only préservant le réalisé, cf. §3).
  **Contrôleur** (`PlanTemplateController`) : `addItem` clone la séance choisie
  (fork à la pose) + resync ; `deleteItem` retire les séances datées `PLANNED`,
  préserve `DONE`/`MISSED`, nettoie la copie orpheline ; `duplicateWeek` (POST) copie
  une semaine sur la suivante (clones indépendants) + resync ; `moveItem` (POST,
  glisser-déposer) change semaine/jour **et réaligne les séances datées encore
  `PLANNED` sur la nouvelle date** (`PlanScheduler::rescheduleItem`, ancre =
  `planAnchorDate` ; `DONE`/`MISSED` conservées, leur date = réalisé) ; la duplication de
  plan clone aussi les copies (plans indépendants). Le stream de grille passe en
  `action="update"` (l'id `#plan-grid` survit aux mutations, même piège que
  `#workout-blocks`). **Front** : contrôleur Stimulus `plangrid` (SortableJS
  inter-cases via une poignée `.kd-planitem__handle` ; sur dépôt, POST `fetch` +
  `renderStreamMessage`) qui porte aussi la **mini-modale d'édition rapide** (cf.
  section suivante). Amélioration progressive : sans JS, poser/retirer/dupliquer
  par formulaire reste le repli (glisser-déposer et édition rapide requièrent JS).
  Classes CSS `.kd-planitem__handle/__title/__meta`, `.kd-planweek__dup`.
  Icônes déjà locales (`copy`,
  `grip-vertical`, `clock`, `sliders-horizontal`, `x`). **Limite connue** : supprimer
  une séance datée issue d'un plan directement au calendrier peut la voir réapparaître
  au prochain `resync` (la case existe toujours) — pour l'enlever pour de bon, retirer
  la case du plan. Re-instancier un plan déjà posé ignore la nouvelle date de départ
  (une seule instance vivante par plan) : vider d'abord le planning pour ré-ancrer.
  **Retrait rapide d'un plan instancié (calendrier).** Repli `.kd-caladd`
  « Retirer un plan du planning » dans la `.kd-calbar`, listé seulement s'il existe
  au moins une instance (`ScheduledWorkoutRepository::findInstantiatedPlansForOwner`,
  `DISTINCT` sur `sourcePlanTemplate`). `ScheduledWorkoutController::clearPlan`
  (`POST /schedule/plan/clear`, CSRF `clear_plan`, `planId` + `year`/`month` dans le
  corps → redirige vers le mois affiché) supprime **toutes** les séances datées du
  plan, **y compris DONE/MISSED** (action explicite et globale, contrairement au
  retrait d'une case qui préserve le réalisé). Le `PlanTemplate` et ses copies locales
  sont conservés : seule l'instanciation calendrier disparaît. C'est le moyen direct
  de « vider le planning pour ré-ancrer ». Amélioration progressive : `planId` passe
  par le corps du formulaire (pas l'URL), donc le repli sans JS marche ; garde-fou
  `confirm()`. Voter `PlanTemplateVoter::VIEW` + filtre `owner` sur la requête. Pas de
  migration.
  **Édition rapide au plan : mini-modale inline (remplace l'iframe).** Cliquer une
  séance dans l'éditeur de plan n'ouvre plus le compositeur complet en iframe
  `?embed=1` mais une **mini-modale** ciblée sur les valeurs. Le contrôleur
  `plangrid` charge en `fetch` le panneau des exercices de la copie locale
  (`app_workout_quick_panel` → fragment `workout/_quick_panel.html.twig`, sans
  layout) dans `#quick-panel` : exercices groupés par bloc, chacun en `<details>`
  dépliant son formulaire `PrescribedExerciseType` (reps/séries/charge/repos…, champs
  pilotés par `prescription-fields`). Enregistrer poste vers
  `app_workout_prescribed_quick_edit` (`POST /workout/{id}/exercises/{prescribedId}/quick-edit`)
  qui renvoie `workout/stream/quick_panel.stream.html.twig` (`action="update"` sur
  `#quick-panel`, même piège d'id que `#workout-blocks`) : recalcule la durée
  estimée, re-rend le panneau (pastille résumé à jour). La modale porte
  `data-turbo="false"` ; `plangrid` intercepte les soumissions **du panneau
  uniquement** (`panelTarget.contains(form)`) — les formulaires de trame gardent leur
  repli natif. Un lien **« Édition complète »** (`data-full-url`) renvoie au
  compositeur pour la structure (blocs, ordre, glisser-déposer). À la fermeture, la
  page est rechargée **seulement** si un enregistrement a eu lieu (`this.dirty`), pour
  refléter durée/titre sur les cases. Le contrôleur réutilise
  `createPrescribedForm($prescribed, $route)` (paramétré par la route d'action) et un
  nouveau `quickPanelContext`. **Mode `embed`/iframe supprimé** (mort) :
  `base.html.twig`, `workout/edit.html.twig`, classes `.kd-modal--wide/__frame` et
  `.kd-page--embed` retirées. Nouvelles classes `.kd-modal--quick`,
  `.kd-modal__card--quick/__headactions`, `.kd-quickedit*`, `.kd-quickblock*`,
  `.kd-quickexo*`. Icône `square-pen` (déjà locale). Pas de migration.
  **Lot UX éditeur de plan (amont, sans migration).** Sept axes, tous en amélioration
  progressive et sans changer le modèle server-driven ni le schéma :
  1. **Palette de séances + mode tampon** (remplace le `<select>` par case, illisible à
     200 séances). Volet gauche `_palette.html.twig` (recherche + filtres d'activité
     100 % client, calqué sur `_composer_library`), cartes `.kd-palettecard`. Poser :
     **armer** une séance (clic) puis **tamponner** les cases (clic), ou glisser-déposer
     (Sortable clone, même groupe `kd-plan-workouts` que les cases). Nouvel endpoint
     `app_plan_template_item_place` (POST workoutId+week+day) partageant
     `placeWorkoutInCell` avec `addItem` (fork à la pose inchangé). Le `<details>` d'ajout
     par case reste le **repli sans JS**. Contexte `paletteContext()` chargé une fois au
     rendu (hors flux de grille), via `WorkoutRepository::findLibraryForOwnerWithContent`
     (fetch-join anti N+1).
  2. **Détail de case + aperçu au survol.** `flattenWorkout` enrichi (additif) de
     `activities` (distinctes) + `exerciseCount` via nouveau `WorkoutMetrics`. Les cases
     montrent badges d'activité (icône seule) + nb d'exos. Au survol, aperçu blocs/
     exercices promu en **top-layer via Popover API** (`popover="manual"`, positionné en
     JS) pour échapper à l'`overflow` de la grille — le clic reste l'édition rapide.
  3. **Édition en ligne « semi-invisible ».** L'encadré Informations disparaît :
     titre/description s'éditent en cliquant l'en-tête (contrôleur générique
     `inline_edit_controller.js`, endpoint `app_plan_template_meta` renvoyant la valeur
     nettoyée en texte brut). Idem pour la **note de case** (`app_plan_template_item_note`).
     Repli sans JS : `<details>` « Modifier les infos (formulaire) » avec le
     `PlanTemplateType` complet.
  4. **Gestion des semaines en ligne.** `app_plan_template_week_add` /
     `app_plan_template_week_remove` (détache les cases via nouveau helper `detachItem` —
     factorisé avec `deleteItem`, préserve DONE/MISSED —, décale les semaines suivantes et
     **réaligne le calendrier** via `PlanScheduler::rescheduleItem`).
  5. **Dupliquer une semaine vers une cible libre.** `app_plan_template_week_copy` (POST
     `target`) remplace `duplicateWeek` (S+1) : clone les cases vers la semaine choisie
     (copies `planLocal` indépendantes), **remplace** le contenu de la cible.
  6. **Volume par semaine ventilé par activité** (demande utilisateur). Nouveau
     `PlanVolumeAggregator::byWeek` (consomme `WorkoutMetrics::volume`) : salle = séries
     par groupe musculaire (attribuées à chaque `targetArea`, × tours) + tonnage ;
     course/vélo/natation = distance/durée. Affiché en chips dans l'en-tête de semaine +
     détail dépliable. `UnitFormatter` **extrait de `PlanFlattener`** (source unique
     km/mm:ss/allure, `PlanFlattener` délègue).
  7. **Partage public du plan** (comme les séances). `PublicShareController::plan`
     (`/s/plan/{slug}`) + `planWeeks` (`/s/plan/{slug}/semaines/{de}-{à}`, plage stateless
     encodée dans l'URL). `_plan_read.html.twig` prend `public`/`weeks` (séances
     cliquables vers leur page publique `/s/{slug}`, filtre de semaines via `|filter`).
     Boutons copier-lien/page-publique + sélecteur de plage (`share_range_controller.js`)
     sur `plan_template/show`. `PlanTemplate` a déjà un slug (garde-fou `ensureSlug` sur
     show/edit), donc **aucune migration**. Reste sous `/s` : pas de changement
     `security.yaml`.
  Nouveaux services : `WorkoutMetrics`, `UnitFormatter`, `PlanVolumeAggregator`. Nouveaux
  contrôleurs Stimulus : `inline_edit`, `share_range` (plus extensions de `plangrid` :
  filtre client, armer/tamponner, drag palette, aperçu). Nouvelles classes CSS
  `.kd-planeditor`, `.kd-palettecard*`, `.kd-cellbadges`, `.kd-planpreview*`,
  `.kd-inlineedit*`, `.kd-planweek__tools/__copy/__select/__add`, `.kd-weekvol*`,
  `.kd-sharerange*`. Icônes ajoutées : `calendar-plus`, `external-link` (autres déjà
  locales). Tests unitaires `WorkoutMetricsTest`. **Limite** : le retrait/copie de semaine
  suit la règle « préserver le réalisé » (supprime les datées `PLANNED`, garde
  `DONE`/`MISSED`) ; comme ailleurs, une case portée par la trame peut réapparaître au
  `resync` tant qu'elle existe.
  **Correctifs éditeur de plan + allure par activité (sans migration).**
  (1) **Placement uniquement par la palette** : le `<details>` « + Séance » par case
  (repli sans JS) et son `PlanItemType` sont supprimés (redondants avec armer/tamponner
  + glisser-déposer). Route `app_plan_template_item_add`, `createAddItemForm`,
  `addItemForms` et `PlanItemType.php` retirés ; il ne reste que
  `app_plan_template_item_place`. Poser une séance requiert donc JS (choix assumé).
  (2) **Grille d'édition en agenda vertical permanent** (`.kd-plangrid--edit` : un jour
  par ligne, quelle que soit la largeur) : dans le volet contraint par la palette, une
  grille 7 colonnes était illisible. (3) **Tampon sur case occupée** : en mode armé
  (`.is-arming`), les séances posées passent en `pointer-events:none` pour que tout le
  cadre de la case (agrandi + padding) tamponne, au lieu du seul espace vide.
  (4) **Aperçu au survol en lecture** : le popover de contenu (blocs/exercices) est
  extrait dans `templates/components/_plan_preview.html.twig` (attend `fw` =
  `flattenWorkout`), réutilisé par l'éditeur (`_grid`) ET la consultation
  (`_plan_read`, éditeur + page publique) via un nouveau contrôleur Stimulus léger
  `preview` (Popover API, positionnement top-layer, aucun AJAX). (5) **Allure dans
  l'unité naturelle de l'activité** : nouvel enum `PaceUnit` (min/km course, km/h vélo,
  min/100m natation) portant la conversion aller/retour depuis les secondes/km stockées
  (unité normalisée inchangée en base). `PaceType` prend une option `unit` ;
  `PrescribedExerciseType` la déduit de l'activité de l'exercice prescrit (option
  `activity`, dérivée dans `WorkoutController::createPrescribedForm`/
  `createAddPrescribedForm`) et adapte label/placeholder. `UnitFormatter::pace` et
  `PlanFlattener::summarizeDistancePace` formatent via `PaceUnit::forActivity(...)`
  (l'export Excel en hérite). Tests : `PlanFlattenerTest::paceUnitCases`.
  (6) **Distance dans l'unité naturelle de l'activité** (pendant de la 5) : enum
  `DistanceUnit` (km course/vélo, mètres natation et reste) + `DistanceType` (option
  `unit`), câblés dans `PrescribedExerciseType` via la même option `activity`. Le
  stockage reste en mètres ; l'AFFICHAGE ne change pas (`UnitFormatter::distance` : m
  sous 1 km, km au-delà, déjà lisible). Tests : `DistanceUnitTest`.
  **Calendrier : vue semaine + refonte (sans migration).** Le calendrier a
  désormais deux vues, basculables via un segmenté « Mois / Semaine »
  (`.kd-viewtoggle` dans un nouvel en-tête `.kd-calhead`), server-driven et
  auto-suffisantes (aucun AJAX). **Vue semaine** : `CalendarController::week`
  (`/calendar/week/{date}`, ancrage au lundi ISO de la semaine contenant la date ;
  `/calendar/week` sans date → aujourd'hui) rend `calendar/week.html.twig` — grille
  7 jours (`.kd-week`/`.kd-weekday`, agenda vertical < 900px), cartes de séance
  détaillées, jour vide « Repos », aujourd'hui/week-end/passé différenciés. Un
  **bandeau de synthèse** `.kd-weekbar` (nb de séances, volume estimé cumulé,
  observance `done/(done+missed)`, chips fait/prévu/manqué) réutilise
  `countByStatusForOwnerBetween` sur la fenêtre lundi→dimanche. Le pivot mois de la
  bascule/export part du jeudi de la semaine (règle ISO). **Composant partagé**
  `templates/components/_cal_event.html.twig` : la pastille + sa modale (extraites de
  `calendar/index`, dédupliquées) sont désormais consommées par les deux vues ;
  paramètre `detailed` (carte haute de la semaine : nb d'exos + marqueur « En
  retard »), paramètre `overdue`. La pastille montre désormais une **méta** (icônes
  d'activité teintées run/gym + durée estimée) tirée de `PlanFlattener::flattenWorkout`
  (déjà chargé). **Barre d'ajout** extraite en `calendar/_addbar.html.twig` (poser /
  instancier / retirer un plan), partagée mois+semaine. **Polish vue mois** : colonnes
  week-end teintées, séance passée encore `PLANNED` marquée (filet gauche terracotta
  pointillé = action à mener). Contrôleur factorisé (`buildAddContext`,
  `formatWeekLabel`). Nouvelles classes CSS `.kd-calhead`, `.kd-viewtoggle*`,
  `.kd-calevent__meta/__act/__dur/__exos/__flag`, `.kd-calevent--detailed`,
  `.kd-calday--weekend`, `.kd-weekbar`, `.kd-weekstat*`, `.kd-weekchip*`, `.kd-week`,
  `.kd-weekday*`. Icônes déjà locales (`calendar-days`, `calendar-range`, `clock`,
  `calendar-clock`…). Pas de migration.
  **Ajout au calendrier : modales à cartes (remplacent les dropdowns, sans migration).**
  Les `<details>` « Poser une séance » / « Instancier un plan » de `_addbar` (dropdowns
  natifs, illisibles à beaucoup de séances) laissent place à de vraies modales à cartes
  cherchables/triables/filtrables, calquées sur la palette de l'éditeur de trame.
  **Poser une séance** : plus de bouton en tête ; chaque case de jour porte un « + »
  (`.kd-calday__add`, révélé au survol / focus, toujours atténué en tactile) qui ouvre
  la modale et fixe la date. Les séances de biblio y sont des **cartes-boutons submit**
  (`.kd-palettecard--btn`) : clic = pose immédiate via l'endpoint lean
  `ScheduledWorkoutController::place` (`POST /schedule/place`, CSRF `schedule_place`,
  `workoutId`+`date`, refuse un `planLocal`, `WorkoutVoter::VIEW`) — calqué sur
  `app_plan_template_item_place`. L'ancien `add()` + `ScheduleWorkoutType` sont
  **supprimés**. **Instancier un plan** garde un bouton en tête mais ouvre une modale :
  cartes de plans (clic = sélection, pilote le `<select>` caché du `PlanInstantiationType`),
  puis date de départ + « Instancier » (submit désactivé tant qu'aucun plan choisi).
  Recherche/tri/filtre d'activité **100 % client** via le nouveau contrôleur Stimulus
  `caladd` (`assets/controllers/caladd_controller.js`) ; les outils (recherche, tri,
  filtres) vivent **hors du `<form>`** pour qu'un Entrée ne déclenche pas de pose
  accidentelle. Le wrapper `data-controller="caladd"` (posé par `calendar/index` et
  `calendar/week`) englobe l'`_addbar` (qui porte les deux `<dialog>`) **et** la grille
  (ses « + »). « Retirer un plan » reste un `<details>` inchangé. Comme le reste du
  calendrier : redirection vers le mois, pas de Turbo Stream ; poser/instancier requiert
  JS (choix assumé, cf. palette de plan). Nouveaux repères de carte construits dans
  `CalendarController::buildPickerCards` (via `WorkoutMetrics` +
  `findLibraryForOwnerWithContent`, anti N+1). Classes CSS `.kd-calday__add(--week)`,
  `.kd-modal--picker`, `.kd-modal__card--picker`, `.kd-picker*`, `.kd-palettecard--btn`,
  `.kd-weekday__right`. Icônes déjà locales (`plus`, `layers`, `search`, `calendar-plus`…).
  Pas de migration.
  **Changement de statut asynchrone + vue mémorisée (sans migration).** Le
  changement de statut d'une séance datée (cycle rapide de la pastille ET
  segmenté de la modale) ne recharge plus la page : il répond en **Turbo Stream**
  `action="replace"` sur `#cal-event-{id}` (nouvel id porté par le composant
  `_cal_event`, template `calendar/stream/cal_event.stream.html.twig`). Turbo
  applique le flux nativement (formulaires interceptés, `Accept:
  text/vnd.turbo-stream.html`) — pas de contrôleur Stimulus dédié. `cycleStatus`
  et `updateStatus` renvoient le stream quand `getPreferredFormat() ===
  TurboBundle::STREAM_FORMAT`, sinon **redirection** (repli sans JS conservé). Le
  formulaire porte un champ caché `detailed` (0/1) pour que la pastille re-rendue
  garde sa forme selon la vue (compacte en mois, haute en semaine) ; `overdue` est
  recalculé côté serveur. Aucun flash sur la réponse stream (il resterait en
  session et s'afficherait plus tard). **Ceci lève, pour le seul cas du statut, la
  règle « pas de Turbo Stream au calendrier »** — les autres mutations (poser /
  instancier / déplacer / retirer) restent en redirection. **Vue résistante au
  refresh** : `CalendarController` pose un cookie `kd_calview` (`month`/`week`) au
  rendu de chaque vue (`rememberView`) ; `app_calendar_index` **et** toutes les
  redirections de `ScheduledWorkoutController` (`redirectToMonth`/`monthFromPayload`,
  via `preferredCalendarView()` lu sur `RequestStack`) retombent sur la vue
  mémorisée — une action faite en vue semaine ré-atterrit en semaine, un refresh
  garde la vue. Pas de migration.
  **Éditeur de séance : zéro bouton d'enregistrement, tout en automatique (sans
  migration).** Aligné sur l'éditeur de plan. (1) **En-tête à édition en ligne** :
  le formulaire d'en-tête (champ titre + repli `<details>` « Détails de la séance »
  pour la description + bouton « Enregistrer la séance ») laisse place à un
  `kd-pagehead` avec titre `<h1>` et description éditables **en ligne**
  (contrôleur `inline-edit`, enregistrement au blur/Entrée), postant vers le nouvel
  endpoint `WorkoutController::updateMeta` (`POST /workout/{id}/meta`, CSRF
  `workout_meta{id}`, `field`=title|description, renvoie la valeur nettoyée en texte
  brut — calqué sur `app_plan_template_meta`). Repli sans JS : `<details>`
  `kd-metafallback` avec le `WorkoutType` complet. (2) **Paramètres d'exercice
  auto-enregistrés** : `_prescribed_form.html.twig` prend un paramètre `auto_action`
  (ex. `change->composer#submitForm`) posé sur chaque champ ; quand il est fourni, le
  bouton « Enregistrer l'exercice » disparaît (le compositeur passe `auto_action`, la
  mini-modale du plan garde son bouton). Comme le stream re-rend tout `#workout-blocks`
  (le panneau de params se referme, le focus saute), `composer_controller.js`
  mémorise le champ actif avant l'envoi (nom unique grâce aux formulaires nommés) puis
  `restoreFocus` ré-ouvre son panneau `.kd-cexo__params` et lui rend le focus + le
  curseur après le re-render (rAF). Le garde `kd-composer__head` de `onSubmit` est
  retiré (l'en-tête n'est plus un formulaire de la section). CSS mort des anciennes
  classes d'en-tête (`kd-composer__head/__headmain/__title/__details/__detailsbody/
  __headactions`) supprimé ; `.kd-composer__meta` et `.kd-eyebrow--accent` conservés.
  Pas de migration.
  **Index filtrables : recherche pondérée + facettes + tri (sans migration).** Les
  trois pages de liste (exercices, séances, plans) gagnent un tri et des filtres,
  100 % client (offline-safe). Le contrôleur `filter_controller.js` est réécrit : il
  ne fait plus un simple `includes`, il **classe** les résultats (score : 4 nom exact,
  3 préfixe du nom, 2 dans le nom, 1 ailleurs — activité/zones) et réordonne la liste
  en place ; en l'absence de recherche, le `<select>` de tri ordonne (nom A→Z/Z→A,
  récence, et selon la page durée / nb blocs / nb semaines / nb séances). Les
  **facettes** sont des puces génériques par groupe (`data-facet-group`/`-value` sur la
  puce, `data-facet-<groupe>` sur l'item, valeurs séparées par des espaces) : activité
  partout, plus **portée** (perso / bibliothèque) sur les exercices. Barre d'outils
  factorisée dans `templates/components/_filterbar.html.twig` (params `placeholder`,
  `total`, `countNoun`, `sortOptions`, `facetGroups`), à placer dans un
  `data-controller="filter"` au-dessus du conteneur `data-filter-target="list"`. Chaque
  item porte `data-filter-name` (base du classement), `data-filter-text` (haystack),
  `data-sort-*` et `data-facet-*`. Côté serveur, `ExerciseController`/`WorkoutController`/
  `PlanTemplateController::index` calculent les activités présentes (via
  `WorkoutMetrics::distinctActivities` ; le workout utilise `findLibraryForOwnerWithContent`
  pour éviter le N+1) et passent `activityFacets` + `items` (`{workout|template,
  activities}`). Les cartes séance/plan affichent aussi les badges d'activité
  (`.kd-cellbadges`). Nouvelles classes CSS `.kd-filterbar`/`__row`, `.kd-sort`/`__select`
  (l'ancien `.kd-toolbar` n'est plus utilisé). Icônes importées : `arrow-up-down`,
  `list-filter`. Pas de migration.
  **Page profil (remplace accueil + synthèse) — AVEC migration.** `HomeController`
  et `SummaryController` (+ `templates/home`, `templates/summary`) sont **supprimés**
  au profit d'un unique **`ProfileController`**. Le **profil est la page d'accueil**
  (`/` = `app_profile`, garde manuelle `getUser()` comme l'ex-home car `/` n'est pas
  dans `access_control`) ; l'édition est `app_profile_edit` (`/profile/edit`) sous la
  nouvelle règle `access_control ^/profile` (remplace `^/summary`). Nav : item
  « Profil » (`lucide:user`) remplace « Accueil », l'onglet « Synthèse » est retiré ;
  marque + page de login repointées sur `app_profile`. Le fragment
  `templates/components/_status_stats.html.twig` est **conservé** (réutilisé pour
  l'observance du profil). **Deux volets** : (1) **stats générales** via le nouveau
  service `ProfileStats` (compose l'existant, aucune mise à plat réimplémentée) —
  compteurs biblio, observance du mois ET « tous temps », répartition des séances
  faites par activité, et **volume réalisé agrégé sur l'historique** (tonnage/séries,
  distances course/vélo/natation) en itérant les séances `DONE` via
  `WorkoutMetrics::volume` ; formatage par `UnitFormatter`. Deux méthodes ajoutées à
  `ScheduledWorkoutRepository` : `countByStatusForOwner` (agrégat sans borne de date)
  et `findDoneWithContentForOwner` (fetch-join anti N+1 des séances faites).
  (2) **fiche athlète éditable** : nouveaux champs **tous nullable** sur `User`
  (identité : `birthDate`→âge dérivé, `sex`, `heightCm`, `weightKg`→IMC dérivé,
  `trainingYears`, `mainGoal`, `bio` ; force kg : squat/bench/deadlift/ohp/traction
  lestée + total SBD & score **DOTS** dérivés ; endurance : temps 5K/10K/semi/marathon
  + 100m nat en secondes, `cyclingFtpWatts`) + `updatedAt`/`PreUpdate`. Enums
  `Sex`/`TrainingGoal`. `ProfileType` (thème global) ; nouveau **`DurationType`**
  calqué sur `PaceType` (round-trip secondes ↔ `mm:ss`/`h:mm:ss`, réutilise
  `UnitFormatter::duration`). Vue lecture (`profile/index`) : stats + fiche en
  `.kd-deflist` groupées (Identité/Force/Endurance) via la carte construite par
  `ProfileStats::athleteCard`. **Migration** `Version20260725073629` : colonnes profil
  nullable sur `user`. Couche CSS `.kd-profile*` (tuiles de volume, fieldsets,
  en-têtes de groupe), tokenisée. Aucune icône nouvelle (toutes déjà locales).
  **Abonnement calendrier ICS (hors-roadmap) — AVEC migration.** Le calendrier
  peut générer un lien d'abonnement (webcal/ICS) à ajouter à Google Agenda, Apple
  Calendar… **Auth par jeton, pas par session** : le flux est récupéré côté serveur
  du client sans cookie, donc la route sort de `access_control` (nouveau préfixe
  `/feed`) et le jeton secret EST l'autorisation — même philosophie que le partage
  public par slug. Nouveau champ `User.calendarFeedToken` (nullable, unique, 64 hex ;
  **Migration** `Version20260725120000`), généré à la demande, **régénérer = révoquer**
  l'ancien lien. `PublicCalendarController` (hors voters) sert deux portées choisies
  par l'URL : `/feed/{token}.ics` = tout le calendrier, `/feed/{token}/plan/{planId}.ics`
  = un plan instancié (borné à l'owner). Service `IcsCalendarBuilder` (`src/Service/`)
  **consomme `PlanFlattener`** (source unique de mise à plat, jamais réimplémentée) pour
  la `DESCRIPTION` de chaque événement (blocs/exercices lisibles depuis le téléphone).
  **Événements journée entière** (`VALUE=DATE`) : le modèle n'a qu'une `scheduledDate`
  sans heure → aucun `VTIMEZONE`, aucun fuseau à gérer. `UID` stable par
  `ScheduledWorkout` (Google met à jour au lieu de dupliquer) ; statut prévu/fait/manqué
  codé par un préfixe de titre (✓/✗). Le builder respecte le format RFC 5545 à la main
  (CRLF, pliage 75 octets **sans couper l'UTF-8**, échappement `,;\`+sauts de ligne),
  aucune lib ajoutée. Deux méthodes repository fetch-join anti-N+1
  (`findAllForOwnerWithContent`, `findBySourcePlanTemplateForOwnerWithContent`). UI :
  bouton « S'abonner » dans l'en-tête du calendrier (mois + semaine) ouvrant une modale
  (`calendar/_subscribe.html.twig`, contrôleur `dialog`) : activation/régénération du
  jeton (POST `app_calendar_feed_token`, sous `/calendar` donc protégé, CSRF), liens à
  copier (contrôleur `clipboard`) + variante `webcal://`, aide Google Agenda, garde-fous
  (rafraîchi ~12-24 h côté Google ; lien à ne pas partager). Couche CSS `.kd-feed*` /
  `.kd-feedrow*` / `.kd-subscribe`. Icônes importées : `rss`, `rotate-cw`. **Limite** :
  Google/Apple ne resynchronisent l'abonnement que toutes les 12-24 h (limite du client,
  pas de push temps réel possible sur un flux ICS).
  **Enrichissement cardio des exercices + zones BPM au profil (hors-roadmap) — AVEC
  migration.** Le modèle d'exercice cardio devient réaliste et les zones d'intensité
  ont enfin une source. **Zones BPM au profil** : nouvel enum `IntensityZone` (Z1..Z5 :
  Récupération/Endurance/Tempo/Seuil/VO2max, valeurs `z1..z5`, `shortLabel()` +
  `defaultBounds()` en % de réserve) et service **`HeartRateZones`** (source unique
  consommée par le form ET le profil) : dérivation **Karvonen** `bpm = repos + pct ×
  (max − repos)` à partir de `User.maxHeartRate`/`restingHeartRate`, **surchargeable**
  par zone via `User.hrZone1Max..hrZone4Max` (null = borne dérivée ; zones contiguës,
  Z5 plafonne à la FC max). Sans FC max, les zones existent mais sans bornes BPM. Champs
  ajoutés à `ProfileType` + affichage `templates/profile/index.html.twig` (section
  `.kd-hrzones`/`.kd-hrzone*`, filet gauche en rampe d'intensité sobre — neutre, la
  couleur reste réservée aux activités). **Prescription enrichie** : `PrescriptionType::fields()`
  — `DISTANCE_PACE` gagne `sets` (répétitions du fractionné : « 8 × 400 m »),
  `intensityZone`, `elevationGainMeters` ; `DURATION` gagne `paceSecondsPerKm` (allure
  en plus de la zone). Nouveaux champs **nullable** sur `PrescribedExercise` : `rpe`
  (effort ressenti 1-10, **transverse** à tous les types, hors `fields()`/`VALUE_FIELDS`,
  jamais nettoyé) et `elevationGainMeters` (D+, dans `VALUE_FIELDS`). **`intensityZone`
  reste une colonne string** (stocke `z1..z5` ; d'anciennes valeurs libres restent
  affichées telles quelles via `IntensityZone::tryFrom(...)?->shortLabel() ?? $raw`).
  **Formulaire prescrit** (`PrescribedExerciseType`) : le `<select>` **exercice est
  retiré** (on n'échange pas un exo posé — remplacé par un rappel d'activité en lecture
  seule, le nom étant déjà porté par la ligne parente) ; `durationSeconds` passe en
  **`DurationType`** (saisie `mm:ss`/`h:mm:ss` au lieu de secondes brutes) ;
  `intensityZone` passe en `ChoiceType` dont les libellés portent les BPM du profil
  (« Z4 · Seuil (146-160 bpm) », injection `HeartRateZones` + option `user`) ; ajout
  `rpe` et `elevationGainMeters`. Le `prescription_fields_controller.js` est inchangé
  (piloté par la map). **Mort supprimé** : route `app_workout_prescribed_add` + méthode
  `addPrescribed` + `createAddPrescribedForm` (dépendaient du select, non rendus depuis
  le passage au compositeur/quick-add). **Résumés** (`PlanFlattener`, source unique dont
  héritent rendu Twig, export Excel et flux ICS) : `summarizeDistancePace` préfixe `sets ×`,
  ajoute zone (label enum) et « D+ N m » ; `summarizeDuration` ajoute l'allure ; wrapper
  `summarize()` suffixe « · RPE N » (transverse). `WorkoutEstimator::distancePace` ×`sets`
  (chaque répétition refait distance + récup). **Migration** `Version20260725202747` :
  colonnes nullable sur `user` (FC + 4 zones) et `prescribed_exercise` (`rpe`,
  `elevation_gain_meters`). Tests : `HeartRateZonesTest` (Karvonen + override) et cas
  cardio ajoutés à `PlanFlattenerTest` (intervalles/zone/D+/allure/RPE). Icône importée :
  `heart-pulse`.
  **Refonte visuelle de la page profil + éditeur de fiche (sans migration).** La page
  profil (`profile/index`) et son formulaire (`profile/edit`) étaient rustiques (pile de
  cartes à styles inline, formulaire brut à fieldsets). **Lecture** : vrai en-tête
  athlète `.kd-phero` (monogramme terracotta = initiale, nom, chips de vitals
  `.kd-vitals` âge/sexe/poids/objectif lus sur `app.user`, bouton d'édition), rythme de
  sections `.kd-section`/`.kd-section__block` (fin des `style=` inline), compteurs
  biblio en `.kd-metric` (icône teintée + valeur), répartition par activité passée d'une
  liste à des **barres proportionnelles** `.kd-actbar` (fill run/gym/neutre, largeur =
  part du max), fiche en `.kd-deflist--profile` (variante label↔valeur ligne à ligne,
  filet fin) où les **valeurs dérivées** (IMC, Total SBD, DOTS) sont accentuées via un
  nouveau flag `derived` posé dans `ProfileStats::athleteCard` et rendues
  `.kd-deflist__derived`. **Édition** : le formulaire passe d'un `<fieldset>` unique
  brut à des **sections en cartes** `.kd-formsection` (en-tête icône+titre+hint), champs
  en **grille 2 colonnes** `.kd-formgrid` (zones cardio en 4 col `.kd-formgrid--zones`),
  **barre d'action collante** `.kd-editform__bar`. **Correctif** : la section
  **Cardio & zones** (FC max/repos + Z1–Z4) n'était pas rendue et tombait en vrac via
  `form_end` — elle a désormais sa propre carte. Anciennes classes `.kd-profile__fieldset/
  __legend` supprimées (CSS mort). Icône importée : `quote` (bio). Pas de migration
  (seul ajout PHP : le flag `derived`, additif, aucun test `ProfileStats` existant).
  **Objectifs datés / événements cibles (hors-roadmap) — AVEC migration.** L'app
  planifiait sans jamais donner de cap : on ajoute l'échéance vers laquelle on
  s'entraîne (course, compétition, test de force, but perso). Nouvelle entité
  **`Goal`** owner-only (pas de biblio globale, comme `ScheduledWorkout`) :
  `title`, `activity` (nullable, code couleur/icône ; transverse si null),
  `priority` (enum `GoalPriority` A/B/C, périodisation), `targetDate` (**journée
  entière** `DATE_IMMUTABLE`, pas d'heure ni de fuseau), `targetValue` (**texte
  libre assumé** — « sub 4h », « 180 kg » ; les objectifs sont trop hétérogènes
  pour la normalisation en unités appliquée aux prescriptions), `description`,
  `outcome` (enum `GoalOutcome` atteint/partiel/manqué, **nullable tant que
  l'échéance n'est pas débriefée** — boucle prévu/réalisé au niveau de l'objectif),
  `resultNote`, lifecycle `createdAt`/`updatedAt`. Méthodes dérivées `getDaysUntil()`
  (compte à rebours, négatif si passée) et `isPast()`. Enums `GoalPriority`
  (`shortLabel()` A/B/C) et `GoalOutcome` (`modifier()` réutilise les tokens de
  statut done/planned/missed, `icon()`). **`GoalVoter`** owner-only (VIEW/EDIT/
  DELETE). **`GoalRepository`** : `findUpcomingForOwner` (échéance ≥ aujourd'hui,
  ASC), `findPastForOwner` (DESC), `findNextForOwner` (compte à rebours),
  `findByOwnerBetween` (marquage calendrier). **`GoalController`** (`/goal`,
  `access_control ^/goal` en `ROLE_USER`) : CRUD complet (index à deux sections
  « à venir » / « passés », new, show, edit, delete CSRF) + **`prepare`** —
  l'ancrage de plan sur une date d'arrivée, le cœur fédérateur : **on raisonne à
  l'envers** (jour J → date de départ), la date de départ est calculée pour que la
  DERNIÈRE semaine du plan tombe sur la semaine ISO de l'objectif, puis délègue à
  `PlanScheduler::instantiate` (idempotent : refuse un plan déjà posé, message pour
  vider d'abord). `GoalType` (form ; champs résultat dans une section à part).
  **Intégrations** (valeur = relier profil + calendrier + plan) : nav « Objectifs »
  (`lucide:target`) après Calendrier ; **profil** — section « Objectifs à venir »
  en tête (3 prochains, via `GoalRepository::findUpcomingForOwner`) ; **calendrier**
  — bandeau de compte à rebours du prochain objectif (`_goal_banner.html.twig`,
  mois + semaine) et **marqueur d'objectif sur la case du jour J** (mois + semaine,
  `CalendarController` injecte `goalsByDate` sur la fenêtre affichée + `nextGoal`,
  helper `indexGoalsByDate`). Composant liste `goal/_card.html.twig` (carte à
  compte à rebours J-N, réutilisé index + profil). Couche CSS `.kd-goal*` /
  `.kd-prio--a/b/c` (priorité A en accent primaire, B/C sobres — la couleur reste
  réservée activités/statuts, la priorité joue sur le contraste) / `.kd-calgoal*`
  / `.kd-goalbanner*` / `.kd-goalhero*` / `.kd-goallead*`, tout tokenisé.
  **Migration** `Version20260726090000` : table `goal` (FK owner `ON DELETE
  CASCADE`). Tests : `GoalControllerTest` (CRUD, voter 403, ancrage de plan =
  dernière semaine calée sur la semaine de l'échéance). Icône importée : `flag`
  (le reste déjà local).
  **Progression prévue — lot A de [`docs/feature-progression.md`](./docs/feature-progression.md)
  (hors-roadmap, SANS migration).** L'app était un bon éditeur mais un mauvais
  miroir : rien ne visualisait la rampe de charge/allure qu'un plan fait monter
  semaine après semaine. Le lot A trace la progression **PRÉVUE** (100 % lecture
  agrégée, aucune donnée de réalisé, zéro entorse à la règle « pas de tracking » —
  on lit la rampe que le *fork à la pose* a déjà matérialisée dans les copies
  locales des cases). Nouveau service **`ProgressionAggregator`** (autowiring,
  consomme `WorkoutMetrics` + `UnitFormatter`, jamais de mise à plat
  réimplémentée) : `weeklyVolume` (charge par semaine ventilée en séries traçables
  — temps estimé, tonnage, séries, distances par activité ; ne garde que les
  séries non vides) et `exerciseTrajectories` (par exercice récurrent — présent
  sur ≥ 2 semaines —, la métrique primaire déduite des paramètres réellement
  prescrits : charge top-set, allure, distance, durée ou séries, agrégée par
  semaine). Les **hauteurs de barres** (`heightPct`) et le **sens** (`direction`
  up/down/flat ; allure = `lowerIsBetter` donc barre inversée : plus haut = plus
  rapide) sont précalculés dans le service pour garder le Twig « bête ». Anti-N+1 :
  nouvelle méthode `PlanTemplateRepository::findWithContent` (fetch-join
  cases→séance→blocs→prescrits→exercice en une requête), appelée dans
  `PlanTemplateController::show` (même instance managée réutilisée par le flattener
  ET l'agrégat). **Rendu 100 % serveur** (aucune lib de charts, cohérent
  AssetMapper) : fragment `templates/components/_progression.html.twig` (barres
  proportionnelles façon `.kd-obar`/`.kd-actbar`, couleur réservée aux activités,
  barre neutre par défaut) branché sur `plan_template/show` — pas d'onglet dédié
  (le lot A se greffe sur les pages existantes). Sélecteur de trajectoire : charts
  pré-rendus, bascule 100 % client (contrôleur Stimulus `progression`) ; sans JS,
  tous les charts restent visibles (auto-suffisant, cachable offline). Couche CSS
  `.kd-prog*` (tokenisée). Tests : `ProgressionAggregatorTest` (rampe de charge,
  allure `lowerIsBetter`, exercice non récurrent exclu, séries de volume filtrées).
  Icône importée : `trending-down` (`trending-up` déjà locale). **Lot B
  (progression RÉALISÉE) NON fait : décision requise** (options 1/2/3 du §3 de la
  spec, à trancher avec l'utilisateur avant de coder).
  **Séries détaillées + superset visible (hors-roadmap) — AVEC migration.** Un
  exercice de force pouvait seulement prescrire N séries identiques (`4×6 @ 100`).
  On ajoute la prescription **hétérogène** : échauffement montant, séries de travail,
  dégressive, à l'échec, drop set. **Modèle** : enum `SetType` (5 cas, `getLabel`/
  `shortLabel`/`countsAsWorking` — l'échauffement ne compte pas comme volume de
  travail) + nouvelle entité **`PrescribedSet`** (position, type, reps, charge, durée),
  collection `PrescribedExercise::detailedSets` (cascade+orphanRemoval, FK `ON DELETE
  CASCADE`). **Opt-in par exercice, muscu uniquement** (`SETS_REPS`/`SETS_TIME`, choix
  utilisateur) : tant que la collection est vide, le compteur scalaire reste le défaut ;
  dès qu'elle est peuplée, elle **prime** via trois helpers dérivés sur
  `PrescribedExercise` (`getWorkingSetCount` hors échauffement, `getTonnageKg` par ligne,
  `getTopWeightKg`). Ces helpers rendent les services **détaillé-aware sans dupliquer la
  logique** : `WorkoutMetrics::volume` (séries/tonnage), `WorkoutEstimator` (durée sommée
  par série), `ProgressionAggregator` (top set + décompte). `PlanFlattener` gagne un
  résumé par série (groupes de séries consécutives identiques fusionnés, ex. « Échauf
  10 reps @ 40 kg · 2× 8 reps @ 100 kg · Drop 6 reps @ 80 kg ») + une clé `sets`
  structurée ; l'export Excel, le flux ICS et l'aperçu au survol en héritent
  automatiquement (ils consomment le `summary`). `WorkoutCloner` clone la collection
  (fork à la pose/duplication) — correction au passage : `rpe`/`elevationGainMeters`
  n'étaient pas copiés. **Compositeur** : `PrescribedSetType` (champ de valeur selon le
  type parent), 5 endpoints minces sur `WorkoutController` (`set_add` — éclate le
  scalaire en N lignes la 1re fois, sinon recopie la dernière ; `set_clear` — retour au
  mode simple, réversible ; `set_edit` — auto-save au `change`, stream **ligne seule**
  pour garder le focus ; `set_delete`/`set_move`). Les mutations structurelles re-rendent
  tout le panneau de paramètres `#prescribed-params-{id}` (form prescrit + éditeur de
  séries) via un stream ciblé — le conteneur `.kd-cexo__params` (et son `hidden`) survit,
  le panneau reste ouvert (même piège d'id que `#workout-blocks`). `_prescribed_form`
  masque les champs scalaires (séries/reps/charge/durée) dès qu'il y a des séries
  détaillées (transverses repos/RPE/notes conservés). Panneau extrait en
  `_prescribed_params.html.twig` (réutilisé par `_block` et le stream). Repli sans JS :
  redirection. **Superset rendu visible** (décision : nommer l'existant, zéro modèle) :
  un bloc à ≥ 2 exercices affiche un badge « Superset » (2) / « Circuit » (3+) en lecture
  ET dans le compositeur — la mécanique bloc+`rounds` l'exprimait déjà. Rendu lecture
  (`_workout_read`) : liste par série (`.kd-setlist`) + badge superset. **Couleur neutre**
  pour type de série et superset (ni activité ni statut). Migration
  `Version20260726120000` (table `prescribed_set`). Tests : `WorkoutMetricsTest`
  (décompte hors échauffement + tonnage par ligne), `PlanFlattenerTest` (regroupement du
  résumé). Icônes importées : `list-ordered`, `list-plus`, `rotate-ccw`. **Limite connue** :
  basculer un exercice détaillé vers un type non-force (ex. distance) ne vide pas ses
  séries et l'éditeur reste affiché jusqu'au rechargement (cas absurde, non géré).
  **Lot design séries (lisibilité + badges + WYSIWYG).** Le résumé détaillé s'affichait en
  une longue ligne mono `nowrap` qui débordait de la ligne repliée du compositeur. Corrigé :
  (1) **Badges de type de série** — nouveau composant macro `templates/components/_set_type.html.twig`
  (`badge(type)`), **monochrome** (le type n'est ni activité ni statut, pas de teinte) : icône
  Lucide par type (`SetType::icon()`) + emphase (contour pour échauffement, plein ink pour « à
  l'échec », chip clair sinon). NORMAL n'affiche pas de badge. Classes `.kd-setbadge(--warmup/
  --to_failure)`. (2) **Ligne repliée lisible** — pour un exercice détaillé, la pastille mono est
  remplacée par un résumé qui passe à la ligne (compteur « N séries » + badges des types présents),
  rendu dans `.kd-cexo__body` (`.kd-cexo__sets`) ; le détail complet reste dans le panneau et la
  vue séance. (3) **Éditeur WYSIWYG** — `_sets_editor` calqué sur la vue séance : chip de type +
  valeurs + unités inline (`8 reps @ 100 kg`), accent gauche neutre par type (`.kd-set--{type}`).
  (4) **Vue séance** (`_workout_read`) : la liste `.kd-setlist` utilise les badges (badge aligné à
  droite de chaque ligne). `SetType::shortLabel()` étoffé (« Dégressive », « À l'échec », « Drop
  set »). Icônes importées : `zap`, `chevrons-down`. Pas de migration.


- **Relation coach ↔ athlète : faite.** Première feature à relier deux `User`.
  **Décision structurante** : le contenu créé par le coach est **possédé par
  l'athlète** (`setOwner($athlete)`), le coach n'est que **co-éditeur** via les
  voters. Cela réutilise tel quel tous les repos owner-scoped (bibliothèque,
  calendrier, resync), garde correct le repli `PlanScheduler::resync()` →
  `$template->getOwner()`, et fait apparaître d'office le contenu chez l'athlète —
  qui le **garde** si la relation se termine. Le point d'appui : `WorkoutCloner` et
  `PlanScheduler` acceptaient déjà un `$owner` explicite, aucun service métier n'a
  été touché. **Modèle** : `Coaching` (coach/athlete/status/requestedBy/respondedAt),
  enum `CoachingStatus` (PENDING/ACCEPTED/DECLINED/ENDED, `modifier()` réutilisant
  les tokens de statut de séance comme `GoalOutcome`), `UNIQUE (coach_id, athlete_id)` —
  une relation refusée ou terminée se **réouvre sur la même ligne** plutôt que de
  créer un doublon. Migration `Version20260726142246`. **Sécurité** : `CoachingResolver`
  mémoïse `isAcceptedCoachOf()` par requête (les voters votent une fois par entité
  affichée, sans cache c'était un COUNT par ligne de calendrier) ; branche « coach
  accepté du propriétaire » ajoutée **après** l'échec du test `owner === user` dans
  `WorkoutVoter`, `PlanTemplateVoter` et `ScheduledWorkoutVoter` (ce dernier **gagne
  un constructeur**, il n'en avait pas). `GoalVoter` laissé inchangé (objectifs hors
  périmètre v1). `CoachingVoter` (VIEW/RESPOND/END) : RESPOND réservé au destinataire
  (`requestedBy !== user`), sinon on s'auto-accepterait coach. `ROLE_COACH` accordé par
  commande `app:user:promote-coach` (calquée sur `app:user:promote`), jamais
  auto-attribuable. `access_control` : `^/coaching` (ROLE_USER) **avant** `^/coach`
  (ROLE_COACH) — dans l'autre ordre la règle coach capterait `/coaching`, où l'athlète
  répond aux demandes. **Flux** : `CoachingController` (`/coaching`) — hub ouvert à
  tous, demande par **email exact** (pas d'annuaire parcourable), accept/decline/end.
  `CoachController` (`/coach`) — dashboard + espace de travail par athlète, avec des
  actions **minces** qui créent la coquille (owner = athlète) puis redirigent vers le
  **compositeur et l'éditeur de plan habituels** : aucun éditeur n'est forké. Pose de
  séance et instanciation de plan refusent tout contenu qui n'appartient pas à cet
  athlète. **UI** : templates `coaching/index`, `coach/dashboard`, `coach/athlete`,
  fragment `_coaching_card`, couche CSS `.kd-coach*` tokenisée, item de nav « Coaching »
  (+ « Mes athlètes » conditionné à `app.user.isCoach`), section coaching sur le profil
  pour la découvrabilité des demandes reçues. Icônes importées : `users`, `user-plus`,
  `user-check`, `user-x`, `mail`, `send`. Tests : `CoachingControllerTest` (13) et
  `CoachControllerTest` (14) — nettoyage FK-safe avec `coaching` **en tête**, avant les
  users. **Limites connues v1** : (1) le panneau bibliothèque du compositeur montre les
  exercices **du coach** + globaux quand il édite pour un athlète (`findLibraryForUser($this->getUser())`),
  pas ceux de l'athlète ; (2) EDIT sur `ScheduledWorkout` laisse techniquement le coach
  basculer prévu/fait/manqué — accepté (il aide à la programmation), à séparer en
  attribut STATUS distinct si on veut réserver le « réalisé » à l'athlète ; (3) pas de
  repère visuel « créé par ton coach » côté athlète (aucune colonne auteur, par choix).

### Lot — Création de compte en console & paramètres du profil (26/07/2026)

**Besoin** : l'app n'a jamais eu d'inscription ni d'écran de compte — les users
étaient créés à la main en base, et le mot de passe n'était changeable nulle part.
**Décision** : pas d'inscription publique (usage perso / cercle restreint), la
porte d'entrée reste la console ; l'app n'expose que ce que le titulaire peut
changer lui-même, c'est-à-dire son mot de passe. **Commande** `app:user:create`
(`src/Command/CreateUserCommand.php`) : email + mot de passe, rôle de base
(`setRoles([])`, `getRoles()` ajoutant déjà ROLE_USER), refus si l'email est
invalide ou déjà pris, minimum 8 caractères. Le mot de passe omis est demandé en
**saisie masquée avec confirmation** (`askHidden` ×2) pour ne pas le laisser dans
l'historique du shell ; en mode non interactif sans argument, la commande sort en
`INVALID`. Les rôles au-delà restent `app:user:promote[-coach]`. **Écran**
`/profile/settings` (`ProfileController::settings`, sous `^/profile` donc déjà
couvert par access_control) : carte compte en lecture (email, date de création,
rôle) + `ChangePasswordType` — `currentPassword` (contrainte `UserPassword`, donc
valable seulement dans le firewall) et `plainPassword` en `RepeatedType`, tous deux
**non mappés** (le hachage se fait dans le contrôleur, le formulaire ne voit que du
clair). Deux pièges traités : (1) le contrôleur refuse un nouveau mot de passe
identique à l'actuel (`isPasswordValid`), sinon on annonçait « mis à jour » sans
rien changer ; (2) le hash fait partie du token en session — sans `Security::login($user)`
après le flush, `ContextListener` voit un utilisateur « changé » et déconnecte
l'auteur du changement à la requête suivante. Côté rendu, les erreurs du
`RepeatedType` portent sur le champ **parent** : le template rend `form_errors(form.plainPassword)`
à part, puis `first`/`second` séparément. **UI** : bouton « Paramètres » dans
l'en-tête du profil (`.kd-phero__actions`, nouveau conteneur flex remplaçant le
bouton unique), icônes déjà locales (`settings-2`, `lock`). **Tests** :
`ProfileControllerTest` (6) — mauvais mot de passe actuel, non-concordance, trop
court, identique à l'actuel (tous en **422**, contrat Turbo des formulaires
invalides) et succès vérifiant à la fois le nouveau hash et le **maintien de la
session**.

### Lot — Fluidité d'édition & resserrage de la navigation (26/07/2026)

**Besoin** : l'app était complète mais gardait des écrans et des onglets qui
existaient pour des raisons techniques, pas pour l'utilisateur — sept frictions
traitées d'un bloc.

**Création en un clic.** « Nouvelle séance » / « Nouveau plan » passaient par un
écran de formulaire. Ils deviennent des **POST** (fragment
`components/_create_button.html.twig`, réutilisé par les index, leurs états vides
et la fiche objectif) qui créent un brouillon titré par défaut — plan à
`DRAFT_PLAN_WEEKS = 4` semaines — puis redirigent vers l'éditeur avec `rename=1`.
Le contrôleur `inline-edit` gagne une value `autofocus` : le titre s'ouvre,
prérempli et sélectionné, taper le remplace. On généralise le geste que
`CoachController::newWorkout`/`newPlan` faisait déjà, on n'invente pas un second
pattern. **Piège traité** : tous les brouillons naissaient avec le slug
`nouvelle-seance-N`. `SlugGenerator` gagne `base()` et `derivesFrom()` ; `updateMeta`
régénère le slug au **premier** renommage seulement (racine du titre par défaut +
suffixe numérique), donc une entité déjà nommée garde son lien de partage public.
Suppression de `workout/new.html.twig`, `plan_template/new.html.twig`,
`WorkoutType` et `PlanTemplateType`.

**Éditeurs sans formulaire de métadonnées.** Le `<details>` « Modifier les infos
(formulaire) » disparaît des deux éditeurs, les routes `edit` passent en **GET
seul**. Régression assumée et documentée : sans JS, titre/description/semaines ne
sont plus modifiables (le reste des éditeurs garde son repli par redirection).

**Semaines une par une ou par paquet.** `addWeek` lit un `count` (défaut 1) et le
**borne** à ce qui reste sous 52 au lieu de refuser tout le paquet. Pied de trame :
bouton « Ajouter une semaine » + champ « ou d'un coup » (deux `<form>` distincts,
même intention CSRF — le bouton « +1 » ne doit pas être bloqué par la validation
HTML5 du champ nombre). Message explicite au plafond.

**Compteur de séries synchronisé dans les deux sens.** Le scalaire `sets` et la
collection `PrescribedSet` décrivaient la même chose sans jamais se parler : passer
en détaillé, ajouter deux séries puis revenir au simple reperdait les deux.
Nouveau service **`SetSynchronizer`** : `syncScalarFromDetailed()` (toute mutation
de la collection réécrit le scalaire) et `applyScalarToDetailed()` (modifier le
scalaire ajoute/retire des lignes `NORMAL` en fin). Référence commune = les séries
**de travail**, échauffement exclu, aligné sur `getWorkingSetCount()` — un
échauffement n'est donc jamais supprimé par une baisse du compteur. Câblé sur
`addSet`/`deleteSet`/`editSet`/`editPrescribed` (`moveSet` ne change pas le
décompte). Le champ « séries » **reste visible et éditable** en mode détaillé, avec
un `.kd-help` qui dit la règle. Deux pièges de rendu : (1) `editSet` conserve son
stream « ligne seule » (focus préservé pendant la saisie) mais bascule sur le
panneau complet **si le décompte a bougé** — ça ne concerne que le `<select>` de
type ; (2) `editPrescribed` fait de même quand `sets` a piloté la liste.
**Correctif au passage** : `_prescribed_form` sautait `reps`/`weightKg`/
`durationSeconds` en mode détaillé, mais `form_end()` appelle `form_rest()` qui les
re-rendait en fin de formulaire, hors des cibles `prescription-fields` (donc jamais
masqués par le type d'effort) et en double saisie contradictoire. Corrigé par une
option `detailed` sur `PrescribedExerciseType` : un champ **non déclaré** ne peut
être ni rendu ni écrasé. Tests : `SetSynchronizerTest` (7) et un fonctionnel
« simple 4 → détailler → +2 → mode simple = 6 » — il n'existait aucun test sur
`set_add`/`set_clear`.

**Navigation à 4 entrées + menu de compte.** La barre ne garde que le travail
quotidien (Exercices / Séances / Plans / Calendrier). Objectifs, Coaching, Mes
athlètes, Mon profil et Paramètres passent dans un menu déroulant sur l'avatar,
implémenté en **`<details>`/`<summary>` natif** — clavier et sans-JS, condition du
cache offline. Le contrôleur `dismiss` n'ajoute que la fermeture au clic extérieur
et à Échap. Le déclencheur s'allume quand la route courante appartient au menu
(sinon on perdait le repère de page courante) ; les entrées `app_profile` et
`app_profile_settings` utilisent une liste `routes` exacte, le préfixe débordait et
allumait deux entrées à la fois. Couche CSS `.kd-usermenu*`. Aucune route ni
contrôleur touché.

**Profil complet de l'athlète côté coach.** `ProfileStats::for()` et
`HeartRateZones::forUser()` prenaient déjà n'importe quel `User` : tout était dans
le découpage des vues. Extraction de `profile/_stats.html.twig` et
`profile/_athlete_sheet.html.twig` (paramètres `stats`, `hrZones`, `own`), inclus
par la page profil (`own: true`) et par `coach/athlete` (`own: false` : aucun lien
d'édition, la fiche appartient à l'athlète). **Correctif de sécurité** : `GoalVoter`
était strictement owner-only alors que la page athlète rendait des cartes pointant
vers `app_goal_show` — 403 garanti. Branche « coach accepté du propriétaire »
ajoutée sur le modèle de `WorkoutVoter`. Conséquence directe traitée :
`GoalController::show` scopait les plans et le lead-up sur `$this->getUser()`, il
scope désormais sur `$goal->getOwner()` — sans quoi le coach voyait **son** contenu
sur la fiche de son athlète.

**Relation Objectif ↔ Plan (N:N).** Le lien plan↔objectif était transitoire :
`prepare` instanciait puis oubliait. Table de jointure `plan_template_goal`
(migration `Version20260726191020`), côté propriétaire sur `PlanTemplate`.
`addGoal`/`removeGoal` maintiennent **les deux côtés** (piège connu du projet : un
fragment re-rendu par Turbo Stream dans la même requête montrerait un état périmé).
N:N assumé : une prépa se découpe en blocs (base puis spécifique), un plan peut
servir deux échéances. Deux points d'entrée symétriques : bandeau `#plan-goals`
dans l'éditeur de plan (stream ciblé, calqué sur `gridResponse()`) et section
« Plans de préparation » sur la fiche objectif (rattacher un plan existant, ou
`app_goal_plan_new` qui crée un brouillon **déjà lié**). `prepare` rattache
désormais au passage. **Scoping** : les deux endpoints n'acceptent que du contenu
dont l'`owner` est celui de l'entité courante, jamais `$this->getUser()` — c'est ce
qui rend le coach correct. Badge d'objectif sur la vue plan, **jamais sur la page
publique** (les objectifs sont privés). Tests : 4 sur `GoalControllerTest`
(rattacher/détacher, refus d'un plan d'autrui, création déjà liée, `prepare` qui
lie) et 1 sur `PlanTemplateControllerTest` (côté éditeur).

**Au passage** : `--color-divider-soft` était utilisé dans `components.css` sans
exister comme token sémantique (seule la primitive `--kd-divider-soft` était
définie) — deux règles étaient donc silencieusement invalides. Token ajouté.
La migration générée par `doctrine:migrations:diff` embarquait aussi des
renommages d'index sans rapport (`prescribed_set`, `scheduled_workout`, `user` :
noms posés à la main vs noms auto Doctrine) : écartés du fichier, c'est du bruit
sans effet fonctionnel — le `doctrine:schema:validate` reste donc « not in sync »
sur ce point précis, comme avant ce lot.

**Tests** : 120 au vert (105 avant le lot).

### Lot — Fusion des pages Coaching et Mes athlètes (26/07/2026)

**Une seule page pour les deux sens de la relation.** `/coaching` (hub relation)
et `/coach` (tableau de bord coach) affichaient les mêmes cartes, les mêmes
demandes reçues/envoyées et le même formulaire à un `hidden` près : redondance
pure, et deux entrées de menu pour une seule idée. `CoachController::dashboard()`
et `coach/dashboard.html.twig` sont **supprimés**. `/coach` ne porte plus que la
fiche de travail d'un athlète (`app_coach_athlete` et ses actions POST), donc
`access_control ^/coach → ROLE_COACH` garde tout son sens et l'ordre des règles
(`^/coaching` avant `^/coach`) reste nécessaire.

**Ce que la fusion apporte côté UX**, au-delà du dédoublonnage :
- les demandes sont **mutualisées** — une seule liste « à traiter » et une seule
  « en attente », quel que soit le sens de la demande. Avant, un coach qui se
  faisait aussi coacher devait regarder deux pages pour voir toutes ses demandes ;
- l'**ordre des sections suit le rôle** : un `ROLE_COACH` voit « Mes athlètes »
  d'abord, un athlète voit « Mes coachs » d'abord. D'où les deux macros
  `coachesSection` / `athletesSection` dans `coaching/index.html.twig`, appelées
  dans un ordre ou dans l'autre (`{% import _self %}`) plutôt que dupliquées ;
- chaque formulaire reste **ancré sous sa liste** : « Demander à être coaché »
  (avec `role=athlete`) sous les coachs, « Inviter un athlète » (sans `role`,
  donc sens coach par défaut) sous les athlètes, réservé à `isCoach`. Un
  sélecteur de sens unique aurait été plus compact mais moins évident.

**Pièges traités.** Le champ `from=coach` du formulaire d'invitation ne servait
qu'à choisir la page de retour : supprimé, `CoachingController::request()` a une
seule redirection. `linkAthlete` vaut désormais `me.isCoach` et non `true` : un
ex-coach (rôle retiré, relations résiduelles) verrait sinon un lien vers un 403 —
c'est aussi pourquoi la section athlètes s'affiche encore, sans formulaire, quand
il reste des relations sans le rôle. Dans le header, l'entrée « Mes athlètes »
disparaît et « Coaching » s'allume via une **liste exacte**
(`app_coaching_index`, `app_coach_athlete`) : le préfixe `app_coach` ne capte pas
`app_coaching_index` (pas d'underscore), et `app_coaching` raterait la fiche
athlète. Le retour depuis la fiche athlète pointe sur l'ancre `#athletes`.

**Tests** : 121 au vert. `CoachControllerTest` perd ses deux tests de dashboard,
remplacés par un test de la fiche athlète refusée sans `ROLE_COACH` ;
`CoachingControllerTest` gagne deux tests de rendu (page complète pour un coach,
côté coach masqué pour un simple utilisateur).

---

### Lot — Aperçu au survol dense & pastilles de type de série (26/07/2026)

**Point de départ** (deux retours sur la vue plan). L'aperçu au survol d'une case
se cassait sur les séances riches : `.kd-planpreview__sum` était en `flex: 0 0 auto`,
donc un résumé de séries détaillées (`12 reps @ 12 kg · 12 reps @ 12 kg · À l'échec`)
ne pouvait ni rétrécir ni revenir à la ligne — il sortait du panneau et passait
par-dessus le nom de l'exercice, lui-même écrasé sur une colonne de trois
caractères. Et même corrigé, la longue chaîne restait illisible dès qu'une
prescription mélangeait plusieurs types de série.

**Décisions.**

- **Le survol rend les séries comme la vue séance** : une ligne par groupe de
  séries (`ex.sets` du `PlanFlattener`), plus le résumé compact d'une seule
  chaîne. Le groupement des séries consécutives identiques (`3× 8 reps @ 100 kg`)
  est conservé — c'est la même source `detailedSetGroups`, pas de duplication. Le
  résumé texte (`summarize`) reste tel quel : il sert encore le mode simple et
  l'export Excel.
- **Le libellé de type devient une pastille sigle** : `W` / `D` / `F` / `DS` dans
  une puce cerclée et teintée, à la place de `Échauf` / `Dégressive` / `À l'échec` /
  `Drop set` et de l'icône Lucide. Motif : largeur quasi constante, donc alignable
  en **colonne en tête de ligne** (`settype.slot()` réserve une gouttière fixe pour
  que les séries normales, sans pastille, restent alignées). `D` et `DS` sont
  distincts à dessein — dégressive et drop set ne sont pas la même intention.
- **Revirement assumé sur la couleur.** Le lot précédent avait tranché « badge de
  type monochrome, la teinte est réservée à l'activité et au statut ». Un sigle
  d'une lettre sans teinte n'est plus discriminant à l'œil, contrairement à une
  icône. D'où une **famille de tokens dédiée** `--color-set-*` (ambre / ardoise /
  brique / prune) : la règle « la couleur porte du sens » est tenue, on lui ajoute
  un troisième code au lieu de recycler terracotta ou olive. Encres choisies
  assez foncées pour rester lisibles en 10 px sur leur tint.

**Détail technique.** `SetType::icon()` supprimée (plus aucun appelant) au profit
de `SetType::letter()`. `shortLabel()` reste : le `PlanFlattener` s'en sert pour le
résumé texte. La macro `_set_type.html.twig` expose `badge(type)` et
`slot(type)` ; la pastille est un pictogramme, d'où `role="img"` + `aria-label`
(sans rôle, un `aria-label` sur un `span` n'est pas restitué de façon fiable).
Côté CSS : `.kd-setbadge` passe en puce **pleine** de 18 px (lettre en papier,
display bold 10 px) — la première version, sigle coloré cerclé sur tint, se lisait
mal à cette taille ; le tint sert désormais de fond de ligne dans l'éditeur de
séries (`.kd-set--{type}`), dont l'accent gauche reprend la même couleur : même
signal des deux côtés de l'édition. `.kd-setline` reçoit la pastille en tête au
lieu du badge poussé à droite, et `.kd-setlist--preview` densifie le tout dans le
popover (filet gauche pour rattacher les séries à leur exercice). Le panneau de
survol passe à 340 px, `overflow: hidden auto`, et ses lignes autorisent le
`flex-wrap` — plus aucun débordement latéral possible. Pas de migration.

**Piège coûteux.** La règle de wrap du survol était écrite `.kd-planpreview__exos li`
et non `> li` : les lignes de la sous-liste de séries héritaient donc de
`flex-wrap: wrap` + `justify-content: space-between`, et chaque série éclatait en
trois lignes (pastille, compteur centré, détail). Réflexe à garder dès qu'une liste
en accueille une autre : cibler l'enfant direct.

**Tests** : 121 au vert, `lint:twig` OK. Aucun test ne portait sur `SetType::icon()`.

---

## Lot — Refonte graphique « Presse » & page de consultation d'une séance

**Point de départ.** Une maquette Claude Design (« Séance — Refonte ») prenant la
page séance comme test d'une nouvelle direction graphique, à généraliser ensuite à
toute l'application.

**Le constat qui a cadré le lot.** `templates/base.html.twig` avait sa balise
`<meta name="viewport">` **commentée**. Conséquence : les navigateurs mobiles
rendaient dans un viewport virtuel de ~980 px puis dézoomaient, et **aucune** des
16 media queries du projet ne se déclenchait jamais sur téléphone. Tout le travail
responsive accumulé était inopérant. C'est un fix d'une ligne qui change le
diagnostic complet — à vérifier en premier sur n'importe quel projet.

**Identité.** Papier froid `#dcdcd7`, encre `#0b0b0b`, un seul accent rouge
`#d8261e`, rayon 0, aucune ombre, Barlow Condensed / Barlow / IBM Plex Mono.

Le levier a été l'architecture en deux couches de `tokens.css` : seules les
**primitives** `--kd-*` ont été réécrites, les noms sémantiques `--color-*` n'ont
pas bougé. Les 4 400 lignes de `components.css` se sont repeintes sans être
touchées. Ce qui a demandé du travail manuel, c'est la **forme** : Barlow
Condensed est bien plus étroite que Space Grotesk, un simple échange de famille
donnait des titres maigres. D'où une passe sur les porteurs de `--font-display`
(taille, graisse 700/800, `letter-spacing`, `text-transform`).

**Deux règles de design réécrites.**
1. *Le code couleur par activité disparaît.* La maquette n'emploie que noir, gris
   et rouge. Les catégories passent sur une échelle de gris ordonnée
   (`--color-cat-1..4`), le rouge restant aux actions et à l'intensité. Bénéfice
   collatéral : le trou documenté depuis longtemps (natation, vélo, mobilité sans
   paire accent/tint) se referme — cinq activités couvertes au lieu de deux.
2. *Les pastilles de série passent de quatre teintes à deux axes* : encre pour ce
   qui structure la série, rouge pour ce qui la pousse ; plein pour le travail
   effectif, contour sinon.

**Contraste.** Les gris clairs de la maquette (`#9a9a93` ≈ 2.8:1, `#8e8e86` ≈
3.3:1) échouent en AA. Règle posée dans les tokens : un gris sous 4.5:1 ne porte
**jamais** de texte, seulement des filets et des segments de barre. Les tokens
`--color-text-*` sont tous ≥ 4.5:1 — c'est un net progrès sur « Carnet clair »,
dont les eyebrows mono (`--kd-ink-faint` `#a99f8d` ≈ 2.3:1) étaient en échec alors
qu'ils étaient la signature de l'identité.

**Page séance.** Hero encre pleine largeur, bandeau de 4 KPI (volume,
enchaînements, RPE moyen avec jauge à 10 crans, charge la plus lourde), onglets
Programme / Analyse, blocs en accordéon, tableau de séries détaillées avec plage
de rangs (« 03 — 06 ») et % du max, menu kebab d'actions.

`_workout_read.html.twig` est éclaté en `_workout_program`,
`_workout_sets_table`, `_workout_analysis`, et expose un **bloc Twig `actions`**
plutôt qu'une variable : `workout/show` l'`embed` pour y poser la barre du
propriétaire, `public_share/workout` l'`include` et laisse le bloc vide. C'est
structurellement ce qui garantit qu'aucune commande d'édition ne peut fuiter sur
la page publique (un `include` ne porte pas de blocs, d'où le passage à `embed`).

**Services.** `WorkoutMetrics::summary()` (RPE **pondéré par les séries de
travail** — une moyenne simple donnerait autant de poids à un exercice de 2 séries
qu'à un de 6) et `::blockBreakdown()`, qui délègue la durée à
`WorkoutEstimator::estimateBlockSeconds()` pour que la somme des blocs retombe
exactement sur le total de la séance (un test le vérifie). Nouvel enum
`TargetRegion` : les 17 `TargetArea` regroupées en 4 régions, qui se mappent une
pour une sur l'échelle catégorielle — ventiler 17 zones donnait une barre
illisible. Le regroupement existait déjà en commentaires dans `TargetArea`, il est
juste rendu exploitable.

**Piège évité de justesse.** Le premier rendu affichait `4 × 6 @ 120 kg · RPE 8`
**et** une colonne RPE, puis `6 reps @ 70 kg` **et** une colonne Charge. Cause :
`PlanFlattener` n'exposait que des chaînes **pré-assemblées** (`summary`,
`detail`), pensées pour être affichées seules. Dès qu'une vue donne sa propre
colonne à chaque valeur, il lui faut les parties. D'où `values` (le résumé sans le
suffixe RPE) et `effort` (l'effort d'une série sans sa charge), à côté des chaînes
complètes qui restent utiles à l'export Excel, à l'aperçu au survol et aux
pastilles de calendrier. Réflexe à garder : une chaîne assemblée n'est pas une
donnée, c'est un rendu.

**Mobile et accessibilité, toute l'application.**
- Points de rupture ramenés de neuf valeurs dispersées (480→1100) à **trois** :
  560 / 900 / 1200. Ils ne peuvent pas être tokenisés — `@media` n'accepte pas
  `var()` et il n'y a pas de build CSS (AssetMapper, pas de PostCSS). C'est une
  convention documentée.
- Nouveau `assets/styles/base.css` : `.kd-skip`, `.kd-sr-only`, `:focus-visible`
  global (anneau encre 2 px ; l'ancien halo `--color-primary-tint` ne tenait pas
  les 3:1 de WCAG 1.4.11), `prefers-reduced-motion`, cibles tactiles 44 px sous
  `pointer: coarse`, et une feuille d'impression qui force l'ouverture des
  `<details>` et des panneaux d'onglets masqués.
- La nav condensée **ne masque plus les libellés** : `display: none` retire
  l'élément de l'arbre d'accessibilité, les 4 liens perdaient donc leur nom
  accessible. Sous 560 px elle passe en barre basse fixe (icône + libellé).
- Calendrier mensuel en **agenda vertical** sous 560 px : le scroll horizontal à
  `min-width: 720px` avec des cases de 100 px était impraticable au doigt. Chaque
  case porte désormais son jour de la semaine (`.kd-calday__dow`), masqué tant
  qu'une colonne le nomme.
- Contrôleur Stimulus `tabs` en **amélioration progressive** : le serveur rend
  tous les panneaux avec leurs titres, le contrôleur révèle la barre (rendue
  `hidden`), masque les titres et pose l'ARIA (roving tabindex, flèches,
  Home/End). Sans JS, la page reste complète.

**Détail technique.** `tools/fetch-fonts.sh` créé : `docs/design-system.md`
mentionnait un « script de fetch » qui n'existait pas dans le dépôt. 22 woff2
(264 Ko contre 380 avant). Quatre tokens sémantiques manquants ont été trouvés au
passage (`--color-border-pill`, `--color-border-muted`, `--color-ink-outside`,
`--shadow-raised`) — ils étaient consommés par `components.css` sans avoir jamais
été déclarés, donc silencieusement inertes.

**Ce qui a été écarté.** Trois éléments de la maquette n'ont aucun support dans le
modèle : « Démarrer la séance » (contredit la règle « pas de tracking détaillé »,
CLAUDE.md §3), le champ « Lieu » (inexistant sur `Workout`), et la comparaison
avec une séance précédente (aucun historique modélisé). Écartés plutôt
qu'improvisés.

**Découpe de `components.css` abandonnée.** Elle était au plan (4 400 lignes → 6
fichiers). À l'exécution, aucun découpage contigu ne donnait des fichiers aux noms
honnêtes : l'ordre source entremêle composants transverses et CSS de vue, et un
découpage non contigu produisait un diff de 4 400 lignes rendant la refonte
elle-même illisible en revue. Le fichier reste sectionné par bannières, et la
couche responsive s'y insère au sein de chaque section. `base.css` a été créé pour
le socle transverse — du code neuf, pas du code déplacé.

**Tests** : 128 au vert (dont un `SmokeTest` qui balaie les 18 vues et vérifie
qu'aucune ne casse après la bascule des tokens), `lint:twig` OK.

---

## Lot — Le superset devient une liaison intra-bloc (27/07/2026)

**Le constat.** Le superset n'était pas stocké du tout : il se *déduisait* du
nombre d'exercices d'un bloc (« 2 exercices = Superset », « 3+ = Circuit »), à
trois endroits qui répétaient la même règle — `_workout_program.html.twig`,
`workout/_block.html.twig` et `WorkoutMetrics::summary()`. Conséquence : un bloc
de cinq exercices dont deux seulement s'enchaînent était inexprimable, et un bloc
de deux exercices simplement voisins était annoncé comme un superset. C'est
l'inverse de la réalité : un superset n'est pas un bloc, c'est un lien **entre
deux exercices à l'intérieur d'un bloc**.

**Le modèle retenu.** Un champ `PrescribedExercise.supersetGroup` (smallint,
nullable) : même bloc + même numéro = exercices enchaînés. Écartée, l'entité
dédiée `SupersetGroup` — elle aurait ajouté une table et une couche à cascader
partout (clonage, mise à plat, streams Turbo, suppression) pour un gain qui se
résume à un libellé propre au groupe, alors que les libellés A1/A2 se dérivent
très bien de l'ordre. Écarté aussi, un `rounds` porté par le groupe : le nombre
de tours d'un superset est **déjà** décrit par le `sets` de chacun de ses
exercices, et `Block.rounds` reste au bloc — un troisième multiplicateur aurait
demandé un arbitrage dans tous les services de calcul.

**`SupersetGrouper`, seule autorité.** Deux invariants tenus là et nulle part
ailleurs : membres **contigus** en position, groupe d'**au moins deux** membres.
`normalize()` les rétablit après n'importe quelle mutation en renumérotant les
suites 1..n et en dissolvant les singletons — les numéros se comparent, ils ne
s'interprètent pas. Le reste en découle :
- `linkToPrevious()` ouvre un groupe, l'étend, ou **fusionne** deux groupes qui
  se touchent (plutôt que d'abandonner les liens de l'un des deux) ;
- `detach()` sort d'abord l'exercice **après** le dernier membre du groupe :
  détacher le milieu d'un tri-set laisse les deux autres liés au lieu de tout
  dissoudre ;
- `settleAfterMove()` porte la règle de dépôt : déposé **strictement à
  l'intérieur** d'un groupe, l'exercice le rejoint ; sinon il le quitte s'il ne
  touche plus aucun de ses membres. Changer de bloc détache toujours (le numéro
  n'a de sens que dans son bloc, le garder ferait entrer par accident dans un
  groupe homonyme du bloc d'arrivée).

**Reprise des données (`Version20260727120000`).** La colonne, puis un `UPDATE`
qui place tous les exercices d'un bloc à 2+ dans un groupe unique. L'affichage
de l'ancienne règle est donc préservé à l'identique après migration ; ce qui
n'était pas un vrai enchaînement se délie à la main.

**Mise à plat.** `PlanFlattener` livre désormais chaque bloc sous **deux formes
complémentaires, jamais divergentes** : `exercises` (liste plate, ordre de
lecture) pour ce qui n'a que faire des liaisons — export Excel, ICS, aperçu — et
`segments` (isolés et groupes liés) pour les vues qui montrent l'enchaînement.
Chaque exercice porte en plus son `groupLabel` (« A1 »), ce qui a permis de faire
suivre le rang jusque dans l'aperçu au survol, la colonne « Exercice » de
l'export Excel et la description des événements ICS — trois sorties sans mise en
forme, où le préfixe est la seule façon de lire l'enchaînement.

**Compositeur.** Une bascule par ligne (`lucide:link` / `lucide:unlink`), le rang
A1/A2 devant le nom, un intitulé « Superset A » en tête de groupe et un rail
continu à gauche. Le DOM reste **plat** : SortableJS ne trie que ses enfants
directs, un conteneur de groupe aurait cassé le glisser-déposer. Le groupe se
dessine donc en `margin-left` + `border-left` sur les lignes elles-mêmes
(`.kd-cexo--linked`), pas par un wrapper. La ligne re-rendue seule (stream ciblé
après un enregistrement de paramètre) reçoit son contexte de superset via
`supersetRowContext()`, sinon le rang et la bascule disparaissaient jusqu'au
rechargement.

**Lecture.** La ligne d'exercice est extraite dans
`components/_workout_exrow.html.twig` pour être rendue à l'identique qu'elle soit
isolée ou membre d'un groupe. Le badge Superset/Circuit descend du bloc au groupe
(`.kd-exgroup`), et la liste imbriquée pose `counter-reset: none` — sans ça la
numérotation du bloc redémarrait à 1 à chaque groupe. `WorkoutMetrics::summary()`
compte les enchaînements **au groupe** : le KPI « Enchaînements » d'une séance à
un bloc de 5 exercices peut désormais afficher « 1 superset · 1 circuit ».

**Tests** : 139 au vert, dont `SupersetGrouperTest` (10 cas : découpage,
normalisation, liaison, fusion, détachement du milieu, dépôt entrant/sortant) et
un cas de `PlanFlattenerTest` qui vérifie que `exercises` et `segments` décrivent
le même contenu dans le même ordre. `WorkoutMetricsTest` a été retourné : « deux
exercices dans un bloc » n'est plus un superset, il faut la liaison.

---

## Lot — Les index Séances / Plans s'ouvrent aux athlètes suivis (27/07/2026)

**Le manque.** Le contenu qu'un coach compose pour un athlète appartient à
l'athlète (`setOwner($athlete)`, règle de propriété inchangée). Les index
`/workout` et `/plan-template` se scopaient sur `owner = utilisateur courant` :
un coach ne retrouvait donc **nulle part** ce qu'il avait bâti, sinon fiche par
fiche sous `/coach/athlete/{id}`. Avec trois athlètes, retrouver « la prépa semi
de quelqu'un » demandait de se souvenir de qui.

**La portée.** Nouveau service `CoachedLibrary` : il répond à « quels
propriétaires cet utilisateur peut-il lister » — soi, puis ses athlètes en
relation **acceptée**. La relation reste dirigée : les bibliothèques de mes
coachs ne me regardent pas. Les repositories reçoivent la liste
(`WorkoutRepository::findLibraryForOwnersWithContent`,
`PlanTemplateRepository::findForOwnersWithContent`, tous deux fetch-joints — le
second remplace un `findBy` qui faisait déjà un N+1 par plan pour dériver ses
activités).

Portée **de consultation uniquement**. Les sélecteurs qui posent une séance sur
son propre calendrier ou dans sa propre trame gardent la version à un seul
propriétaire (`findLibraryForOwnerWithContent`) : proposer la séance d'un athlète
à la pose serait un contresens, et `CoachController::scheduleWorkout` refuse
déjà l'inverse.

**Pas de champ « créé par ».** Distinguer ce que le coach a écrit de ce que
l'athlète a écrit demanderait une colonne, sans reprise possible de
l'historique — et le coach a de toute façon le droit de voir et d'éditer tout le
contenu de son athlète (branche coach des voters). C'est déjà ce que montre la
fiche athlète.

**À l'écran.** Un groupe de facette `owner` dans la barre de filtres, avec
**« Moi » actif par défaut** : la page reste celle d'avant pour un usage perso,
les athlètes sont à une puce. Chaque carte qui n'est pas la sienne porte un badge
de propriétaire (`components/_owner_badge.html.twig`, variante cliquable de
`.kd-scope`) qui renvoie à la fiche de l'athlète. L'identifiant de l'athlète
entre aussi dans `data-filter-text` : chercher son email remonte ses entrées.

Le groupe **disparaît** quand il n'y a aucun athlète (`_filterbar` saute les
groupes vides) : sans coaching, rien ne change à l'affichage.

**Deux détails qui n'en sont pas.**
- `_filterbar` accepte désormais un `default` par groupe de facette. C'est ce qui
  permet d'ouvrir la page sur autre chose que « tout ».
- Le contrôleur Stimulus `filter` **lisait** un état initial vide (`this.facets =
  {}` au `connect`). Une puce pré-active en HTML aurait donc été affichée active
  tout en laissant passer tous les items. `readFacets()` lit maintenant les
  classes rendues. Corollaire : ne jamais activer une puce à la main dans un
  template sans passer par `default`.

**Tests** : 143 au vert, dont quatre cas ajoutés à `CoachControllerTest` — les
deux index listent le contenu de l'athlète avec la facette « Moi » active, une
relation `PENDING` n'élargit rien, et l'athlète ne voit pas la bibliothèque de
son coach.
