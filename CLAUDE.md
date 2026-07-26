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
  commande (`app:user:promote-coach`), jamais depuis l'app.
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

L'historique détaillé phase par phase (socle Symfony, Phases 1 à 9, et tous les
lots de design/finitions) vit désormais dans
[`docs/journal-de-bord.md`](./docs/journal-de-bord.md) : il n'est plus rechargé à
chaque session. S'y référer pour le détail d'une fonctionnalité livrée, et y
consigner chaque nouveau lot terminé (cf. §7).

Résumé : toutes les phases du ROADMAP sont livrées. Socle Symfony en place
(Docker, MariaDB, CI/CD), design tokens posés, et couche visuelle « Carnet
clair » déployée sur toutes les vues. Au-delà du ROADMAP : objectifs datés,
séries détaillées, **relation coach ↔ athlète** (hub `/coaching`, espace
coach `/coach`, `ROLE_COACH`) — cf. §3 pour la règle de propriété — et
**paramètres de compte** (`/profile/settings` : changement de mot de passe,
création de compte par `app:user:create`).

---

## 7. Maintenance de ce fichier

Mettre à jour CLAUDE.md quand :
- une décision d'archi change ou s'ajoute (répercuter aussi dans `ROADMAP.md`) ;
- une phase est terminée (mettre à jour §6) ;
- l'identité visuelle ou les tokens évoluent (répercuter dans
  `docs/design-system.md` et `assets/styles/tokens.css`).
