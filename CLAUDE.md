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
- **Modèle d'exercice unique et flexible**, piloté par l'enum `PrescriptionType`
  (champs de valeurs nullable, seul le sous-ensemble pertinent est rempli). Pas
  d'héritage par activité.
- **Blocs avec `rounds` + `role`** (`BlockRole`: WARMUP/MAIN/COOLDOWN). Une seule
  mécanique couvre séance plate, superset, circuit, échauffement.
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

Identité **« Carnet clair »** : papier & encre, accent terracotta, olive en
secondaire. Issue de la maquette Claude Design « Kadens — Éditeur de plan ».

- **Source de vérité des tokens** : [`assets/styles/tokens.css`](./assets/styles/tokens.css)
  (primitives `--kd-*` + tokens sémantiques `--color-*`, `--font-*`).
- **Guide d'usage + patterns de composants** :
  [`docs/design-system.md`](./docs/design-system.md).

Règles non négociables :
1. **Jamais de couleur ou de police en dur** dans un template/composant. Toujours
   un token sémantique.
2. **La couleur porte du sens** : terracotta = actions primaires + course/trail ;
   olive = muscu/renfo ; statuts fait/prévu/manqué ont leurs tokens dédiés.
3. Nouvelle valeur → primitive `--kd-*` d'abord, puis token sémantique.
4. Polices (Space Grotesk / Instrument Sans / JetBrains Mono) à charger dans
   `base.html.twig` — voir `docs/design-system.md §3`. À self-héberger le moment
   venu pour l'offline (Phase 9).

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
  suppression `.kd-btn--danger`). La grille bascule en agenda vertical (jour en ligne) :
  à 1024px en édition, 880px en lecture. Couleur neutre (trame multi-activités). Icônes
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
  (l'export Excel en hérite). Tests : `PlanFlattenerTest::paceUnitCases`. **Piste non
  faite (signalée)** : la distance se saisit toujours en mètres (illogique pour
  course/vélo qui pensent en km) — un `DistanceType` activité-conscient serait le
  pendant de `PaceType`.

---

## 7. Maintenance de ce fichier

Mettre à jour CLAUDE.md quand :
- une décision d'archi change ou s'ajoute (répercuter aussi dans `ROADMAP.md`) ;
- une phase est terminée (mettre à jour §6) ;
- l'identité visuelle ou les tokens évoluent (répercuter dans
  `docs/design-system.md` et `assets/styles/tokens.css`).
