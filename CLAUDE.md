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
- **Phase 9 — PWA : faite.** App installable + consultation hors ligne. Fichiers
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
  **Reste à styler** : synthèse.

---

## 7. Maintenance de ce fichier

Mettre à jour CLAUDE.md quand :
- une décision d'archi change ou s'ajoute (répercuter aussi dans `ROADMAP.md`) ;
- une phase est terminée (mettre à jour §6) ;
- l'identité visuelle ou les tokens évoluent (répercuter dans
  `docs/design-system.md` et `assets/styles/tokens.css`).
