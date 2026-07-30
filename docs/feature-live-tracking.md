# Feature — Kadens Live (suivi de séance en direct, app mobile)

> Spécification autosuffisante, découpée en tickets. À lire avec `CLAUDE.md`
> (§3 règles verrouillées), `ROADMAP.md` (§1) et `docs/design-system.md`.
> Ce document **modifie une règle verrouillée du projet** (§0.2) : ne pas
> commencer à coder sans avoir passé le ticket KL-01.

---

> **État (2026-07-30)** : cadrage validé, **KL-01 à KL-06 livrés** (règle révisée
> partout, le modèle du réalisé en base — `LoggedExercise` / `LoggedSet`,
> `ScheduledWorkout` étendue et sa FK `workout` passée en `SET NULL` — puis
> `LogMetrics`, le résumé du réalisé, `PerformanceHistory`, la dernière perf
> et le record, `LogComparator`, l'écart prévu vs réalisé, et l'attribut `LOG`
> qui ferme l'écriture du réalisé au coach).
> Prochain ticket : **KL-07** (affichage du réalisé sur `/schedule/{id}`).

---

## 0. Le cadre

### 0.1 Ce qu'on construit

Une **app mobile Android native** (Expo / React Native), distribuée par un
**dépôt F-Droid personnel** hébergé sur `kadens.antoninpamart.fr`, qui permet de :

1. dérouler une séance **programmée ce jour** et en dévier (poids, reps, séries,
   exercice sauté ou remplacé) ;
2. démarrer une **séance vierge** et la remplir au fur et à mesure ;
3. le faire **entièrement hors réseau**, avec synchronisation différée.

Et, côté Symfony, la couche qui rend tout ça possible : un modèle du réalisé,
une API à token, et l'affichage web du réalisé.

### 0.2 La règle verrouillée qu'on change

`ROADMAP.md §1.5` et `CLAUDE.md §3` disent : *« Aucun log détaillé de séries
réalisées. Strava fait le suivi. »* Et `ROADMAP.md` Phase 7 point 4 : *« Ne pas
ajouter de log de séries réalisées. La frontière est nette. »*

**Cette règle était mal calibrée.** Strava enregistre une activité
« musculation » avec une durée et un chrono, et rien d'autre : ni série, ni
charge, ni exercice. La frontière juste n'est pas « pas de tracking » mais
**« pas de tracking cardio »**, où Strava fait effectivement le travail et où le
doublonner serait absurde.

Nouvelle formulation à inscrire dans les deux fichiers :

> **Le réalisé se logue en muscu, jamais en cardio.** Une séance de force écrit
> son réalisé série par série sur la séance datée, parce que rien d'autre ne le
> fait. Une sortie course, vélo ou natation ne se logue pas ici : Strava la
> couvre, et Kadens se contente du `ScheduledStatus`.

Conséquence annexe : ça **tranche le « Lot B » resté en suspens** dans
`docs/feature-progression.md` §3. La progression réalisée se lira désormais sur
les `LoggedSet`, et ce fichier doit être mis à jour en conséquence (KL-01).

### 0.3 Le principe qui tient toute la feature

**Le prescrit ne bouge jamais, le réalisé vit à côté.**

C'est la déclinaison directe de la décision « préserver le réalisé » déjà tenue
par `PlanScheduler`. Une séance en cours n'écrit **jamais** dans `Workout`,
`PrescribedExercise` ou `PrescribedSet`. Elle écrit des `LoggedExercise` et des
`LoggedSet`, portés par la **séance datée** (§2).

Trois corollaires à ne pas casser :

1. **Le mobile est la seule source d'écriture du réalisé.** Le web l'affiche, ne
   l'édite pas. C'est ce qui supprime la quasi-totalité des conflits de
   synchronisation et rend l'offline-first tenable pour un projet solo.
2. **Les identifiants sont générés par le téléphone** (UUIDv7), pas par le
   serveur. C'est ce qui rend `PUT /api/schedule/{uuid}` idempotent : rejouer une
   requête après une coupure réseau ne crée pas de doublon.
3. **En séance on dévie, on ne recompose pas.** Changer un poids, ajouter une
   série, sauter ou remplacer un exercice : oui. Réordonner des blocs, créer un
   superset, gérer les tours d'un circuit : non, ça reste sur le web. Sinon on
   réécrit en React Native le compositeur qui a coûté plusieurs lots en Twig.

### 0.4 Hors périmètre (explicite)

- **iOS.** Le compte développeur Apple à 99 euros par an n'est pas engagé. Le
  code Expo reste multiplateforme par nature, mais aucun ticket ne cible iOS,
  aucun build iOS n'est vérifié, et le design ne se teste que sur Android.
- **Le cardio.** Pas d'écran de saisie pour `DISTANCE_PACE`, `DISTANCE_TIME`,
  `DURATION` ni `FREE`. Ces exercices sont affichés en lecture et cochables
  fait / pas fait, rien de plus.
- **La recomposition de séance** depuis le mobile (cf. §0.3 point 3).
- **L'édition du réalisé depuis le web** (cf. §0.3 point 1). Un réalisé erroné se
  supprime, il ne se corrige pas en v1.
- **Les mises à jour OTA** (`expo-updates` auto-hébergé). Une nouvelle version
  passe par le dépôt F-Droid, comme n'importe quelle app.
- **Le Play Store.** L'app est distribuée en APK signé sur un dépôt personnel.
  C'est un choix, pas un repli.

### 0.5 Stack retenue

| Sujet | Choix | Pourquoi pas l'autre |
|---|---|---|
| App | Expo SDK + expo-router, TypeScript | Capacitor resterait un webview, donc les travers reprochés à la PWA. Flutter ajouterait Dart à maintenir. |
| Base locale | `expo-sqlite` + Drizzle ORM | WatermelonDB et PowerSync sont disproportionnés pour un client unique. |
| Sync | File de mutations maison (~200 lignes) | Voir ci-dessus. |
| Auth API | Token opaque en base (`ApiToken`) | Le JWT n'apporte rien avec un seul client et une seule base, et impose des clés RSA à gérer sur du mutualisé. Le token opaque est révocable pour de vrai. |
| Connexion | Appairage par QR depuis le desktop (§0.6) | Le deep link « Se connecter avec Kadens » demande des App Links vérifiés, PKCE et un Custom Tab, pour un geste trimestriel. |
| Sérialisation | `symfony/serializer` (déjà installé) + DTO | API Platform impose un modèle CRUD-ish qui colle mal, et contredit l'esprit « pas de surcouche » du projet. |
| Build | `expo prebuild` + Gradle dans GitHub Actions | EAS Build ajoute une dépendance à un service tiers pour un besoin que Gradle couvre. |
| Distribution | Dépôt F-Droid statique auto-hébergé | Cohérent avec la philosophie du projet. Obtainium sur GitHub Releases reste le repli documenté. |

**Deux gains à noter** : une app native n'est pas un navigateur, donc **aucune
configuration CORS**. Et un dépôt F-Droid n'accepte que des **APK**, pas des AAB.

### 0.6 La connexion se fait par appairage, pas par mot de passe

**On ne tape pas son mot de passe sur le téléphone.** Kadens desktop, où la
session est de fait permanente (`remember_me` à dix ans), affiche un **code
d'appairage à usage unique** sous forme de QR. L'app le scanne et l'échange
contre un `ApiToken`.

Le deep link « Se connecter avec Kadens » (OAuth authorization code + PKCE) a
été **écarté** : il impose des Android App Links vérifiés (donc un
`/.well-known/assetlinks.json` sur le mutualisé, réputé capricieux à faire
valider), PKCE, un Custom Tab obligatoire — une WebView a son propre pot de
cookies, la session Kadens n'y est pas et tout l'intérêt du flow disparaît — et
il risque d'être capturé par la PWA installée, qui casserait la redirection de
retour. Beaucoup de machinerie pour un geste qu'on répète **une fois tous les
90 jours** (l'expiration glissante de `ApiToken`).

Effet de bord utile : **le QR porte aussi l'URL du serveur**. Zéro configuration
sur le téléphone, et en développement ça règle la saisie de l'IP LAN.

Six règles à tenir, elles sont reprises dans les tickets KL-46 à KL-48 :

1. **Le QR ne contient jamais le token**, seulement un code à usage unique de
   TTL 2 minutes. Sinon une photo de l'écran vaut accès permanent.
2. **Le code se consomme atomiquement** (`UPDATE ... WHERE used_at IS NULL` et
   vérification des lignes affectées). Une lecture puis une écriture laisserait
   passer deux scans simultanés.
3. **Le code est lié à la session desktop qui l'a émis** : seul un utilisateur
   déjà authentifié peut en générer un.
4. **Un code texte de 8 caractères sous le QR**, en repli si la caméra refuse.
5. **L'email et le mot de passe restent** (`KL-11`), en repli ultime. Ils sont de
   toute façon nécessaires aux tests fonctionnels de l'API.
6. **L'appairage est visible et révocable** depuis `/profile/settings` (`KL-12`).

### 0.7 Où le réalisé se lit, côté Kadens web

Cinq endroits, et pas un de plus. Dans tous les cas le réalisé est **dérivé, pas
stocké** : rien n'est ajouté sur `Workout`, `PrescribedExercise` ni `Exercise`.

| Vue | Ce qu'on y voit | Ticket |
|---|---|---|
| `/schedule/{id}` | La séance datée : prévu et réalisé **côte à côte**, en place | KL-07 |
| Le calendrier | L'état de chaque séance, et les séances libres « hors plan » | KL-08 |
| `plan_template/show` | Le réalisé **superposé** à la courbe de progression prévue | KL-49 |
| `/exercise/{id}` | La trajectoire réelle sur un exercice, tous plans confondus | KL-50 |
| `/exercise` | Le tri de la bibliothèque par usage réel | KL-51 |

**La comparaison se lit en place, jamais dans un onglet séparé.** Le projet a
déjà posé la règle « une ligne = une série, quel que soit le mode de saisie » :
le réalisé n'est qu'une colonne de plus dans le tableau existant. Un onglet
dédié obligerait à faire des allers-retours pour comparer, ce qui est exactement
ce qu'on veut éviter.

```
Développé couché
  Série   Prévu           Réalisé
   1      8 × 80 kg       8 × 80 kg
   2      8 × 80 kg       8 × 82,5 kg
   3      8 × 80 kg       6 × 82,5 kg
```

Le prescrit passe en encre atténuée dès qu'un réalisé existe, mais **il ne
disparaît pas** : sans lui, on ne sait plus si la séance a été tenue.

**L'onglet par défaut dépend du statut** : `PLANNED` ouvre sur le programme (on
va la faire), `DONE` ouvre sur le réalisé (on l'a faite). Même URL, même page.

Et **quatre endroits où le réalisé ne doit jamais apparaître** :

- **`/workout/{id}`, la séance en bibliothèque.** C'est la recette, sans date,
  utilisée dans N plans. Un réalisé n'y aurait aucun sens.
- **La page de partage public**, l'**export Excel** et le **flux ICS**. Ils
  passent tous par `PlanFlattener`, où le réalisé n'entre jamais (KL-07, testé
  en KL-09).

### 0.8 Repos

Deux dépôts, comme sur le projet Villard.

- `kadens` (existant) : lots 0 à 2, plus les tickets serveur des lots 6 et 7.
- `kadens-mobile` (nouveau) : lots 3 à 5, plus les tickets de build du lot 6.

---

## 1. Ce qui existe déjà et qu'on RÉUTILISE

Ne rien réimplémenter de cette liste.

- **`PlanFlattener`** — source unique de mise à plat. **L'API le consomme aussi**,
  c'est une règle verrouillée à laquelle `/api/schedule/{id}` n'échappe pas.
  Il expose déjà `setLines` (une entrée par série, dérivée du détaillé ou
  synthétisée depuis le scalaire) : c'est **exactement** la structure dont l'app
  a besoin pour pré-remplir une séance.
- **`SetType`** — l'enum des types de série. Réutilisé tel quel côté log.
  L'échauffement reste exclu du volume de travail.
- **`WorkoutMetrics`**, **`WorkoutEstimator`**, **`UnitFormatter`** — modèles à
  calquer pour les métriques du réalisé, jamais à dupliquer pour le prescrit.
- **`CoachingResolver`** — mémoïse la relation coach acceptée. Le voter des logs
  s'y branche comme les autres.
- **Unités normalisées** (kg / mètres / secondes). L'API les expose brutes, le
  formatage est une affaire de client.
- **`tools/fetch-fonts.sh`** — source des polices. À étendre pour produire aussi
  les `.ttf` dont React Native a besoin (il ne lit pas le `woff2`).
- **`.github/workflows/deploy.yml`** — le patron rsync vers le mutualisé, avec
  gate manuel. Le workflow de publication du dépôt F-Droid le copie.

---

## 2. Modèle de données cible

### 2.1 Pas de nouvelle entité conteneur : la séance datée porte le réalisé

Il n'y a **pas** d'entité `WorkoutLog`. Un premier cadrage en prévoyait une,
elle a été supprimée après vérification : `ScheduledWorkout` portait déjà
l'owner, la date, le `status` et `completionNotes`. Une entité de plus aurait
dupliqué les quatre.

La séance datée devient donc le point unique où le prévu et le réalisé se
rencontrent, ce qui correspond enfin à ce que `CLAUDE.md` dit déjà de la page
`/schedule/{id}` : *« la seule page qui porte la boucle prévu vs réalisé »*.

```
Workout ───────► ScheduledWorkout ◄─────── LoggedExercise
(le prescrit,    (date, statut, le prévu    (le réalisé,
 jamais touché)   ET le réalisé)             série par série)
```

**La séparation qui compte est préservée** : le prescrit vit dans
`PrescribedExercise` / `PrescribedSet`, le réalisé dans `LoggedExercise` /
`LoggedSet`. Ils ne se mélangent jamais. `ScheduledWorkout` n'est que le
contenant qui tient les deux bouts.

Une **séance vierge**, c'est simplement une séance datée **sans séance source**
(`workout = null`, colonne déjà nullable aujourd'hui).

### 2.2 Le schéma

```
ScheduledWorkout                        (MODIFIÉE)
  … champs existants inchangés …
+ uuid              UUID, unique, généré par le CLIENT quand il crée
+ title             string nullable     <- snapshot / titre d'une séance libre
+ startedAt         datetime nullable
+ endedAt           datetime nullable
  workout           ManyToOne Workout, ON DELETE **SET NULL**   <- CHANGÉ
  status            ScheduledStatus     <- réutilisé, pas de cycle parallèle
  completionNotes   text nullable       <- réutilisé, pas de `notes` en double

LoggedExercise                          (NOUVELLE)
  id
  scheduledWorkout  ON DELETE CASCADE
  exercise          Exercise nullable, ON DELETE SET NULL
  exerciseName      string, NOT NULL          <- snapshot
  sourcePrescribedExercise  nullable, ON DELETE SET NULL
  position          int
  skipped           bool, default false
  notes             text nullable

LoggedSet                               (NOUVELLE)
  id
  uuid              UUID, unique, généré par le CLIENT
  loggedExercise    ON DELETE CASCADE
  position          int
  setType           SetType, default NORMAL
  reps              int nullable
  weightKg          float nullable
  durationSeconds   int nullable
  rpe               int nullable
  completedAt       datetime nullable
```

### 2.3 Les cinq points non négociables

1. **`ScheduledWorkout.workout` passe de `CASCADE` à `SET NULL`.** C'est la
   conséquence la plus importante de la fusion. Le commentaire actuel du code
   dit *« La séance datée n'a pas de sens sans sa séance source »* : ça devient
   **faux** le jour où elle porte le réalisé. En l'état, supprimer une séance de
   la bibliothèque effacerait une séance réellement faite, ce qui contredit
   frontalement la décision « préserver le réalisé ». Le snapshot `title` prend
   le relais pour l'affichage.
2. **`uuid` sur `ScheduledWorkout` et `LoggedSet`, unique, généré côté client**
   quand c'est le client qui crée. Sans ça, pas d'idempotence, donc pas
   d'offline-first. Les séances datées créées par le web reçoivent le leur au
   `prePersist`.
3. **`exerciseName` est un snapshot dupliqué volontairement.** Le réalisé doit
   rester lisible après suppression de l'exercice en bibliothèque. Même logique
   que le `SET NULL` sur `sourcePlanItem`.
4. **`sourcePrescribedExercise` en `SET NULL`** : éditer la séance prescrite
   après coup ne doit jamais casser un réalisé déjà écrit.
5. **Une séance datée porte au plus une exécution.** Si une séance est réellement
   faite deux fois dans la journée, ce sont deux séances datées. Pas de
   collection d'exécutions, pas de reprise après clôture.

Clôturer une séance la passe en `ScheduledStatus::DONE`. L'écart prévu vs
réalisé devient calculable série par série, ce qui n'existait pas.

---

## 3. Tableau de bord des tickets

Sept lots, 51 tickets. Taille indicative : S = moins d'une soirée, M = une
soirée ou deux, L = un week-end.

| # | Ticket | Lot | Taille | Dépend de |
|---|---|---|---|---|
| KL-01 | Acter la révision de la règle §1.5 | 0 | S | — |
| KL-02 | Entités du réalisé + migration de `ScheduledWorkout` | 1 | M | KL-01 |
| KL-03 | `LogMetrics` | 1 | M | KL-02 |
| KL-04 | `PerformanceHistory` (dernière perf + record) | 1 | M | KL-02 |
| KL-05 | `LogComparator` (écart prévu vs réalisé) | 1 | M | KL-02, KL-03 |
| KL-06 | Garde d'écriture sur `ScheduledWorkoutVoter` | 1 | S | KL-02 |
| KL-07 | Affichage du réalisé sur `/schedule/{id}` | 1 | L | KL-05, KL-06 |
| KL-08 | Séance datée sans source au calendrier | 1 | S | KL-07 |
| KL-09 | Tests du lot 1 | 1 | M | KL-08 |
| KL-10 | Entité `ApiToken` + authenticator + firewall | 2 | M | KL-01 |
| KL-11 | Endpoints d'authentification (mot de passe, repli) | 2 | S | KL-10 |
| KL-46 | Appairage : entité `PairingCode` + endpoints | 2 | M | KL-10 |
| KL-47 | Page QR d'appairage sur le desktop | 2 | M | KL-46 |
| KL-12 | Gestion des appareils dans `/profile/settings` | 2 | M | KL-10 |
| KL-13 | Réponses d'erreur normalisées + limitation de débit | 2 | M | KL-10 |
| KL-14 | `GET /api/bootstrap` | 2 | L | KL-11, KL-04 |
| KL-15 | `GET /api/schedule/{uuid}` | 2 | M | KL-11 |
| KL-16 | `PUT /api/schedule/{uuid}` idempotent | 2 | L | KL-11, KL-02 |
| KL-17 | `GET /api/exercises/{id}/history` | 2 | S | KL-11, KL-04 |
| KL-18 | Tests fonctionnels de l'API | 2 | L | KL-17 |
| KL-19 | `docs/api-mobile.md` | 2 | M | KL-18 |
| KL-20 | Export des tokens de design | 2 | S | KL-01 |
| KL-21 | Init du dépôt `kadens-mobile` | 3 | M | — |
| KL-22 | Socle de design natif | 3 | L | KL-21, KL-20 |
| KL-23 | Composants de base | 3 | L | KL-22 |
| KL-24 | Couche SQLite + Drizzle | 3 | L | KL-21 |
| KL-25 | Client API + stockage sécurisé du token | 3 | M | KL-21, KL-11 |
| KL-26 | Écran de connexion | 3 | M | KL-25, KL-23 |
| KL-48 | Écran de scan du QR d'appairage | 3 | M | KL-26, KL-46 |
| KL-27 | Moteur de synchronisation | 3 | L | KL-24, KL-25, KL-14, KL-16 |
| KL-28 | Écran Aujourd'hui | 4 | M | KL-27, KL-23 |
| KL-29 | Écran Séance en cours (lecture + cochage) | 4 | L | KL-28, KL-15 |
| KL-30 | Déviations en séance | 4 | L | KL-29 |
| KL-31 | Timer de repos, veille écran, notification | 4 | M | KL-29 |
| KL-32 | Historique en séance | 4 | M | KL-29, KL-17 |
| KL-33 | Clôture de séance | 4 | M | KL-30 |
| KL-34 | Séance vierge | 4 | L | KL-33 |
| KL-35 | Écran Réglages | 4 | S | KL-27 |
| KL-36 | Tests mobile | 4 | L | KL-34 |
| KL-37 | Passe design complète | 5 | L | KL-35 |
| KL-38 | États vides, erreurs, bandeau hors ligne | 5 | M | KL-37 |
| KL-39 | Ergonomie de salle + accessibilité | 5 | M | KL-37 |
| KL-40 | Signature Android | 6 | M | KL-21 |
| KL-41 | Workflow de build APK | 6 | L | KL-40, KL-36 |
| KL-42 | Dépôt F-Droid auto-hébergé + publication | 6 | L | KL-41 |
| KL-43 | Page d'installation + contrôle de version in-app | 6 | M | KL-42 |
| KL-44 | Recette finale et documentation | 6 | M | KL-43, KL-39 |
| KL-49 | Réalisé superposé à la progression du plan | 7 | L | KL-05, KL-07 |
| KL-50 | Trajectoire d'un exercice sur `/exercise/{id}` | 7 | M | KL-04 |
| KL-51 | Tri de la bibliothèque par usage réel | 7 | S | KL-02 |
| KL-45 | Lecture du réalisé par le coach | 7 | M | KL-07 |

Deux tickets peuvent démarrer sans attendre : **KL-21** (init mobile) est
indépendant du serveur, et **KL-40** (keystore) n'attend que lui. Le reste suit
l'ordre.

**Jalon de valeur** : à la fin du **lot 1**, la feature a déjà de la valeur sans
une ligne de React Native, parce que le réalisé s'affiche sur le web. C'est le
filet de sécurité si le chantier mobile s'enlise.

---

## Lot 0 — Décision

### KL-01 — Acter la révision de la règle du tracking

**Où** : `ROADMAP.md`, `CLAUDE.md`, `docs/feature-progression.md`

**Quoi** : inscrire la nouvelle formulation de §0.2 dans les fichiers qui
portent la règle actuelle, avant toute ligne de code. Une session de dev qui
lirait `CLAUDE.md` sans cette mise à jour appliquerait une règle abrogée.

**Fini quand** :
- [x] `ROADMAP.md §1.5` reformulé (« pas de tracking **cardio** »), et la
      Phase 7 point 4 amendée avec un renvoi vers ce document
- [x] `CLAUDE.md §3` : nouvelle puce sur le modèle du réalisé, le principe
      « le prescrit ne bouge jamais », et le passage de
      `ScheduledWorkout.workout` en `SET NULL` (la puce actuelle justifie le
      `CASCADE`, elle devient fausse)
- [x] `docs/feature-progression.md §3` : le lot B n'est plus « décision requise »,
      il pointe vers `LoggedSet` comme source du réalisé
- [x] Une entrée dans `docs/journal-de-bord.md` ouvrant le chantier

**Livré le 29/07/2026.** Deux ajouts hors liste, jugés dans l'esprit du ticket : le
résumé de tête de `ROADMAP.md` portait aussi l'ancienne règle, et `ROADMAP.md §2.3`
(modèle de données de `ScheduledWorkout`) annonce désormais les nouveaux champs et
le `SET NULL` — c'est la section que KL-02 viendra lire.

---

## Lot 1 — Le modèle du réalisé (Symfony)

### KL-02 — Entités, migration de `ScheduledWorkout`, repositories

**Où** : `src/Entity/`, `src/Repository/`, `migrations/`

**Quoi** : les deux nouvelles entités de §2.2 et l'extension de
`ScheduledWorkout`. C'est le ticket qui porte le changement de clé étrangère,
donc **le plus sensible du lot**.

**Fini quand** :
- [x] `LoggedExercise` et `LoggedSet` conformes à §2.2
- [x] `ScheduledWorkout` gagne `uuid`, `title`, `startedAt`, `endedAt` et la
      collection `loggedExercises`
- [x] **`ScheduledWorkout.workout` passe en `ON DELETE SET NULL`**, et le
      commentaire du code qui justifiait le `CASCADE` est réécrit (§2.3 point 1)
- [x] Migration en **deux temps** : d'abord peupler `uuid` et `title` sur les
      lignes existantes (le titre recopié depuis `workout`), ensuite poser la
      contrainte d'unicité. Une contrainte posée avant le remplissage échouerait
      sur toutes les lignes déjà en base
- [x] Tout endroit qui affiche une séance datée gère `workout === null` et
      retombe sur `title`. Chercher les usages, il y en a dans le calendrier, la
      page `/schedule/{id}`, l'export et le flux ICS
- [x] Index unique sur `ScheduledWorkout.uuid` et `LoggedSet.uuid`
- [x] Index `(exercise_id)` sur `LoggedExercise` (c'est la requête d'historique)
- [x] `ScheduledWorkoutRepository::findByUuid()`
- [~] Migration jouée et rejouée à blanc sur MariaDB 10.4, **sur une copie de la
      base de prod** et pas seulement sur une base vide — jouée, annulée puis
      rejouée sur la base de **dev peuplée** (44 séances datées réelles, uuid
      tous distincts, aucun titre nul), et la chaîne complète des 17 migrations
      rejouée sur une base vierge. La copie de prod reste à faire au moment du
      déploiement : elle demande un accès qu'une session de dev n'a pas.

**Piège** : `uuid` en `binary(16)` ou en `char(36)` ? Prendre `char(36)` avec le
type Doctrine `uuid` de `symfony/uid`. Le gain de place du binaire ne compense
pas l'illisibilité en debug sur un projet de cette taille.

**Livré le 29/07/2026.** Trois points décidés en cours de route, à ne pas
redécouvrir :

1. **Le type `uuid` de `symfony/uid` ne donne PAS `char(36)` sur MariaDB.** Sa
   détection de « type GUID natif » compare `getGuidTypeDeclarationSQL()` au
   `CHAR(36)` d'une chaîne fixe : sur MySQL/MariaDB les deux sont identiques, la
   détection échoue et le type retombe sur `BINARY(16)`. Tenir le choix du ticket
   demandait donc un type maison, `App\Doctrine\UuidCharType`, enregistré **sous
   le nom `uuid`** dans `config/packages/doctrine.yaml`. Les entités écrivent
   `type: 'uuid'` comme d'habitude et la valeur PHP reste un
   `Symfony\Component\Uid\Uuid` : seule la colonne change.
2. **Les repos devaient passer en `leftJoin`.** `ScheduledWorkoutRepository`
   faisait cinq `join('s.workout')` **internes** : une séance sans source aurait
   simplement **disparu** du calendrier, de l'ICS et du profil au lieu de s'y
   afficher. Un bug silencieux, pas une erreur — c'est le piège du `SET NULL`.
3. **Le snapshot `title` se pose au `prePersist`**, pas à l'appel. Aucun appelant
   (pose au calendrier, `PlanScheduler`, tests) n'a eu à changer, et l'affichage
   passe partout par `ScheduledWorkout::getDisplayTitle()` : titre vivant tant
   qu'il existe, snapshot ensuite, « Séance libre » en dernier recours.

Deux ajouts hors liste, jugés dans l'esprit du ticket :
`tests/Controller/ScheduledWorkoutSourcelessTest.php` (8 tests, dont **la
non-régression qui garde le `SET NULL`** — supprimer une séance de bibliothèque
laisse debout ses séances datées ; KL-09 l'étendra au réalisé), et le rendu de
`/schedule/{id}` sans source, réduit au hero + date + statut, dont KL-07 fera la
vraie page.

**Nettoyage préalable, dev seulement** : la base de dev portait encore la table
`logged_set` de la branche abandonnée `feature/live-session-tracking` (migration
`Version20260727180000`, « migrated, not available » — son fichier n'est sur
aucune branche livrée). Table sauvegardée puis supprimée, ligne retirée de
`doctrine_migration_versions`. **La prod n'est pas concernée** : cette migration
n'y a jamais été déployée.

### KL-03 — Service `LogMetrics`

**Où** : `src/Service/LogMetrics.php`

**Quoi** : le pendant réalisé de `WorkoutMetrics`. Tonnage effectif, nombre de
séries de travail (échauffement exclu, comme partout), durée réelle
(`endedAt - startedAt`), répartition par `TargetRegion`.

**Fini quand** :
- [x] `summary(ScheduledWorkout): array` avec la même forme que
      `WorkoutMetrics::summary()` (pour que les composants Twig de KPI se
      réutilisent tels quels)
- [x] Renvoie `null` si la séance datée n'a aucun `LoggedExercise`
- [x] `SetType::WARMUP` exclu du volume de travail
- [x] Aucune duplication de `WorkoutMetrics` : ce qui est commun se factorise

**Livré le 30/07/2026.** Quatre décisions prises en cours de route :

1. **Ce qui est commun, c'est la ventilation par région, et elle seule.** Le
   `regionShares()` privé de `WorkoutMetrics` est devenu le service
   `RegionBreakdown::shares()`, consommé par les deux : le prescrit et le réalisé
   comptent leurs séries par zone différemment, mais les regroupent et en tirent
   des parts à l'identique. Le reste ne se factorise pas — le RPE du prescrit est
   porté par l'**exercice** et doit être pondéré à la main par ses séries, celui
   du réalisé est porté par la **série**, donc déjà pondéré. Fusionner les deux
   boucles aurait produit un service paramétré plus long que les deux réunis.
2. **La forme est identique, deux clés valent 0.** Le réalisé est **plat** : il
   ne porte ni blocs (`blockCount`) ni liaisons de superset (`supersets`,
   `circuits`), qui n'appartiennent qu'à l'intention. Elles restent présentes
   pour que le bandeau de KPI se rende tel quel (KL-07), à charge pour la vue de
   ne pas afficher un « 0 enchaînement » qui ne veut rien dire.
3. **Le volume du réalisé ne filtre pas sur `ActivityType::GYM`**, contrairement
   au prescrit. Un `LoggedExercise` dont l'`Exercise` a été supprimé (SET NULL)
   n'a plus d'activité du tout : l'écarter ferait disparaître le tonnage d'une
   séance réellement faite, exactement ce que le snapshot `exerciseName` est là
   pour empêcher. Seule la ventilation par région dépend encore de la définition
   en bibliothèque, faute de zones ciblées ailleurs.
4. **Trois clés en plus, propres au fait accompli** : `durationSeconds` (null
   tant qu'une borne manque — une durée « jusqu'à maintenant » bougerait à chaque
   rafraîchissement, et une fin antérieure au début est ramenée à 0), `skipped`
   (les exercices sautés sont comptés à part et n'apportent aucun volume, même
   s'ils portent des séries abandonnées) et `loggedAt` (fin d'exécution, sinon
   dernière série complétée : le réalisé peut être synchronisé sans bornes).

Livré avec `tests/Service/LogMetricsTest.php` (11 tests), qui coche la première
case de KL-09.

### KL-04 — Service `PerformanceHistory`

**Où** : `src/Service/PerformanceHistory.php`

**Quoi** : pour un `Exercise` et un `User`, retrouver la dernière performance et
le record. C'est le service qui donne sa valeur à l'app en séance.

**Fini quand** :
- [x] `lastPerformance(User, Exercise): ?array` (date, séries de travail
      condensées à la manière de `detailedSetGroups`)
- [x] `bestSet(User, Exercise): ?array` (charge max sur une série de travail)
- [x] `bulkFor(User, Exercise[]): array` indexé par id d'exercice, **en deux
      requêtes maximum** : le bootstrap l'appelle sur toute la bibliothèque, un
      N+1 le rendrait inutilisable
- [x] L'échauffement n'entre jamais dans un record

**Livré le 30/07/2026.** Quatre décisions prises en cours de route :

1. **Les deux bornes se lisent par sous-requête corrélée, pas en PHP.** Les deux
   lectures (`findLastWorkingSetsForExercises`, `findBestWorkingSetsForExercises`
   sur `LoggedSetRepository`) partagent un socle de filtres et se bornent chacune
   par une sous-requête corrélée sur `le.exercise` — `MAX(scheduledDate)` pour la
   dernière séance, `MAX(weightKg)` pour le record. Remonter l'historique pour le
   trier en mémoire aurait tenu aussi, mais l'historique d'un exercice grossit
   sans limite là où sa dernière séance, non. Le `FROM ... WHERE` corrélé est
   écrit **une seule fois** (`correlatedFrom()`) : les deux bornes ne peuvent pas
   diverger de périmètre. Projection scalaire, aucune entité hydratée, et un test
   compte les requêtes (`doctrine.debug_data_holder`) — la contrainte des deux
   requêtes est gardée, pas seulement écrite.
2. **Même périmètre que `LogMetrics`, à la ligne près** : échauffement exclu,
   exercice `skipped` exclu, et **aucun filtre sur le statut** de la séance
   datée. Le réalisé est un fait dès qu'il est écrit : une séance encore
   `PLANNED` en cours de synchro compte déjà. Corollaire assumé : les rangs
   `firstIndex`/`lastIndex` du condensé sont ceux des séries **de travail**,
   l'échauffement n'étant jamais remonté il ne peut pas décaler la numérotation
   d'une lecture à l'autre.
3. **Le record se départage aux répétitions, puis à la date.** La requête ramène
   toutes les séries portant la charge maximale ; à charge égale, 6 × 60 kg vaut
   mieux que 3 × 60 kg, et à égalité parfaite c'est la plus récente qui reste.
   Une série sans charge (poids du corps, gainage) ne produit **aucun** record —
   mais elle a bien une dernière performance, lue en durée : le réalisé n'a pas
   de `PrescriptionType` pour trancher entre reps et durée, il porte ses valeurs
   et on lit celle qui est renseignée.
4. **Un exercice sans historique est absent de `bulkFor`**, pas présent à null.
   « Rien à dire » n'est pas un zéro (même logique que `LogMetrics::summary()`
   qui rend `null`), et transporter des entrées vides jusqu'au téléphone n'aurait
   ajouté que du volume au bootstrap. `lastPerformance` et `bestSet` n'appellent
   chacun **qu'une** des deux requêtes : l'unitaire ne paie pas le prix du bulk.

Livré avec `tests/Service/PerformanceHistoryTest.php` (12 tests, en
`KernelTestCase` : les règles vivent dans le SQL, un double en mémoire n'en
garderait aucune). L'isolation par propriétaire y est testée explicitement — un
exercice de la bibliothèque globale est pratiqué par tout le monde, son
historique n'appartient qu'à celui qui l'a fait (KL-50 en dépend).

### KL-05 — Service `LogComparator`

**Où** : `src/Service/LogComparator.php`

**Quoi** : aligner le prescrit (via `PlanFlattener`) et le réalisé, série par
série, pour produire l'écart affichable.

**Fini quand** :
- [x] `compare(ScheduledWorkout): array` avec, par exercice : prescrit, réalisé,
      état (`tenu`, `dépassé`, `allégé`, `sauté`, `hors programme`)
- [x] L'appariement se fait sur `sourcePrescribedExercise` quand il est présent,
      sur l'`Exercise` sinon, et tombe en « hors programme » en dernier recours
- [x] Consomme `PlanFlattener`, ne remet pas à plat lui-même

**Livré le 30/07/2026.** Cinq décisions prises en cours de route :

1. **Six états, pas cinq** (`App\Enum\LogDeviation`). Le modèle distingue déjà
   l'exercice **volontairement sauté** (`LoggedExercise.skipped`, une
   déclaration) de l'exercice **jamais logué** (un trou) : les confondre ferait
   dire à l'app que l'athlète a déclaré quelque chose qu'il n'a pas déclaré.
   D'où `NOT_LOGGED` en plus des cinq du ticket. `HELD` est aussi la valeur
   « rien à signaler » quand l'écart n'est **pas mesurable** (prescrit sans
   séries à apparier : cardio, AMRAP, for time) — on ne prétend jamais mesurer
   ce qu'on ne sait pas comparer.
2. **L'appariement des exercices se fait en deux passes, pas en une.**
   `sourcePrescribedExercise` d'abord pour **tous** les logs, l'`Exercise`
   ensuite pour ce qui reste. Séparer les passes n'est pas cosmétique : sinon un
   log apparié par son exercice vole la ligne qu'un autre revendique par sa
   source, et l'ordre de la collection décide du résultat (testé sur une séance
   à deux lignes du même exercice). L'appariement se fait par **identité
   d'objet**, avec l'identifiant en repli — Doctrine ne rend qu'une instance par
   entité, proxy compris.
3. **Les séries s'apparient par rang, échauffement et travail dans deux files
   séparées.** C'est la décision qui évite le faux positif le plus visible : un
   échauffement prescrit mais non logué (le cas courant) décalerait toutes les
   séries de travail d'un cran, et une séance tenue se lirait « allégée » de bout
   en bout. La ligne d'échauffement reste affichée, simplement « non réalisée ».
4. **L'écart se lit sur le premier axe où les deux côtés parlent et divergent** :
   tonnage, charge, répétitions, durée, nombre de séries. Un axe muet d'un côté
   ne tranche **jamais** — comparer une charge à une absence de charge dirait
   « allégé » d'une série au poids du corps. Le tonnage passe en premier parce
   que c'est la grandeur du projet : 6 × 82,5 kg là où 8 × 80 kg étaient prévus,
   c'est plus lourd mais moins de travail, donc allégé. La même cascade sert aux
   deux échelles — une série contre une série, puis les totaux de l'exercice
   (efforts sommés, charge prise au plus lourd, échauffement exclu comme partout).
5. **`PlanFlattener` gagne deux clés, il n'est pas contourné.** `FlatSetLine`
   expose désormais `reps` et `durationSeconds` bruts à côté de son `effort`
   formaté : sans eux le comparateur aurait dû re-dériver les séries prescrites,
   c'est-à-dire dupliquer `setLines`, exactement ce que le ticket interdit. Le
   réalisé sort sous la **même forme** qu'une série prescrite (`type`,
   `typeLabel`, `effort`, `weightKg`, + `rpe`), pour que la colonne « Réalisé »
   de KL-07 se rende avec le fragment de la colonne « Prévu ».

Livré avec `tests/Service/LogComparatorTest.php` (16 tests), qui coche la
troisième case de KL-09.

### KL-06 — Garde d'écriture sur `ScheduledWorkoutVoter`

**Où** : `src/Security/Voter/ScheduledWorkoutVoter.php`

**Quoi** : la fusion (§2.1) rend inutile un voter dédié au réalisé. La lecture
par le coach est déjà accordée par `ScheduledWorkoutVoter::VIEW`. **Mais** ce
voter accorde aussi `EDIT` au coach accepté, et `EDIT` ne doit pas devenir un
droit d'écrire le réalisé de son athlète.

**Fini quand** :
- [x] Nouvel attribut `LOG` : accordé **au seul propriétaire**, jamais au coach
- [x] `EDIT` conserve son sens actuel (déplacer, marquer fait, retirer) et reste
      ouvert au coach
- [x] Tout point d'écriture du réalisé, web comme API, teste `LOG` et non `EDIT`
      (aucun n'existe encore : la garde précède ses appelants, KL-07 et KL-16)
- [x] Le commentaire du voter explique la distinction, sinon elle se perdra

**Livré le 30/07/2026.** Ce que le voter dit désormais, en une phrase : `EDIT`
c'est **programmer** (dates, statut, retrait — le travail du coach), `LOG` c'est
**consigner ce qui a été fait** (le propriétaire seul). Sur `LOG`, la branche
coach s'arrête avant même d'interroger `CoachingResolver` : un test le vérifie
avec `expects(never())`, pour qu'aucun « et si le coach était aussi… » ne se
glisse plus tard dans la branche partagée. Livré avec
`tests/Security/ScheduledWorkoutVoterTest.php` (6 tests), qui coche la quatrième
case de KL-09.

### KL-07 — Affichage du réalisé sur `/schedule/{id}`

**Où** : `templates/schedule/`, `templates/components/`, `src/Controller/ScheduledWorkoutController.php`

**Quoi** : `/schedule/{id}` est déjà « la seule page qui porte la boucle prévu vs
réalisé ». Elle affiche maintenant le réalisé quand il existe. Depuis la fusion
(§2.1), l'entité de la page correspond enfin à sa fonction.

**Fini quand** :
- [ ] **Comparaison en place, pas d'onglet dédié** (§0.7) : `_workout_sets_table`
      gagne une colonne « Réalisé » quand `LogComparator` a quelque chose à dire.
      Le composant se paramètre, il ne se duplique pas
- [ ] Le prescrit passe en encre atténuée dès qu'un réalisé existe, **sans
      disparaître**
- [ ] **L'onglet par défaut dépend du statut** : `PLANNED` ouvre sur le
      programme, `DONE` sur le réalisé
- [ ] Une séance `MISSED` porte une marque explicite, sinon elle se confond avec
      une séance à venir
- [ ] La page se rend correctement pour une séance datée **sans `workout`**
      (séance libre) : pas de colonne « Prévu », seulement le réalisé et le
      `title`
- [ ] L'écart se lit à l'encre ; **le rouge ne sort que sur un exercice sauté**,
      conformément à la règle 2 du design system
- [ ] Bandeau de KPI du réalisé (`LogMetrics`) réutilisant le composant existant
- [ ] Suppression du réalisé possible depuis cette page (avec confirmation), sans
      supprimer la séance datée elle-même
- [ ] Le réalisé **n'entre jamais** dans `PlanFlattener`, donc jamais dans
      l'export Excel, le flux ICS ni la page publique. Vérifier explicitement.
- [ ] Aucun AJAX post-chargement (règle des pages auto-suffisantes)

### KL-08 — Séance datée sans source au calendrier

**Où** : `src/Controller/CalendarController.php`, `templates/calendar/`

**Quoi** : une séance vierge est une séance datée avec `workout = null`. Le
calendrier la requête donc **déjà** : il ne reste qu'à l'afficher correctement.
C'est tout ce qui reste de ce ticket depuis la fusion (§2.1), et il n'y a ni
requête supplémentaire ni risque de N+1 à traiter.

**Fini quand** :
- [ ] La pastille retombe sur `title` quand `workout` est null, sans planter
- [ ] Marque visuelle « hors plan », codée par le rang dans l'échelle de gris,
      jamais par une teinte inventée
- [ ] Le clic mène à `/schedule/{id}`, comme toutes les autres pastilles

### KL-09 — Tests du lot 1

**Où** : `tests/`

**Fini quand** :
- [x] `LogMetricsTest` : tonnage, exclusion de l'échauffement, séance sans réalisé
      (livré avec KL-03)
- [x] `PerformanceHistoryTest` : record, dernière perf, absence d'historique,
      et **un test qui compte les requêtes de `bulkFor`** (livré avec KL-04)
- [x] `LogComparatorTest` : tenu, dépassé, allégé, sauté, hors programme,
      exercice prescrit supprimé après coup (livré avec KL-05)
- [x] `ScheduledWorkoutVoterTest` : le coach a `VIEW` et `EDIT`, **jamais `LOG`**
      (livré avec KL-06 — le fichier n'existait pas, il est créé)
- [ ] **Un test de non-régression sur la suppression** : supprimer un `Workout`
      de la bibliothèque laisse debout ses séances datées et leur réalisé.
      C'est le test qui garde le `SET NULL` de §2.3 point 1
- [ ] Un test sur une séance datée sans `workout` : elle se rend, s'affiche au
      calendrier et n'entre pas dans l'export
- [ ] Un test qui **échoue** si le réalisé fuite dans `PlanFlattener`

---

## Lot 2 — L'API (Symfony)

### KL-10 — `ApiToken`, authenticator, firewall

**Où** : `src/Entity/ApiToken.php`, `src/Security/ApiTokenAuthenticator.php`, `config/packages/security.yaml`

**Quoi** : un firewall `api` **stateless** sur `^/api`, distinct de `main`.

**Fini quand** :
- [ ] Entité `ApiToken` : `owner`, `tokenHash` (hash SHA-256, **jamais le token
      en clair**), `deviceName`, `createdAt`, `lastUsedAt`, `expiresAt`
- [ ] Authenticator custom lisant `Authorization: Bearer <token>`
- [ ] Firewall `api` avec `stateless: true`, **placé avant `main`** dans
      `security.yaml` (l'ordre des firewalls décide, le premier motif qui
      correspond gagne)
- [ ] `access_control` : `^/api/auth` public, tout le reste `ROLE_USER`
- [ ] Expiration glissante : `lastUsedAt` rafraîchi, `expiresAt` repoussé de
      90 jours à chaque usage
- [ ] Aucune session créée sur `^/api` (vérifié par un test sur l'absence de
      `Set-Cookie`)

**Piège** : le firewall `main` a `lazy: true` et un `remember_me` à dix ans. Si
`^/api` tombait dedans, une requête mobile serait authentifiée par cookie et le
token deviendrait décoratif. L'ordre dans `security.yaml` n'est pas cosmétique.

### KL-11 — Endpoints d'authentification (mot de passe, repli)

**Où** : `src/Controller/Api/AuthController.php`

**Quoi** : le chemin nominal est l'appairage par QR (KL-46). Le mot de passe
reste comme repli, et parce que les tests fonctionnels de l'API en ont besoin.

**Fini quand** :
- [ ] `POST /api/auth/login` : `{email, password, deviceName}` → `{token, user}`.
      Le token en clair n'est renvoyé **qu'ici et à l'appairage**, une seule fois
- [ ] `POST /api/auth/logout` : révoque le token courant
- [ ] `GET /api/me` : identité, rôles, date du dernier bootstrap
- [ ] Pas de parcours d'inscription (les comptes se créent en console, règle
      verrouillée). Le mot de passe oublié reste hors périmètre
- [ ] Réponse 401 uniforme, sans distinguer « email inconnu » de « mot de passe
      faux »

### KL-46 — Appairage : entité `PairingCode` et endpoints

**Où** : `src/Entity/PairingCode.php`, `src/Controller/Api/AuthController.php`, `src/Controller/ProfileController.php`

**Quoi** : le mécanisme décrit en §0.6. Un utilisateur authentifié sur le web
émet un code à usage unique ; le téléphone l'échange contre un `ApiToken`.

**Fini quand** :
- [ ] Entité `PairingCode` : `owner`, `codeHash`, `createdAt`, `expiresAt`
      (2 minutes), `usedAt` nullable, `consumedByDevice` nullable
- [ ] Le code fait 8 caractères en alphabet **sans ambiguïté** (ni `O`/`0`, ni
      `I`/`1`/`l`), pour rester saisissable à la main en repli
- [ ] `POST /pairing/code` (firewall `main`, utilisateur authentifié) émet un
      code et renvoie la charge utile du QR :
      `{"url": "<base API>", "code": "<code>", "exp": "<ISO8601>"}`
- [ ] **Le QR ne contient jamais de token**, seulement ce code (§0.6 règle 1)
- [ ] `POST /api/auth/pair` : `{code, deviceName}` → `{token, user}`
- [ ] **Consommation atomique** : `UPDATE pairing_code SET used_at = NOW()
      WHERE id = ? AND used_at IS NULL`, puis vérification des lignes affectées.
      Une lecture suivie d'une écriture laisserait passer deux scans simultanés
- [ ] Un code expiré, déjà utilisé ou inconnu renvoie la **même** erreur 400
- [ ] Limiteur de débit sur `POST /api/auth/pair` (10 essais par IP et par
      minute), sinon les 8 caractères se cassent par force brute
- [ ] Purge des codes expirés par une commande console, appelable en cron
- [ ] Le code est lié à son émetteur : le token créé appartient à l'utilisateur
      de la session desktop, jamais à un autre

### KL-47 — Page QR d'appairage sur le desktop

**Où** : `templates/profile/`, `src/Controller/ProfileController.php`

**Fini quand** :
- [ ] Une section « Connecter un téléphone » dans `/profile/settings`
- [ ] Le QR est généré **côté serveur** (`endroid/qr-code`, rendu SVG inline) :
      pas de dépendance JavaScript à faire passer par l'importmap, et ça marche
      sans JS
- [ ] Le code de 8 caractères est affiché **en toutes lettres sous le QR**, en
      IBM Plex Mono, comme repli si la caméra refuse
- [ ] Compte à rebours visible et **régénération en un clic** à l'expiration
- [ ] L'appareil appairé apparaît dans la liste de KL-12, avec confirmation
      visuelle sur le desktop
- [ ] Rendu à l'identité Presse, cohérent avec le reste de la page

### KL-12 — Gestion des appareils dans `/profile/settings`

**Où** : `src/Controller/ProfileController.php`, `templates/profile/`

**Quoi** : un token qu'on ne peut pas révoquer depuis l'app web est un trou.

**Fini quand** :
- [ ] Liste des appareils connectés (nom, dernière utilisation, expiration)
- [ ] Bouton de révocation par appareil, et « tout révoquer »
- [ ] Rendu dans l'identité Presse, cohérent avec le reste de la page

### KL-13 — Erreurs normalisées et limitation de débit

**Où** : `src/EventListener/ApiExceptionListener.php`, `config/packages/rate_limiter.yaml`

**Fini quand** :
- [ ] Toute exception sur `^/api` sort en `application/problem+json`
      (RFC 9457 : `type`, `title`, `status`, `detail`)
- [ ] Les erreurs de validation listent les champs fautifs
- [ ] Aucune trace de pile en prod
- [ ] Limiteur sur `POST /api/auth/login` (5 tentatives par IP et par minute)
- [ ] Le listener **ne capte pas** les routes hors `^/api` (les pages d'erreur
      Twig existantes doivent continuer de sortir)

### KL-14 — `GET /api/bootstrap`

**Où** : `src/Controller/Api/BootstrapController.php`, `src/Dto/`

**Quoi** : l'hydratation complète de la base locale en **une** requête. C'est
l'endpoint le plus important du lot.

**Fini quand** :
- [ ] `?since=<ISO8601>` renvoie le delta ; sans paramètre, le jeu complet
- [ ] Contenu : exercices visibles (perso + globale + biblio du coach en
      lecture), séances datées de J-30 à J+14 avec leur prescrit à plat **et leur
      réalisé**, dernières perfs et records par exercice
- [ ] Le delta sur les exercices se calcule sur `COALESCE(updatedAt, createdAt)` :
      `updatedAt` reste **null** tant qu'un exercice n'a jamais été modifié, un
      filtre naïf sur `updatedAt` les ferait tous disparaître du delta
- [ ] Le prescrit vient de `PlanFlattener`, y compris `setLines`
- [ ] Une liste des identifiants supprimés depuis `since` (sinon la base locale
      accumule des fantômes). Prévoir une table `deleted_entity` ou un
      `deletedAt` sur les entités concernées, à trancher dans le ticket
- [ ] Le bloc-notes privé (`Workout.notes`) **n'est pas** dans la charge utile
- [ ] Réponse mesurée sur un jeu réaliste : moins de 500 ms et moins de 1 Mo

### KL-15 — `GET /api/schedule/{uuid}`

**Fini quand** :
- [ ] Le prescrit à plat d'une séance datée, via `PlanFlattener`, plus son
      réalisé s'il existe
- [ ] Résolution par `uuid`, pas par `id` (le client ne connaît que l'uuid pour
      ce qu'il a créé lui-même)
- [ ] Une séance datée sans `workout` renvoie un prescrit vide, pas une erreur
- [ ] `ScheduledWorkoutVoter::VIEW` appliqué
- [ ] Structure identique à celle du bootstrap (le client n'a qu'un seul
      désérialiseur à écrire)

### KL-16 — `PUT /api/schedule/{uuid}` idempotent

**Où** : `src/Controller/Api/ScheduleController.php`, `src/Service/LogIngestor.php`

**Quoi** : l'app envoie **la séance datée complète avec son réalisé** en un
document, pas série par série. Un seul endpoint couvre les deux cas : la séance
programmée qu'on remplit, et la séance libre que le téléphone crée de toutes
pièces.

**Fini quand** :
- [ ] `PUT /api/schedule/{uuid}` fait un **upsert** : la séance datée est créée
      si l'`uuid` est inconnu, mise à jour sinon
- [ ] **Idempotent** : un même document rejoué ne crée rien de nouveau et renvoie
      200 avec l'état persisté
- [ ] Un document déjà connu avec un contenu différent **écrase le réalisé** (le
      téléphone fait autorité, cf. §0.3 point 1). Il n'écrase **jamais** le
      prescrit, ni `sourcePlanItem`, ni `planAnchorDate`
- [ ] `DELETE /api/schedule/{uuid}` refuse une séance issue d'un plan (elle se
      retire depuis le web) et n'accepte que les séances libres
- [ ] Clôture → `ScheduledWorkout::setStatus(DONE)`
- [ ] `exerciseName` renseigné côté serveur si le client ne l'a pas envoyé
- [ ] Validation stricte : un poids négatif, 400 reps ou un `setType` inconnu
      sont refusés en 422
- [ ] Toute l'ingestion dans **une transaction**
- [ ] L'attribut `LOG` de KL-06 est testé, pas `EDIT` : un coach n'écrit jamais
      le réalisé de son athlète
- [ ] Une séance libre créée par le téléphone arrive avec `workout = null` et un
      `title`. Le serveur ne crée **aucun** `Workout` en bibliothèque

### KL-17 — `GET /api/exercises/{id}/history`

**Fini quand** :
- [ ] Dernière performance, record, et les 10 dernières séances sur cet exercice
- [ ] Consomme `PerformanceHistory`, ne requête pas en direct

### KL-18 — Tests fonctionnels de l'API

**Fini quand** :
- [ ] Un test par endpoint : cas nominal, non authentifié, token expiré, token
      révoqué, ressource d'un autre utilisateur
- [ ] **Un test d'idempotence** : le même document envoyé trois fois donne une
      seule séance datée et un seul jeu de séries
- [ ] Un test vérifiant qu'un `PUT` n'écrase jamais le prescrit ni le
      rattachement au plan de la séance datée visée
- [ ] **Un test d'appairage** : un code consommé deux fois échoue la seconde
      fois, un code expiré échoue, et un code émis par un utilisateur ne crée
      jamais un token pour un autre
- [ ] Un test vérifiant qu'aucune réponse d'API ne contient `notes` de `Workout`
- [ ] Un test vérifiant qu'aucune requête `^/api` ne pose de cookie de session

### KL-19 — `docs/api-mobile.md`

**Fini quand** :
- [ ] Chaque endpoint documenté : méthode, charge utile, réponse, codes d'erreur
- [ ] Le protocole de synchronisation décrit noir sur blanc (qui fait autorité
      sur quoi, comment les conflits sont tranchés)
- [ ] Le protocole d'appairage décrit de bout en bout, avec le format exact de
      la charge utile du QR
- [ ] Un exemple `curl` complet par endpoint, réellement exécuté

### KL-20 — Export des tokens de design

**Où** : `src/Command/ExportDesignTokensCommand.php`

**Quoi** : les tokens vivent dans `assets/styles/tokens.css`, que React Native ne
sait pas lire. Plutôt que de les recopier à la main dans le repo mobile et de
les laisser diverger, on les publie.

**Fini quand** :
- [ ] `php bin/console app:tokens:export` lit `tokens.css` et écrit
      `public/design-tokens.json` (primitives `--kd-*` et tokens sémantiques)
- [ ] La commande tourne dans le workflow de build, le fichier est servi sur
      `kadens.antoninpamart.fr/design-tokens.json`
- [ ] Un test qui échoue si un token sémantique du CSS n'est pas dans le JSON
- [ ] `tools/fetch-fonts.sh` produit aussi les `.ttf` de Barlow et Barlow
      Condensed (React Native ne lit pas le `woff2`)

---

## Lot 3 — Le socle mobile (`kadens-mobile`)

### KL-21 — Init du dépôt

**Fini quand** :
- [ ] Projet Expo TypeScript, `expo-router`, ESLint + Prettier
- [ ] `app.json` : nom « Kadens », identifiant `fr.antoninpamart.kadens`,
      orientation portrait, `userInterfaceStyle: light`
- [ ] Le dossier `android/` **n'est pas** versionné : le workflow le régénère par
      `expo prebuild`. Toute configuration native passe donc par un plugin
      déclaré dans `app.json`, jamais par une édition manuelle
- [ ] README : prérequis, lancement, et **le rappel de l'IP LAN** (l'app doit
      viser l'IP de la machine, pas `localhost`, et Symfony démarre avec
      `--listen-ip=0.0.0.0`)
- [ ] `.env` d'exemple avec l'URL de l'API

### KL-22 — Socle de design natif

**Fini quand** :
- [ ] `npm run sync:tokens` télécharge `design-tokens.json` et génère
      `src/theme/tokens.ts` typé. Le fichier généré est versionné mais **jamais
      édité à la main** (même règle que `assets/styles/fonts.css`)
- [ ] Polices Barlow, Barlow Condensed et IBM Plex Mono chargées par `expo-font`
- [ ] Échelle typographique, espacements, rayon 0, aucune ombre
- [ ] **Le condensé capitales ne touche pas au contenu saisi** : titres, boutons
      et onglets en Barlow Condensed capitales ; noms d'exercice et de séance en
      Barlow, casse normale. C'est la règle 4 du design system, elle s'applique
      telle quelle en natif
- [ ] Pas de thème sombre (l'identité Presse est papier et encre, un thème sombre
      serait une deuxième identité à maintenir)

### KL-23 — Composants de base

**Fini quand** :
- [ ] `Button` (primaire rouge, secondaire encre, fantôme), `Card`, `Chip`,
      `Field`, `NumberStepper`, `Sheet`, `Header`, `EmptyState`
- [ ] Aucune couleur ni police en dur dans un composant, toujours un token
      sémantique (règle 1 du design system)
- [ ] Toutes les cibles tactiles à 44 points minimum
- [ ] `NumberStepper` : saisie au clavier numérique **et** boutons plus/moins par
      incrément (2,5 kg par défaut). En salle, on ne tape pas au clavier

### KL-24 — Couche SQLite + Drizzle

**Fini quand** :
- [ ] Schéma local miroir de §2.2 : `exercise`, `scheduled_workout` (qui porte le
      prévu **et** le réalisé, comme côté serveur), `prescribed_snapshot`,
      `logged_exercise`, `logged_set`, plus `sync_state` et `mutation_queue`
- [ ] Migrations locales versionnées et rejouables
- [ ] `mutation_queue` : `id`, `type`, `payload`, `attempts`, `lastError`,
      `createdAt`
- [ ] Les UUID sont générés **localement** à la création (UUIDv7, ordonnable par
      le temps)
- [ ] Un jeu de données de démonstration injectable en dev

### KL-25 — Client API et stockage du token

**Fini quand** :
- [ ] Client typé partagé, timeout, retry avec backoff exponentiel
- [ ] Token dans `expo-secure-store`, jamais dans `AsyncStorage`
- [ ] Un 401 purge le token et renvoie vers l'écran de connexion
- [ ] Le nom d'appareil envoyé au login vient de `expo-device`

### KL-26 — Écran de connexion

**Fini quand** :
- [ ] Écran d'accueil proposant **« Scanner le QR » en action primaire**, et
      « Saisir le code » puis « Email et mot de passe » en actions secondaires
- [ ] Le formulaire mot de passe existe mais n'est pas le chemin par défaut
- [ ] Session restaurée au lancement si le token est valide
- [ ] Premier bootstrap déclenché après connexion, avec un état de chargement
      honnête (« Récupération de tes séances »)
- [ ] Aucun lien « créer un compte » (il n'y a pas d'inscription publique)

### KL-48 — Écran de scan du QR d'appairage

**Où** : repo `kadens-mobile`

**Fini quand** :
- [ ] `expo-camera` avec demande de permission **expliquée avant** de la
      déclencher (un refus définitif ne se rattrape que dans les réglages
      Android)
- [ ] Le scan lit `{url, code, exp}` et **configure l'URL de l'API au passage** :
      c'est ce qui rend l'app utilisable sans aucune saisie, y compris en
      développement contre une IP LAN
- [ ] Saisie manuelle du code de 8 caractères en repli, clavier en majuscules
- [ ] Erreurs traitées : code expiré, code déjà utilisé, réseau absent, permission
      caméra refusée
- [ ] Le token reçu part directement dans `expo-secure-store`, il n'est jamais
      journalisé ni écrit en base locale
- [ ] Un QR d'une autre application est rejeté proprement, sans plantage

### KL-27 — Moteur de synchronisation

**Quoi** : le cœur technique du projet. À écrire avec soin, c'est là que les
bugs coûteux se logent.

**Fini quand** :
- [ ] **Pull** : `GET /api/bootstrap?since=<sync_state.lastPulledAt>`, application
      en transaction, mise à jour de `lastPulledAt`
- [ ] **Push** : dépilage de `mutation_queue` en FIFO, une mutation à la fois,
      suppression sur succès
- [ ] Une mutation en échec est **rejouée**, jamais perdue. Après 5 échecs, elle
      est marquée et remontée dans les réglages, pas silencieusement abandonnée
- [ ] Déclenchement : au lancement, au retour au premier plan, au retour du
      réseau (`expo-network`), et à la clôture d'une séance
- [ ] **Le push passe toujours avant le pull** : sinon un bootstrap écraserait
      localement une séance pas encore envoyée
- [ ] Aucune fenêtre où une séance en cours peut être perdue : la base locale est
      la source de vérité tant que le log n'est pas confirmé par le serveur
- [ ] La synchronisation ne bloque **jamais** l'interface

---

## Lot 4 — L'exécution de séance

### KL-28 — Écran Aujourd'hui

**Fini quand** :
- [ ] Les séances programmées du jour, lues en local
- [ ] Bouton « Démarrer » par séance, et « Séance libre » toujours accessible
- [ ] Reprise d'une séance en cours si l'app a été fermée
- [ ] Accès aux jours voisins (J-2 à J+2), pour rattraper la veille
- [ ] État vide traité (aucune séance programmée)

### KL-29 — Écran Séance en cours

**Fini quand** :
- [ ] Le prescrit s'affiche bloc par bloc, dans l'ordre, avec les rangs de
      superset (A1/A2) **dérivés de l'ordre**, jamais stockés
- [ ] Chaque série est une ligne cochable, pré-remplie par le prescrit
- [ ] Cocher une série écrit un `LoggedSet` en base locale et empile un `PUT` de
      la séance datée dans `mutation_queue` (les mutations d'une même séance se
      coalescent : inutile d'en empiler une par série)
- [ ] Progression visible (séries faites sur séries prévues)
- [ ] Les exercices cardio sont en lecture seule, cochables fait / pas fait
- [ ] L'écran survit à une mise en arrière-plan et à une coupure réseau
- [ ] Rien n'est jamais perdu si l'app est tuée

### KL-30 — Déviations

**Fini quand** :
- [ ] Modifier le poids, les reps ou la durée d'une série
- [ ] Ajouter une série à un exercice, en supprimer une
- [ ] Marquer un exercice comme sauté (avec une raison optionnelle)
- [ ] Remplacer un exercice par un autre de la bibliothèque locale
- [ ] Ajouter un exercice non prévu
- [ ] **Aucune réorganisation de blocs, aucun superset créé, aucun tour modifié**
      (§0.3 point 3). Si le besoin remonte pendant le développement, il devient
      un ticket web, pas un ticket mobile
- [ ] Le prescrit reste visible à côté de la valeur saisie, pour voir l'écart

### KL-31 — Timer de repos, veille, notification

**Fini quand** :
- [ ] Timer démarré automatiquement à la validation d'une série
- [ ] Durée par défaut réglable, ajustable en un geste (+ 15 s / - 15 s)
- [ ] `expo-keep-awake` actif pendant toute la séance, relâché à la clôture
- [ ] Notification locale à la fin du repos si l'app est en arrière-plan
- [ ] Vibration courte, désactivable dans les réglages

### KL-32 — Historique en séance

**Fini quand** :
- [ ] Sous chaque exercice : « Dernière fois » et « Record », lus en local
      (donc disponibles hors réseau, ils viennent du bootstrap)
- [ ] Affichage compact, sur deux lignes maximum
- [ ] Absence d'historique traitée sans case vide disgracieuse

### KL-33 — Clôture de séance

**Fini quand** :
- [ ] Écran de résumé : durée, tonnage, séries faites, écarts au prescrit
- [ ] Champ de notes libre
- [ ] La clôture empile la mutation finale et déclenche une synchronisation
- [ ] Une séance clôturée hors réseau se voit « en attente de synchronisation »,
      et l'état disparaît une fois confirmée
- [ ] Abandon possible sans clôture (la séance reste ouverte et reprenable, son
      statut ne passe pas à `DONE`)
- [ ] **Pas de reprise après clôture** : une séance clôturée est close (§2.3
      point 5). Refaire la même séance dans la journée crée une séance libre

### KL-34 — Séance vierge

**Fini quand** :
- [ ] Démarrage sans prescrit, à la date du jour
- [ ] Recherche d'exercice dans la bibliothèque **locale** (donc hors réseau),
      avec filtre par activité et zone
- [ ] Ajout d'exercices au fil de la séance
- [ ] L'app crée une **séance datée sans `workout`**, avec son propre `uuid` et
      un `title` saisi ou daté par défaut. Elle apparaît au calendrier web en
      « hors plan » (KL-08)
- [ ] Aucun `Workout` n'est créé en bibliothèque (décision actée)

### KL-35 — Écran Réglages

**Fini quand** :
- [ ] Compte, déconnexion, version de l'app et du build
- [ ] État de synchronisation : dernière réussite, mutations en attente,
      mutations en échec avec possibilité de les rejouer
- [ ] Durée de repos par défaut, vibration
- [ ] Bouton « Resynchroniser tout » (purge locale et bootstrap complet)

### KL-36 — Tests mobile

**Fini quand** :
- [ ] Jest configuré, `@testing-library/react-native`
- [ ] **Le moteur de synchronisation est testé en priorité** : file rejouée,
      échec puis succès, mutation en double, application d'un delta, ordre
      push avant pull
- [ ] Les réducteurs de séance testés (cocher, dévier, sauter, clôturer)
- [ ] Un test de bout en bout du parcours « séance programmée, entièrement hors
      réseau, puis synchronisée »
- [ ] Les tests tournent en CI sur chaque push

---

## Lot 5 — Design et finitions

### KL-37 — Passe design complète

**Fini quand** :
- [ ] Tous les écrans repris à l'identité Presse : papier froid, encre quasi
      noire, un seul accent rouge, rayon 0, aucune ombre
- [ ] **Le rouge ne sort que pour l'action primaire, l'intensité et l'échec.**
      Toute catégorie (activité, zone, rôle de bloc) se code par son rang dans
      l'échelle de gris, jamais par une teinte inventée
- [ ] Icône de l'app générée depuis `assets/icons/kadens.png`, en réutilisant la
      logique d'isolation du K par composantes connexes de
      `tools/build-pwa-icons.php` (les traits de vitesse chevauchent le K, aucun
      recadrage rectangulaire ne les sépare)
- [ ] Écran de démarrage natif cohérent avec celui de la PWA
- [ ] Navigation basse à trois entrées, cohérente avec le web
- [ ] Zones sûres respectées (barre gestuelle Android)

### KL-38 — États vides, erreurs, hors ligne

**Fini quand** :
- [ ] Un état vide dessiné pour chaque liste
- [ ] Bandeau hors ligne discret et **non bloquant** : hors réseau, l'app marche,
      le bandeau informe, il n'alerte pas
- [ ] Les erreurs d'API se lisent en français, sans code technique
- [ ] Aucun écran blanc possible : un `ErrorBoundary` global avec une porte de
      sortie

### KL-39 — Ergonomie de salle et accessibilité

**Quoi** : le contexte d'usage, c'est debout, une main, écran gras, parfois dans
le noir. Ce ticket n'est pas cosmétique.

**Fini quand** :
- [ ] Cible principale (valider une série) atteignable au pouce, en bas d'écran
- [ ] Aucun geste fin requis : pas de glissement pour supprimer sans repli, pas
      de zone de moins de 44 points
- [ ] Contrastes vérifiés à AA
- [ ] Libellés d'accessibilité sur tous les contrôles
- [ ] `prefers-reduced-motion` respecté
- [ ] La séance en cours ne défile jamais de façon à perdre la série courante

---

## Lot 6 — Build et store perso

### KL-40 — Signature Android

**Fini quand** :
- [ ] Keystore de release généré et **sauvegardé hors du dépôt** (perdre cette
      clé rend toute mise à jour de l'app impossible : il faudrait désinstaller
      et réinstaller, en perdant les données locales)
- [ ] Keystore en base64 dans les secrets GitHub, avec ses trois mots de passe
- [ ] Une deuxième clé, distincte, pour signer l'index du dépôt F-Droid
- [ ] Procédure de restauration écrite dans le README du repo mobile

### KL-41 — Workflow de build APK

**Où** : `.github/workflows/build.yml` (repo mobile)

**Fini quand** :
- [ ] Sur push : installation, lint, tests, `expo prebuild --platform android`,
      `./gradlew assembleRelease`
- [ ] **APK, pas AAB** : un dépôt F-Droid ne distribue que des APK
- [ ] `versionCode` dérivé de `github.run_number`, `versionName` du tag
- [ ] APK signé avec le keystore des secrets
- [ ] Artefact publié en GitHub Release sur un tag `v*`
- [ ] Le workflow échoue si les tests échouent (pas de release verte sur du rouge)

### KL-42 — Dépôt F-Droid auto-hébergé

**Où** : `.github/workflows/publish.yml` (repo mobile), `public/fdroid/` (serveur)

**Quoi** : le store perso. Un dépôt F-Droid est un ensemble de fichiers
statiques, donc parfaitement servi par le mutualisé Infomaniak.

**Fini quand** :
- [ ] `fdroidserver` exécuté en CI (image Docker officielle, elle embarque le SDK
      Android dont `fdroid update` a besoin)
- [ ] L'APK de la release est ajouté au dépôt, l'index régénéré et signé
- [ ] Publication par **rsync sur le mutualisé**, en copiant le patron de
      `deploy.yml` (clé SSH en secret, `-rlptz` et non `-a`, gate manuel)
- [ ] Le dépôt est servi sur `https://kadens.antoninpamart.fr/fdroid/repo`
- [ ] **`.htaccess` déclarant le type MIME des APK**
      (`AddType application/vnd.android.package-archive .apk`), sinon Android
      refuse l'installation du fichier téléchargé
- [ ] Métadonnées renseignées : nom, description, captures, licence
- [ ] Le dépôt s'ajoute dans le client F-Droid et propose la mise à jour
- [ ] Repli documenté dans le README : Obtainium pointé sur les GitHub Releases,
      si le dépôt F-Droid pose problème

### KL-43 — Page d'installation et contrôle de version

**Où** : `templates/` (repo web), `src/Controller/` (repo web), repo mobile

**Fini quand** :
- [ ] Une page `/app` sur le site : à quoi sert l'app, comment ajouter le dépôt
      F-Droid, QR code de l'URL du dépôt, lien APK direct en secours
- [ ] Page rendue dans l'identité Presse, accessible sans être connecté
- [ ] `GET /api/app-version` renvoie la dernière `versionCode` publiée
- [ ] L'app compare au lancement et affiche un bandeau non bloquant quand une
      mise à jour existe, avec un lien vers le dépôt
- [ ] Un champ « version minimale supportée » qui, lui, bloque : c'est la seule
      porte de sortie si un jour le format de synchronisation change

### KL-44 — Recette finale et documentation

**Fini quand** :
- [ ] **Recette réelle en salle** : une séance programmée complète en mode avion,
      puis synchronisation. Une séance vierge. Une déviation. Un exercice sauté.
      Une app tuée en pleine séance puis rouverte
- [ ] Le réalisé de ces séances vérifié sur le web
- [ ] `docs/journal-de-bord.md` : entrée complète du chantier, avec les pièges
      rencontrés
- [ ] `CLAUDE.md §6` mis à jour (nouveau lot livré), et §2 mentionnant le repo
      mobile et l'API
- [ ] `README.md` du repo web renvoyant vers l'app
- [ ] `README.md` du repo mobile complet : installation, build, publication,
      restauration du keystore

---

## Lot 7 — Vues web du réalisé et coaching (après la mise en production)

Ces trois tickets ne bloquent ni le mobile ni la mise en production. Ils sont
là parce que c'est là que la donnée devient intéressante à regarder.
**`KL-50` peut être tiré en avant à tout moment** : sa seule dépendance,
`PerformanceHistory`, est déjà construite au lot 1.

### KL-49 — Réalisé superposé à la progression du plan

**Où** : `templates/plan_template/`, `src/Service/ProgressionAggregator.php`

**Quoi** : `plan_template/show` porte **déjà** un bloc « Progression prévue »
(lot A de `docs/feature-progression.md`). Le réalisé ne demande pas une nouvelle
page, seulement **une deuxième courbe sur le graphique existant**.

Ce ticket referme le « lot B » de `feature-progression.md`, resté en attente de
décision depuis le début.

**Fini quand** :
- [ ] Le réalisé se superpose à la rampe prévue, semaine par semaine
- [ ] Indicateur de respect du plan (« 11 séances tenues sur 14 »)
- [ ] **Une trame n'a pas de dates** : le réalisé affiché est celui de la
      **dernière instanciation** (`planAnchorDate` le plus récent). Un sélecteur
      n'apparaît que s'il y en a plusieurs
- [ ] Un plan jamais instancié affiche le prévu seul, sans espace vide
- [ ] `ProgressionAggregator` est étendu, pas dupliqué
- [ ] `docs/feature-progression.md` mis à jour : le lot B est livré

### KL-50 — Trajectoire d'un exercice

**Où** : `templates/exercise/show.html.twig`, `src/Controller/ExerciseController.php`

**Quoi** : `/exercise/{id}` montre aujourd'hui une définition figée. C'est le seul
endroit qui peut répondre à « est-ce que je progresse sur cet exercice ? » sans
passer par un plan, toutes séances et tous plans confondus.

**Fini quand** :
- [ ] Courbe de charge dans le temps, sur les séries de travail
- [ ] Record et dernière performance, alimentés par `PerformanceHistory` (KL-04)
- [ ] Les dix dernières séances où l'exercice apparaît, avec un lien vers leur
      séance datée
- [ ] Un exercice sans historique n'affiche **rien**, pas un graphique vide
- [ ] Un exercice de la bibliothèque globale n'affiche que **mon** historique,
      jamais celui d'un autre utilisateur. Point à tester explicitement
- [ ] Le bloc ne se rend pas sur la page publique d'un exercice

### KL-51 — Tri de la bibliothèque par usage réel

**Où** : `src/Controller/ExerciseController.php`, `src/Repository/LoggedExerciseRepository.php`, `templates/exercise/index.html.twig`

**Quoi** : une fois le réalisé en base, `/exercise` peut se trier par usage
réel. C'est presque gratuit : l'index charge **déjà toute la bibliothèque en une
fois**, sans pagination, avec un filtrage côté client.

**Rien n'est ajouté sur `Exercise`.** La règle « définition réutilisable sans
paramètres » tient : le compteur se lit à travers `LoggedExercise.exercise`, il
ne se range pas sur l'entité.

**Fini quand** :
- [ ] **Une seule** requête d'agrégat renvoie `[exercise_id => count, lastAt]`
      pour l'utilisateur courant, fusionnée en PHP avec la liste existante
- [ ] **Le compteur est scopé sur l'utilisateur courant.** Un exercice de la
      bibliothèque globale est partagé : « le plus exécuté » veut dire « par
      moi », jamais « par tout le monde ». Même piège que KL-50, même test
- [ ] Trois tris exposés : **les plus faits**, **jamais faits**, **pas fait
      depuis** (par date de dernière exécution décroissante)
- [ ] Le tri suit le mécanisme de filtrage client déjà en place, il ne le
      contourne pas
- [ ] **Pas de dénormalisation** : aucun `timesPerformed` sur `Exercise`. Un
      compteur stocké dériverait à la première suppression de séance et
      demanderait une commande de reconstruction, pour un gain nul à ce volume
- [ ] Le compte apparaît discrètement sur la carte, sans surcharger la grille

**Piste écartée pour l'instant** : afficher la dernière charge dans la palette
du compositeur. Utile pour composer, mais c'est un autre écran et un autre
besoin. À traiter comme un lot à part si le besoin se confirme, jamais en
extension de ce ticket.

### KL-45 — Lecture du réalisé par le coach

**Quoi** : la fusion (§2.1) a rendu ce ticket presque gratuit. Le coach a déjà
`ScheduledWorkoutVoter::VIEW` sur les séances datées de son athlète : il ne reste
qu'à afficher le réalisé qu'elles portent désormais.

**Fini quand** :
- [ ] La fiche athlète sous `/coach` montre les séances réalisées
- [ ] Lecture seule stricte, garantie par l'attribut `LOG` de KL-06
- [ ] La vue se scope sur `$entity->getOwner()`, jamais sur `$this->getUser()`
- [ ] Le bloc-notes privé de l'athlète reste invisible

---

## 4. Les quatre risques qui peuvent faire échouer le projet

0. **La migration de `ScheduledWorkout`** (KL-02). C'est le seul ticket qui
   touche une table déjà remplie en production, et il change une clé étrangère
   de `CASCADE` en `SET NULL`. Le rejouer sur une copie de la base de prod n'est
   pas une précaution, c'est une condition. Tout le reste de la feature est
   additif.


1. **La tentation de recomposer** (§0.3 point 3). C'est le risque numéro un, et
   il ne se manifestera pas au cadrage mais au ticket KL-30, sous la forme
   « tant qu'à faire, autant pouvoir réordonner ». Répondre non.
2. **La synchronisation bidirectionnelle.** Tant que le web n'édite pas les logs,
   il n'y a pas de conflit à résoudre. Le jour où l'édition web s'ouvre, il faut
   un vrai protocole (horodatage vectoriel ou équivalent). Repousser ce jour.
3. **La double maintenance du design.** KL-20 et KL-22 génèrent les tokens depuis
   le CSS pour éviter la divergence, mais les **composants**, eux, sont écrits
   deux fois. Toute évolution de l'identité Presse coûtera désormais double.
   C'est le prix du natif, il est assumé, il doit être connu.

---

## 5. Ordre de construction résumé

```
KL-01                                  décision, avant tout le reste
KL-02 → KL-09                          le réalisé en base et sur le web
       ↳ valeur livrée sans mobile
KL-10 → KL-20, KL-46, KL-47            l'API et l'appairage
KL-21 → KL-27, KL-48                   le socle mobile          (KL-21 en parallèle)
KL-28 → KL-36                          l'exécution de séance
KL-37 → KL-39                          design et finitions
KL-40 → KL-44                          build, store perso, recette
       ↳ app installée et à jour sur le téléphone
KL-49 → KL-51, KL-45                   vues web du réalisé et coaching
       ↳ KL-50 et KL-51 tirables en avant dès le lot 1
```
