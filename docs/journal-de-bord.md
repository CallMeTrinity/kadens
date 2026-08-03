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
  détaillé *(règle révisée le 29/07/2026 en « pas de tracking **cardio** » — le
  réalisé de la muscu se logue depuis, hors de cette phase : voir l'entrée
  « Kadens Live » en fin de journal)*. Depuis chaque case du calendrier : formulaire de statut (`PLANNED`/`DONE`/
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
- **Phase 9 — PWA : RÉACTIVÉE en 2026-07 sous une forme amputée** (voir le lot
  « PWA installable » en fin de journal). La suspension ci-dessous n'a plus cours
  pour l'installabilité, mais sa cause reste vraie et gouverne le nouveau service
  worker : plus jamais de HTML servi depuis le cache quand le réseau répond.
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

> *Note du 29/07/2026 : deux des trois écartés reviennent par la porte du chantier
> Kadens Live. La règle invoquée ici a été révisée (« pas de tracking **cardio** »),
> « Démarrer la séance » devient l'app mobile, et la comparaison avec une séance
> précédente devient `PerformanceHistory`. Seul le champ « Lieu » reste sans support.*

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

---

## Lot — L'app devient utilisable au téléphone (27/07/2026)

Point de départ : « le site n'est pas utilisable sur téléphone ». La couche
mobile existait pourtant — trois paliers documentés, agenda vertical, barre de
navigation basse. Elle était annulée par **une seule déclaration CSS**, et le
reste des interactions restait pensé pour une souris.

### La cause racine : `backdrop-filter`

`.kd-header` portait `backdrop-filter: saturate(1.1) blur(8px)`. Une valeur autre
que `none` fait de l'élément le **bloc conteneur de ses descendants en
`position: fixed`** et crée un contexte d'empilement. Or `.kd-nav`, enfant du
header, passe en `position: fixed; bottom: 0` sous 560px : elle se calait donc
sur la boîte de 52px du header au lieu du viewport.

Deux symptômes pour une cause :
- la barre, haute d'environ 54px pour 52px disponibles, **débordait par le haut
  de l'écran** — c'est le « menu tronqué avant le scroll » ;
- son `z-index: 30` la peignait **par-dessus l'avatar et le logo**, seuls chemins
  vers le profil, les objectifs, le coaching et les paramètres. Tout le compte
  était donc inatteignable au téléphone.

Le flou est neutralisé sous 560px (fond opaque à la place — il n'a aucun sens
derrière une barre pleine). Les deux valeurs magiques qui décrivaient la hauteur
de cette même barre avec **deux nombres différents** (`72px` et `56px`) sont
remplacées par `--kd-navbar-h`.

### Trois onglets

La nav principale tombe à **Séances / Plans / Calendrier** — le fil de la
planification. Les exercices rejoignent le menu de compte : c'est un matériau
qu'on consulte en composant une séance, pas une destination quotidienne. Un accès
visible est ajouté sur la page profil, le menu déroulant seul étant trop discret.

### La page d'une séance datée — `GET /schedule/{id}`

Le bouton « fait » demandé n'avait nulle part où vivre : `app_workout_show` montre
la séance de **bibliothèque**, qui ne connaît aucune date et peut être posée sur
dix jours. Nouvelle page, même composant de lecture (`_workout_read` en `embed`),
plus une section « Réalisé » en bas : bascule prévue ↔ faite, note d'écart,
déplacer, retirer. Les endpoints existants sont réutilisés ; ils acceptent un
champ `return=schedule` pour ne pas éjecter vers le calendrier à chaque geste.

Piège rencontré : `{% embed ... only %}` **isole** le composant. Ce que le bloc
`actions` utilise doit être passé dans le `with`, la portée du template appelant
n'y entre pas.

### Le survol cesse d'être un chemin

L'aperçu de séance est un `popover="manual"`, donc **sans light-dismiss**. Au
doigt, un tap émet un `mouseenter` synthétique sans `mouseleave` : le panneau
s'ouvrait et restait collé à l'écran. `preview` et `plangrid` se gardent
désormais derrière `(hover: hover) and (pointer: fine)`.

En conséquence, la pastille de calendrier devient cliquable : son centre est un
**lien** vers la page datée, intercepté par `dialog#openFine` au pointeur fin
seulement (modale rapide sur ordinateur, navigation au doigt), et un **œil** à
droite mène toujours à la page entière. Le HTML de base reste un lien : clavier,
clic du milieu et sans-JS fonctionnent.

### Le reste

- **« Éditer » n'est plus collant.** La barre flottante en bas d'écran recouvrait
  deux lignes du programme en permanence — or c'est le programme qu'on vient
  lire. Elle reste en tête du hero.
- **Vue semaine par défaut au téléphone.** Le cookie `kd_calview` est `httpOnly`,
  donc illisible en JS : c'est le serveur qui dit si une vue a déjà été choisie
  (`viewRemembered`), et le contrôleur `calview` n'aiguille que la première fois.
  Un choix explicite prime ensuite définitivement.
- **Filtres repliés au téléphone.** `_filterbar` devient un `<details>` rendu
  **ouvert** côté serveur, refermé par le contrôleur `collapse` sous 560px. Sans
  JS, rien n'est caché. Arbitrage assumé : le compteur de résultats reste dans le
  panneau — le dupliquer casserait `filter_controller` (`this.countTarget` ne lit
  que le premier target).
- Cibles tactiles : l'œil, le bouton de statut et les puces de facette (~22px de
  haut) rejoignent le plancher de 44px sous `@media (pointer: coarse)`.

**Tests** : 153 au vert, dont cinq cas ajoutés — rendu de la page datée, refus à
un non-propriétaire, bascule du statut dans les deux sens avec retour sur la
page, et pastille de calendrier rendue en lien.

---

## Lot — Le CSS mobile qui existait sans s'appliquer (27/07/2026)

Deux signalements au téléphone : le contenu passe sous la barre de nav basse, et
le bouton « Modifier ma fiche » sort de la carte d'en-tête du profil. Le premier
avait une cause qu'il fallait chercher, parce que le CSS qui devait le régler
existait déjà.

### Le piège : une `@media` n'ajoute aucune spécificité

Le palier 560px était **regroupé** en tête de `components.css`, avec la nav. Il y
déclarait un dégagement pour la fin de page :

```css
@media (max-width: 560px) {
    .kd-page { padding-bottom: calc(var(--kd-space-13) + var(--kd-navbar-h)); }
}
```

Vingt lignes plus bas, la section « Mise en page » définit le composant :

```css
.kd-page { padding: var(--kd-space-13) min(var(--kd-space-8), 4vw) var(--kd-space-13); }
```

Même spécificité, déclarée après : **le raccourci `padding` gagne**, y compris
sous 560px. Le dégagement n'a jamais existé à l'écran. Le média n'y change rien —
il conditionne l'application d'une règle, pas son poids dans la cascade.

Un audit de la feuille (chaque sélecteur d'une `@media` comparé à la position de
sa règle de base) a sorti exactement trois occurrences, toutes réelles :

| Surcharge | Écrasée par | Symptôme |
|---|---|---|
| `.kd-page` padding-bottom | le raccourci `padding` | fin de page sous la barre de nav |
| `.kd-editform__bar` `bottom: var(--kd-navbar-h)` | son `bottom: 0` | « Enregistrer » derrière la barre |
| `.kd-calday__add` `margin-left: auto` | son `align-self: stretch` | le « + » de l'agenda étiré sur toute la largeur |

Les trois surcharges sont déplacées **après la définition de leur composant**,
dans une `@media` locale, avec le commentaire qui explique pourquoi elles ne
peuvent pas remonter. Le bloc du header ne garde que ce qu'il définit lui-même
(`.kd-header`, `.kd-nav`, `.kd-usermenu__panel`, `--kd-navbar-h`) : ces
surcharges-là étaient correctes, mais par chance de rangement.

La règle est consignée dans `docs/design-system.md §5` : **une surcharge
responsive vit avec son composant, jamais regroupée par palier.** C'est la
deuxième fois qu'une seule déclaration CSS annule toute la couche mobile — après
`backdrop-filter`, la leçon est la même : sur une feuille de 10 000 lignes sans
build, la position d'une règle est une information de premier ordre.

### `--kd-navbar-h` devient la place occupée

La barre prend l'`env(safe-area-inset-bottom)` en padding (encoche / barre
gestuelle iOS). La variable l'inclut désormais :

```css
--kd-navbar-h: calc(56px + env(safe-area-inset-bottom, 0px));
```

Sans `viewport-fit=cover` dans la meta viewport, l'inset vaut 0 : le comportement
actuel est inchangé, mais le calcul reste juste si on l'ajoute un jour. À noter,
le fallback doit s'écrire `0px` et non `0` — sans unité, il invalide le `calc()`.

### En-tête du profil : `flex: none` sur un conteneur qui doit se plier

`.kd-phero__actions` porte trois boutons (« Mes exercices », « Paramètres »,
« Modifier ma fiche »), soit ~400px en condensé capitales. Il était en
`flex: none` : sa boîte gardait sa largeur `max-content` et sortait de la carte.
Son `flex-wrap: wrap` interne ne pouvait rien — on ne passe à la ligne que dans
une boîte qui a été contrainte.

- Base : `flex: 0 1 auto` + `min-width: 0`. Le conteneur cède, les boutons
  passent à la ligne. Corrige aussi les largeurs intermédiaires, pas seulement le
  téléphone.
- Sous 560px : les actions prennent la largeur entière sous l'identité
  (`flex: 1 1 100%`, boutons en `flex: 1 1 auto` pour se partager la ligne), et
  la carte resserre son padding — 24px de gouttière interne plus l'avatar de 64px
  ne tiennent pas sur 390px.

`.kd-phero__edit` (fiche athlète chez le coach) suit la même règle.

---

## Lot — PWA installable (icône, écran de démarrage, mode standalone)

Reprise de la **Phase 9, amputée de sa moitié dangereuse**. On veut
l'installabilité (icône d'écran d'accueil, nom, écran de démarrage, plein écran
sans barre d'URL) ; on ne veut plus le mode hors connexion complet, qui servait
des pages périmées **alors qu'on était en ligne** et avait fait suspendre la
phase. Règle qui gouverne tout le lot : **en ligne, le réseau gagne toujours
pour du HTML.**

### Le service worker n'est pas optionnel

C'est le point contre-intuitif : on ne voulait plus de cache, mais Chrome ne
propose l'installation que si un service worker doté d'un gestionnaire `fetch`
est enregistré. Il fallait donc le réécrire, pas le supprimer.

`public/sw.js` (cache `kadens-v3`) n'intercepte que trois choses :

- `/assets/*` et `/pwa/*` → **cache-first**. Les URL AssetMapper sont digestées
  (hash dans le nom) et les visuels PWA ne bougent pas : un changement de contenu
  produit une autre URL, jamais du contenu périmé.
- les **navigations** (`request.mode === 'navigate'`) → **network-first**, repli
  sur la copie en cache puis sur `/offline.html`.

Tout le reste sort du handler sans être touché : non-GET, cross-origin, exports,
flux ICS — et surtout **Turbo**. Une visite Turbo Drive ou un Turbo Stream est un
`fetch()` dont le `mode` n'est **pas** `navigate` : un handler qui traiterait
« tout le reste » en cache-first servirait des fragments périmés, ce qui est
exactement le piège dans lequel la Phase 9 était tombée. C'est la ligne à ne pas
franchir si le service worker évolue.

### Enregistrement conditionné côté serveur

L'enregistrement quitte `app.js` pour `base.html.twig`, où l'environnement est
connaissable : `app.environment == 'prod'` enregistre, **tout autre
environnement désenregistre** ce qui traîne. Ce n'est pas de la précaution
gratuite — tester avec `APP_ENV=prod` en local laisse un service worker actif sur
`localhost`, qui continue ensuite à servir du HTML en cache une fois revenu en
dev. C'est la panne d'origine de la Phase 9, reproduite à volonté.

Conséquence pratique : **pour vérifier l'installabilité en local, il faut
`APP_ENV=prod`.** En dev il n'y a rien à installer.

### `/pwa/` et surtout pas `/icons/`

Apache déclare par défaut un `Alias /icons/` vers ses propres icônes
d'autoindex. Sur le mutualisé Infomaniak on ne peut pas le retirer : tout fichier
de `public/icons/` est **inatteignable en prod**, quel que soit le `.htaccess`.
Les visuels vivent donc dans `public/pwa/`, et `public/icons/` est supprimé (il
ne contenait que l'ancien monogramme terracotta, hors identité depuis « Presse »
et de toute façon jamais servi). `assets/icons/` n'est pas concerné : AssetMapper
le publie sous `/assets/icons/…`.

Ajouté au `.htaccess` : `Cache-Control: no-cache, must-revalidate` sur `sw.js` et
`manifest.json`. Un `sw.js` figé dans un cache HTTP bloque toute mise à jour de
l'app installée jusqu'à expiration.

### Les visuels : `tools/build-pwa-icons.php`

Générateur commité (comme `tools/fetch-fonts.sh` pour les polices), source unique
`assets/icons/kadens.png`. Deux découpes de la marque :

- **Le K seul pour les icônes.** Le lockup complet fait 1,57 de rapport : dans une
  tuile carrée il tombe à ~50 % de hauteur et devient illisible sous 48px. Les
  traits de vitesse sont retirés **par étiquetage de composantes connexes**, pas
  par un recadrage — ils chevauchent le K en abscisse, aucune découpe
  rectangulaire ne les sépare. Les trois masses du K pèsent chacune > 30 % de la
  plus grosse, les six traits < 3 % : le seuil à 8 % tranche largement.
- **Le lockup complet pour les écrans de démarrage**, où la place ne manque pas.

Fond opaque `#ffffff` partout : le transparent est proscrit, iOS compose sur du
noir et la moitié sombre du K disparaîtrait. Couverture de 0,55 pour les
`maskable` (zone sûre à 80 % du côté), 0,74 pour l'`apple-touch-icon` (iOS rogne
les angles), 0,80 pour les icônes standard.

Les 34 écrans de démarrage (17 devices × portrait/paysage) passent en palette
255 couleurs : la marque n'en compte que trois, le reste n'est que de
l'antialiasing — 3,7 Mo → 1,2 Mo sans perte visible.

### Le fragment splash est généré, pas écrit

Le même script produit `templates/components/_pwa_splash.html.twig`. iOS exige
une correspondance **exacte** de la media query et ne redimensionne rien : un
`<link>` sans fichier, ou un fichier sans `<link>`, donne un écran de lancement
blanc. Laisser la liste se maintenir à la main garantissait la dérive. Le test
`PwaHeadTest::testEveryStartupImageIsBackedByAFile` vérifie la bijection dans les
deux sens à partir du HTML réellement rendu.

Android n'a pas besoin de ces images : il compose son écran de démarrage depuis
le manifest (`name` + `background_color` + icône 512).

### Détail qui n'a rien à voir avec le cache : `viewport-fit=cover`

Ajouté à la `<meta viewport>`. Sans lui, `env(safe-area-inset-bottom)` vaut **0** :
`--kd-navbar-h` ignore alors la barre gestuelle iOS et la nav basse passe dessous
en mode standalone. La couche mobile du lot précédent avait posé le calcul, il lui
manquait la déclaration qui le rend non nul — même famille de bug que le
`backdrop-filter`, une seule déclaration qui annule tout un dispositif.

### Reste

Manifest repeint « Presse » (`theme_color` encre `#0b0b0b`, `background_color`
papier `#ffffff`, l'ancien terracotta datait de « Carnet clair ») + trois
`shortcuts` (Calendrier / Séances / Plans). `offline.html` restylé « Presse »
(rayon 0, accent rouge, cible tactile 44px) — ses couleurs restent codées en dur,
seule entorse tolérée à la règle des tokens : la page doit s'afficher sans
feuille de style, et les URL AssetMapper étant digestées on ne peut pas les y
inscrire. Elle affiche `/pwa/icon-192.png`, précaché.

**Non vérifiable ici** : installation réelle, rendu de l'écran de démarrage iOS et
audit Lighthouse demandent un navigateur sur `kadens.antoninpamart.fr` (HTTPS
requis pour le service worker).

---

## Correctif — Le bouton « fait » qui ne se rafraîchissait pas (27/07/2026)

Sur `/schedule/{id}`, marquer une séance comme faite enregistrait bien le statut
mais ne changeait rien à l'écran : il fallait recharger la page pour voir la
pastille basculer.

### Cause

Le formulaire poste vers `app_scheduled_workout_status`. Turbo envoie
`Accept: text/vnd.turbo-stream.html`, donc `getPreferredFormat()` vaut
`turbo_stream` — et le contrôleur testait ce format **avant** de regarder
`return=schedule`. Il répondait donc avec le stream du calendrier, qui remplace
`#cal-event-{id}`. Cet élément n'existe pas sur la page de la séance datée.

**La règle à retenir : un `<turbo-stream>` dont la cible est absente du DOM
n'échoue pas, il ne fait rien.** Pas d'erreur console, pas de 500 — le réseau
répond 200, le statut part en base, et l'écran ment jusqu'au rechargement. Un
endpoint servi par deux pages doit donc choisir son fragment en fonction de
l'appelant, pas seulement en fonction du format demandé.

### Correction

Le même `return=schedule` qui pilotait déjà la redirection de repli pilote
maintenant aussi le choix du stream (`streamScheduleStatus()` vs
`streamCalEvent()`). Deux cibles, parce que le statut se lit à deux endroits sur
cette page :

- `#schedule-badge` — la pastille du hero, extraite dans
  `components/_scheduled_badge.html.twig` ;
- `#schedule-done` — la section « Réalisé » entière, extraite dans
  `components/_scheduled_done.html.twig` (le libellé du bouton, le statut posté
  et la note d'écart en dépendent tous).

L'extraction en composants n'est pas cosmétique : elle est ce qui permet au
stream de re-rendre exactement le même markup que le rendu initial, id compris.
Le repli sans JS (redirection vers `app_scheduled_workout_show`) est inchangé.

Couvert par `CalendarControllerTest::testDoneButtonAnswersWithAStreamTargetingThisPage`,
qui poste avec l'en-tête `Accept` de Turbo et vérifie que la réponse vise bien
les fragments de cette page — et jamais `cal-event-{id}`.

---

## Lot — Une ligne par série, quel que soit le mode de saisie (27/07/2026)

**Le constat.** La page d'une séance affichait deux langages selon la façon dont
l'exercice avait été saisi. Séries détaillées : un tableau, une ligne par groupe,
type, rang, charge, % du max. Mode simple : une seule chaîne compacte, « 3 × 15
@ 130 kg », posée dans l'en-tête de la ligne d'exercice. Même contenu prescrit,
deux lectures — et il fallait décoder la chaîne pour comparer deux exercices
voisins.

**La règle posée.** Sur la page de consultation, **une ligne = une série**,
toujours. Trois séries scalaires valent trois lignes identiques ; dix séries
valent dix lignes. La répétition n'est pas du bruit, c'est ce qui rend la lecture
uniforme et ce qui permet de compter des yeux.

**Deux vues, pas deux vérités.** `PlanFlattener` expose désormais les séries sous
deux formes, sur le modèle de `summary`/`values` et `exercises`/`segments` :

- `sets` — la vue **condensée** (`detailedSetGroups`), inchangée : les séries
  consécutives identiques fusionnent, chaque groupe garde son rang réel. Elle
  reste réservée au mode détaillé et alimente les contextes compacts (résumé
  `summarizeDetailedSets`, aperçu au survol, export, pastille de calendrier).
- `setLines` — la vue **déroulée**, nouvelle : une entrée par série
  (`type`, `typeLabel`, `index`, `effort`, `weightKg`), dérivée de la collection
  détaillée si elle existe, **synthétisée depuis le scalaire** sinon (toutes les
  lignes en `SetType::NORMAL`, mêmes valeurs). C'est ce que consomme la page.

Le déroulé est réservé à `SETS_REPS` / `SETS_TIME`. Le `sets` d'un
`DISTANCE_PACE` compte des **intervalles**, pas des séries : « 8 × 400 m » reste
une ligne de résumé, et un exercice sans compteur (ajout express, `sets` null)
garde son repli sur `values` — pas de tableau de « ? reps » pour un exercice
qu'on n'a pas encore paramétré.

**Conséquences à l'écran.**

- `_workout_sets_table` prend `lines` au lieu de `groups` : plus de colonne
  multiplicateur (`.kd-setrow__mult`, supprimé du CSS), plus de plage « 02 — 03 »,
  un rang simple par ligne.
- Le « % du max » ne s'affiche plus que si les charges **varient** : à charge
  constante — le cas de toute prescription scalaire — la colonne n'aurait aligné
  que des 100 %.
- L'en-tête de la ligne d'exercice n'affiche plus qu'un compte (« 4 séries ») dès
  qu'un tableau le suit : répéter « 4 × 8 @ 60 kg » juste au-dessus des quatre
  lignes qui le disent était redondant. Le libellé « séries détaillées » disparaît
  avec ça — en lecture, la distinction n'existe plus.

**Ce qui n'a pas bougé.** L'aperçu au survol garde sa vue condensée (un panneau de
survol doit tenir à l'écran), l'export Excel et le flux ICS gardent `summary`, et
l'éditeur garde ses deux modes de saisie : c'est une refonte de **lecture**, pas
du modèle.

Couvert par `PlanFlattenerTest` (déroulé scalaire, déroulé détaillé sans fusion,
intervalles non déroulés) et les tests de contrôleur `WorkoutControllerTest` /
`PublicShareControllerTest`, qui comptent désormais les `<tr>` du tableau.

### Suite — La largeur du tableau

Dérouler les séries a rendu visible un défaut que la vue condensée masquait : le
tableau prenait toute la largeur disponible. Sur un grand écran, « 6 reps » et
« 80 kg » se retrouvaient séparés de plusieurs centaines de pixels — on ne lit
plus une ligne, on traverse un vide — et sous 560px il défilait horizontalement
dans son propre cadre, geste sans repère visuel dans une page qui, elle, ne
défile pas : on rate des colonnes sans savoir qu'elles existent.

Trois corrections, dans cet ordre d'efficacité :

1. **Deux colonnes conditionnelles.** « % du max » ne s'affiche que si les
   charges varient, « Type » que si une série est qualifiée. Une prescription
   scalaire tombe donc à trois colonnes — c'est la moitié de la largeur gagnée
   avant même de toucher au CSS, et une colonne vide en moins à l'écran.
2. **Cadre plafonné à `34rem`** (`.kd-settable__wrap`), au lieu de suivre la
   largeur de la page. Cinq colonnes courtes n'ont pas besoin de plus ; le
   `min-width` du tableau descend de 30rem à 26rem.
3. **Compression sous 560px** plutôt que défilement : `min-width: 0`, corps à
   12px, gouttières à `--kd-space-2/3`, gouttière de pastille à 2,5rem, tracking
   d'en-tête réduit. L'`overflow-x: auto` reste, mais comme filet de sécurité
   (écrans très étroits), plus comme mode de lecture normal.

---

## Lot — Le compositeur de séance au téléphone (27/07/2026)

**Le constat.** L'éditeur était la page la moins utilisable au doigt, pour deux
raisons distinctes.

D'abord l'ordre : sous 900px, les deux volets s'empilaient, la bibliothèque
au-dessus des blocs. On traversait donc un panneau de recherche, de filtres et de
cartes — dont on n'a besoin qu'au moment précis d'ajouter un exercice — avant
d'atteindre ce qu'on est venu voir. À chaque défilement.

Ensuite la ligne d'exercice. Elle alignait sur une seule rangée sans retour à la
ligne : poignée, rang, code, nom, type, pastille de résumé, bascule de superset,
bouton paramètres, croix de suppression. Neuf éléments. En colonne étroite, la
pastille mono poussait le nom hors de la ligne, et les boutons de 26px se
chevauchaient en débordant du cadre — ce que montrait la capture d'écran : trois
carrés empilés sur un « tou(rs) » tronqué.

**Le principe retenu.** Une ligne ne porte que deux choses : ce qu'elle est, et
un menu. Tout le reste se déduit du geste.

- **Taper la carte la déplie.** Le bouton « paramètres » n'existe plus ; c'est la
  carte entière qui est le bouton (`.kd-cexo__main`), et un chevron dit son état.
- **L'appui long la soulève.** Plus de poignée-cible : SortableJS départage les
  deux gestes par le **temps** (`delay: 320` + `delayOnTouchOnly: true`), avec un
  `touchStartThreshold` qui laisse le défilement partir en premier. Au pointeur
  fin le délai retombe à zéro — la souris garde son glisser immédiat, et l'icône
  de préhension n'est plus qu'une affordance.
- **Le reste passe dans un menu.** Enchaîner/détacher, monter, descendre, retirer :
  quatre entrées en toutes lettres derrière trois points, au lieu de quatre icônes
  nues côte à côte. Un `title` ne se survole pas au doigt. Même traitement pour
  l'en-tête de bloc, dont les trois carrés passaient par-dessus le champ
  d'intitulé.
- **Le résumé passe sous le nom.** Posé à côté, il l'écrasait ; dessous, les deux
  se lisent.

**La bibliothèque devient une feuille.** Sous 900px, `.kd-composer--sheet` la sort
du flux : elle monte du bas par-dessus un voile, ouverte depuis un « + Ajouter un
exercice » attaché à chaque bloc — qui désigne du même geste le bloc de
destination. Taper une carte l'ajoute et referme. Sur écran large, rien ne change :
la classe n'a d'effet que dans la `@media`, et le même bouton donne simplement le
focus à la recherche de la colonne de gauche. La carte de bibliothèque est
devenue un vrai `<button>` (sélecteur `button.kd-libx`, pour ne pas toucher la
palette de trame ni la barre d'ajout du calendrier, qui restent des zones à
glisser) : le « + » de 26px était la seule cible d'ajout, et elle était trop petite.

**Les champs de paramètres ne sont plus des boîtes.** Onze champs encadrés côte à
côte, c'était onze contenants pour une poignée de chiffres. Dans
`.kd-cexo__params`, le champ n'est qu'une valeur posée sur un filet, et ne
redevient une boîte qu'au focus — le seul moment où le contour informe. La portée
est volontairement limitée au compositeur : le même formulaire sert au panneau
rapide du calendrier, qui garde des champs pleins. Effet de bord utile : les
`style="flex:1 1 140px"` inline du formulaire prescrit sont devenus une classe
`.kd-fieldrow__cell`, donc surchargeable — la base tombe à 88px dans le panneau,
où les valeurs tiennent en trois caractères.

**Pièges rencontrés, à ne pas réintroduire.**

- **`overflow: hidden` sur `.kd-cblock` et `.kd-composer`.** Il ne servait qu'à
  empêcher un fond de sortir des coins arrondis, mais il clippait les menus, qui
  sont des calques absolus dépassant par le bas. Remplacé par un rayon porté
  directement par l'en-tête de bloc et par la bibliothèque. `.kd-composer` garde
  le sien : c'est la variante `--sheet` qui l'ouvre, parce que `.kd-composer__lib`
  et `__main` servent aussi à l'éditeur de trame.
- **L'état déplié ne peut pas vivre sur la ligne.** Le stream ciblé qui réécrit
  `#cexo-row-{id}` après une sauvegarde de paramètre la rendrait à
  `aria-expanded="false"` alors que le panneau, frère de la ligne, est resté
  ouvert. L'état est donc porté par `.kd-cexo--open` sur la **carte** (qui
  survit), et `aria-expanded` est resynchronisé après chaque flux — dans un
  `requestAnimationFrame`, `renderStreamMessage` rendant de façon asynchrone.
- **Le bouton d'ajout est sorti du conteneur trié.** SortableJS calcule ses index
  sur les enfants directs de `[data-composer-target="items"]` : un bouton parmi
  eux décalait le point de dépôt.
- **`--kd-navbar-h` n'existe que sous 560px.** L'utiliser dans la `@media` 900px
  demande un repli (`var(--kd-navbar-h, 0px)`), sans quoi la déclaration est
  simplement invalide entre les deux.
- **Une hauteur défilable se contraint sur toute la chaîne.** La feuille ne
  défilait pas : `overflow-y: auto` était bien sur la liste, mais deux maillons
  au-dessus étaient libres — le conteneur en `display: flex` sans
  `flex-direction: column` (en `row`, l'item est étiré à la hauteur de la ligne,
  qui suit son contenu, donc il déborde du `max-height` au lieu de s'y contraindre)
  et le panneau sans `flex: 1` (son `height: 100%` ne résout pas quand le parent
  n'a qu'un `max-height`). Un seul maillon libre annule le défilement, et le
  symptôme se lit sur l'élément le plus bas alors que le défaut est au-dessus.
- **La fermeture au clic extérieur n'appartient pas au voile.** Un voile ne
  recouvre que ce qui est sous lui dans l'ordre de peinture : un clic sur un calque
  au-dessus ne le traverse pas. Elle est portée par un écouteur `click` sur le
  **document**, avec deux gardes — le clic d'ouverture remonte dans la même phase
  de bouillonnement, la classe étant déjà posée, et il faut ignorer l'intérieur du
  panneau. Le voile ne garde que son rôle visuel (assombrir, absorber les clics
  destinés au contenu derrière).
- **Les surcharges responsive sont écrites en fin de section**, après les
  composants qu'elles surchargent (cf. `docs/design-system.md` §5).

Nouveau token sémantique `--color-scrim` (le voile de `.kd-modal::backdrop`, qui
était une valeur en dur, le consomme désormais aussi) et nouveau fragment
`_menu_form.html.twig` — la variante « item de menu » de `_action_form`, qui rend
un bouton icône seul. (Il vit dans `templates/components/` depuis que l'éditeur de
trame le consomme lui aussi.)

---

## Lot — L'éditeur de plan au téléphone (28/07/2026)

**Le constat.** Le lot précédent avait traité le compositeur de séance ; l'éditeur
de trame était resté avec exactement les mêmes défauts, un cran plus loin même,
parce qu'il empile deux niveaux (semaine, puis jour).

- La palette s'empilait **au-dessus** de la trame sous 1200px : on la traversait à
  chaque défilement, pour un panneau dont on n'a besoin qu'au moment de poser.
- Poser une séance demandait le mode **tampon** — armer une carte, puis retrouver
  la case et la taper. Deux gestes séparés par un défilement, et un état invisible
  entre les deux.
- Une case posée n'offrait qu'une **croix de 22px** et une **poignée de 13px**.
  Le déplacement n'avait aucun repli : ni clavier, ni sans JS.
- L'en-tête de semaine alignait un `<select>` de destination et deux boutons, qui
  passaient à la ligne et doublaient la hauteur de chaque en-tête.

**Le principe retenu, repris tel quel du compositeur.** *Une ligne ne porte que ce
qu'elle est et un menu ; le reste se déduit du geste.*

### La palette devient une feuille, et le mécanisme est mutualisé

Les règles de feuille ne sont plus scopées `kd-composer--sheet` mais
**`kd-libsheet`** : le conteneur des deux volets la porte (compositeur *et*
`.kd-planeditor`), son contrôleur y pose `kd-libsheet--open`. Le voile
`.kd-composer__scrim`, `.kd-noscroll` sur `<body>`, le bouton de fermeture, la
chaîne de hauteurs défilables : une seule définition pour les deux écrans. Ce qui
reste propre à un éditeur garde sa portée à lui (`kd-composer--sheet` pour le
débordement et le rayon du compositeur, `.kd-planeditor` pour les siens).

### Le « + » d'un jour désigne la case

C'est le pendant du « + Ajouter un exercice » attaché à un bloc : il ouvre la
palette **sur** cette case (mémorisée dans le contrôleur), et taper une carte y
pose directement la séance. Le mode tampon reste, mais il n'est plus le seul
chemin — et il est explicitement désarmé quand une case est visée, deux intentions
de pose concurrentes rendant le prochain clic imprévisible. Un rappel
`Poser dans S2 · mercredi` s'affiche en tête de palette : en feuille, la trame est
masquée, sans lui on ne sait plus où l'on pose.

Le bouton vit **hors** de `[data-plangrid-target="cell"]` (SortableJS calcule ses
index sur les enfants directs) et suit le motif du « + » du calendrier : révélé au
survol à la souris, visible en retrait sous `@media (hover: none)` — c'est le seul
chemin d'ajout au doigt, il ne peut pas dépendre d'un survol.

### La carte entière est la prise, le reste passe en menu

Même départage par le temps que dans le compositeur (`delay: 320` +
`delayOnTouchOnly`, `filter` sur le menu et la note en édition en ligne) : tap =
édition rapide, appui long = soulever. La poignée disparaît.

Le menu kebab de la case porte **Édition complète**, **Déplacer vers** et
**Retirer de la trame**. Celui de la semaine porte **Copier vers** et **Retirer la
semaine**. Deux d'entre eux ne sont pas de simples boutons : ils demandent un
choix avant d'agir, d'où `.kd-kebab__form` (libellé, puis une ligne de `<select>`
et un bouton). « Déplacer vers » est au passage le **premier repli** du
glisser-déposer de trame : il n'y en avait aucun, ni au clavier ni sans JS, et
c'est aussi le seul geste praticable quand la case d'arrivée est à trois semaines
de défilement. Verrouillé par `testMoveItemFromTheRowMenuWithoutJs`.

### Pièges rencontrés

- **`.kd-planeditor` ne peut plus clipper.** Il portait `overflow: clip` (choisi
  pour ne pas casser la palette `sticky`) ; les panneaux de menu, calques absolus,
  en sortent par le bas. Même piège que `overflow: hidden` sur `.kd-cblock`. Le
  rayon passe sur la palette, qui est l'enfant de bord.
- **`.kd-planitem form { display: inline-flex }`**, hérité du bouton de retrait,
  est plus spécifique que `.kd-kebab__form` : il remettait les formulaires du menu
  en ligne. Supprimé — et signalé sur place, parce que c'est le genre de règle
  qu'on réintroduit sans y penser.
- **Une feuille qui monte du bas doit remettre `top: auto`.** La palette de trame
  est `sticky` avec un `top` sur écran large ; la règle de feuille passait bien en
  `position: fixed` avec `bottom: 0`, mais le `top` hérité restait — le panneau se
  retrouvait ancré des **deux** côtés et s'étirait depuis le haut de l'écran au
  lieu de monter du bas. Le compositeur ne l'avait jamais montré : sa colonne n'a
  pas de `top`. Mutualiser une règle, c'est hériter des `position` des deux écrans.
- **`base.css` est importé avant `components.css`.** Sa règle jumelle
  `@media (pointer: coarse) { .kd-planday__add { opacity: .55 } }` est écrasée par
  l'`opacity: 0` du composant : la révélation au survol doit être neutralisée dans
  `components.css`, après la définition (même famille de piège que les `@media`
  sans spécificité).
- **On n'a PAS étendu l'interception des soumissions à toute la section.** C'était
  tentant (le compositeur le fait), mais ici les formulaires de la trame sont déjà
  soumis par Turbo, qui répond en stream *et* fait remonter les réponses en erreur.
  Un `fetch` maison les aurait avalées en silence.

---

## Lot — Bloc-notes privé sur une séance et sur un plan (28/07/2026)

**Le besoin.** Un endroit où jeter le déroulé en vrac et le construire petit à
petit : le brouillon qui précède les blocs et les cases. La `description` ne peut
pas jouer ce rôle — elle est lue par le coach, par le partage public et par
l'export ; ce qu'on y écrit s'adresse à quelqu'un.

**La règle.** `notes` est la **seule** chose que le coach ne voit pas du contenu de
son athlète. C'est une exception assumée à « le coach est co-éditeur de tout », et
elle tient parce qu'elle est étroite : un champ, deux entités, aucune lecture
ailleurs.

### Le modèle

`Workout::$notes` et `PlanTemplate::$notes` (TEXT nullable, migration
`Version20260728100000`). Rien d'autre : pas d'entité, pas de voter, pas de table
de jointure — l'alternative « un bloc-notes par utilisateur » (chacun le sien sur
la même séance) a été écartée, elle payait une entité et un voter pour un besoin
que personne n'a encore exprimé.

### La portée, doublée

- **À l'écran** : `templates/components/_private_notes.html.twig` ne se rend que si
  `entity.owner.id == app.user.id`. Comparaison par `id`, pas par objet : `owner`
  peut être un proxy Doctrine non initialisé, que Twig comparerait attribut par
  attribut, donc à tort différent.
- **Au serveur** : `updateMeta` (séance et plan) refuse `field=notes` en 403 quand
  l'utilisateur n'est pas le propriétaire. L'attribut `EDIT` de la route ne suffit
  pas — c'est précisément lui que le coach possède. Sans cette garde, l'endpoint
  répondant la valeur persistée, un coach aurait pu **lire** le brouillon en le
  réécrivant.
- **Nulle part ailleurs** : le champ n'entre pas dans `PlanFlattener`, donc ni la
  page de consultation, ni `public_share`, ni l'export Excel, ni l'ICS ne peuvent
  le faire fuiter par inadvertance. C'est l'intérêt de ne pas l'exposer au
  flattener.

### Ce qui n'est pas copié

`WorkoutCloner` ne recopie pas `notes`. Le fork à la pose est le cas dominant en
nombre : chaque case d'un plan en aurait reçu un exemplaire, et le même fourre-tout
se serait retrouvé dupliqué dix fois. La `description`, elle, décrit la séance
elle-même et suit la copie.

### L'écran

Un `<details>` rendu **ouvert par le serveur** dès qu'il contient quelque chose,
posé sous l'en-tête des deux éditeurs. Le champ réutilise `inline-edit` en mode
`textarea` : cliquer ouvre, blur (ou Ctrl/Cmd+Entrée) enregistre. Comme le titre et
la description, il n'a pas de repli sans JS — c'est la règle déjà en vigueur pour
les métadonnées.

### Piège rencontré

**Le padding vit sur `.kd-notes__body`, pas sur `.kd-notes__text`.** `inline-edit`
insère son `<textarea>` en **frère** du display, pas à sa place : un retrait porté
par le paragraphe ne s'appliquerait pas au champ, qui viendrait coller aux bords du
cadre. Vaut pour tout futur usage du contrôleur dans un conteneur à retrait.

---

## Lot — Pages d'erreur 404 / 403 / 5xx (28/07/2026)

**Le besoin.** En prod, une adresse morte, un voter qui dit non ou une panne
renvoyaient la page blanche générique de Symfony : hors identité, en anglais, sans
la moindre sortie. C'est la seule famille de vues qu'on ne conçoit jamais parce
qu'on ne la voit pas en développement — en dev, la page de debug prend sa place.

**La forme.** Quatre templates dans `templates/bundles/TwigBundle/Exception/`
(`error404`, `error403`, `error500`, `error.html.twig`), tous minces : ils étendent
`base.html.twig` et passent leur texte au squelette commun
`templates/components/_error.html.twig`. Composition « Presse » : le code HTTP en
très gros au filet, puis statut, titre, phrase, actions, et une ligne d'aide.

### Pourquoi un `error.html.twig` générique en plus des trois

Symfony cherche `error{code}.html.twig`, **puis** `error.html.twig`. Le générique
est ce qui fait que « 50X » est couvert sans écrire un template par code : 502, 503
et 504 (proxy, redémarrage, timeout sur le mutualisé) y tombent, comme les 4xx
rares (405, 429). Il bascule son texte et sa couleur sur `status_code >= 500`.

### Le rouge ne sort que sur un échec serveur

`kd-error--fault` (chiffre en `--color-primary`) n'est posé que par la 500 et par le
générique en 5xx. Une 404 ou une 403 sont des **réponses normales** du système, pas
des pannes : elles restent à l'encre. C'est l'application directe de la règle
« la couleur porte du sens » (`CLAUDE.md` §5, règle 2) — repeindre les quatre pages
en rouge aurait vidé le signal.

### Ce que le squelette n'a pas le droit de faire

Aucun accès base, aucun service métier, aucune donnée. Une page d'erreur doit se
rendre **quand le reste est cassé** — si la panne est côté MariaDB, la 500 doit
quand même s'afficher. Corollaire : `app.request` est gardé par un `if`, le rendu
pouvant avoir lieu hors cycle de requête, et les liens proposés dépendent de
`app.user` (anonyme → `/login`, pas une page qui redirigerait aussitôt).

### Piège : ces pages ne se voient pas en dev, et ne se testent pas par HTTP

`TwigErrorRenderer::render()` court-circuite ses templates dès que `kernel.debug`
est vrai, et rend la page de debug à la place. Conséquences :

- **Pour les regarder en local** : `APP_ENV=prod` (le préviseur `/_error/{code}`,
  lui, n'existe qu'en dev — donc en debug, donc il montre la page de debug, pas la
  nôtre).
- **Pour les tester** : `ErrorPageTest` rend les templates **directement** par le
  service Twig, avec une requête empilée dans le `RequestStack`. Une requête HTTP
  en test (debug=1) ne les exercerait jamais. `strict_variables` étant actif en
  test, ce rendu attrape ce qu'on veut attraper : variable inexistante, filtre
  absent, route morte.

Vérifié en plus par un rendu réel du noyau en `prod`/`debug=0` sur une adresse
inconnue. À noter si l'exercice se refait : en CLI, `error_renderer` est choisi par
`kernel.runtime_mode.web`, qui vient de la **query string** `APP_RUNTIME_MODE` — il
faut `APP_RUNTIME_MODE='web=1'` (et non `web`), sinon c'est le `CliErrorRenderer`
qui répond et on ne teste rien.

### Détails

- L'adresse demandée n'est rappelée qu'en 404 (`showUri`), tronquée à 120
  caractères — `twig/string-extra` n'est pas installé, la troncature est faite à la
  main au `slice`.
- « Réessayer » n'apparaît qu'en 5xx : rejouer une 404 ou une 403 ne peut rien
  donner d'autre.
- Aucune nouvelle icône : `search`, `lock`, `zap`, `home`, `calendar-days`,
  `log-in`, `rotate-cw` étaient déjà figées en local.

---

## Chantier ouvert — Kadens Live : le réalisé se logue (29/07/2026)

**KL-01, lot 0 du chantier.** Ce lot ne livre aucune ligne de code : il **révise une
règle verrouillée** avant que quoi que ce soit s'écrive dessus. Une session de dev
qui lirait `CLAUDE.md` sans cette mise à jour appliquerait une règle abrogée — d'où
un ticket à part, en tête du découpage.

Cadrage complet, modèle de données et 51 tickets :
[`docs/feature-live-tracking.md`](./feature-live-tracking.md).

### La règle qui change

`ROADMAP.md §1.5` et `CLAUDE.md §3` disaient *« aucun log détaillé de séries
réalisées, Strava fait le suivi »*. C'était **mal calibré** : Strava enregistre une
activité « musculation » avec une durée et un chrono, et rien d'autre — ni série, ni
charge, ni exercice. La frontière défendue n'existait donc que pour le cardio. Elle
devient :

> **Le réalisé se logue en muscu, jamais en cardio.** Une séance de force écrit son
> réalisé série par série sur la séance datée, parce que rien d'autre ne le fait. Une
> sortie course, vélo ou natation ne se logue pas ici : Strava la couvre, et Kadens
> se contente du `ScheduledStatus`.

Ce n'est pas un assouplissement de confort : c'est reconnaître qu'on avait interdit
un besoin réel en croyant éviter un doublon qui n'en était pas un.

### Ce que la révision entraîne, et qui est écrit noir sur blanc

- **Le prescrit ne bouge jamais, le réalisé vit à côté** (`LoggedExercise` /
  `LoggedSet` sur la séance datée). C'est la déclinaison de « préserver le réalisé »
  déjà tenue par `PlanScheduler`.
- **`ScheduledWorkout.workout` passera de `CASCADE` à `SET NULL`** (KL-02). Le
  commentaire du code — *« la séance datée n'a pas de sens sans sa séance source »* —
  devient faux le jour où elle porte le réalisé : en l'état, supprimer une séance de
  bibliothèque effacerait une séance réellement faite. C'est la conséquence la plus
  dangereuse du chantier, donc celle qui est notée en premier.
- **Le réalisé n'entre jamais dans `PlanFlattener`** : même garde que le bloc-notes
  privé, et pour la même raison — c'est ce qui l'empêche de fuiter dans l'export
  Excel, l'ICS et la page publique sans avoir à y penser à chaque vue.
- **`LOG` (propriétaire seul) et non `EDIT`** (que le coach possède) pour toute
  écriture du réalisé.

### Effet de bord : le lot B de la progression est tranché

`docs/feature-progression.md §3` attendait un arbitrage depuis le 26/07 (trois
options : rester au binaire, un réalisé « léger » agrégé, ou des records saisis à la
main au profil). La révision le règle sans avoir à choisir entre elles : **le réalisé
se lit sur `LoggedSet`**, et le lot B devient les tickets KL-49 à KL-51. L'option
« records au profil » tombe en particulier d'elle-même — un record se **dérive** des
séries loguées (`PerformanceHistory`) au lieu de se ressaisir, ce qui supprime la
saisie manuelle qui était son seul vrai défaut.

### Fichiers touchés

`ROADMAP.md` (§1.5 reformulé, résumé de tête, §2.3 étendu, Phase 7 point 4 amendé
plutôt que réécrit — l'ancienne phrase reste barrée, pour qu'on voie qu'elle a été
révisée et non oubliée), `CLAUDE.md` (§3, nouvelle puce et ses quatre corollaires),
`docs/feature-progression.md` (§0, §3, §6), et cette entrée.

**Prochain ticket : KL-02** — les entités du réalisé et la migration de
`ScheduledWorkout`, le plus sensible du lot 1.

---

## Kadens Live KL-02 — le modèle du réalisé en base (29/07/2026)

Le ticket le plus sensible du lot 1 : deux tables neuves, quatre colonnes sur
`ScheduledWorkout`, et **un changement de clé étrangère qui protège des données
qui n'existent pas encore**.

### Ce qui est en base

`logged_exercise` (un exercice réellement fait) et `logged_set` (une série
réellement faite) pendent de `ScheduledWorkout`, qui gagne `uuid`, `title`,
`started_at` et `ended_at`. Pas d'entité conteneur : la séance datée portait déjà
l'owner, la date, le statut et la note d'écart. Le prescrit n'est pas touché —
`workout`, `prescribed_exercise` et `prescribed_set` sortent du ticket inchangés.

Les liens sont volontairement **faibles là où le réalisé doit survivre** :
`exercise` et `source_prescribed_exercise` en `SET NULL`, avec `exercise_name` en
snapshot. Nettoyer la bibliothèque ou retoucher un programme ne rend jamais
illisible une séance faite.

### Le changement qui portait le risque

`ScheduledWorkout.workout` passe de `CASCADE` à `SET NULL`. Le commentaire du code
disait *« la séance datée n'a pas de sens sans sa séance source »* : vrai tant
qu'elle ne portait qu'un statut, faux dès qu'elle porte le réalisé. En l'état,
supprimer une séance de la bibliothèque aurait effacé une séance réellement faite.

Le piège n'était pas la migration, qui est mécanique. Il était **dans les
requêtes** : `ScheduledWorkoutRepository` faisait cinq `join('s.workout')`
internes. Une séance sans source n'aurait pas planté, elle aurait **disparu** —
du calendrier, du flux ICS, du profil. Un bug silencieux vaut toujours plus cher
qu'une erreur. Tous passés en `leftJoin`.

Corollaire tenu partout ailleurs : `getDisplayTitle()` (titre vivant → snapshot →
« Séance libre ») est le seul chemin d'affichage du titre d'une séance datée. Le
calendrier, la page `/schedule/{id}`, l'aperçu au survol, la fiche athlète du
coach, l'ICS et l'export y passent. Le snapshot lui-même se pose au `prePersist` :
aucun appelant n'a eu à changer.

### `char(36)` a demandé un type maison

Le ticket voulait `char(36)` « avec le type Doctrine `uuid` de symfony/uid ». Les
deux ne vont pas ensemble : ce type choisit sa colonne en comparant
`getGuidTypeDeclarationSQL()` à `CHAR(36)` pour deviner si la plateforme a un GUID
natif. Sur MySQL/MariaDB les deux sont identiques, la détection échoue, et il
retombe sur `BINARY(16)`.

D'où `App\Doctrine\UuidCharType`, enregistré **sous le nom `uuid`** dans
`config/packages/doctrine.yaml`. Les entités écrivent `type: 'uuid'` comme
d'habitude, la valeur PHP reste un `Symfony\Component\Uid\Uuid`, seule la colonne
change. Le motif du choix tient : un uuid de séance datée se lit dans un `SELECT`,
se recopie dans une URL d'API, se compare à l'œil avec ce que le mobile a envoyé.

### La migration, en deux temps

`uuid` est ajouté **nullable**, peuplé par `UUID()` (évalué par ligne sous
MariaDB), `title` recopié depuis `workout`, et seulement ensuite le `NOT NULL` et
l'index unique. L'ordre inverse aurait échoué sur toutes les lignes en base.
Jouée, annulée, rejouée sur la base de dev peuplée (44 séances datées réelles), et
la chaîne complète des 17 migrations rejouée sur une base vierge.

### Piège d'environnement

La base de dev portait encore la table `logged_set` de la branche abandonnée
`feature/live-session-tracking` (`Version20260727180000`, statut « migrated, not
available » : son fichier n'est sur aucune branche livrée). Elle entrait en
collision avec la table du même nom, de forme différente. Sauvegardée, supprimée,
ligne retirée de `doctrine_migration_versions`. La prod ne l'a jamais eue.

### Fichiers touchés

`src/Entity/` (`LoggedExercise`, `LoggedSet`, `ScheduledWorkout`),
`src/Repository/` (deux repos neufs, `findByUuid`, cinq `leftJoin`),
`src/Doctrine/UuidCharType.php`, `migrations/Version20260729120000.php`,
`config/packages/doctrine.yaml`, les consommateurs d'une séance datée
(`CalendarController`, `ScheduledWorkoutController`, `IcsCalendarBuilder`,
`ProfileStats`, `_cal_event`, `scheduled_workout/show`, `coach/athlete`), et
`tests/Controller/ScheduledWorkoutSourcelessTest.php`.

**Prochain ticket : KL-03** — `LogMetrics`, le pendant réalisé de
`WorkoutMetrics`.

---

## Kadens Live KL-03 — `LogMetrics`, le résumé du réalisé (30/07/2026)

Le pendant réalisé de `WorkoutMetrics` : tonnage effectif, séries de travail,
durée réelle, répartition par région. Un ticket court, dont l'essentiel de la
réflexion a porté sur **ce qui se factorise et ce qui ne se factorise pas**.

### La seule chose vraiment commune, c'est la ventilation par région

Le ticket demandait « aucune duplication ». À l'examen, le prescrit et le réalisé
ne partagent qu'un bout de calcul : une fois qu'on a des **séries par zone**, le
regroupement en `TargetRegion` et le calcul des parts sont identiques. Le
`regionShares()` privé de `WorkoutMetrics` est donc devenu le service
`RegionBreakdown::shares()`, injecté dans les deux.

Le reste ne se factorise pas, et c'est structurel :

- **le RPE du prescrit est porté par l'exercice** (« 4 séries à RPE 9 »), il faut
  donc le pondérer à la main par le nombre de séries, sinon un exercice de 2
  séries pèse autant qu'un de 6 ;
- **celui du réalisé est porté par la série**, la moyenne simple est déjà la
  moyenne pondérée ;
- le prescrit multiplie tout par les **tours de bloc**, le réalisé n'a pas de
  blocs — ce qui a été fait a été fait.

Fusionner les deux boucles derrière un service paramétré aurait donné plus de
lignes que les deux réunis, pour un résultat moins lisible. La duplication qu'on
évite est celle du *calcul*, pas celle de la *forme*.

### La forme est identique, deux clés valent 0

`LogMetrics::summary()` rend toutes les clés de `WorkoutMetrics::summary()` :
c'est la condition pour que le bandeau de KPI de `_workout_read` se rende tel quel
sur du réalisé (KL-07). Mais **le réalisé est plat** : il ne porte ni blocs
(`blockCount`) ni liaisons de superset (`supersets`, `circuits`). Ces trois clés
valent 0, et la vue devra ne pas afficher un « 0 enchaînement » qui ne veut rien
dire — un superset est une intention, pas un fait qu'on observe après coup.

Trois clés s'ajoutent en revanche, propres au fait accompli : `durationSeconds`,
`skipped`, `loggedAt`.

### `null` quand il n'y a rien, plutôt que des zéros

`summary()` rend `null` dès qu'il n'y a aucun `LoggedExercise`. Une séance
simplement cochée « faite » n'a pas de bandeau à montrer, et l'appelant n'a pas à
distinguer « zéro série » de « pas de réalisé » — un `{% if summary %}` suffit.

### Le volume du réalisé ne filtre pas sur l'activité

`WorkoutMetrics::volume()` écarte tout ce qui n'est pas `ActivityType::GYM`. Le
réalisé ne peut pas se le permettre : un `LoggedExercise` dont l'`Exercise` a été
supprimé de la bibliothèque n'a **plus d'activité du tout** (SET NULL), et le
filtrer ferait disparaître le tonnage d'une séance réellement faite — exactement
ce que le snapshot `exerciseName` est là pour empêcher. La règle du projet garantit
déjà qu'on ne logue que de la muscu. Seule la ventilation par région dépend encore
de la définition en bibliothèque, faute de zones ciblées ailleurs : un exercice
orphelin garde son tonnage et perd sa région.

### Trois détails qui auraient produit des valeurs fausses

- **`durationSeconds` est `null` tant qu'une borne manque.** Une séance
  synchronisée en cours d'exécution n'a pas de fin ; afficher « depuis le début
  jusqu'à maintenant » donnerait une durée qui bouge à chaque rafraîchissement.
  Et une fin antérieure au début (horloge du téléphone rattrapée entre deux
  écritures) est ramenée à 0, jamais rendue négative.
- **Un exercice sauté ne compte pas comme un exercice fait.** Il peut porter des
  séries saisies puis abandonnées : elles n'entrent ni dans le tonnage ni dans les
  séries de travail. Le compteur `skipped` les expose à part, KL-05 en fera un écart.
- **L'échauffement ne devient jamais le record.** `topLift` ne regarde que les
  séries de travail, et un test le vérifie avec un échauffement plus lourd que le
  travail — le cas absurde en salle, mais exactement ce qu'un mauvais filtre
  laisserait passer.

### Fichiers touchés

`src/Service/LogMetrics.php` (neuf), `src/Service/RegionBreakdown.php` (neuf,
extrait de `WorkoutMetrics`), `src/Service/WorkoutMetrics.php` (consomme le
service extrait, un paramètre de constructeur en plus),
`tests/Service/LogMetricsTest.php` (11 tests, coche la première case de KL-09) et
les trois tests qui instanciaient `WorkoutMetrics` à la main.

**Prochain ticket : KL-04** — `PerformanceHistory` (dernière performance et
record), le service qui donne sa valeur à l'app en séance.

---

## Kadens Live KL-04 — `PerformanceHistory`, la dernière perf et le record (30/07/2026)

« La dernière fois, j'avais fait quoi ? » et « c'est quoi mon record ? » — les
deux questions qu'on se pose entre deux séries. Ce ticket, c'est ce qui donne sa
valeur à l'app en séance, et c'est le premier du chantier dont l'essentiel se
joue **dans le SQL** plutôt que dans une boucle PHP.

### La contrainte qui décide de tout : deux requêtes

Le bootstrap mobile (KL-14) appelle `bulkFor()` sur la **bibliothèque entière**.
Un N+1 le rendrait inutilisable, et charger tout l'historique pour le trier en
mémoire vieillirait mal : l'historique d'un exercice grossit sans limite, sa
dernière séance non.

D'où deux lectures, une chacune, portées par `LoggedSetRepository` :

- `findLastWorkingSetsForExercises()` — les séries de travail de la séance la
  plus récente de chaque exercice, bornée par une **sous-requête corrélée** sur
  `MAX(s2.scheduledDate)` ;
- `findBestWorkingSetsForExercises()` — les séries portant la charge maximale,
  bornée de la même façon sur `MAX(ls2.weightKg)`.

Les deux partagent un socle de filtres (`workingSetRows()`) et **le même**
`FROM ... WHERE` corrélé (`correlatedFrom()`), écrit une seule fois : les deux
bornes ne peuvent pas diverger de périmètre. Projection scalaire, aucune entité
hydratée. Et un test **compte les requêtes** via `doctrine.debug_data_holder` :
la contrainte est gardée, pas seulement écrite. Vérifié en la cassant — deux
appels successifs font bien échouer le test à 4.

Effet de bord voulu : `lastPerformance()` et `bestSet()` n'appellent chacun
**qu'une** des deux requêtes. L'unitaire ne paie pas le prix du bulk.

### Même périmètre que `LogMetrics`, à la ligne près

Échauffement exclu (un échauffement lourd n'est pas un record — le test le
vérifie avec 3 × 200 kg d'échauffement contre 5 × 140 kg de travail), exercice
`skipped` exclu même s'il porte des séries abandonnées, et **aucun filtre sur le
statut** de la séance datée : le réalisé est un fait dès qu'il est écrit, une
séance encore `PLANNED` en cours de synchro compte déjà.

Corollaire assumé : les rangs `firstIndex`/`lastIndex` du condensé sont ceux des
séries **de travail**. Comme l'échauffement n'est jamais remonté, il ne peut pas
décaler la numérotation d'une lecture à l'autre.

### Ce qui départage deux records, et ce qui n'en est pas un

La requête ramène toutes les séries à la charge maximale ; à charge égale,
6 × 60 kg vaut mieux que 3 × 60 kg, et à égalité parfaite c'est la plus récente
qui reste. Une série sans charge (poids du corps, gainage) ne produit **aucun**
record — il n'y a pas de record sans kilos — mais elle a bien une dernière
performance, lue en durée. Le réalisé n'a pas de `PrescriptionType` pour trancher
entre reps et durée : il porte ses valeurs, on lit celle qui est renseignée.

Deux séances le même jour (matin et soir) sont départagées à l'identifiant : la
sous-requête ne borne que la date, l'ordre de la requête fait le reste et le
service ne garde que la première séance rencontrée par exercice.

### Rien à dire n'est pas un zéro

Un exercice sans historique est **absent** de `bulkFor()`, pas présent à null —
même logique que `LogMetrics::summary()` qui rend `null`. KL-50 le dit déjà
autrement : « un exercice sans historique n'affiche rien, pas un graphique
vide ». Et transporter des entrées vides jusqu'au téléphone n'ajouterait que du
volume au bootstrap.

Le condensé des séries, lui, est calqué sur `PlanFlattener::detailedSetGroups` —
mêmes clés, même principe de fusion des séries consécutives identiques — pour que
le prescrit et le réalisé se rendent avec les mêmes composants (KL-07).

### Fichiers touchés

`src/Service/PerformanceHistory.php` (neuf),
`src/Repository/LoggedSetRepository.php` (les deux lectures),
`tests/Service/PerformanceHistoryTest.php` (12 tests, en `KernelTestCase` : les
règles vivent dans le SQL, un double en mémoire n'en garderait aucune — dont
l'isolation par propriétaire, qu'un exercice de la bibliothèque globale rend
indispensable, et le compte de requêtes de `bulkFor`).

**Prochain ticket : KL-05** — `LogComparator`, l'alignement du prescrit et du
réalisé série par série.

---

## Kadens Live KL-05 — `LogComparator`, l'écart prévu vs réalisé (30/07/2026)

Le service qui fait exister la boucle « prévu vs réalisé » autrement que dans une
case à cocher : il aligne le programme et ce qui a été fait, série par série, et
nomme l'écart. C'est ce que KL-07 affichera en colonne à côté du prescrit.

### Ce qu'il ne fait pas : remettre à plat

Le prescrit arrive par `PlanFlattener`, jamais relu ici. Deux lectures du
programme finiraient par diverger, et c'est la même mise à plat qui rend la page,
l'export et l'API.

Une clé manquait pour ça : `setLines` donnait `effort` (« 8 reps ») mais pas les
valeurs qui le composent. Comparer une chaîne formatée à une autre n'aurait
mesuré aucun écart, et re-dériver les séries prescrites dans le comparateur, ce
serait dupliquer `setLines` — exactement ce que le ticket interdit. `FlatSetLine`
expose donc désormais `reps` et `durationSeconds` **bruts** à côté de son
`effort`. C'est déjà le principe du reste du fichier : la valeur brute est
conservée, le formatage vient en plus.

Dans l'autre sens, le réalisé sort sous la **même forme** qu'une série prescrite
(`type`, `typeLabel`, `effort`, `weightKg`, plus `rpe` et l'entité). La colonne
« Réalisé » se rendra avec le fragment de la colonne « Prévu » : le composant se
paramètre, il ne se duplique pas.

### Deux passes pour apparier les exercices

Le ticket donne l'ordre : `sourcePrescribedExercise`, puis l'`Exercise`, puis
« hors programme ». Ce qu'il ne dit pas, c'est qu'il faut **deux passes
distinctes**, pas une cascade par log.

Une séance à deux lignes du même exercice (lourd puis léger) le montre : si
chaque log résout sa cascade à son tour, un log apparié par son exercice prend la
première ligne libre — y compris celle qu'un autre revendique explicitement par
sa source. L'ordre de la collection déciderait alors du résultat. La passe 1
traite donc **tous** les liens explicites, la passe 2 ramasse le reste. Un test
le garde, avec le log de la seconde ligne placé en premier.

L'appariement compare par **identité d'objet**, l'identifiant en repli : Doctrine
ne rend qu'une instance par entité, proxy compris, et une entité pas encore
persistée ne doit jamais se confondre avec une autre par son id nul.

### Deux files de séries, pas une

C'est la décision qui évite le faux positif le plus visible. Un échauffement est
souvent prescrit et rarement logué. Apparier les séries par rang, toutes natures
confondues, décalerait alors tout d'un cran : la première série de travail
réalisée se retrouverait en face de l'échauffement prescrit, et une séance tenue
à la perfection se lirait « allégée » de bout en bout.

L'échauffement et le travail ont donc chacun leur file. La ligne d'échauffement
reste affichée, simplement « non réalisée ».

### Nommer l'écart : le premier axe qui parle

L'écart se lit sur le premier axe où **les deux côtés parlent et divergent** :
tonnage, charge, répétitions, durée, nombre de séries.

Deux règles dans cette phrase. « Les deux côtés parlent » d'abord : un axe muet
d'un côté ne tranche jamais, sinon une série au poids du corps face à une série
chargée serait « allégée » alors qu'il manque juste une valeur. Et l'ordre
ensuite : le tonnage passe avant la charge, parce que 6 × 82,5 kg là où 8 × 80 kg
étaient prévus, c'est plus lourd mais moins de travail — donc allégé.

La même cascade sert aux deux échelles : une série contre une série, puis les
totaux de l'exercice (efforts sommés, charge prise au plus lourd comme
`getTopWeightKg`, échauffement exclu du volume comme partout).

### Six états, pas cinq

Le ticket en listait cinq. Le modèle en impose un sixième : `LoggedExercise`
distingue déjà l'exercice **volontairement sauté** (`skipped`, une déclaration de
l'athlète) de l'exercice **jamais logué** (un trou). Les confondre ferait dire à
l'app que quelqu'un a déclaré quelque chose qu'il n'a pas déclaré. D'où
`NOT_LOGGED` à côté de `SKIPPED` dans `App\Enum\LogDeviation`.

`HELD` porte deux sens, assumés : « tenu » et « rien à signaler ». C'est ce qu'on
rend quand l'écart n'est pas **mesurable** — un `DISTANCE_PACE` ou un AMRAP n'a
pas de séries à apparier, l'exercice a été fait, on ne prétend pas mesurer ce
qu'on ne sait pas comparer.

Et comme `LogMetrics::summary()` rend `null` sans réalisé, `compare()` rend un
tableau **vide** : la colonne « Réalisé » n'apparaîtra pas, plutôt que
d'apparaître vide.

### Fichiers touchés

`src/Enum/LogDeviation.php` (neuf), `src/Service/LogComparator.php` (neuf),
`src/Service/PlanFlattener.php` (`reps` et `durationSeconds` sur `FlatSetLine`),
`tests/Service/LogComparatorTest.php` (16 tests — dont le décalage
d'échauffement, la priorité du lien explicite, la séance libre et le repli sur
l'`Exercise` quand la source a disparu).

**Prochain ticket : KL-06** — l'attribut `LOG` sur `ScheduledWorkoutVoter` : le
coach lit le réalisé de son athlète, il ne l'écrit pas.

---

## Kadens Live KL-06 — l'attribut `LOG`, la garde d'écriture du réalisé (30/07/2026)

Petit ticket, mais c'est celui qui empêche une régression de sécurité silencieuse.
Depuis KL-02, la séance datée porte le réalisé. Or `ScheduledWorkoutVoter`
accordait `EDIT` au coach accepté : sans rien changer au voter, le premier
endpoint d'écriture du réalisé aurait hérité de ce droit, et un coach aurait pu
déclarer ce que son athlète a soulevé.

### Deux natures d'écriture, une seule fermée au coach

Le voter ne distingue plus « lire » et « écrire » mais **trois** choses, parce
qu'« écrire sur une séance datée » recouvre deux gestes qui n'appartiennent pas à
la même personne :

- `EDIT` = **programmer**. Déplacer une date, basculer prévu/fait/manqué, noter un
  écart léger, retirer du calendrier. C'est le travail du coach, il le garde. Le
  commentaire du voter qui parlait de séparer `EDIT` de `STATUS` est donc devenu
  faux dans son diagnostic : ce n'est pas le **statut** qu'il fallait extraire
  (marquer une séance faite reste de la programmation), c'est le **contenu du
  réalisé**.
- `LOG` = **consigner ce qui a été fait**. Créer, modifier, supprimer le réalisé
  série par série. Propriétaire seul.
- `VIEW` ne bouge pas : le coach lit tout, réalisé compris. C'est déjà ce que
  KL-45 attend, et ça reste vrai sans une ligne de plus.

### La branche coach s'arrête avant la question

Sur `LOG`, le voter rend `false` **sans interroger `CoachingResolver`**. Ce n'est
pas une optimisation (une requête COUNT mémoïsée ne coûte rien) : c'est ce qui
rend le refus structurel. Tant que le code passe par la branche partagée, il
existe un endroit où ajouter « sauf si… ». Un test le garde avec
`expects(self::never())` sur le repository : si quelqu'un fait un jour redescendre
`LOG` dans la branche coach, ce test tombe même si la décision finale reste un
refus.

### Une garde qui précède ses appelants

Aucun point d'écriture du réalisé n'existe encore : la suppression depuis
`/schedule/{id}` arrive avec KL-07, le `PUT` idempotent avec KL-16. La case
« tout point d'écriture teste `LOG` » est donc cochée par constat, pas par
migration d'appelants. C'était l'ordre voulu par le découpage — la garde d'abord,
ce qui l'utilise ensuite — et c'est aussi pourquoi la distinction doit vivre dans
le commentaire du voter : elle sera lue plusieurs semaines avant d'avoir un seul
appelant.

### Fichiers touchés

`src/Security/Voter/ScheduledWorkoutVoter.php` (constante `LOG`, `supports`,
sortie anticipée, et le commentaire de classe réécrit — l'ancien documentait une
décision qu'on vient d'annuler),
`tests/Security/ScheduledWorkoutVoterTest.php` (neuf, 6 tests : le propriétaire a
les quatre attributs, le coach en a trois, le tiers et l'anonyme aucun, `LOG`
n'interroge jamais la relation, et `LOG` ne s'abstient pas sur un `ScheduledWorkout`).
Test unitaire : le seul fait qui vienne de la base est la relation de coaching, et
`CoachingResolver` l'isole déjà derrière une méthode — le double porte donc sur
`CoachingRepository`, le résolveur reste le vrai. Conséquence à retenir pour tout
test de voter à venir : `CoachingResolver` refuse une entité **non persistée** (pas
de clé de cache fiable), donc les `User` de fixture ont besoin d'un id posé par
réflexion, sinon la branche coach n'est jamais atteinte et les tests passent pour
la mauvaise raison.

**Prochain ticket : KL-07** — l'affichage du réalisé sur `/schedule/{id}` :
colonne « Réalisé » dans `_workout_sets_table`, onglet par défaut selon le statut,
et suppression du réalisé (premier appelant de `LOG`).

---

## Kadens Live KL-07 — l'affichage du réalisé sur `/schedule/{id}` (30/07/2026)

Le premier ticket du chantier qui se voit. Tout le reste — le modèle, les trois
services, la garde — était en place ; il ne restait qu'à le rendre. Et le rendre
sans dupliquer un seul composant, ce qui était la vraie contrainte.

### Les deux règles de §0.7 qui semblaient se contredire

Le cadrage dit deux choses qui, lues vite, s'annulent : *« la comparaison se lit
en place, jamais dans un onglet séparé »* et *« l'onglet par défaut dépend du
statut : `PLANNED` ouvre sur le programme, `DONE` sur le réalisé »*. La première
interdit l'onglet, la seconde en suppose un.

La lecture qui les tient toutes les deux : **ce que §0.7 interdit, c'est un onglet
du réalisé SEUL**, qu'il faudrait quitter pour retrouver le prescrit et comparer.
Le panneau « Réalisé » livré ici ne fait pas ça. Il rend **le même programme** —
mêmes blocs, mêmes supersets, même `_workout_program` — avec une colonne de plus
dans chaque tableau de séries. On ne le quitte jamais pour comparer : les deux
valeurs sont côte à côte sur la même ligne.

Conséquence utile : les deux panneaux ne diffèrent pas par leur contenu mais par
un **paramètre** (`comparedById` rempli ou vide). Deux lectures du même programme,
l'intention et le fait — et c'est pour ça que le statut peut décider de celle qui
s'ouvre sans qu'on ait rien à choisir à la place de l'utilisateur.

### Le composant se paramètre, il ne se duplique pas

Quatre composants existants ont reçu un paramètre optionnel, aucun n'a été copié :

- `_workout_sets_table` prend `compared` (`list<ComparedLine>`) et bascule en
  Série / Prévu / Réalisé. Les lignes viennent alors de `compared` et non de
  `lines` : l'appariement en porte parfois **davantage** (une série faite en trop
  s'ajoute à la suite) et il tient son propre rang. Deux colonnes disparaissent —
  la charge rejoint sa cellule d'effort (« 8 × 80 kg » se lit d'un bloc, et c'est
  ce qu'on compare), le « % du max » cède sa largeur. Une macro `value()` locale
  rend une valeur de série, prescrite **ou** réalisée : c'est la forme identique
  que KL-05 avait posée exprès qui rend les deux colonnes interchangeables.
- `_workout_exrow` prend `compared` (`ComparedExercise`), pose la pastille d'écart
  et l'atténuation.
- `_workout_program` prend `comparedById` et le distribue.
- `_workout_read` prend `comparison` / `logSummary` / `defaultTab`, et accueille le
  panneau.

Deux composants sont neufs : `_log_panel` (le contenu du panneau) et `_log_exrow`
(un exercice réalisé **sans** prescrit en face — hors programme, ou toute une
séance libre). Ce dernier n'est pas un doublon de `_workout_exrow` : là-bas la
ligne part du prescrit et le réalisé s'y ajoute, ici il n'y a que le réalisé et le
nom vient de son snapshot. Le tableau, lui, est le même composant — `compared`
sans aucune ligne prescrite fait tomber la colonne « Prévu » de lui-même.

Et le bandeau de KPI est **extrait** en `_workout_kpis`, pour servir le prescrit
comme le réalisé. C'est exactement ce que la forme identique de
`LogMetrics::summary()` et `WorkoutMetrics::summary()` (KL-03) existait pour
permettre. Une seule tuile diffère, et elle ne peut pas ne pas différer : le
prescrit annonce ses **enchaînements** (une intention), le réalisé sa **durée
réelle** (un fait). Le réalisé rend `supersets`/`circuits` à 0 — afficher
« séance à plat » sur une séance justement faite en supersets serait faux.

### Le piège qui a coûté une heure : `merge` renumérote

`comparedById` ne fonctionnait pas. Les entrées étaient là, l'appariement rendait
`null`. La cause : le filtre Twig **`merge` est `array_merge()`**, qui
**renumérote les clés entières**. Un `PrescribedExercise` d'id 42 atterrissait à
l'index 0, et l'appariement se faisait au hasard de l'ordre de la collection.

Les clés sont donc préfixées : `'p' ~ id`. Et le détail qui explique pourquoi
personne ne s'était fait prendre avant : le `statsByIndex` de `_workout_read`
utilise le même motif depuis des mois et marche — **par chance**, ses clés étant
déjà `0..n-1`, la renumérotation les reproduit à l'identique. Un bug silencieux
dormait dans un motif qu'on croyait éprouvé.

### L'écart se lit à l'encre

Un écart n'est pas un échec : soulever plus lourd, ou moins, c'est de
l'information. Les pastilles `kd-dev` restent donc en niveaux de gris, et la seule
sortie de rouge est `--skipped`, la seule ligne où l'athlète déclare avoir renoncé
à ce qui était prévu (§5 règle 2).

Deux choix de lecture qui vont avec :

- **`HELD` n'affiche rien du tout.** « Tenu » se lit déjà dans le tableau, les
  deux colonnes y portent les mêmes valeurs. Une pastille sur chaque ligne d'une
  séance parfaitement tenue noierait les trois lignes qui, elles, ont quelque
  chose à dire.
- **Dans une cellule, l'écart se réduit au pictogramme** (`dev.mark`), le libellé
  restant au `title`/`aria-label`. Un libellé par ligne masquerait les valeurs
  qu'on est venu comparer.

Et le prescrit s'atténue **sans disparaître** : sans lui, on ne sait plus si la
séance a été tenue. Piège de cascade rencontré au passage — `.kd-setrow--normal td`
remet l'encre pleine sur toutes ses cellules, d'où la reprise explicite de
`.kd-setrow__planned` : sans elle, la série de travail aurait été la seule dont le
prescrit ne s'atténue pas, c'est-à-dire précisément la ligne qu'on regarde. Le
**nom** de l'exercice, lui, ne s'atténue jamais : ce n'est pas un paramètre, c'est
le sujet de la ligne.

### La portée comme garde anti-fuite

Le réalisé ne doit jamais atteindre la page de bibliothèque, la page publique,
l'export Excel ni le flux ICS. La garde n'est pas une condition d'affichage : les
trois entrées du réalisé sont des paramètres **optionnels** de `_workout_read`, et
`ScheduledWorkoutController::show()` est le seul appelant qui les passe.
`workout/show` et `public_share` rendent le même composant sans eux — ils sont
structurellement incapables d'afficher un réalisé. Le réalisé n'entre toujours pas
dans `PlanFlattener` : il est lu par `LogComparator` et `LogMetrics`, appelés dans
ce contrôleur et nulle part ailleurs.

`testLogNeverLeaksThroughPlanFlattener` le vérifie en interrogeant les cinq
consommateurs sur une séance portant une charge de **123,5 kg prescrite nulle
part** : la valeur ne peut venir que du réalisé, là où un tonnage agrégé aurait pu
coïncider par hasard. Ce test coche la troisième case restante de KL-09.

### Les à-côtés

- **Le contrôleur `tabs` reçoit son onglet d'ouverture du serveur**
  (`data-tabs-default-value`, valeur Stimulus `default`, repli sur le premier
  panneau). Il ne le devine pas : c'est ce qui rend le choix testable sans
  navigateur, le test gardant ce que le serveur annonce.
- **Supprimer le réalisé teste `LOG`** — premier appelant de la garde de KL-06 — et
  ne touche pas au planning. `startedAt`/`endedAt` repassent à null (elles ne
  mesuraient que ce réalisé), mais **ni le statut ni `completionNotes`** : effacer
  le détail des séries n'annule pas le fait que la séance a été faite, et ces deux
  champs relèvent de la programmation, donc du coach.
- **`_scheduled_done` s'intitule « Boucler la séance ».** Deux sections
  « Réalisé » sur la même page, l'une fermée au coach et l'autre pas, ne pouvaient
  que se confondre.
- **Une séance sans bloc mais avec du réalisé n'est pas « encore vide ».** La garde
  de l'état vide compte les deux côtés, sinon une séance entièrement faite hors
  programme s'annoncerait vide.
- **Une séance `MISSED` porte une marque en clair dans le hero.** Une pastille se
  survole, elle ne se lit pas, et une date passée sans réalisé a exactement
  l'allure d'une séance à venir. Le token `--color-status-missed` est un token de
  statut dédié : l'employer ne consomme pas le rouge de §5 règle 2.
- **L'en-tête d'une ligne comparée dit « 3 prévues », pas « 3 séries ».** Le
  tableau en dessous peut avoir plus de lignes que le prescrit ; un compte nu
  au-dessus de quatre lignes se lirait comme une erreur.
- Deux icônes Lucide importées en local : `minus`, `timer`.

### Fichiers touchés

Neufs : `templates/components/_workout_kpis.html.twig`,
`templates/components/_log_deviation.html.twig`,
`templates/components/_log_panel.html.twig`,
`templates/components/_log_exrow.html.twig`,
`tests/Controller/ScheduledWorkoutLogTest.php` (11 tests).
Modifiés : `src/Controller/ScheduledWorkoutController.php` (`show()` étendu,
`defaultTab()`, `deleteLog()`), `assets/controllers/tabs_controller.js` (valeur
`default`), `templates/components/_workout_read.html.twig`,
`_workout_program.html.twig`, `_workout_exrow.html.twig`,
`_workout_sets_table.html.twig`, `_scheduled_done.html.twig`,
`templates/scheduled_workout/show.html.twig`, `_hero_actions.html.twig`,
`assets/styles/components.css`.

**Prochain ticket : KL-08** — la séance datée sans source au calendrier : la
pastille retombe sur son `title`, marque « hors plan » codée par le rang dans
l'échelle de gris.

---

## Kadens Live KL-08 — la séance sans source au calendrier (30/07/2026)

Une séance vierge est une séance datée avec `workout = null`. Le calendrier la
requêtait déjà : le `leftJoin` du repository et `getDisplayTitle()` datent de
KL-02, le lien vers `/schedule/{id}` de la couche mobile. Il ne restait donc de ce
ticket qu'une chose — **la marque visuelle** — et une décision de vocabulaire.

### Ce qui a été fait

- **Un composant, `templates/components/_freeform_mark.html.twig`.** Il se pose à
  deux endroits du même fichier : la ligne méta de la pastille (où il n'y avait
  rien, faute de programme à annoncer) et sa modale rapide, où il prend la place
  du lien « Voir la séance » qui n'a plus de cible. Une seule définition du signe
  et du mot, servie aussi par le Turbo Stream de statut.
- **Une classe, `.kd-freeform`** : contour pointillé au rang le plus clair de
  l'échelle catégorielle (`--color-cat-4`), libellé mono à l'encre faible, icône
  `lucide:circle-dashed` (importée en local).
- **Un maillon de compression rétabli sur `.kd-calevent__meta`** (`min-width: 0`),
  découvert en regardant le rendu réel : le chip débordait de la case.
- **La pastille de calendrier réagencée** : contenu sur toute la case, cycle de
  statut et œil en rangée dessous, à parts égales. Retour à la ligne sous 560px.
- **Deux tests** ajoutés à `ScheduledWorkoutSourcelessTest` (10 tests au total).

### Décisions

- **Le libellé dit « Libre », pas « Hors plan ».** Le ticket écrivait « hors
  plan », mais une séance posée à la main depuis la bibliothèque est elle aussi
  hors d'un plan — et elle a un programme : le mot aurait nommé une autre
  distinction que celle qu'on marque. « Libre » reprend le vocabulaire déjà en
  place (`getDisplayTitle()` retombe sur « Séance libre », l'eyebrow de
  `/schedule/{id}` dit la même chose). Un premier essai plus explicite
  (« Sans programme ») a été abandonné à la vue du rendu : trop long pour une case
  de calendrier, il se faisait couper au milieu d'un mot. La place disponible fait
  partie des contraintes du libellé, pas seulement l'exactitude.
- **La marque ne touche pas au filet de gauche.** C'était le réflexe tentant — une
  bordure en pointillé pour dire « pas de contenu » — mais ce filet porte le
  statut, et `is-overdue` s'y exprime déjà en pointillé rouge. Une deuxième
  grammaire au même endroit aurait rendu les deux illisibles. La catégorie se dit
  donc à côté du titre, pas sur le bord.
- **Contour plutôt que couleur de texte.** L'échelle catégorielle ne porte jamais
  de texte (design-system §5) : `--color-cat-4` est un gris de remplissage, pas un
  gris lisible. Le libellé reste à `--color-text-faint`.
- **La marque a fait apparaître un défaut plus ancien que KL-08.** En regardant le
  rendu, le chip débordait de la case et se faisait couper au milieu d'un mot —
  et surtout, il ne restait qu'une quarantaine de pixels au titre, qui s'élidait
  dès trois mots. Cause : sur ordinateur une colonne de calendrier est **plus
  étroite que l'écran d'un téléphone**, et la pastille y tenait trois zones en
  ligne. D'où le réagencement — contenu sur toute la case, actions en rangée
  dessous — qui n'était pas au ticket mais que la marque a rendu impossible à
  ignorer. La ligne reste la bonne forme sous 560px, où l'agenda vertical rend la
  pleine largeur : empiler y allongerait une vue qui ne fait que défiler.
- **Le compte du test se fait sur `.kd-calevent__open`, pas sur `.kd-calevent`.**
  La modale vit **à l'intérieur** de la pastille et porte la sienne : compter au
  conteneur donne deux marques par séance et fait échouer le test pour une raison
  qui n'existe pas.

### Fichiers touchés

Neuf : `templates/components/_freeform_mark.html.twig`,
`assets/icons/lucide/circle-dashed.svg`.
Modifiés : `templates/components/_cal_event.html.twig`,
`assets/styles/components.css`,
`tests/Controller/ScheduledWorkoutSourcelessTest.php`.

**Le lot 1 de Kadens Live est clos**, KL-09 compris : ses deux dernières cases
(non-régression de la suppression, séance datée sans `workout`) étaient déjà
couvertes par les tests écrits chemin faisant. Prochain ticket : **KL-10**, le
lot 2 — `ApiToken`, authenticator et firewall `api` stateless.

---

## Kadens Live KL-10 — `ApiToken`, authenticator, firewall (30/07/2026)

Le premier ticket du lot 2, et le seul du lot dont l'erreur serait invisible : une
API qui marche alors que son jeton ne sert à rien.

### L'ordre des pare-feux est la substance du ticket

`main` porte `lazy: true`, un `form_login` et un `remember_me` de **dix ans**. Un
pare-feu Symfony se choisit au premier motif qui correspond : sans un `api`
déclaré **avant**, `^/api` serait tombé dans `main` et une requête mobile aurait
été authentifiée par cookie. Tout aurait fonctionné en apparence — l'app aurait
reçu ses réponses — mais le jeton serait devenu décoratif, et surtout **révoquer
un appareil depuis `/profile/settings` (KL-12) n'aurait eu aucun effet**. C'est
une panne de sécurité qui ne se manifeste par aucun symptôme fonctionnel, d'où
le test qui la garde : un utilisateur connecté sur le web reçoit 401 sur
`/api/ping`.

`stateless: true` complète la même idée par l'autre bout : aucune session ouverte,
donc aucun `Set-Cookie`, donc pas de CSRF à gérer côté API. Un test l'affirme sur
la réponse plutôt que sur la configuration — c'est l'absence d'en-tête qui compte,
pas la ligne de YAML qui devait la produire.

### Le secret n'existe qu'une fois

La base ne stocke que l'empreinte SHA-256 (`token_hash`, CHAR(64) unique). Le
constructeur d'`ApiToken` prend le secret **en clair et le hache sur place** :
il n'y a donc aucun chemin de code où le secret puisse être écrit par distraction,
et aucun appelant n'a de raison de le garder après la réponse qui le renvoie.
Corollaire assumé : un jeton perdu ne se retrouve pas, il se remplace.

SHA-256 nu, pas bcrypt ni argon. Ce n'est pas un mot de passe : le secret fait
256 bits d'aléa tirés par `random_bytes`, il n'y a pas de dictionnaire à ralentir
— et l'authentification doit tenir en **une lecture indexée** à chaque requête de
l'API. Ralentir volontairement cette lecture n'achèterait rien.

### L'expiration glissante vit dans l'entité, pas dans l'authenticator

`touch()` note `lastUsedAt` et repousse `expiresAt` de 90 jours. Les deux gestes
sont un seul fait — « ce jeton a servi » — et les séparer laisserait exister un
état où l'un est écrit sans l'autre. L'authenticator appelle `touch()` puis
`flush()` juste après avoir validé le jeton : à ce stade de la requête rien
d'autre n'est en attente, la persistance ne peut pas emporter autre chose.

Effet voulu : un téléphone dont on se sert ne se déconnecte **jamais**, un
téléphone oublié se périme tout seul en trois mois. C'est ce qui rend l'appairage
par QR (§0.6) un geste trimestriel plutôt qu'hebdomadaire.

### Une seule réponse pour trois échecs

Jeton vide, inconnu ou périmé sortent le **même** 401 avec le même texte.
Distinguer « inconnu » de « périmé » confirmerait l'existence d'un jeton à qui le
devine. La réponse est déjà à la forme RFC 9457 (`application/problem+json`) que
KL-13 généralisera : ça ne coûte rien maintenant, et ça évite que les deux seules
erreurs existantes de l'API soient les deux seules à ne pas suivre la règle.

`supports()` rend `false` quand il n'y a pas d'en-tête `Bearer` — la requête
poursuit en anonyme, `access_control` la refuse, et le refus appelle `start()`.
C'est ce qui laisse `^/api/auth` public sans une exception écrite dans
l'authenticator : la porte d'entrée de l'API n'a pas à connaître la liste de ce
qui ne demande pas de jeton.

### `/api/ping`, la sonde

Un ticket de pare-feu ne peut pas se tester sans une route derrière lui : le
routage s'exécute **avant** le contrôle d'accès, donc une URL inexistante rend 404
sans jamais réveiller le pare-feu. Plutôt qu'une route de test, `GET /api/ping`
— authentifiée, muette sur l'identité. Le client mobile en a l'usage : le QR
d'appairage porte l'URL du serveur (§0.6), il faut pouvoir vérifier qu'elle mène
bien à un Kadens et que le jeton y est encore valide. `GET /api/me` (KL-11)
portera l'identité, les rôles et le dernier bootstrap.

### Fichiers touchés

Neufs : `src/Entity/ApiToken.php`, `src/Repository/ApiTokenRepository.php`
(`findOneByPlainToken`, plus `findForOwner` que KL-12 consommera),
`src/Security/ApiTokenAuthenticator.php`, `src/Controller/Api/PingController.php`,
`migrations/Version20260730140000.php`,
`tests/Controller/ApiAuthenticationTest.php` (8 tests).
Modifié : `config/packages/security.yaml` (pare-feu `api` avant `main`, deux
règles d'`access_control` en tête — `^/api/auth` public avant `^/api`, sans quoi
obtenir un jeton demanderait d'en avoir un).

**Prochain ticket : KL-11** — les endpoints d'authentification :
`POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/me`.

---

## Kadens Live KL-11 — Endpoints d'authentification (31/07/2026)

KL-10 a posé la serrure ; celui-ci pose la clé. Trois routes, et rien d'autre :
pas d'inscription (les comptes se créent en console, règle verrouillée), pas de
mot de passe oublié. Le chemin nominal d'un appairage reste le QR (KL-46) — le
mot de passe est un **repli**, et de toute façon le seul moyen d'écrire un test
fonctionnel de l'API.

### Un 401 qui ne dit rien, ni par son texte ni par son temps

La consigne du ticket était « ne pas distinguer email inconnu de mot de passe
faux ». Le message uniforme ne suffit pas : sur un compte inexistant, la réponse
part **sans avoir haché quoi que ce soit**, donc bien plus vite qu'une
vérification de mot de passe réelle. Le temps de réponse redevient l'oracle que
le message refusait d'être. D'où le hachage à vide (`hashPassword(new User(), …)`)
sur la branche « utilisateur inconnu » — trois lignes qui achètent l'uniformité
qu'on prétendait avoir.

Le test correspondant compare les deux corps **au caractère près**, pas seulement
les statuts : c'est ce qui empêchera d'y ajouter plus tard un `detail` plus
« utile » d'un côté seulement.

### Le jeton validé se publie sur la requête

`logout` doit révoquer *celui qu'on présente*, `/api/me` doit décrire l'appareil
courant. Les deux ont donc besoin de l'`ApiToken`, que le jeton de sécurité ne
porte pas (`SelfValidatingPassport` ne transporte que l'utilisateur). Trois
options : relire l'en-tête dans le contrôleur, un badge sur mesure, ou un attribut
de requête.

C'est l'attribut (`ApiTokenAuthenticator::REQUEST_ATTRIBUTE`, `_api_token`). La
raison n'est pas la brièveté : relire l'en-tête ailleurs créerait un **second
endroit qui décide de ce que vaut un `Bearer`**, et deux endroits finissent
toujours par diverger. L'authenticator reste la seule autorité ; il valide, puis
il dit ce qu'il a validé. Le préfixe `_` tient l'attribut hors des arguments de
contrôleur résolus par nom.

### `logout` se garde sur le jeton, pas sur l'utilisateur

La route vit sous `^/api/auth`, donc publique pour `access_control` (c'est la
règle qui laisse `login` accessible sans jeton). La garde est donc dans le
contrôleur — et elle porte sur **le jeton**, pas sur `getUser()`. Ce n'est pas un
raccourci : sans jeton il n'y a rien à révoquer, quand bien même on saurait qui
appelle. Révoquer, c'est supprimer la ligne, pas la marquer périmée : une ligne
morte qu'on garde n'est qu'une ligne à purger plus tard. Et un seul appareil part
— « tout révoquer » est un geste explicite, il vivra dans `/profile/settings`
(KL-12).

### Le contrat client qu'on assume au lieu de le contourner

L'authenticator se déclenche sur la **seule présence** d'un `Bearer`, quel que
soit l'`access_control` de la route. Conséquence : un jeton périmé présenté à
`POST /api/auth/login` fait échouer la requête *avant* le contrôleur — exactement
le cas où le client voudrait se reconnecter.

La correction évidente aurait été de faire rendre `false` à `supports()` sur
`^/api/auth`. Elle a été écartée : KL-10 a refusé d'écrire une liste de routes
dans l'authenticator, et `logout` a besoin de l'en-tête — l'exception ne serait
donc pas une ligne mais une liste à tenir à jour. Le flux côté mobile est de
toute façon le flux standard : 401 → effacer le jeton local → login sans en-tête.
Le comportement est donc **figé par un test** plutôt que subi, et écrit noir sur
blanc dans le contrôleur pour KL-25.

### `last_bootstrap_at`, et pourquoi `last_used_at` ne suffisait pas

`GET /api/me` doit rendre « la date du dernier bootstrap ». Rien ne la portait :
`lastUsedAt` bouge à **chaque** requête, l'authenticator la repoussant y compris
sur un `ping`. Un téléphone peut donc répondre tous les jours en travaillant sur
des données de trois semaines, et les deux faits doivent se distinguer — c'est
précisément ce que KL-12 affichera pour décider d'une révocation.

D'où une colonne nullable et une méthode `markBootstrapped()` dont **KL-14 sera le
seul appelant** : un endpoint qui ne rend pas le jeu complet n'a pas à laisser
croire que l'appareil est à jour. Nullable et sans défaut : un appareil qui vient
de s'appairer n'a pas encore synchronisé, et « jamais » n'est pas une date.

### 201, 204, et le reste

`login` rend **201** : l'appel enregistre un appareil, que `/profile/settings`
listera et pourra révoquer — ce n'est pas une lecture. `logout` rend **204** :
il n'y a rien à dire. Les erreurs du contrôleur sont déjà à la forme RFC 9457,
comme celles de l'authenticator, pour que KL-13 n'ait qu'à les remonter dans un
listener au lieu de les convertir.

La borne du `VARCHAR(100)` de `deviceName` se refuse **dans le contrôleur** : le
nom vient du client, il n'a pas à atteindre la base pour être jugé, et une chaîne
trop longue doit rendre 400, pas une erreur SQL en 500.

### Le piège de test, qui n'en était pas un de production

Un test « une session web n'authentifie pas `/api/me` » passait au vert… en
répondant **200**. Cause : `loginUser()` pose le jeton dans le `token_storage` du
conteneur *en plus* du cookie de session, et tant que le noyau n'a pas redémarré
ce jeton résiduel traverse n'importe quel pare-feu, `stateless` compris. Le trou
était dans le test, pas dans l'application.

Le test équivalent de KL-10 échappait à ça par accident : il intercalait trois
requêtes web avant d'appeler l'API, ce qui purgeait le conteneur. La leçon vaut
pour tout test qui veut prouver qu'un cookie **ne** suffit **pas** — il faut au
moins une requête entre `loginUser()` et l'assertion, sinon on teste le contraire
de ce qu'on croit.

### Fichiers touchés

Neufs : `src/Controller/Api/AuthController.php`,
`migrations/Version20260731100000.php`,
`tests/Controller/ApiAuthEndpointsTest.php` (19 tests).
Modifiés : `src/Entity/ApiToken.php` (`lastBootstrapAt` + `markBootstrapped()`),
`src/Security/ApiTokenAuthenticator.php` (publication du jeton validé sur la
requête).

**Prochain ticket : KL-46** — l'appairage par QR (`PairingCode`,
`POST /pairing/code`, `POST /api/auth/pair`), qui réutilise le geste d'émission
posé ici ; ou **KL-12**, la liste et la révocation d'appareils dans
`/profile/settings`, que `findForOwner` et `lastBootstrapAt` attendent déjà.

---

## Kadens Live KL-46 — L'appairage par QR (31/07/2026)

KL-11 a posé la clé de repli ; celui-ci pose le chemin nominal. **On ne tape pas
son mot de passe sur le téléphone** : le desktop, où la session est de fait
permanente, affiche un code à usage unique, l'app le scanne, l'échange contre un
`ApiToken`. Deux endpoints, une entité, une commande.

### Ce que le QR porte, et surtout ce qu'il ne porte pas

Le QR ne contient **jamais** de jeton, seulement un code de huit caractères de
TTL deux minutes. C'est toute la raison d'être d'une table de plus : afficher le
secret d'`ApiToken` à l'écran ferait d'une photo de cet écran un accès permanent
au compte. Il porte en revanche l'**URL du serveur**, ce qui dispense de la
saisir sur le téléphone — et règle au passage la saisie de l'IP LAN en
développement.

Le code est stocké **haché**, comme le jeton. Avec une nuance assumée : huit
caractères sur un alphabet de 32, c'est 40 bits, pas 256. Une empreinte volée
est cassable hors ligne. Ce qui la rend sans intérêt, c'est la fenêtre de deux
minutes et l'usage unique ; ce qui protège l'entrée en ligne, c'est le limiteur
de débit — il n'est pas un confort ici, c'est une pièce du modèle de sécurité.

L'alphabet exclut `O`/`0` et `I`/`1`/`l` : le code doit rester **saisissable à
la main** quand la caméra refuse, et une confusion de caractère y coûterait un
aller-retour. Il en reste 32, ce qui tombe bien.

### L'usage unique est une garantie de la base

Le point structurant du ticket tient en une requête :

```sql
UPDATE pairing_code SET used_at = ?, consumed_by_device = ?
WHERE id = ? AND used_at IS NULL AND expires_at > ?
```

Lire puis écrire laisserait passer deux scans simultanés du même QR : les deux
lectures verraient `used_at IS NULL` avant que l'une des deux n'écrive, et deux
téléphones repartiraient avec un jeton. Ici c'est la base qui tranche, et le
nombre de lignes affectées dit qui a gagné. L'échéance est dans le **même**
`WHERE` pour exactement la même raison : elle ne peut pas être vraie au moment
du test et fausse au moment de l'écriture.

Conséquence de forme : `PairingCode` n'a pas de setter pour `usedAt`. La
consommation ne passe pas par l'ORM, et l'entité est relue (`refresh`) après
coup — ce que le contrôleur rend doit être ce que la base a écrit, pas ce que le
PHP croit avoir écrit.

### Le compte vient du code, jamais de la requête

`pair()` appelle `issue($pairingCode->getOwner(), …)`. C'est la seule différence
de fond avec `login()`, qui lit le compte dans le corps de la requête : le
téléphone ne choisit pas à qui il se rattache. Un code deviné n'ouvre donc que le
compte de son émetteur, et un `email` glissé dans le corps de `pair` ne sert à
rien — un test le vérifie, session web ouverte sur un *autre* compte comprise.

### Une seule erreur pour trois cas

Inconnu, expiré, déjà utilisé : même réponse, au caractère près. Même
raisonnement que le 401 uniforme de KL-11 — distinguer dirait à qui devine un
code s'il a visé juste. **400 et non 401** : le client n'a pas à réessayer avec
le même code, il doit en demander un autre au desktop.

Le 429 du limiteur, lui, se rend **avant** toute lecture de la base. Ce n'est pas
une micro-optimisation : un quota épuisé ne doit pas non plus consommer un code
valide présenté au même moment.

### Un écran, un code

Émettre un code invalide les codes non consommés du même utilisateur. Sans ça,
un code affiché sur un poste qu'on vient de quitter resterait échangeable deux
minutes après qu'on en a demandé un autre ailleurs.

Les codes **consommés** survivent, eux, jusqu'à leur échéance :
`consumedByDevice` est la trace que KL-47 affichera en confirmation (« quel
téléphone vient de se connecter »). C'est un snapshot du nom, pas une relation
vers l'`ApiToken` créé — celui-ci se révoque (KL-12) et emporterait la trace avec
lui. Passé l'échéance, plus rien ne distingue un code utilisé d'un code mort :
`app:pairing:purge` les retire tous, sur une seule borne.

### Deux détails qui auraient coûté cher

`PairingCode::hash()` normalise avant de hacher (`trim`, majuscules). Le repli
clavier se tape comme il vient ; sans cette normalisation le code affiché et le
code saisi ne donneraient pas la même empreinte, et l'erreur uniforme rendrait la
panne indéchiffrable.

`/pairing/code` ne vit pas sous `^/profile`, donc `access_control` a une règle
`^/pairing` en propre. Le CSRF est vérifié à la main, comme partout ailleurs
dans le projet où la requête ne passe pas par un `FormType`.

### Le piège de test

Le compteur du limiteur vit dans un pool de cache **sur disque**, qui persiste
entre deux exécutions : sans un vidage au `setUp`, un test qui épuise le quota
fait échouer les suivants et l'ordre des tests devient significatif. Le réflexe
— passer le pool en `ArrayAdapter` pour l'environnement de test — ne marche
**pas** : le `services_resetter` le remet à zéro entre deux requêtes du *même*
test, et le quota ne compte plus rien. Les deux tests de débit passaient alors
au vert pour la mauvaise raison, en 400 au lieu de 429.

### Fichiers touchés

Neufs : `src/Entity/PairingCode.php`, `src/Repository/PairingCodeRepository.php`,
`src/Command/PurgePairingCodesCommand.php`,
`config/packages/rate_limiter.yaml`, `migrations/Version20260731140000.php`,
`tests/Controller/ApiPairingTest.php` (22 tests),
`tests/Command/PurgePairingCodesCommandTest.php`.
Modifiés : `src/Controller/Api/AuthController.php` (`POST /api/auth/pair`),
`src/Controller/ProfileController.php` (`POST /pairing/code`),
`config/packages/security.yaml` (`^/pairing`), `composer.json`
(`symfony/rate-limiter`).

**Prochain ticket : KL-47** — la page QR sur le desktop (`endroid/qr-code` en
SVG inline, code en toutes lettres sous le QR, compte à rebours et régénération
en un clic), qui consomme la charge utile posée ici ; ou **KL-12**, la liste et
la révocation d'appareils, où l'appairage devient visible et réversible.

---

## Kadens Live KL-47 — La page QR sur le desktop (31/07/2026)

KL-46 avait posé le mécanisme sans son écran : un endpoint qui émettait un code
et le rendait en JSON, faute d'un endroit où le montrer. Ce lot lui donne cet
endroit — une section « Connecter un téléphone » dans `/profile/settings` — et,
ce faisant, corrige la nature de la charge utile.

### La charge utile n'était pas une réponse, c'était le contenu du QR

`{url, code, exp}` n'a jamais eu de consommateur HTTP. Personne n'appelle
`POST /pairing/code` pour lire du JSON : le desktop veut un dessin à montrer, et
le téléphone (KL-48) lit ce dessin. Le trio est donc ce que le **QR encode**, et
l'endpoint rend désormais du HTML. Garder les deux représentations aurait laissé
deux façons de dire la même chose, à tenir d'accord pour toujours, dont une sans
appelant.

Conséquence dans les tests : `ApiPairingTest` lit le code émis dans la page au
lieu du JSON. Ce n'est pas un contournement, c'est le vrai chemin — le code en
clair n'existe qu'à l'écran.

### Ce qui se teste, c'est ce qui est encodé

Un QR est une image, et on n'installe pas un décodeur pour vérifier une image.
Mais `svg()` est **déterministe** : à charge utile égale, dessin égal. Le test
reconstruit donc la charge utile attendue — l'URL du serveur, le code lu à
l'écran, l'échéance lue en base — la fait dessiner par le même service, et
cherche ce SVG dans la page. Ce qui est figé, c'est le contrat avec le mobile,
pas la façon de le peindre.

D'où la coupure du service en deux gestes : `payload()` est le contrat,
`svg()` n'en est qu'une lecture optique. Ils ont deux publics, ils méritaient
deux méthodes.

### Émettre est une écriture, l'afficher ne l'est pas

L'état par défaut de la page est **sans code**. C'était tentant d'en générer un
au chargement — la page aurait été plus courte à écrire, et le QR déjà là. Mais
ouvrir ses paramètres pour changer son mot de passe aurait alors gâché un code à
chaque fois, et surtout invalidé celui qu'un autre onglet affiche : « un écran,
un code » est une garantie de KL-46, pas une préférence.

Corollaire moins évident : le POST **ne redirige pas**. Le code en clair n'existe
que dans la réponse qui l'émet ; rediriger obligerait à le stocker en session,
donc à créer un second endroit où un secret de deux minutes traîne. Le repli sans
JS rend la page entière en réponse au POST, et Turbo ne remplace que
`#pairing-panel` — sinon le formulaire de mot de passe de la même page perdrait
sa saisie.

### Le décompte est un confort, l'échéance est l'information

Le serveur écrit « Valable jusqu'à 14:32 ». Le contrôleur Stimulus remplace ce
texte par « Expire dans 1:47 ». Sans JS il ne manque donc rien, et le QR — dessiné
côté serveur — s'affiche de toute façon : aucune bibliothèque JavaScript n'entre
dans l'importmap pour ça.

Le même contrôleur sonde `GET /pairing/{id}/status` toutes les deux secondes pour
afficher **quel** téléphone vient de se connecter. C'est ce que le snapshot
`consumedByDevice` de KL-46 gardait en réserve. Le sondage est borné par ce qu'il
observe : il s'arrête au code consommé, à l'échéance, ou sur une réponse non-`ok`
— un code régénéré ailleurs a été supprimé, et réessayer n'empilerait que des
404. Ce n'est pas l'AJAX post-chargement que le projet refuse sur ses pages de
consultation : il n'y a rien à mettre en cache offline dans un secret qui périme.

L'état d'un code qui n'est pas le sien rend **404**, pas 403 : un refus qui
distingue « pas à toi » de « n'existe pas » confirme l'existence à qui essaie des
identifiants.

### Deux détails de rendu qui ont une raison

La marge blanche autour du motif est **dans l'image**, pas dans le CSS : c'est la
« quiet zone » de la norme, sans laquelle un décodeur ne trouve pas les motifs de
repérage. Du `padding` autour ne la remplace pas, la caméra ne voit que l'image.
Pour la même raison le QR reste noir sur blanc : c'est une cible optique avant
d'être un élément de l'identité, et son contraste ne se négocie pas.

Ni la confirmation d'appairage ni l'expiration ne sortent le rouge. Un code
consommé est un succès, un code échu une réponse normale du système — même
raisonnement que les pages 404 et 403, qui restent à l'encre.

### Fichiers touchés

Neufs : `src/Service/PairingQr.php`,
`assets/controllers/pairing_controller.js`,
`templates/profile/_pairing.html.twig`,
`templates/profile/_pairing_form.html.twig`,
`templates/profile/stream/pairing.stream.html.twig`,
`tests/Controller/PairingPageTest.php` (8 tests).
Modifiés : `src/Controller/ProfileController.php` (`POST /pairing/code` en HTML,
`GET /pairing/{id}/status`), `templates/profile/settings.html.twig`,
`assets/styles/components.css` (section `kd-pairing`),
`tests/Controller/ApiPairingTest.php` (le code se lit à l'écran),
`composer.json` (`endroid/qr-code`).

**Prochain ticket : KL-12** — la liste et la révocation d'appareils. C'est la
seule case de KL-47 que ce lot ne pouvait pas cocher, et surtout ce qui manque
pour qu'un appairage soit **réversible** : un jeton qu'on ne peut pas révoquer
depuis le web est un trou.

---

## Kadens Live KL-12 — La gestion des appareils (31/07/2026)

KL-46 et KL-47 avaient fait de l'appairage un geste d'une seconde ; il restait à
sens unique. Un `ApiToken` repousse son échéance de 90 jours **à chaque usage**
(KL-10) : un téléphone qui s'en sert ne se déconnecte donc jamais tout seul, et
il n'existait aucun endroit pour lui retirer la main. Ce lot ferme la boucle avec
une section « Appareils connectés » dans `/profile/settings`, une révocation par
appareil et un « tout révoquer ».

### Révoquer, c'est supprimer la ligne

Le même choix qu'au `POST /api/auth/logout` de KL-11, et pour la même raison : un
jeton marqué « révoqué » obligerait chaque lecture à s'en souvenir — l'authenticator
d'abord, mais aussi la liste, `GET /api/me`, et tout ce qui viendra après. Un
oubli à un seul de ces endroits rouvre l'accès sans bruit. L'absence, elle, ne
s'oublie pas.

Corollaire pour « tout révoquer » : `ApiTokenRepository::deleteForOwner()` écrit
un `DELETE` DQL et **ne passe pas par les entités chargées**. C'est le geste qu'on
fait quand on ne sait plus ce qui est connecté ; il n'a pas à dépendre d'un état
lu au préalable, ni de la liste que la page affichait dix secondes plus tôt.
`ApiToken` ne porte aucune association sortante, une suppression en masse ne
saute donc aucun `onDelete`.

### Le refus qui ne confirme rien

Un jeton qui n'est pas le sien rend **404, pas 403** — exactement la règle posée
par `GET /pairing/{id}/status` en KL-47. Distinguer « pas à toi » de « n'existe
pas » transforme la route en oracle pour qui essaie des identifiants.

La garde de propriété passe **avant** la vérification CSRF, et c'est délibéré :
elle ne fait que lire un `owner`, aucune écriture n'a lieu avant que le jeton CSRF
soit validé. Un tiers ne lit de toute façon pas la réponse d'une requête
cross-origin ; l'utilisateur légitime, lui, mérite le bon message.

### Un Turbo Stream, parce que la page porte trois choses indépendantes

`/profile/settings` affiche désormais un QR éventuellement à l'écran, une saisie de
mot de passe et cette liste. Révoquer un vieux téléphone pendant qu'on en appaire
un nouveau est un geste **normal** : il n'a pas à effacer le code affiché ni la
saisie en cours. La révocation rend donc un stream ciblé sur `#devices-panel`,
avec repli par redirection sans JS — même construction qu'en KL-47.

Le panneau **entier** est remplacé, pas la seule ligne retirée : « tout révoquer »
vide la liste, et le bouton global disparaît dès qu'il ne reste qu'un appareil.
Une cible par ligne aurait obligé à décrire ces deux effets de bord ailleurs.

Et **pas de flash dans la branche stream** : il n'y a aucun rechargement pour
l'afficher, il resterait en session et surgirait à la navigation suivante, à
contretemps. La ligne qui disparaît est la confirmation.

### Ce que la liste montre, et ce que ça coûte de le montrer

Quatre dates par appareil : appairé, dernier usage, dernière synchro, expiration.
La troisième est celle que `ApiToken.lastBootstrapAt` existait pour porter (KL-11)
— elle affichera « jamais » tant que `GET /api/bootstrap` (KL-14) n'existe pas,
et c'est correct : aucun appareil n'a encore jamais synchronisé. C'est elle qui
distingue « ce téléphone répond » de « ce téléphone est à jour ».

Un jeton échu garde sa ligne : il n'authentifie plus, mais il se révoque, donc il
s'affiche. Atténué et **jamais rouge** — une expiration est une réponse normale du
système, pas un échec (§5 règle 2, même raisonnement que le code d'appairage
expiré de KL-47).

« Tout révoquer » n'apparaît qu'à partir de deux appareils. Avec un seul, il ne
serait qu'un second bouton pour le geste d'à côté.

### Ce que le lot ne fait pas

Il ne touche **pas** aux codes d'appairage non consommés. Un code n'est pas un
accès mais une invitation de deux minutes, affichée sur l'écran de celui-là même
qui révoque : il ne peut pas être parti avec le téléphone perdu. Mélanger les deux
durées de vie aurait donné à « tout révoquer » un effet que son libellé ne promet
pas.

Il ne rafraîchit pas non plus la liste au moment où un appairage se confirme : le
sondage de KL-47 observe **un code**, pas un compte, et lui faire réécrire le
panneau des appareils créerait un second endroit qui décide de ce que cette liste
contient. Le nouvel appareil y apparaît au chargement suivant ; sur le moment,
c'est la confirmation « Pixel 8 est connecté » qui fait foi.

### Fichiers touchés

Neufs : `templates/profile/_devices.html.twig`,
`templates/profile/stream/devices.stream.html.twig`,
`tests/Controller/DeviceRevocationTest.php` (7 tests),
`assets/icons/lucide/tablet-smartphone.svg`.
Modifiés : `src/Controller/ProfileController.php` (constructeur + les deux
révocations), `src/Repository/ApiTokenRepository.php` (`deleteForOwner()`),
`templates/profile/settings.html.twig`, `assets/styles/components.css` (section
`kd-devices`).

Le test qui porte le lot est `testRevokingADeviceEndsItsApiAccess` : sans lui, la
page ne prouverait qu'une ligne retirée d'un tableau. Ce qui compte, c'est que le
secret présenté juste avant rende 401 juste après.

**Prochain ticket : KL-13** — les réponses d'erreur normalisées (RFC 9457) et le
limiteur de débit sur la connexion. Le lot 2 a désormais son socle
d'authentification complet et réversible.

---

## Kadens Live KL-13 — Erreurs normalisées et limitation de débit (31/07/2026)

Le lot 2 avait trois producteurs d'erreurs et aucune garantie. L'authenticator
rendait un objet RFC 9457 écrit à la main, `AuthController` en rendait un autre
écrit à la main, et **tout le reste** — une entité introuvable, une méthode qui
n'existe pas sur une route qui existe, une exception Doctrine — serait sorti en
HTML. Sur un pare-feu stateless dont le seul client est une app mobile qui n'a
qu'un décodeur JSON, c'était une panne en attente. Ce lot ferme le trou par le
bas (un listener qui rattrape tout) et par le haut (une enveloppe unique), et
pose au passage le limiteur qui manquait sur la connexion.

### Le contrôleur rend ses erreurs, le listener rattrape le reste

La tentation était de faire du listener **la** couche d'erreurs et de convertir
`AuthController` à des exceptions. Mauvaise idée, pour une raison qui tient en
une phrase : un contrôleur sait ce qu'il refuse, un listener ne connaît qu'un
statut. « Identifiants invalides. » et « Code d'appairage invalide ou expiré. »
sont des messages travaillés — leur uniformité entre trois cas distincts est
justement ce que KL-11 et KL-46 protègent par des tests qui comparent les corps
au caractère près. Les faire transiter par une table de traduction statut →
message les aurait tous ramenés à « Requête invalide. ».

La répartition est donc : **le contrôleur rend ses erreurs, le listener rattrape
ce que personne n'a rendu.** Le listener est un filet, pas une couche de plus à
traverser. Ce qui se factorise, c'est l'enveloppe, pas la décision.

### Le `title` ne s'écrit plus à la main

`ApiProblem::response()` est cette enveloppe. La signature a perdu un paramètre
au passage, et c'est le vrai gain : le `title` se dérive du statut. Avant, huit
appels portaient un couple `(Response::HTTP_BAD_REQUEST, 'Bad Request')` — huit
occasions de les mettre en désaccord, dont aucune ne se serait vue avant qu'un
client ne lise le mauvais libellé. Un libellé de catégorie n'est pas un message :
il n'y a rien à choisir.

Reste une bizarrerie assumée : `title` en anglais, `detail` en français. Ce n'est
pas une négligence. Le `title` est le vocabulaire HTTP, celui qu'on retrouve dans
la RFC et dans les journaux ; le `detail` est ce que le mobile peut afficher tel
quel.

### Le message d'une exception est écrit pour les journaux

C'est la décision qui porte le ticket, et elle va plus loin que la case « aucune
trace de pile en prod ».

Le réflexe est de rendre `$exception->getMessage()` pour les 4xx : le message est
souvent bon, `createNotFoundException('Séance introuvable.')` s'écrit tout seul.
Sauf que la majorité des exceptions 4xx ne sont pas écrites par nous. Le
`NotFoundHttpException` du routeur récite l'URL demandée. Un résolveur d'argument
annonce `App\Entity\Workout object not found by...`. Une exception Doctrine porte
le SQL, avec son utilisateur et son hôte. Distinguer « message écrit pour le
client » de « message écrit pour les journaux » demanderait de marquer les
premiers — une exception maison, donc une discipline, donc un oubli un jour.

Le `detail` est donc **choisi ici**, par statut, dans une table du listener.
Aucune exception ne parle au client. La formulation de la case du ticket devient
alors une conséquence, pas un objectif : il n'y a pas de chemin par lequel un
détail interne puisse partir, pas seulement pas de trace.

Hors prod, et **seulement sur une 5xx**, un membre d'extension `exception` ajoute
la classe, le message et la ligne. Sans lui, une 500 d'API en développement est
un mur de texte identique à toutes les autres. Mais toujours pas la trace : le
profileur la garde déjà, et ne pas l'écrire ici, c'est ne pas avoir de code qui
n'aurait pas le droit de tourner en prod.

### Priorité -1, entre deux écoutes du même listener

`ErrorListener` de Symfony écoute `kernel.exception` **deux fois** :
`logKernelException` à **0**, `onKernelException` à **-128**. Et
`setResponse()` sur un `ExceptionEvent` **arrête la propagation** — c'est un
`RequestEvent`. Se placer à 0, le réflexe, revenait donc à jouer l'existence des
journaux à pile ou face : selon l'ordre d'enregistrement des services, une 500
d'API aurait pu cesser d'être tracée sans que rien ne le signale. À -1, la
journalisation a déjà eu lieu et seul le rendu HTML est supplanté.

L'autre borne est le pare-feu de sécurité, qui écoute à **1**. Il doit passer
d'abord : c'est lui qui transforme un accès refusé en 401 via
`ApiTokenAuthenticator::start()`, ou en 403. Notre listener n'a à connaître ni
l'un ni l'autre.

### Le périmètre est le préfixe, littéralement

`str_starts_with($path, '/api')`, et pas une expression plus fine comme
`^/api(/|$)`. C'est volontaire : le pare-feu de `security.yaml` matche `^/api`,
et deux définitions du périmètre de l'API finiraient par diverger. Une zone où le
pare-feu stateless s'applique mais pas la mise en forme, c'est une URL d'API qui
sort en HTML — exactement ce que le lot vient supprimer.

Hors du préfixe, le listener ne fait **rien**. Les pages d'erreur Twig du lot
précédent (404, 403, 5xx) continuent de se rendre, et un test le vérifie par une
vraie requête, pas par une lecture du code.

### Le limiteur : 5, et par IP

Plus serré que les 10 de l'appairage, parce que les deux secrets ne se ratent pas
de la même façon. Un code de deux minutes se retape après une faute de frappe ; un
mot de passe ne s'essaie pas de bonne foi cinq fois par minute.

La clé reste l'**IP**, et c'est le point qui mérite d'être écrit : compter par
email aurait été plus juste en apparence, mais aurait rendu à la connexion
exactement ce que le 401 uniforme de KL-11 lui retire. « Ce compte est bloqué »
dit « ce compte existe ». Et il aurait suffi de connaître un email pour en
verrouiller l'accès à son titulaire.

Le 429 se rend avant le décodage du corps, comme en appairage. Un test l'exige de
la façon qui compte : quota épuisé, **bon** mot de passe, aucun jeton émis.

### Tester ce que fait la prod, sans prod

`kernel.debug` est vrai en test comme en dev. Une requête HTTP ne pouvait donc
rien prouver sur ce que la prod laisse filtrer — c'est le même mur que les pages
d'erreur, qui ne se rendent qu'en prod et que `ErrorPageTest` exerce en rendant
les templates directement. Ici, `ApiExceptionListenerTest` instancie le listener
avec `debug: false` et lui passe un `ExceptionEvent` construit à la main. Le test
qui porte le lot y lève une exception dont le message contient une chaîne de
connexion, et vérifie qu'elle ne sort nulle part.

Le comportement en situation (404, 405 avec son `Allow`, périmètre) reste
fonctionnel, dans `ApiErrorResponseTest`.

### Fichiers touchés

Neufs : `src/Http/ApiProblem.php`, `src/EventListener/ApiExceptionListener.php`,
`tests/EventListener/ApiExceptionListenerTest.php` (7 tests),
`tests/Controller/ApiErrorResponseTest.php` (5 tests).
Modifiés : `config/packages/rate_limiter.yaml` (limiteur `api_login`),
`src/Controller/Api/AuthController.php` (limiteur sur `login`, `throttle()`
partagé avec `pair`, `problem()` délégué), `src/Security/ApiTokenAuthenticator.php`
(401 délégué), `tests/Controller/ApiAuthEndpointsTest.php` (2 tests de limiteur +
vidage du pool de cache au `setUp`), `CLAUDE.md` (§4 pour `src/Http/`, §6).

**Prochain ticket : KL-14** — `GET /api/bootstrap`, l'hydratation complète de la
base locale en une requête. C'est l'endpoint le plus important du lot.

---

## Kadens Live KL-14 — `GET /api/bootstrap` (01/08/2026)

Tout ce que l'app fait hors réseau, elle le fait sur ce que cet appel a
descendu : la bibliothèque d'exercices, une fenêtre de calendrier avec son
prescrit **et** son réalisé, la dernière perf et le record par exercice. Une
requête HTTP, seize requêtes SQL, et un nombre de requêtes qui ne bouge pas avec
le volume.

### Le delta n'allège que ce qui sait dire quand il a changé

Le ticket demandait `?since=` sans préciser sur quoi. La réponse honnête, après
avoir cherché la colonne, c'est : **sur les exercices, et sur rien d'autre**.

La fraîcheur d'une séance datée n'est mesurable nulle part. Elle dépend d'un
arbre — `Workout` → `Block` → `PrescribedExercise` → `PrescribedSet` — dont aucun
niveau n'horodate son parent. Retoucher une série ne date pas sa séance, encore
moins la séance datée qui la référence. Un delta sur `ScheduledWorkout.updatedAt`
aurait très bien marché à l'essai, et aurait manqué **en silence** le seul cas
qui compte : le coach qui corrige le programme de la semaine sur le web. Une
fenêtre de 45 jours est bornée par construction ; un programme périmé sur le
téléphone ne l'est pas. La fenêtre part donc toujours en entier.

L'historique aussi, pour une raison différente : il est déjà bon marché — deux
requêtes quel que soit le nombre d'exercices, c'est exactement ce pour quoi
`PerformanceHistory::bulkFor()` a été écrit en KL-04. Le rendre partiel
laisserait un second appareil, ou une suppression de réalisé faite sur le web,
avec un record fantôme.

Ce qui grossit sans borne, c'est la bibliothèque — la globale importée en
console, surtout — et elle, elle a un horodatage fiable.

Corollaire pour le client, écrit noir sur blanc dans la réponse : `window` dit
l'intervalle, et ce qu'il contient fait autorité. Une séance datée que le
téléphone garde dans cet intervalle et qui n'est pas dans la réponse n'existe
plus — déplacée hors fenêtre ou supprimée, le geste local est le même. C'est ce
qui évite d'inventer une pierre tombale pour un simple déplacement.

### `COALESCE(updatedAt, createdAt)`, et la case du ticket qui sauve le delta

`Exercise.updatedAt` n'est écrit qu'au `preUpdate` : il reste **null** tant qu'un
exercice n'a jamais été retouché. Un filtre naïf `updatedAt >= :since` aurait
donc fait disparaître du delta la quasi-totalité de la bibliothèque globale, et
un exercice créé après le dernier bootstrap ne serait **jamais** arrivé sur le
téléphone. Le ticket l'avait vu venir ; le test qui le garde crée les trois cas
(ancien jamais retouché, récent jamais retouché, ancien retouché ce matin) et
n'en attend que deux.

### La pierre tombale plutôt que le `deleted_at`

Sans trace des suppressions, un delta ne sait dire que ce qui a changé, jamais ce
qui a disparu : la base locale accumule des fantômes, et un exercice supprimé
reste proposé pendant des mois.

Le ticket laissait le choix entre un `deletedAt` sur les entités concernées et
une table dédiée. C'est la table, `deleted_entity`, et l'argument tient en une
phrase : **la suppression douce ne supprime pas, elle cache**, et il faut alors
la filtrer dans *chaque* requête du site — index, sélecteurs de pose, calendrier,
export, ICS, page publique. Un oubli n'y produit aucune erreur, seulement une
ligne morte qui réapparaît. La pierre tombale, elle, ne touche à rien : la ligne
est bel et bien supprimée, et ce qu'on garde n'est plus l'entité mais son avis de
décès.

Elle porte une **clé** et non une relation (une FK vers une ligne supprimée
n'existe pas) : l'`id` pour un exercice, l'`uuid` pour une séance datée — le
mobile ne connaît que celui-là pour ce qu'il a créé lui-même. Et un `owner`
nullable, qui dit à qui l'annoncer : null pour la bibliothèque globale, dont la
disparition regarde tout le monde.

### L'écrire dans un écouteur, pas au moment de supprimer

Il y a une douzaine de points de suppression dans l'app, et il s'en ajoutera. Un
oubli à un seul d'entre eux ne casse rien de visible : il laisse un fantôme dans
la base d'un téléphone, des semaines plus tard. Une panne sans symptôme se
prévient structurellement.

`TombstoneListener` travaille en deux temps, et les deux sont nécessaires.
`onFlush` est le seul moment où l'on voit encore les entités à supprimer *avec*
leur identifiant et leur propriétaire ; `postFlush` le seul où l'on peut
persister du neuf sans se battre avec l'unité de travail en cours. La liste vidée
avant le second `flush()` borne la récursion à elle seule.

Une garde qui n'est pas décorative : si le propriétaire part dans la même
transaction (suppression de compte), la pierre tombale est **sautée**. Sa clé
étrangère pointerait dans le vide, et il n'y a de toute façon plus de téléphone à
prévenir. Limite assumée du même ordre : un `ON DELETE CASCADE` exécuté par la
base ne déclenche aucun événement Doctrine — sans conséquence, pour la même
raison.

`app:deleted:purge` retire les lignes de plus de 180 jours, en cron, comme
`app:pairing:purge`. Cent quatre-vingts jours, soit le double de la vie d'un
`ApiToken` : un téléphone plus en retard que ça n'a plus de jeton valide, il
repartira d'un bootstrap complet — où les pierres tombales ne servent pas,
puisque le jeu complet remplace tout. C'est aussi pourquoi la liste des
disparitions est **vide** quand `since` est absent.

### Une structure de séance datée, et une seule définition

`ScheduledWorkoutPayload` produit le document d'une séance datée. Le bootstrap le
répète pour chaque séance de sa fenêtre, KL-15 le rendra seul, KL-16 le recevra.
KL-15 exige que le client n'écrive qu'un désérialiseur : la seule façon de tenir
cette promesse est de n'avoir qu'un endroit qui produit la structure.

Le prescrit vient de `PlanFlattener`, `setLines` compris — règle verrouillée, et
la vue déroulée est exactement ce dont le téléphone a besoin pour pré-remplir une
séance. La liste **plate** du bloc et non ses `segments` : un superset y ferait
figurer deux fois le même exercice, et le rang (`groupLabel`, « A1 »/« A2 »)
suffit à le dessiner puisque le mobile ne recompose pas.

Les valeurs sont brutes (kg, mètres, secondes), avec une exception assumée :
`summary`, la ligne lisible d'un exercice. Le cardio est hors périmètre de la
saisie mobile — il ne s'affiche qu'en lecture — et réécrire en TypeScript les six
branches de `summarize()` pour une chaîne qu'on ne fait que peindre serait une
duplication sans contrepartie.

### Ce qui ne sort pas, et pourquoi c'est testé

`Workout.notes` est le fourre-tout du propriétaire seul. Il n'entre pas dans
`PlanFlattener`, donc ni dans l'export Excel, ni dans l'ICS, ni dans la page
publique : l'API est une vue de consultation de plus, elle tombe sous la même
garde. Le test le cherche dans le corps brut de la réponse, et vérifie du même
geste que la consigne d'un exercice prescrit, elle, **sort** — c'est une note
adressée à celui qui exécute.

La portée de la bibliothèque est en revanche **symétrique**, celle
d'`ExerciseVoter::VIEW` : soi, ses coachs, ses athlètes. Une séance composée par
le coach peut poser ses variantes maison dans le programme de l'athlète, elles
doivent donc descendre sur son téléphone — sinon il lit des exercices sans fiche.
Ce n'est pas la portée dirigée de `CoachedLibrary` : ici on parle de ce qu'on peut
lire, pas de ce qu'on retrouve dans sa bibliothèque. Le calendrier, lui, ne se
partage pas : un coach ne reçoit pas les séances datées de son athlète.

### Deux requêtes pour la fenêtre, et c'est structurel

Le prescrit (`w → b → pe → ps`) et le réalisé (`le → ls`) sont deux collections
**sœurs** sous la même séance datée. Les joindre dans la même requête en ferait
le produit cartésien : quinze séries prescrites et douze réalisées donneraient
cent quatre-vingts lignes à hydrater pour un seul exercice. Chaque branche est en
revanche une chaîne, donc sans risque — on peut y descendre aussi profond qu'on
veut. La seconde requête retombe sur les mêmes entités gérées, Doctrine les
complète en place.

L'historique est une liste, jamais un objet indexé par identifiant d'exercice :
`json_encode` rend un tableau PHP en objet **ou** en liste selon ses clés, et un
index qui se dégraderait en tableau JSON ferait déchiffrer autre chose au client
sans qu'aucun test ne bronche. Même piège que le `'p' ~ id` de KL-07, réglé ici
par une forme qui n'a pas d'ambiguïté.

### Mesuré, et le piège qui a failli fausser la mesure

Jeu d'essai : 200 exercices, un mois et demi de calendrier chargé (15 séances de
5 exercices), le réalisé de tout le passé. **80,6 Ko et 16 requêtes SQL**,
106 ms avec le profileur actif — les budgets du ticket étaient 1 Mo et 500 ms.

Le test garde la taille et le **nombre de requêtes**, pas le chronomètre : en CI,
un chronomètre mesure la machine, un compteur de requêtes mesure le code — et
c'est un N+1 qui ferait exploser le budget de temps.

Le piège, lui, valait le détour : `KernelBrowser` ne redémarre le noyau qu'à
partir de la **deuxième** requête. La première mesure se faisait donc dans le
conteneur qui venait de poser les fixtures, journal SQL compris : 991 requêtes,
dont les centaines d'`INSERT` du jeu d'essai. Une sonde `/api/ping` intercalée
force le redémarrage et la mesure retombe sur ce qu'elle prétend mesurer. Même
famille que le piège `loginUser()` de KL-11.

Dernier détail de taille : la réponse sort en `JSON_UNESCAPED_UNICODE`. Sans lui
chaque caractère accentué part en `\uXXXX`, six octets pour un — sur des noms
d'exercices et des consignes écrits en français, c'est le poste d'économie le
plus bête à ne pas prendre.

### Fichiers touchés

Neufs : `src/Controller/Api/BootstrapController.php`,
`src/Service/BootstrapPayload.php`, `src/Service/ScheduledWorkoutPayload.php`,
`src/Entity/DeletedEntity.php`, `src/Enum/DeletedEntityType.php`,
`src/Repository/DeletedEntityRepository.php`,
`src/EventListener/TombstoneListener.php`,
`src/Command/PurgeDeletedEntitiesCommand.php`,
`migrations/Version20260801100000.php`,
`tests/Controller/ApiBootstrapTest.php` (16 tests).
Modifiés : `src/Repository/ExerciseRepository.php`
(`findLibraryForUsersChangedSince`, `libraryIdsForUsers`),
`src/Repository/ScheduledWorkoutRepository.php` (`findWindowWithContentAndLog`),
`src/Service/PerformanceHistory.php` (`bulkForIds` extrait de `bulkFor`),
`CLAUDE.md` (§6), `docs/feature-live-tracking.md` (état, KL-14).

**Prochain ticket : KL-15** — `GET /api/schedule/{uuid}`, la même structure pour
une séance seule. `ScheduledWorkoutPayload` est déjà là, il ne reste que la
résolution par uuid et le voter.

---

## Kadens Live KL-15 et KL-16 — la séance datée, dans les deux sens (02/08/2026)

Deux tickets d'un coup parce qu'ils ne se séparent pas : le second reçoit ce que
le premier rend. Après ce lot, **le sens montant existe** — le téléphone peut
écrire une séance faite, et c'est la dernière pièce manquante avant l'app.

### KL-15 : la promesse « un seul désérialiseur » se teste, ou elle n'existe pas

Le ticket demandait une structure identique à celle du bootstrap.
`ScheduledWorkoutPayload` (KL-14) était déjà le producteur unique, donc le code
était court. Ce qui a demandé de réfléchir, c'est comment **empêcher** la
divergence future : rien n'aurait interdit à ce contrôleur d'ajouter un champ
« juste pour cet endpoint ».

Le test compare donc les deux réponses **entières** — la sortie du `GET` et
l'entrée correspondante du tableau `schedule` du bootstrap — avec un `assertSame`
sur les tableaux décodés. Il échoue au premier écart, d'où qu'il vienne. Comparer
trois clés bien choisies aurait rassuré sans rien garantir.

Deux extractions au passage, chacune pour la même raison :

- **`ApiJson`**, pendant d'`ApiProblem` : un seul endroit pose
  `JSON_UNESCAPED_UNICODE`. L'oublier ne casse rien de visible, ça gonfle la
  réponse de six octets par caractère accentué.
- **`IsoDate`**, qui reprend le garde-fou de forme que `?since` portait seul.
  « Ce que l'API accepte comme date » n'a plus qu'une définition, et les charges
  utiles de KL-16 s'y branchent.

Sur le repository, `withPrescribed()` et `withLog()` remplacent les deux blocs de
jointures écrits à la main : la fenêtre et l'unité partagent la définition de
« avec tout son contenu ». Deux écritures auraient divergé, et la divergence ne
se serait pas vue en erreur — seulement en N+1.

### 404 ou 403 : la question s'est reposée, la réponse a changé

KL-12 et KL-47 avaient tranché « 404, jamais 403 » pour un jeton ou un code
d'appairage qui n'est pas le sien : distinguer confirmerait l'existence de la
ressource à qui l'énumère. La règle est bonne **là où elle a été posée**, et elle
ne s'applique pas ici.

Ce qui change, c'est la clé. Un id d'`ApiToken` est séquentiel, un tiers les
essaie tous en trois lignes de script. Un `uuid` de séance est posé par le client,
128 bits : il n'y a pas d'énumération à fermer, donc pas d'oracle à protéger. Et
le 403 dit quelque chose de vrai à quelqu'un de légitime — le coach dont la
relation vient d'être rompue apprend qu'il n'a plus le droit, là où un 404 lui
ferait croire à une séance disparue.

### KL-16 : « le téléphone fait autorité » ne veut pas dire « le téléphone gagne »

C'est la décision qui a demandé le plus de relecture du cadrage. §0.3 point 1 dit
« le mobile est la seule source d'écriture du **réalisé** ». Pas du planning.

D'où un partage d'autorité plus fin que ce que « le document complet écrase »
laissait entendre :

- `log`, `startedAt`, `endedAt` : du réalisé, le document les écrase.
- `date` et `title` : **création seulement**. Déplacer une séance est un geste de
  programmation, ouvert au coach par `EDIT`. Un téléphone resté trois jours hors
  réseau ramènerait sinon à son ancienne date la séance que le coach vient de
  décaler — et le client n'aurait rien fait de mal, il aurait juste renvoyé le
  document qu'on lui avait donné.
- `status` : ne peut que **clôturer**. Les autres valeurs passent la validation
  mais restent sans effet — un client qui recopie le document reçu ne doit pas se
  prendre un 422 sur un `planned` — et rien ne *déclôture* : §2.3 point 5 dit
  « pas de reprise après clôture ».
- `completionNotes` : s'écrit si le document en porte une, n'efface **jamais**
  celle qui existe. Le silence du téléphone n'est pas un ordre d'effacer la note
  d'écart du coach.

### Deux `flush()`, et ce n'est pas une maladresse

Le remplacement du réalisé a l'air trivial : on efface, on réécrit. Il ne l'est
pas, et les deux chemins naïfs cassent tous les deux.

**Un seul flush ne marche pas.** Doctrine ordonne un `flush()` en insertions,
puis mises à jour, puis suppressions. Effacer et réécrire la même série dans le
même flush envoie donc l'`INSERT` avant le `DELETE` : violation de
`uniq_logged_set_uuid`, c'est-à-dire une 500 sur le cas le plus normal du ticket
— un document rejoué, exactement ce que l'idempotence promet de bien traiter.

**Réconcilier les lignes par uuid est pire.** C'était le premier réflexe : garder
les entités existantes, mettre à jour leurs champs, supprimer le reste. Sauf
qu'une série peut changer d'exercice au sein de la séance, et qu'un élément
retiré d'une collection en `orphanRemoval` est **programmé pour suppression**,
même si on l'ajoute ensuite ailleurs. La série déplacée disparaîtrait. Perte de
données silencieuse, sur un chemin rare : le pire des deux mondes.

Donc : on efface tout le réalisé, on flush, on réécrit, le tout dans une
transaction. Le prix est quelques `DELETE`/`INSERT` sur des dizaines de lignes.
Le gain est un invariant qu'on peut relire sans se demander « sauf si » — après
l'appel, le réalisé **est** le document.

### Les références se vérifient, elles ne se rattrapent pas

Un `exerciseId` qu'on n'a pas le droit de voir, un `sourcePrescribedId` qui
désigne la ligne du programme d'une **autre** séance : les deux sont refusés en
422, avec le chemin du champ (`log[0].exerciseId`).

La tentation était de les mettre à `null` — les colonnes sont nullable, la séance
resterait lisible. Elle serait surtout **muette** : plus d'historique, plus de
record, plus d'appariement prévu vs réalisé, et rien pour le signaler. Un client
qui envoie une mauvaise référence a un bug ; il vaut mieux qu'il l'apprenne.

La portée des exercices est celle d'`ExerciseVoter::VIEW`, appelé exercice par
exercice, pas une requête DQL réécrite pour l'occasion : la réécrire ferait un
second endroit qui décide de ce qu'on a le droit de lire. `CoachingResolver`
mémoïse, et une séance référence une dizaine d'exercices.

Un `uuid` de série emprunté à une autre séance rend **409** et non 422 : le
document n'est pas malformé, il entre en conflit avec un état existant. Le client
doit régénérer l'identifiant, pas corriger un champ.

### Ce que le `DELETE` accepte

Trois colonnes testées, pas une : `workout`, `sourcePlanTemplate`,
`sourcePlanItem`. Une séance de plan dont la séance source a été supprimée en
bibliothèque a `workout = null` sans être libre pour autant — c'est
précisément le cas que le `SET NULL` de KL-02 a créé.

Le refus est un **409**, pas un 403 : le propriétaire a bien le droit de
supprimer sa séance, c'est l'état de la ressource qui rend le geste impossible
*ici*. Une séance qui porte un programme se retire depuis le web, avec le
contexte qui va avec.

Et la garde est `LOG`, pas `DELETE` : ce que le téléphone efface, c'est du
réalisé. Un coach ne supprime pas la séance libre de son athlète.

### Fichiers touchés

Neufs : `src/Controller/Api/ScheduleController.php`,
`src/Service/LogIngestor.php`, `src/Http/ApiJson.php`, `src/Http/IsoDate.php`,
`src/Http/ScheduledWorkoutInput.php`, `src/Http/LoggedExerciseInput.php`,
`src/Http/LoggedSetInput.php`, `tests/Controller/ApiScheduleTest.php` (19 tests).
Modifiés : `src/Repository/ScheduledWorkoutRepository.php`
(`findByUuidWithContentAndLog`, jointures factorisées),
`src/Controller/Api/BootstrapController.php` (passe par `ApiJson` et `IsoDate`),
`CLAUDE.md` (§4, §6), `docs/feature-live-tracking.md` (état, KL-15, KL-16).
Aucune migration : le modèle du réalisé était complet depuis KL-02.

**Prochain ticket : KL-17** — `GET /api/exercises/{id}/history`, qui consomme
`PerformanceHistory` sans requêter en direct.

---

## Kadens Live KL-17 — la trajectoire d'un exercice (02/08/2026)

`GET /api/exercises/{id}/history` : dernière performance, record, et les dix
dernières séances sur un exercice. Le plus court ticket du lot 2, et pourtant
deux décisions valaient d'être écrites — l'une sur le producteur, l'autre sur le
code de refus.

### « Consomme `PerformanceHistory`, ne requête pas en direct »

Le service (KL-04) savait dire « la dernière fois » et « c'est quoi mon
record ? ». Il ne savait pas dire « les dix dernières fois ». La tentation était
d'écrire la requête dans le contrôleur ou dans un service à côté ; la ligne du
ticket l'interdisait, et elle avait raison pour une raison qui n'est pas
esthétique : les trois lectures doivent partager **le même périmètre**.
Échauffement exclu, exercice sauté exclu, statut de la séance non filtré, portée
du seul utilisateur demandé. Trois chiffres lus sur trois définitions différentes
de « ce qui compte » ne se comparent pas, et l'écart ne se verrait que sur un
téléphone, des semaines plus tard.

`recentSessions()` est donc écrit dans `PerformanceHistory`, et le périmètre des
trois lectures est descendu d'un cran dans le repository : `workingSetScope()`
porte les filtres, `workingSetRows()` n'ajoute que la projection et le tri. Le
résumé d'une séance (condensé des séries, tonnage, charge maximale) est lui aussi
factorisé en `summarize()` — la dernière performance et chaque point de la
trajectoire sont la même chose calculée une seule fois.

### Deux requêtes, et bornées toutes les deux

L'historique d'un exercice grossit sans limite. Ramener toutes ses séries pour
n'en garder que dix séances marcherait la première année, puis se dégraderait
sans que rien ne le signale. La borne est donc en SQL : une première requête
prend les dix séances distinctes les plus récentes (`setMaxResults`), une seconde
lit les séries de celles-là. Un test compte les requêtes sur quatorze séances,
comme celui de `bulkFor()`.

### `last` est dérivé, pas relu

`sessions[0]` **est** la dernière performance : même requête, même règle. Le
déduire supprime une lecture et, surtout, la possibilité que les deux se
contredisent. Le champ reste exposé parce que le client l'a déjà dans son
bootstrap — le retirer l'obligerait à traiter la fiche d'exercice autrement que
le reste. Un test unitaire fige l'égalité avec `lastPerformance()` : si les deux
lectures cessaient de s'accorder, il le dirait ici et pas sur le téléphone.

### `PerformanceHistoryPayload`, ou la troisième fois qu'on extrait un producteur

`BootstrapPayload` portait la traduction JSON d'une performance. L'endpoint
l'aurait réécrite. C'est exactement ce que KL-14 avait évité pour la séance datée
avec `ScheduledWorkoutPayload`, et pour la même raison : deux écritures de « à
quoi ressemble une dernière perf » ne divergent pas tout de suite, elles
divergent un jour, en silence, sur un client qui n'a qu'un désérialiseur.

Le corps de l'endpoint est donc, au champ `sessions` près, une entrée du tableau
`history` du bootstrap — et le test le compare sous-document par sous-document,
pas clé par clé.

Ce que la charge utile ne porte pas : **aucun identifiant de séance**. Une séance
datée s'adresse par son `uuid` partout ailleurs dans l'API, et l'historique n'a
pas vocation à en ouvrir une. C'est une trajectoire, une suite de points datés ;
deux séances du même jour restent deux entrées, départagées par leur rang.

### Introuvable et invisible rendent le même 404

KL-15 avait tranché l'inverse pour la séance datée (404 *et* 403). Ce n'est pas
une contradiction, c'est la même règle appliquée : ce qui décide, c'est la
**nature de la clé**. Un `uuid` posé par le client ne se devine pas, il n'y a pas
d'oracle à fermer. Un identifiant séquentiel d'exercice s'énumère en trois lignes
de script, et un 403 y dirait « cet identifiant existe, il appartient à quelqu'un
d'autre » — soit la taille et la composition de la bibliothèque perso des autres,
exercice par exercice.

La distinction ne manquerait à personne : le téléphone ne demande l'historique
que d'un exercice reçu au bootstrap, donc visible.

### Voir la fiche n'est pas voir l'historique

`ExerciseVoter::VIEW` est la seule règle symétrique du projet : un coach ouvre la
fiche de la variante maison de son athlète. Mais `PerformanceHistory` ne lit que
le réalisé du **porteur du jeton**. Un coach qui ouvre cette fiche voit donc sa
propre trajectoire sur cet exercice, pas celle de son athlète — et un test le
fige. Lire le réalisé d'un athlète a son endroit, `GET /api/schedule/{uuid}`, où
la séance dit de qui elle parle.

### Le seul écran mobile qui suppose du réseau

Assumé. Le bootstrap descend déjà le dernier point et le record de toute la
bibliothèque : c'est ce que KL-32 affichera sous chaque exercice **en séance**,
hors réseau. Descendre en plus dix séances par exercice ferait grossir une
réponse bornée à 1 Mo pour un écran qu'on ouvre rarement. Consulter une
progression n'est pas dérouler une séance.

### Fichiers touchés

Neufs : `src/Controller/Api/ExerciseController.php`,
`src/Service/PerformanceHistoryPayload.php`,
`tests/Controller/ApiExerciseHistoryTest.php` (7 tests).
Modifiés : `src/Service/PerformanceHistory.php` (`recentSessions()`,
`summarize()`), `src/Repository/LoggedSetRepository.php`
(`findRecentWorkingSetsForExercise()`, périmètre extrait en `workingSetScope()`),
`src/Service/BootstrapPayload.php` (délègue la mise en forme de l'historique),
`tests/Service/PerformanceHistoryTest.php` (5 tests de plus), `CLAUDE.md` (§6),
`docs/feature-live-tracking.md` (état, KL-17). Aucune migration.

**Prochain ticket : KL-18** — les tests fonctionnels de l'API, endpoint par
endpoint.

---

## Kadens Live KL-18 et KL-19 — la matrice et le contrat (03/08/2026)

Deux tickets de clôture du lot 2. L'un fige ce que tous les endpoints doivent
tenir, l'autre écrit ce que le client peut en attendre. Ils vont ensemble : la
documentation n'engage à rien si rien ne la garde, et une garde qui n'est écrite
nulle part ne se lit que dans le code.

### Ce qui existait déjà, et ce qui manquait vraiment

La tentation, sur un ticket « tests fonctionnels de l'API », est d'écrire huit
fichiers de plus. Ils existaient : `ApiBootstrapTest`, `ApiScheduleTest`,
`ApiExerciseHistoryTest`, `ApiAuthEndpointsTest`, `ApiPairingTest`,
`ApiAuthenticationTest`, `ApiErrorResponseTest` — chacun teste ce que son
endpoint fait de particulier, et la plupart des cases du ticket étaient déjà
cochées (idempotence à trois envois, `PUT` qui ne touche ni le prescrit ni le
plan, les trois refus d'appairage).

Ce qui manquait n'était pas un test de plus par endpoint : c'étaient les gardes
qui **ne dépendent d'aucun endpoint**. Être authentifié, ne pas ouvrir de
session, ne pas laisser fuiter le bloc-notes privé — trois règles vraies partout,
vérifiées à deux ou trois endroits. Le bloc-notes était testé sur le bootstrap,
l'absence de cookie sur `ping` et `bootstrap`, le jeton expiré sur `ping` seul, le
jeton révoqué nulle part.

### Une liste, pas huit copies

`ApiEndpointMatrixTest` tient dans un fournisseur de données et cinq tests. La
liste des endpoints s'écrit une fois ; chacun passe par anonyme → 401, expiré →
401, révoqué → 401, nominal → son statut, et aucun cookie sur les deux issues.
Huit endpoints, quarante-cinq tests, cent vingt-trois assertions.

L'intérêt n'est pas la couverture d'aujourd'hui, c'est celle de demain : un
endpoint ajouté au lot 3 s'écrit **une ligne** dans `endpoints()` et se retrouve
soumis aux quatre gardes d'un coup. Le seul trou possible est un endpoint absent
de la liste, et il se voit à la lecture — compromis assumé, dériver la liste des
routes ne dispenserait pas de fabriquer une ressource valide pour chacune.

### Trois pièges qui valaient la peine

**La sentinelle du bloc-notes doit être en ASCII.** `AuthController` rend ses
réponses par `$this->json()`, pas par `ApiJson` : sans `JSON_UNESCAPED_UNICODE`,
une note écrite « Brouillon privé » sortirait en `Brouillon privé`, et
`assertStringNotContainsString('Brouillon privé', …)` passerait sur une **vraie**
fuite. Le test cherche donc `SENTINELLE-BLOC-NOTES-PRIVE`, qu'aucun échappement
ne peut déguiser.

**Le nominal fait partie de la matrice.** Sans lui, un endpoint cassé rendrait
401 partout et passerait les trois tests de refus sans qu'un seul ne bronche.

**`WWW-Authenticate` n'est pas uniforme, et c'est correct.**
`POST /api/auth/logout` vit sous `^/api/auth`, donc public pour `access_control` :
sa garde est dans le contrôleur, qui formule son propre 401 sans en-tête de défi.
D'où une colonne `byFirewall` dans le fournisseur plutôt qu'une assertion faussée
sur sept endpoints.

### La doc écrite en l'exécutant

`docs/api-mobile.md` documente onze endpoints, le partage d'autorité champ par
champ, le protocole d'appairage de bout en bout et le format exact de la charge
utile du QR. La règle qui le tient : **il dit le *quoi*, jamais le *pourquoi***.
Le raisonnement vit dans `CLAUDE.md` et dans `feature-live-tracking.md` ; le
recopier créerait une seconde source qui divergerait au premier changement.

La case « un exemple `curl` complet par endpoint, réellement exécuté » n'est pas
une formalité. Tout a tourné contre le serveur de dev : compte de démonstration,
appairage complet (session web, jeton CSRF, code émis, code échangé, code rejoué,
sondage de statut avant et après), cycle de synchronisation entier, et les
réponses sont collées telles quelles.

### Ce que l'exécution a fait sortir

Un défaut que personne n'avait vu, et qu'aucun test n'aurait attrapé puisqu'ils
écrivent tous en UTC : **un horodatage entrant à décalage non nul perd son
fuseau.** `"startedAt": "2026-08-02T18:04:00+02:00"` est stocké `18:04:00` et
relu `2026-08-02T18:04:00+00:00` — l'heure murale conservée, le décalage jeté,
l'instant absolu faux de deux heures. Les durées, elles, restent justes : les
deux bornes glissent ensemble.

Cause : le décalage n'est pas normalisé avant persistance, et Doctrine formate
l'objet tel qu'il le reçoit. Le correctif tient en une normalisation à la lecture
de la charge utile, mais il change le comportement d'un endpoint déjà livré
(KL-16) : ce n'est pas à la documentation de le décider. Le contournement client
est documenté et vérifié — **tout envoyer en UTC** (`2026-08-02T16:04:00Z` est
relu à l'identique).

### Fichiers touchés

Neufs : `tests/Controller/ApiEndpointMatrixTest.php` (45 tests),
`docs/api-mobile.md`. Modifiés : `CLAUDE.md` (§6),
`docs/feature-live-tracking.md` (état, KL-18, KL-19). Aucune migration, aucun
code de production touché.

**Le lot 2 est clos. Prochain ticket : KL-20** — l'export des tokens de design,
premier ticket du lot 3 (l'app Android).

---

## Kadens Live KL-20 — les tokens publiés (03/08/2026)

Premier ticket du lot 3, et le seul de ce lot qui vive côté serveur. L'identité
« Presse » est décrite dans `assets/styles/tokens.css`, que React Native ne sait
pas lire. La question n'est donc pas « comment donner ces valeurs au mobile »
mais « comment éviter qu'elles y divergent ».

### Une projection, pas une traduction

`app:tokens:export` lit la feuille, résout les `var()` et écrit
`public/design-tokens.json` : 90 primitives, 65 tokens sémantiques, dans l'ordre
du fichier, avec les deux couches séparées.

Résoudre les références est indispensable — `--color-bg` doit valoir `#dcdcd7`,
un consommateur natif ne suit pas une référence. **Traduire** aurait été le
piège : `--font-display` sort en `'Barlow Condensed', system-ui, sans-serif`,
`--color-scrim` en `color-mix(in srgb, #0b0b0b 42%, transparent)`, et il est
tentant de rendre la première famille et de calculer le mélange. Ce serait écrire
un moteur CSS partiel en PHP, dont chaque cas non couvert produirait une valeur
fausse — et muette. La fidélité à la source est ce qui rend l'export vérifiable ;
l'adaptation aux API natives est exactement le travail de `src/theme/tokens.ts`
(KL-22), qui sait, lui, ce que `expo-font` attend.

Ce que la commande refuse, en revanche, c'est une `var()` qui ne se résout pas :
nom inconnu ou cycle, elle échoue. Un token qui pointe un nom inexistant est une
faute de frappe dans `tokens.css`, et sans cette garde elle sortirait sur un
téléphone, sous la forme d'une couleur manquante qui ne dit rien.

### Le fichier est versionné, et c'est ce qui rend le test possible

Le ticket demandait « un test qui échoue si un token sémantique du CSS n'est pas
dans le JSON ». Un test qui régénère puis vérifie sa propre sortie ne prouverait
que le parseur. Le JSON est donc **généré et versionné**, comme
`assets/styles/fonts.css` et `templates/components/_pwa_splash.html.twig` — la
convention existait déjà — et `ExportDesignTokensCommandTest` compare les
**documents entiers** : un token ajouté, renommé, ou dont la valeur a bougé, fait
échouer la suite avec le diff sous les yeux. Vérifié en ajoutant un token pour
voir.

Corollaire : le rendu est déterministe, aucun horodatage. Une date de génération
aurait rendu la comparaison impossible et bruité chaque diff. La commande tourne
quand même dans le workflow de build, pour que ce qui part en prod soit produit
par la source.

### Les polices

`tools/fetch-fonts.sh` produit en plus un `.ttf` par famille et par graisse dans
`public/fonts/` (11 fichiers, 1 Mo). Deux détails qui ne s'inventent pas :

- **Le ttf s'obtient en appelant Google Fonts *sans* l'UA moderne.** Le script en
  posait un pour forcer le woff2 ; le retirer bascule la réponse en `truetype`.
  Ces fichiers-là ne sont pas subsettés — Google ne sert le ttf qu'entier, et un
  téléphone n'a pas de budget de première peinture à tenir.
- **Ils vivent dans `public/fonts/`, pas dans `assets/`.** Rangés à côté des
  woff2, AssetMapper les compilerait en URL digestées : un méga-octet embarqué en
  prod pour des fichiers que le web ne demande jamais et que le mobile ne saurait
  pas trouver.

Le ticket ne nommait que les deux Barlow. IBM Plex Mono est du lot quand même :
KL-22 charge les trois familles par `expo-font`, en livrer deux aurait garanti un
ticket de rattrapage.

### Fichiers touchés

Neufs : `src/Command/ExportDesignTokensCommand.php`,
`tests/Command/ExportDesignTokensCommandTest.php`, `public/design-tokens.json`,
`public/fonts/*.ttf`. Modifiés : `tools/fetch-fonts.sh`,
`.github/workflows/deploy.yml`, `CLAUDE.md` (§6),
`docs/feature-live-tracking.md` (état, KL-20), `docs/design-system.md`. Aucune
migration, aucun code de production touché.

**Prochain ticket : KL-21** — l'init du dépôt `kadens-mobile`. Il n'attend rien
du serveur, et KL-40 (le keystore Android) n'attend que lui.

---

## Kadens Live KL-21 — le dépôt mobile (03/08/2026)

Premier ticket qui n'écrit pas une ligne de PHP. `kadens-mobile` existe : Expo
SDK 57 (React Native 0.86, React 19), TypeScript, `expo-router`, ESLint et
Prettier, `app.json` à l'identité Kadens (`fr.antoninpamart.kadens`, portrait,
`userInterfaceStyle: light`), `android/` hors versionnement, `.env.example` et un
README.

### Le boilerplate est retiré, pas rangé de côté

`create-expo-app` livre un exemple complet : deux onglets, des composants
« thémés », un `constants/theme.ts` avec ses couleurs, un `reset-project.js` qui
déplace tout ça dans `app-example/` le jour où l'on veut démarrer pour de bon.

Le garder aurait posé un thème concurrent de celui que KL-22 va **générer** à
partir de `design-tokens.json`. Deux sources pour une couleur, c'est la situation
que KL-20 a passé un ticket entier à éviter. Ne survivent donc que `_layout.tsx`
et une route racine qui n'affiche que la configuration effective — elle sera
remplacée par l'écran de connexion (KL-26).

Même raisonnement pour iOS : l'icône `assets/expo.icon` du template et la clé
`ios` d'`app.json` sont supprimées. Le hors-périmètre §0.4 est explicite, et un
visuel qu'on ne regarde jamais est un visuel qui pourrit.

### Le rendu web reste, et c'est un revirement

Il avait été retiré avec le reste — `react-dom`, `react-native-web`, la clé `web`
d'`app.json`, `platforms: ["android"]` — au motif que la cible est Android et que
le web mort est du bruit.

Un `expo start` lancé en parallèle sur la machine a échoué : `Unable to resolve
"react-native-web/dist/index" from expo-router/build/renderRootComponent.js`. Le
point d'entrée d'`expo-router` l'importe, et c'est le chemin le plus court pour
regarder un composant sans téléphone branché. Rétabli, avec la mention explicite
dans le README que rien ne s'y vérifie.

### `EXPO_PUBLIC_API_URL` est un défaut, pas la configuration

L'URL du serveur arrive par le QR d'appairage (KL-48) et vivra dans la base
locale (KL-24). La variable d'environnement ne sert qu'au développement, et
`src/config.ts` documente les deux pièges sur place : une variable
`EXPO_PUBLIC_*` est **inlinée dans le bundle**, donc lisible dans l'APK — rien de
secret n'y passe jamais — et l'accès doit rester écrit en toutes lettres, un
`process.env[nom]` calculé rendrait `undefined`.

Le README porte le rappel qui fait perdre une soirée à qui l'ignore : sur le
téléphone, `localhost` désigne le téléphone. L'IP LAN de la machine, et Symfony
démarré avec `--listen-ip=0.0.0.0`, faute de quoi il n'écoute que la boucle
locale et l'erreur ressemble à une panne réseau.

### Ce qui se vérifie sans téléphone

`tsc --noEmit`, `eslint`, `prettier --check`, `expo-doctor` (20/20), et surtout
un `expo export` réel pour Android **et** pour web. C'est le seul de ces
contrôles qui exerce vraiment le bundler, et c'est lui qui aurait dû faire sortir
l'histoire de `react-native-web` — il a fallu qu'un `expo start` extérieur la
révèle d'abord. Le bundle Android contient bien l'URL inlinée, vérifié dans le
`.hbc`.

### Les visuels ne se redessinent pas

L'icône et l'icône adaptative Android reprennent `public/pwa/icon-512.png` et
`icon-maskable-512.png`, produits par `tools/build-pwa-icons.php` — le maskable
porte la marque à 55 % du côté, donc dans la zone sûre du disque Android. Fonds
au papier `#dcdcd7` de `--color-bg`. Dessiner ici aurait créé une seconde source
pour une marque déjà générée.

### Fichiers touchés

Dépôt `kadens-mobile` (neuf) : projet Expo, `src/app/_layout.tsx`,
`src/app/index.tsx`, `src/config.ts`, `eslint.config.js`, `.prettierrc.json`,
`.env.example`, `README.md`, `CLAUDE.md`. Dépôt `kadens` : `CLAUDE.md` (§6),
`docs/feature-live-tracking.md` (état, KL-21), ce journal. Aucun code de
production serveur touché.

**Prochain ticket : KL-22** — le socle de design natif, qui consomme le
`design-tokens.json` et les `.ttf` publiés par KL-20.
