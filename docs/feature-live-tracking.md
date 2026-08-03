# Feature — Kadens Live (suivi de séance en direct, app mobile)

> Spécification autosuffisante, découpée en tickets. À lire avec `CLAUDE.md`
> (§3 règles verrouillées), `ROADMAP.md` (§1) et `docs/design-system.md`.
> Ce document **modifie une règle verrouillée du projet** (§0.2) : ne pas
> commencer à coder sans avoir passé le ticket KL-01.

---

> **État (2026-07-31)** : cadrage validé, **KL-01 à KL-08 livrés** (règle révisée
> partout, le modèle du réalisé en base — `LoggedExercise` / `LoggedSet`,
> `ScheduledWorkout` étendue et sa FK `workout` passée en `SET NULL` — puis
> `LogMetrics`, le résumé du réalisé, `PerformanceHistory`, la dernière perf
> et le record, `LogComparator`, l'écart prévu vs réalisé, l'attribut `LOG`
> qui ferme l'écriture du réalisé au coach, **l'affichage du réalisé sur
> `/schedule/{id}`** — la comparaison en place dans le tableau de séries — et la
> **marque « Libre »** au calendrier). **Le lot 1 est clos**, KL-09
> compris : ses deux dernières cases étaient déjà couvertes par les tests écrits
> chemin faisant. **KL-10 livré** : le lot 2 est ouvert — `ApiToken` (secret
> opaque stocké **haché**, expiration glissante de 90 jours),
> `ApiTokenAuthenticator` et le pare-feu `api` **stateless**, déclaré avant
> `main`. **KL-11 livré** : `POST /api/auth/login`, `POST /api/auth/logout`,
> `GET /api/me` — le mot de passe en repli, le secret rendu une seule fois, un
> 401 qui ne dit pas si le compte existe. **KL-46 livré** : l'appairage par QR,
> le chemin nominal — `PairingCode` (code de 8 caractères stocké **haché**,
> usage unique garanti par la base, TTL 2 minutes), `POST /pairing/code` côté
> desktop, `POST /api/auth/pair` côté téléphone, limiteur de débit et commande
> de purge. **KL-47 livré** : la page QR sur le desktop — section « Connecter un
> téléphone » dans `/profile/settings`, QR dessiné **côté serveur** en SVG inline
> (`endroid/qr-code`), code de secours en toutes lettres, décompte et
> confirmation d'appairage. **KL-12 livré** : la gestion des appareils —
> section « Appareils connectés » dans `/profile/settings`, révocation par
> appareil et « tout révoquer », le jeton **supprimé** et non marqué.
> L'appairage est désormais réversible. **KL-13 livré** : les erreurs de l'API
> sont normalisées (RFC 9457) par `ApiExceptionListener` et une enveloppe unique
> `ApiProblem`, et la connexion par mot de passe est limitée à 5 tentatives par
> IP et par minute. **KL-14 livré (01/08/2026)** : `GET /api/bootstrap`, une
> requête qui rend la bibliothèque visible, la fenêtre J-30 → J+14 avec son
> prescrit **et** son réalisé, l'historique par exercice et la liste des
> disparus. Le delta `?since=` n'allège que la bibliothèque (§KL-14), les
> suppressions sont tracées par une table de **pierres tombales**
> (`deleted_entity`), et la structure d'une séance datée a désormais une
> définition unique (`ScheduledWorkoutPayload`) que KL-15 et KL-16 réutiliseront.
> Mesuré sur un jeu réaliste : 80,6 Ko, 16 requêtes SQL, 106 ms.
> **KL-15 et KL-16 livrés (02/08/2026)** : la séance datée s'ouvre seule
> (`GET /api/schedule/{uuid}`, dans la structure du bootstrap au champ près) et
> **le sens montant existe** — `PUT /api/schedule/{uuid}` fait un upsert
> idempotent du document complet, `DELETE` retire une séance libre. Le téléphone
> fait autorité sur le réalisé, le serveur sur la programmation (§KL-16) ; le
> remplacement du réalisé se fait en deux `flush()` dans une transaction, et les
> références invalides sortent en 422 avec le champ fautif.
> **KL-17 livré (02/08/2026)** : `GET /api/exercises/{id}/history` rend la
> trajectoire d'un exercice — dernière performance, record, et les dix dernières
> séances, en deux requêtes bornées. La mise en forme d'une performance a
> désormais un producteur unique (`PerformanceHistoryPayload`), partagé avec le
> tableau `history` du bootstrap.
> **KL-18 et KL-19 livrés (03/08/2026) — le lot 2 est clos.** Les gardes que
> *tous* les endpoints doivent tenir sont désormais tenues au même endroit
> (`ApiEndpointMatrixTest` : anonyme / expiré / révoqué → 401, nominal → 2xx,
> aucun cookie, aucune fuite du bloc-notes privé, ressource d'un tiers refusée),
> et le contrat client est écrit noir sur blanc dans
> [`docs/api-mobile.md`](./api-mobile.md) — onze endpoints, le partage
> d'autorité champ par champ, le protocole d'appairage de bout en bout avec le
> format exact du QR, et un `curl` réellement exécuté par endpoint. Une limite
> connue en est sortie : les horodatages à décalage non nul perdent leur fuseau
> (§KL-19), à envoyer en UTC en attendant un correctif.
> **KL-20 livré (03/08/2026)** : les tokens de design sont publiés.
> `app:tokens:export` projette `assets/styles/tokens.css` en
> `public/design-tokens.json` (155 tokens, `var()` résolues, aucune traduction),
> le fichier est versionné et un test échoue dès qu'il a divergé de la feuille,
> et `tools/fetch-fonts.sh` produit en plus les `.ttf` que lira `expo-font`.
> Prochain ticket : **KL-21** (init du dépôt `kadens-mobile`), qui n'attend rien
> du serveur.

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
- [x] **Comparaison en place, pas d'onglet dédié** (§0.7) : `_workout_sets_table`
      gagne une colonne « Réalisé » quand `LogComparator` a quelque chose à dire.
      Le composant se paramètre, il ne se duplique pas
- [x] Le prescrit passe en encre atténuée dès qu'un réalisé existe, **sans
      disparaître**
- [x] **L'onglet par défaut dépend du statut** : `PLANNED` ouvre sur le
      programme, `DONE` sur le réalisé
- [x] Une séance `MISSED` porte une marque explicite, sinon elle se confond avec
      une séance à venir
- [x] La page se rend correctement pour une séance datée **sans `workout`**
      (séance libre) : pas de colonne « Prévu », seulement le réalisé et le
      `title`
- [x] L'écart se lit à l'encre ; **le rouge ne sort que sur un exercice sauté**,
      conformément à la règle 2 du design system
- [x] Bandeau de KPI du réalisé (`LogMetrics`) réutilisant le composant existant
- [x] Suppression du réalisé possible depuis cette page (avec confirmation), sans
      supprimer la séance datée elle-même
- [x] Le réalisé **n'entre jamais** dans `PlanFlattener`, donc jamais dans
      l'export Excel, le flux ICS ni la page publique. Vérifier explicitement.
- [x] Aucun AJAX post-chargement (règle des pages auto-suffisantes)

**Comment les deux règles de §0.7 se conjuguent.** « Comparaison en place » et
« onglet par défaut selon le statut » se contredisent en apparence : la première
interdit l'onglet, la seconde en suppose un. La lecture qui les tient toutes les
deux est celle-ci — **ce que §0.7 interdit, c'est un onglet du réalisé SEUL**,
qu'il faudrait quitter pour retrouver le prescrit. Le panneau « Réalisé » livré
ici ne fait pas ça : il rend **le même programme**, les mêmes blocs, les mêmes
supersets, avec une colonne de plus dans chaque tableau. On ne le quitte jamais
pour comparer. Les deux panneaux ne diffèrent donc pas par leur contenu mais par
leur paramètre (`comparedById` rempli ou vide) : deux lectures du même programme,
l'intention et le fait, et le statut décide de celle qui s'ouvre.

**Ce que le ticket a posé et qu'il ne faut pas casser** :

- **`merge` est `array_merge`, qui renumérote les clés entières.** L'index
  `comparedById` est donc keyé `'p' ~ id`. Sans le préfixe, un `PrescribedExercise`
  d'id 42 atterrit à l'index 0 et l'appariement se fait au hasard de l'ordre de la
  collection — un bug silencieux, pas une erreur. (Le `statsByIndex` de
  `_workout_read` s'en sort par chance : ses clés sont déjà 0..n-1.)
- **Le bandeau de KPI est extrait en `components/_workout_kpis.html.twig`** et
  sert le prescrit comme le réalisé — c'est ce que la forme identique de
  `LogMetrics::summary()` et `WorkoutMetrics::summary()` (KL-03) existait pour
  permettre. Une seule tuile diffère, et elle ne peut pas ne pas différer : le
  prescrit annonce ses enchaînements (une intention), le réalisé sa durée réelle
  (un fait). Le réalisé rend `supersets`/`circuits` à 0, afficher « séance à plat »
  sur une séance faite en supersets serait faux.
- **Le contrôleur `tabs` reçoit son onglet d'ouverture du serveur**
  (`data-tabs-default-value`), il ne le devine pas. C'est ce qui rend le choix
  testable sans navigateur : le test garde ce que le serveur annonce.
- **La suppression du réalisé teste `LOG`, jamais `EDIT`**, et remet
  `startedAt`/`endedAt` à null (elles ne mesuraient que ce réalisé) mais **ne
  touche ni le statut ni `completionNotes`** : effacer le détail des séries
  n'annule pas le fait que la séance a été faite, et ces deux champs relèvent de
  la programmation, donc du coach.
- **`_scheduled_done` s'intitule désormais « Boucler la séance ».** Deux sections
  « Réalisé » sur la même page, l'une fermée au coach (`LOG`) et l'autre pas
  (`EDIT`), ne pouvaient que se confondre.
- **La portée est la garde anti-fuite, pas une condition d'affichage.** `comparison`
  / `logSummary` / `defaultTab` sont trois paramètres **optionnels** de
  `_workout_read`, et `ScheduledWorkoutController::show()` est le seul appelant qui
  les passe. `workout/show` et `public_share` rendent le même composant sans eux et
  sont donc structurellement incapables d'afficher un réalisé.
- **Une séance sans bloc mais avec du réalisé n'est pas « encore vide ».** La garde
  de l'état vide compte les deux côtés (`flat.blocks is empty and not has_log`),
  sinon une séance entièrement faite hors programme s'annoncerait vide.

Livré avec `tests/Controller/ScheduledWorkoutLogTest.php` (11 tests), dont
`testLogNeverLeaksThroughPlanFlattener` — qui interroge les cinq consommateurs de
la mise à plat sur une séance portant une charge (123,5 kg) prescrite nulle part,
donc impossible à produire autrement que par le réalisé. Il coche la troisième
case de KL-09.

### KL-08 — Séance datée sans source au calendrier

**Où** : `src/Controller/CalendarController.php`, `templates/calendar/`

**Quoi** : une séance vierge est une séance datée avec `workout = null`. Le
calendrier la requête donc **déjà** : il ne reste qu'à l'afficher correctement.
C'est tout ce qui reste de ce ticket depuis la fusion (§2.1), et il n'y a ni
requête supplémentaire ni risque de N+1 à traiter.

**Fini quand** :
- [x] La pastille retombe sur `title` quand `workout` est null, sans planter
- [x] Marque visuelle « hors plan », codée par le rang dans l'échelle de gris,
      jamais par une teinte inventée
- [x] Le clic mène à `/schedule/{id}`, comme toutes les autres pastilles

**Livré le 30/07/2026.** Les deux cases extrêmes l'étaient déjà : `displayTitle`
et le `leftJoin` viennent de KL-02, le lien vers `/schedule/{id}` de la couche
mobile. Il ne restait que la marque — et une décision de vocabulaire.

- **Le libellé dit « Libre », pas « Hors plan ».** Une séance posée à la main
  depuis la bibliothèque est elle aussi hors d'un plan, et elle a pourtant un
  programme : « hors plan » aurait nommé une autre distinction que celle qu'on
  marque. « Libre » reprend le mot que l'app emploie déjà pour cette chose —
  `getDisplayTitle()` retombe sur « Séance libre », l'eyebrow de `/schedule/{id}`
  dit la même. Il est aussi court **par nécessité** : un premier essai
  (« Sans programme ») imposait sa largeur à la pastille et se faisait couper au
  milieu d'un mot dans une case de calendrier.
- **La marque est un composant, `components/_freeform_mark.html.twig`**, parce
  qu'elle se pose à deux endroits du même fichier : la pastille et sa modale
  rapide — où elle prend la place du lien « Voir la séance », qui n'a plus de
  cible. Une seule définition du signe et du mot.
- **Contour au rang le plus clair de l'échelle catégorielle (`--color-cat-4`),
  texte à l'encre faible.** C'est une catégorie de séance, pas un statut : le
  filet gauche de la pastille continue de porter prévu / fait / manqué, et il ne
  fallait surtout pas y toucher — `is-overdue` s'y exprime déjà en pointillé
  rouge. L'échelle catégorielle ne porte jamais de texte (design-system §5), d'où
  le contour plutôt qu'une couleur de libellé.
- **La marque passe par le Turbo Stream de statut**, qui re-rend la pastille par
  le même composant : sans ce chemin, elle disparaîtrait au premier clic sur
  « fait ». Un test le garde.
- **La pastille ne se comprime que si tous ses maillons portent `min-width: 0`.**
  `.kd-calevent__open` l'avait, mais ses enfants en colonne gardaient
  `min-width: auto` et lui réimposaient la largeur de leur contenu : le chip
  débordait de la case, où l'`overflow: hidden` de la pastille le coupait au
  milieu d'un mot. Corrigé sur `.kd-calevent__meta`, donc pour tout ce qu'on
  ajoutera dans cette méta — pas seulement pour cette marque.

Livré dans `tests/Controller/ScheduledWorkoutSourcelessTest.php` (10 tests), dont
`testOnlySourcelessSessionsCarryTheFreeformMark` — qui cadre un mois **contenant
les deux cas** : sans la séance avec source, une marque posée sur tout le monde
passerait le test.

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
- [x] **Un test de non-régression sur la suppression** : supprimer un `Workout`
      de la bibliothèque laisse debout ses séances datées et leur réalisé.
      C'est le test qui garde le `SET NULL` de §2.3 point 1
      (`ScheduledWorkoutSourcelessTest::testDeletingLibraryWorkoutKeepsItsDatedSessions`,
      livré avec KL-02)
- [x] Un test sur une séance datée sans `workout` : elle se rend, s'affiche au
      calendrier et n'entre pas dans l'export (même fichier — les deux vues, la
      page datée, l'export et le flux ICS ; la marque s'y est ajoutée en KL-08)
- [x] Un test qui **échoue** si le réalisé fuite dans `PlanFlattener` (livré avec
      KL-07, `ScheduledWorkoutLogTest::testLogNeverLeaksThroughPlanFlattener`)

---

## Lot 2 — L'API (Symfony)

### KL-10 — `ApiToken`, authenticator, firewall

**Où** : `src/Entity/ApiToken.php`, `src/Security/ApiTokenAuthenticator.php`, `config/packages/security.yaml`

**Quoi** : un firewall `api` **stateless** sur `^/api`, distinct de `main`.

**Fini quand** :
- [x] Entité `ApiToken` : `owner`, `tokenHash` (hash SHA-256, **jamais le token
      en clair** — le constructeur prend le secret et le hache sur place, il n'y a
      pas de chemin où il puisse être écrit), `deviceName`, `createdAt`,
      `lastUsedAt`, `expiresAt`
- [x] Authenticator custom lisant `Authorization: Bearer <token>`, qui sert aussi
      d'`entry_point` (401 `application/problem+json`, jamais une redirection vers
      le formulaire web)
- [x] Firewall `api` avec `stateless: true`, **placé avant `main`** dans
      `security.yaml` (l'ordre des firewalls décide, le premier motif qui
      correspond gagne)
- [x] `access_control` : `^/api/auth` public, tout le reste `ROLE_USER`
- [x] Expiration glissante : `lastUsedAt` rafraîchi, `expiresAt` repoussé de
      90 jours à chaque usage (`ApiToken::touch()`, appelé par l'authenticator)
- [x] Aucune session créée sur `^/api` (vérifié par un test sur l'absence de
      `Set-Cookie`), **et** un test qui vérifie qu'une session `main` active
      n'authentifie pas l'API — c'est le piège ci-dessous

`GET /api/ping` est ajouté au passage : le routage s'exécute avant le contrôle
d'accès, donc sans une route sur `^/api` le pare-feu n'est pas testable. Sonde
authentifiée, muette sur l'identité (c'est `GET /api/me`, KL-11, qui la porte) ;
le client mobile s'en sert pour vérifier l'URL de serveur portée par le QR.

**Piège** : le firewall `main` a `lazy: true` et un `remember_me` à dix ans. Si
`^/api` tombait dedans, une requête mobile serait authentifiée par cookie et le
token deviendrait décoratif. L'ordre dans `security.yaml` n'est pas cosmétique.

### KL-11 — Endpoints d'authentification (mot de passe, repli)

**Où** : `src/Controller/Api/AuthController.php`

**Quoi** : le chemin nominal est l'appairage par QR (KL-46). Le mot de passe
reste comme repli, et parce que les tests fonctionnels de l'API en ont besoin.

**Fini quand** :
- [x] `POST /api/auth/login` : `{email, password, deviceName}` → `{token, user}`.
      Le token en clair n'est renvoyé **qu'ici et à l'appairage**, une seule fois.
      **201** et non 200 : l'appel enregistre un appareil, il ne fait pas que lire
- [x] `POST /api/auth/logout` : révoque le token courant (**204**), et lui seul —
      les autres appareils du compte restent connectés
- [x] `GET /api/me` : identité, rôles, et l'appareil courant (nom, dernier usage,
      échéance, **date du dernier bootstrap**)
- [x] Pas de parcours d'inscription (les comptes se créent en console, règle
      verrouillée). Le mot de passe oublié reste hors périmètre
- [x] Réponse 401 uniforme, sans distinguer « email inconnu » de « mot de passe
      faux » — **corps identique au caractère près**, et hachage à vide sur un
      compte inexistant pour que le *temps* de réponse ne le trahisse pas non plus

Ce que le ticket pose, et qu'il ne faut pas casser :

- **`api_token.last_bootstrap_at` ne double pas `last_used_at`.** La seconde
  bouge à chaque requête (l'authenticator la repousse), la première ne bougera
  qu'au `GET /api/bootstrap` — **KL-14 en est le seul écrivain**, et un appel qui
  ne rend pas le jeu complet ne doit pas laisser croire que l'appareil est à jour.
  C'est la différence entre « ce téléphone répond » et « ce téléphone travaille
  sur des données à jour », et c'est ce que KL-12 affichera.
- **Le jeton validé est publié sur la requête** (`ApiTokenAuthenticator::REQUEST_ATTRIBUTE`),
  pas relu depuis l'en-tête par le contrôleur. `logout` révoque *celui qu'on
  présente* et `/api/me` décrit l'appareil courant sans qu'aucun second endroit
  n'ait à savoir ce que vaut un `Bearer`. Le préfixe `_` le tient hors des
  arguments de contrôleur résolus par nom.
- **`logout` vit sous `^/api/auth`, donc publique pour `access_control` : la
  garde est dans le contrôleur, et elle porte sur le jeton, pas sur
  l'utilisateur.** C'est le jeton qui est l'objet de l'action — sans lui, il n'y a
  rien à révoquer, quand bien même on saurait qui appelle.
- **Contrat client : ne pas envoyer d'`Authorization` sur `/api/auth/login`.**
  L'authenticator se déclenche sur la seule présence d'un `Bearer`, quel que soit
  l'`access_control` de la route — KL-10 a volontairement refusé d'écrire une
  liste d'exceptions de routes dans l'authenticator, et logout a besoin de
  l'en-tête. Un jeton périmé présenté à la connexion la fait donc échouer **avant**
  le contrôleur. Le flux de reconnexion est : 401 → effacer le jeton local →
  login sans en-tête. Un test fige ce comportement plutôt que de le subir.
- **La borne du `VARCHAR(100)` se refuse dans le contrôleur.** Un `deviceName`
  trop long rend 400, jamais une erreur SQL en 500 : le nom vient du client, il
  n'a pas à atteindre la base pour être jugé.
- **Piège de test** : `loginUser()` pose le jeton dans le `token_storage` du
  conteneur *en plus* du cookie. Tant que le noyau n'a pas redémarré, ce jeton
  résiduel traverse n'importe quel pare-feu, **stateless compris** — un test
  « la session web n'authentifie pas l'API » passerait alors pour la mauvaise
  raison. Il faut une requête web intercalée pour purger le conteneur ; ce qui
  reste ensuite, le seul cookie, est bien ce qu'on prétend tester.

### KL-46 — Appairage : entité `PairingCode` et endpoints

**Où** : `src/Entity/PairingCode.php`, `src/Controller/Api/AuthController.php`, `src/Controller/ProfileController.php`

**Quoi** : le mécanisme décrit en §0.6. Un utilisateur authentifié sur le web
émet un code à usage unique ; le téléphone l'échange contre un `ApiToken`.

**Fini quand** :
- [x] Entité `PairingCode` : `owner`, `codeHash`, `createdAt`, `expiresAt`
      (2 minutes), `usedAt` nullable, `consumedByDevice` nullable
- [x] Le code fait 8 caractères en alphabet **sans ambiguïté** (ni `O`/`0`, ni
      `I`/`1`/`l`), pour rester saisissable à la main en repli
- [x] `POST /pairing/code` (firewall `main`, utilisateur authentifié) émet un
      code et rend la charge utile du QR :
      `{"url": "<base API>", "code": "<code>", "exp": "<ISO8601>"}`.
      **Précision apportée par KL-47** : cette charge utile est ce que le QR
      *encode*, pas ce que la réponse HTTP rend — le ticket la rendait en JSON
      faute d'écran pour l'afficher, l'endpoint rend désormais le panneau
- [x] **Le QR ne contient jamais de token**, seulement ce code (§0.6 règle 1)
- [x] `POST /api/auth/pair` : `{code, deviceName}` → `{token, user}`
- [x] **Consommation atomique** : `UPDATE pairing_code SET used_at = NOW()
      WHERE id = ? AND used_at IS NULL`, puis vérification des lignes affectées.
      Une lecture suivie d'une écriture laisserait passer deux scans simultanés
- [x] Un code expiré, déjà utilisé ou inconnu renvoie la **même** erreur 400
- [x] Limiteur de débit sur `POST /api/auth/pair` (10 essais par IP et par
      minute), sinon les 8 caractères se cassent par force brute
- [x] Purge des codes expirés par une commande console, appelable en cron
      (`app:pairing:purge`)
- [x] Le code est lié à son émetteur : le token créé appartient à l'utilisateur
      de la session desktop, jamais à un autre

Ce que le ticket pose, et qu'il ne faut pas casser :

- **L'usage unique est une garantie de la base, pas une intention du code PHP.**
  `PairingCodeRepository::consume()` écrit
  `UPDATE ... WHERE id = ? AND used_at IS NULL AND expires_at > ?` et lit le
  nombre de lignes affectées. Deux scans simultanés du même QR verraient tous
  les deux `used_at IS NULL` si on lisait avant d'écrire, et repartiraient tous
  les deux avec un jeton. L'échéance est **dans le même `WHERE`** pour la même
  raison : elle ne peut pas être vraie au moment du test et fausse au moment de
  l'écriture. L'entité est relue (`refresh`) après coup — ce que le contrôleur
  rend doit être ce que la base a écrit.
- **Le compte vient du code, jamais de la requête.** `pair()` appelle
  `issue($pairingCode->getOwner(), …)`. C'est la seule différence de fond avec
  `login()`, qui lit le compte dans le corps : le téléphone ne choisit pas à qui
  il se rattache, et un code deviné n'ouvre que le compte de son émetteur.
- **Inconnu, expiré, déjà utilisé : la même réponse, au caractère près.** Même
  raisonnement que le 401 uniforme de KL-11 — distinguer dirait à qui devine un
  code s'il a visé juste. 400 et non 401 : le client n'a pas à réessayer, il doit
  en demander un autre au desktop. Un test compare les trois corps.
- **Le limiteur de débit est une pièce du modèle de sécurité, pas un confort.**
  Huit caractères sur un alphabet de 32, c'est 40 bits : assez pour ne pas se
  deviner, pas assez pour encaisser une force brute non bridée. La clé est l'IP
  parce qu'à ce stade l'appelant n'a pas d'identité — c'est ce qu'il vient
  chercher. Le 429 est rendu **avant** toute lecture de la base, et il ne
  consomme donc pas non plus un code valide.
- **Un écran, un code.** Émettre invalide les codes non consommés du même
  utilisateur (`deleteUnusedFor`), sinon un code affiché sur un poste qu'on
  vient de quitter resterait échangeable deux minutes. Les codes **consommés**
  survivent : `consumedByDevice` est la trace que KL-47 affiche en confirmation,
  et c'est un snapshot, pas une relation vers l'`ApiToken` — celui-ci se révoque
  (KL-12) et emporterait la trace avec lui.
- **`PairingCode::hash()` normalise avant de hacher** (`trim` + majuscules) : le
  repli clavier de §0.6 règle 4 se tape comme il vient, et sans ça l'erreur
  uniforme rendrait la panne indéchiffrable.
- **`^/pairing` est déclaré dans `access_control`**, `/pairing/code` ne vivant
  pas sous `^/profile`. Le CSRF est vérifié à la main (`pairing_code`), comme
  partout ailleurs dans le projet où la requête ne passe pas par un `FormType`.
- **Piège de test** : le compteur du limiteur vit dans un pool de cache **sur
  disque**, qu'il faut vider au `setUp` — sinon l'ordre des tests devient
  significatif. Le passer en `ArrayAdapter` ne marche pas : le
  `services_resetter` le remet à zéro entre deux requêtes du même test, et le
  quota ne compte plus rien.

### KL-47 — Page QR d'appairage sur le desktop

**Où** : `templates/profile/`, `src/Controller/ProfileController.php`,
`src/Service/PairingQr.php`, `assets/controllers/pairing_controller.js`

**Fini quand** :
- [x] Une section « Connecter un téléphone » dans `/profile/settings`
- [x] Le QR est généré **côté serveur** (`endroid/qr-code`, rendu SVG inline) :
      pas de dépendance JavaScript à faire passer par l'importmap, et ça marche
      sans JS
- [x] Le code de 8 caractères est affiché **en toutes lettres sous le QR**, en
      IBM Plex Mono, comme repli si la caméra refuse
- [x] Compte à rebours visible et **régénération en un clic** à l'expiration
- [x] Confirmation visuelle sur le desktop : le nom de l'appareil qui vient de
      consommer le code (`consumedByDevice`), via `GET /pairing/{id}/status`.
      **La liste des appareils reste à KL-12** — elle n'existe pas encore, c'est
      la seule case du ticket que ce lot ne pouvait pas couvrir
- [x] Rendu à l'identité Presse, cohérent avec le reste de la page

Ce que le ticket pose, et qu'il ne faut pas casser :

- **L'état par défaut de la page est *sans* code.** Émettre est une écriture, pas
  un effet de bord de l'affichage : générer un code à chaque ouverture des
  paramètres en gâcherait un à chaque fois et invaliderait celui qu'un autre
  onglet montre (« un écran, un code », KL-46). D'où un bouton « Afficher le
  QR », et un panneau qui a deux états.
- **`POST /pairing/code` ne redirige pas après son écriture.** Le code en clair
  n'existe que dans la réponse qui l'émet et sur l'écran qui l'affiche — la base
  n'en a que l'empreinte. Rediriger obligerait à le faire vivre ailleurs, en
  session, c'est-à-dire à créer un second endroit où un secret de deux minutes
  traîne. Le repli sans JS rend donc la page entière en réponse au POST ; avec
  Turbo, seul `#pairing-panel` est remplacé (le formulaire de mot de passe de la
  même page ne doit pas perdre sa saisie).
- **L'endpoint ne rend plus de JSON.** La charge utile `{url, code, exp}` de
  KL-46 n'a jamais eu de consommateur HTTP : c'est ce que le **QR** encode, et
  KL-48 la lit en scannant. La rendre aussi en réponse aurait laissé deux
  représentations d'une même chose à tenir d'accord.
- **Le contenu du QR se teste sans décodeur.** `PairingQr::payload()` est le
  contrat avec le mobile, `svg()` n'en est qu'un dessin — et un dessin
  déterministe : le test régénère le SVG attendu à partir de la charge utile
  attendue et le cherche dans la page. Ce qui est figé, c'est ce qui est encodé,
  pas la façon dont c'est peint.
- **Le décompte est un confort, l'échéance est l'information.** Le serveur écrit
  « Valable jusqu'à 14:32 » ; le contrôleur Stimulus `pairing` remplace ce texte
  par « Expire dans 1:47 ». Sans JS il ne manque donc rien — c'est la même règle
  que les `<details>` rendus ouverts côté serveur.
- **Le sondage de `GET /pairing/{id}/status` est borné par ce qu'il observe** :
  il s'arrête au code consommé, à l'échéance, ou sur une réponse non-`ok` (un
  code régénéré ailleurs a été supprimé, réessayer n'empilerait que des 404). Ce
  n'est pas l'AJAX post-chargement que le projet refuse sur ses pages de
  consultation : il n'y a rien à mettre en cache offline dans un secret qui
  périme en deux minutes.
- **L'état d'un code qui n'est pas le sien rend 404, pas 403** : un refus qui
  distingue « pas à toi » de « n'existe pas » confirme l'existence à qui essaie
  des identifiants.
- **Ni la confirmation ni l'expiration ne sortent le rouge.** Un code consommé
  est un succès, un code échu une réponse normale du système — même raisonnement
  que les pages 404/403, qui restent à l'encre (§5 règle 2 du `CLAUDE.md`).
- **La marge blanche du QR est dans l'image, pas dans le CSS.** C'est la « quiet
  zone » de la norme : sans elle un décodeur ne trouve pas les motifs de
  repérage, et du `padding` autour ne la remplace pas — la caméra ne voit que
  l'image.

### KL-12 — Gestion des appareils dans `/profile/settings`

**Où** : `src/Controller/ProfileController.php`, `templates/profile/`

**Quoi** : un token qu'on ne peut pas révoquer depuis l'app web est un trou.
L'échéance d'un `ApiToken` glisse à chaque usage (KL-10) : un téléphone qui s'en
sert ne s'éteint jamais tout seul.

**Fini quand** :
- [x] Liste des appareils connectés (nom, dernière utilisation, expiration —
      plus « appairé le » et `lastBootstrapAt`, la dernière synchro, qui
      distingue « ce téléphone répond » de « ce téléphone est à jour »)
- [x] Bouton de révocation par appareil, et « tout révoquer »
- [x] Rendu dans l'identité Presse, cohérent avec le reste de la page

Ce que le ticket pose, et qu'il ne faut pas casser :

- **Révoquer, c'est supprimer la ligne**, comme `POST /api/auth/logout` (KL-11).
  Un jeton marqué « révoqué » obligerait chaque lecture à s'en souvenir —
  l'authenticator, la liste, `GET /api/me`, et tout ce qui viendra ensuite ; un
  oubli à un seul de ces endroits rouvre l'accès sans bruit. L'absence, elle, ne
  s'oublie pas. Corollaire : `ApiTokenRepository::deleteForOwner()` écrit un
  `DELETE` DQL et **ne passe pas par les entités chargées** — « tout révoquer »
  se fait quand on ne sait plus ce qui est connecté, il ne doit dépendre d'aucun
  état lu au préalable.
- **Un jeton qui n'est pas le sien rend 404, pas 403**, comme
  `GET /pairing/{id}/status` (KL-47). La garde de propriété passe **avant** la
  vérification CSRF : elle ne fait que lire un `owner`, et aucune écriture n'a
  lieu tant que le jeton CSRF n'est pas validé.
- **La réponse est un Turbo Stream ciblé sur `#devices-panel`**, repli par
  redirection sans JS. `/profile/settings` porte trois choses indépendantes (un
  QR éventuellement affiché, une saisie de mot de passe, cette liste) : révoquer
  un vieux téléphone pendant qu'on en appaire un nouveau est un geste normal, il
  n'a pas à effacer les deux autres. Le panneau **entier** est remplacé, pas la
  ligne : « tout révoquer » vide la liste, et le bouton global disparaît dès
  qu'il ne reste qu'un appareil.
- **Pas de flash dans la branche stream** : rien ne le rechargerait, il resterait
  en session et surgirait à la navigation suivante. La ligne qui disparaît est la
  confirmation.
- **Un jeton échu garde sa ligne**, atténuée et jamais rouge : il n'authentifie
  plus mais il se révoque, donc il s'affiche — une expiration est une réponse
  normale du système (§5 règle 2, même raisonnement qu'un code d'appairage échu).
- **« Tout révoquer » ne touche pas aux codes d'appairage non consommés.** Un
  code n'est pas un accès mais une invitation de deux minutes, affichée sur
  l'écran de celui-là même qui révoque : il ne peut pas être parti avec le
  téléphone perdu. Il n'apparaît qu'à partir de **deux** appareils — avec un
  seul, il doublerait le bouton d'à côté.
- **La liste ne se rafraîchit pas au moment où un appairage se confirme.** Le
  sondage de KL-47 observe un **code**, pas un compte ; lui faire réécrire ce
  panneau créerait un second endroit qui décide de ce que la liste contient. Le
  nouvel appareil y apparaît au chargement suivant, la confirmation « Pixel 8 est
  connecté » faisant foi sur le moment.
- **Le test qui porte le ticket est `testRevokingADeviceEndsItsApiAccess`** :
  sans lui, la page ne prouverait qu'une ligne retirée d'un tableau. Ce qui
  compte, c'est que le secret qui ouvrait `GET /api/ping` juste avant rende 401
  juste après.

### KL-13 — Erreurs normalisées et limitation de débit

**Où** : `src/EventListener/ApiExceptionListener.php`, `src/Http/ApiProblem.php`,
`config/packages/rate_limiter.yaml`

**Fini quand** :
- [x] Toute exception sur `^/api` sort en `application/problem+json`
      (RFC 9457 : `type`, `title`, `status`, `detail`)
- [x] Les erreurs de validation listent les champs fautifs
- [x] Aucune trace de pile en prod
- [x] Limiteur sur `POST /api/auth/login` (5 tentatives par IP et par minute)
- [x] Le listener **ne capte pas** les routes hors `^/api` (les pages d'erreur
      Twig existantes doivent continuer de sortir)

Ce que le ticket pose, et qu'il ne faut pas casser :

- **Le contrôleur rend ses erreurs, le listener rattrape ce que personne n'a
  rendu.** `AuthController` et `ApiTokenAuthenticator` continuent de formuler
  leurs refus (identifiants invalides, code d'appairage périmé, jeton absent) :
  ils savent ce qu'ils refusent, là où le listener ne peut que traduire un
  statut. Le listener est un filet, pas une couche de plus à traverser.
- **Une seule enveloppe, `App\Http\ApiProblem`.** Les trois producteurs
  d'erreurs de l'API passent par elle. Le `title` s'y **dérive du statut** et ne
  s'écrit jamais à la main — un appelant qui le choisissait pouvait le mettre en
  désaccord avec le `status` de la même réponse (c'était le cas avant, avec un
  couple `(status, title)` répété à huit endroits). Corollaire : `title` reste en
  anglais (le vocabulaire HTTP), `detail` en français (il est destiné à être lu).
- **Le message d'une exception ne sort JAMAIS dans la réponse.** Il est écrit
  pour les journaux : une exception Doctrine porte le SQL, un résolveur
  d'argument porte un nom de classe interne, et le `NotFoundHttpException` du
  routeur récite l'URL demandée. Le `detail` est donc choisi par statut, dans une
  table du listener. C'est la lecture forte du « aucune trace de pile en prod » :
  il n'y a pas de chemin par lequel un détail interne puisse partir, pas
  seulement pas de trace. Hors prod, et **seulement sur une 5xx**, un membre
  d'extension `exception` ajoute la classe, le message et la ligne — jamais la
  trace, que le profileur garde déjà.
- **Priorité -1 sur `kernel.exception`, et c'est mesuré.** Le pare-feu de
  sécurité écoute à **1** : il doit passer d'abord, c'est lui qui transforme un
  accès refusé en 401 (via `ApiTokenAuthenticator::start()`) ou en 403.
  `ErrorListener` de Symfony écoute **deux fois** — la journalisation à **0**, le
  rendu HTML à **-128**. Se placer entre les deux, c'est garder le journal et
  supplanter le rendu. À 0, l'ordre avec la journalisation serait celui de
  l'enregistrement des services : une 500 d'API pourrait cesser d'être tracée
  sans que rien ne le dise. Rappel du mécanisme : `setResponse()` **arrête la
  propagation** (`RequestEvent`), tout ce qui écoute plus bas est court-circuité.
- **Le périmètre est le préfixe littéral de `security.yaml`**
  (`str_starts_with('/api')`), volontairement pas une expression plus fine. Le
  raffiner ici (`^/api(/|$)`) créerait une zone où le pare-feu stateless
  s'applique mais pas la mise en forme, donc un chemin d'API qui sortirait en
  HTML. Hors du préfixe, le listener ne fait **rien** : les pages d'erreur Twig
  continuent de sortir, et un test le vérifie par une vraie requête.
- **Les en-têtes de l'exception survivent** (`Allow` sur un 405, `Retry-After`
  sur un 429, `WWW-Authenticate` sur un 401) : ils font partie de la réponse, pas
  de sa décoration. Un 405 sans `Allow` ne dit pas ce qu'il aurait fallu appeler.
- **Une validation est un 422 même nue.** Les violations sont cherchées dans
  **toute** la chaîne des causes : `#[MapRequestPayload]` (KL-16) n'expose pas la
  `ValidationFailedException`, il la met en `previous`. S'arrêter au premier
  niveau rendrait un 422 sans le moindre champ, exactement l'inverse de ce que le
  ticket demande. Et c'est la **présence** de l'exception qui décide du statut,
  pas le nombre de violations : une liste vide reste une validation, pas une panne.
- **Le limiteur de connexion est plus serré que celui de l'appairage** (5 contre
  10 par minute et par IP) : un mot de passe ne s'essaie pas de bonne foi cinq
  fois par minute, là où un code de deux minutes se retape après une faute de
  frappe. La clé reste l'IP — compter par email ferait de la connexion un oracle
  d'existence de compte (« ce compte est bloqué, donc il existe ») et offrirait
  un déni de service ciblé sur un compte connu.
- **Le 429 se rend avant le décodage du corps**, comme en appairage : un quota
  épuisé ne coûte pas une lecture de plus, et le bon mot de passe ne passe pas
  davantage — un test l'exige.
- **La garde de prod se teste hors requête HTTP.** `kernel.debug` est vrai en
  test comme en dev : une requête ne prouverait rien sur ce que la prod laisse
  filtrer. `ApiExceptionListenerTest` instancie donc le listener avec
  `debug: false` et lui passe un `ExceptionEvent` construit à la main. Même
  raisonnement que `ErrorPageTest`, qui rend ses templates directement.
- **Piège de test hérité de KL-46** : le compteur du limiteur vit dans un pool de
  cache **sur disque**. `ApiAuthEndpointsTest` doit le vider au `setUp`, sinon
  l'ordre des tests devient significatif.

### KL-14 — `GET /api/bootstrap`

**Où** : `src/Controller/Api/BootstrapController.php`,
`src/Service/BootstrapPayload.php`, `src/Service/ScheduledWorkoutPayload.php`

**Quoi** : l'hydratation complète de la base locale en **une** requête. C'est
l'endpoint le plus important du lot.

**Fini quand** :
- [x] `?since=<ISO8601>` renvoie le delta ; sans paramètre, le jeu complet
- [x] Contenu : exercices visibles (perso + globale + biblio du coach en
      lecture), séances datées de J-30 à J+14 avec leur prescrit à plat **et leur
      réalisé**, dernières perfs et records par exercice
- [x] Le delta sur les exercices se calcule sur `COALESCE(updatedAt, createdAt)` :
      `updatedAt` reste **null** tant qu'un exercice n'a jamais été modifié, un
      filtre naïf sur `updatedAt` les ferait tous disparaître du delta
- [x] Le prescrit vient de `PlanFlattener`, y compris `setLines`
- [x] Une liste des identifiants supprimés depuis `since` (sinon la base locale
      accumule des fantômes). Prévoir une table `deleted_entity` ou un
      `deletedAt` sur les entités concernées, à trancher dans le ticket
- [x] Le bloc-notes privé (`Workout.notes`) **n'est pas** dans la charge utile
- [x] Réponse mesurée sur un jeu réaliste : moins de 500 ms et moins de 1 Mo

**Ce qui a été tranché en écrivant le ticket** :

- **Le delta n'allège que la bibliothèque d'exercices** (et la liste des
  disparus). La fenêtre de séances datées et l'historique partent toujours en
  entier. La fraîcheur d'une séance datée n'est portée par **aucune colonne** :
  elle dépend de `Workout` → `Block` → `PrescribedExercise` → `PrescribedSet`, et
  aucun niveau n'horodate son parent. Un delta sur `ScheduledWorkout.updatedAt`
  manquerait en silence le programme corrigé par le coach. L'historique, lui,
  coûte déjà deux requêtes quel que soit le volume (`PerformanceHistory`, KL-04) :
  le rendre partiel laisserait un second appareil avec un record fantôme.
- **`window` fait autorité.** La réponse annonce l'intervalle en clair ; une
  séance datée que le client garde dedans et qui n'y est pas n'existe plus
  (déplacée hors fenêtre ou supprimée, le geste local est le même). C'est ce qui
  évite d'inventer une pierre tombale pour un déplacement.
- **Table de pierres tombales, pas de `deletedAt`.** La suppression douce ne
  supprime pas, elle cache : il faudrait alors la filtrer dans *chaque* requête
  du site (index, sélecteurs de pose, calendrier, export, ICS, page publique), et
  un oubli n'y produit aucune erreur, seulement une ligne morte qui réapparaît.
  `deleted_entity` porte une **clé** (`id` d'exercice, `uuid` de séance datée) et
  non une relation, plus un `owner` nullable qui dit à qui l'annoncer.
  `TombstoneListener` (`onFlush` + `postFlush`) l'écrit pour **tous** les points
  de suppression à la fois — il y en a une douzaine, et un oubli ne se verrait
  que des semaines plus tard sur un téléphone. `app:deleted:purge` retire les
  lignes de plus de 180 jours. La liste est **vide** sans `since` : un jeu
  complet remplace tout.
- **`ScheduledWorkoutPayload` est la définition unique** de la structure d'une
  séance datée. C'est elle que KL-15 rendra seule et que KL-16 recevra : la seule
  façon de tenir la promesse « un seul désérialiseur côté client » est de n'avoir
  qu'un endroit qui produit la structure.
- **Valeurs brutes, sauf `summary`.** Le cardio ne se saisit pas sur le mobile
  (§0.4), il ne s'affiche qu'en lecture : réécrire les six branches de
  `PlanFlattener::summarize()` en TypeScript pour une chaîne qu'on ne fait que
  peindre serait une duplication sans contrepartie.
- **L'historique est une liste, pas un objet indexé par id d'exercice** :
  `json_encode` rend un tableau PHP en objet **ou** en liste selon ses clés.
  Même piège que le `'p' ~ id` de KL-07.
- **La portée de la bibliothèque est symétrique** (soi + coachs + athlètes),
  celle d'`ExerciseVoter::VIEW`, pas celle — dirigée — de `CoachedLibrary` : une
  séance composée par le coach peut poser ses variantes maison. Le calendrier, en
  revanche, ne se partage pas.
- **Piège de test** : `KernelBrowser` ne redémarre le noyau qu'à partir de la
  **deuxième** requête. Une mesure de requêtes SQL faite sur la première compte
  aussi les `INSERT` des fixtures (991 au lieu de 16). Une sonde `/api/ping`
  intercalée force le redémarrage. Même famille que le piège `loginUser()` de
  KL-11.
- **Mesure** : 200 exercices, 15 séances de 5 exercices sur la fenêtre, réalisé
  sur tout le passé → **80,6 Ko, 16 requêtes SQL, 106 ms** (profileur actif). Le
  test garde la taille et le **nombre de requêtes**, pas le chronomètre : en CI un
  chronomètre mesure la machine, un compteur de requêtes mesure le code.

### KL-15 — `GET /api/schedule/{uuid}`

**Où** : `src/Controller/Api/ScheduleController.php`, `src/Http/ApiJson.php`

**Fini quand** :
- [x] Le prescrit à plat d'une séance datée, via `PlanFlattener`, plus son
      réalisé s'il existe
- [x] Résolution par `uuid`, pas par `id` (le client ne connaît que l'uuid pour
      ce qu'il a créé lui-même)
- [x] Une séance datée sans `workout` renvoie un prescrit vide, pas une erreur
- [x] `ScheduledWorkoutVoter::VIEW` appliqué
- [x] Structure identique à celle du bootstrap (le client n'a qu'un seul
      désérialiseur à écrire)

**Ce qui a été tranché en écrivant le ticket** :

- **« Structure identique » se teste en comparant les deux corps entiers**, pas
  quelques clés. `ScheduledWorkoutPayload` (KL-14) est le producteur unique, mais
  rien n'empêchait le contrôleur d'ajouter un champ « juste pour cet endpoint » ;
  le test compare la réponse du `GET` à l'entrée correspondante du bootstrap et
  échoue au premier écart, d'où qu'il vienne.
- **Introuvable rend 404, refusé rend 403** — et pas le 404 uniforme de
  `GET /pairing/{id}/status` (KL-47) ou de la révocation d'appareil (KL-12). La
  clé n'est pas de même nature : là-bas un identifiant séquentiel qu'un tiers
  énumère en trois lignes, ici un UUID posé par le client. Il n'y a pas d'oracle
  à fermer, et le 403 dit au coach dont la relation vient d'être rompue ce qui
  lui arrive, là où un 404 lui ferait croire à une séance disparue.
- **Les deux requêtes du bootstrap deviennent une définition partagée.**
  `withPrescribed()` / `withLog()` sur le repository servent la fenêtre **et**
  l'unité : deux écritures de « avec tout son contenu » auraient divergé, et la
  divergence ne se serait pas vue en erreur mais en N+1.
- **`ApiJson` naît ici**, pendant d'`ApiProblem` : un seul endroit pose
  `JSON_UNESCAPED_UNICODE`. L'oublier ne casse rien de visible, ça gonfle
  simplement la réponse de six octets par caractère accentué — exactement le
  genre d'oubli qu'on prévient par la structure. Au passage, `IsoDate` extrait le
  garde-fou de forme que `?since` portait seul (KL-14) : « ce que l'API accepte
  comme date » n'a plus qu'une définition.

### KL-16 — `PUT /api/schedule/{uuid}` idempotent

**Où** : `src/Controller/Api/ScheduleController.php`, `src/Service/LogIngestor.php`,
`src/Http/ScheduledWorkoutInput.php` (+ `LoggedExerciseInput`, `LoggedSetInput`)

**Quoi** : l'app envoie **la séance datée complète avec son réalisé** en un
document, pas série par série. Un seul endpoint couvre les deux cas : la séance
programmée qu'on remplit, et la séance libre que le téléphone crée de toutes
pièces.

**Fini quand** :
- [x] `PUT /api/schedule/{uuid}` fait un **upsert** : la séance datée est créée
      si l'`uuid` est inconnu, mise à jour sinon
- [x] **Idempotent** : un même document rejoué ne crée rien de nouveau et renvoie
      200 avec l'état persisté
- [x] Un document déjà connu avec un contenu différent **écrase le réalisé** (le
      téléphone fait autorité, cf. §0.3 point 1). Il n'écrase **jamais** le
      prescrit, ni `sourcePlanItem`, ni `planAnchorDate`
- [x] `DELETE /api/schedule/{uuid}` refuse une séance issue d'un plan (elle se
      retire depuis le web) et n'accepte que les séances libres
- [x] Clôture → `ScheduledWorkout::setStatus(DONE)`
- [x] `exerciseName` renseigné côté serveur si le client ne l'a pas envoyé
- [x] Validation stricte : un poids négatif, 400 reps ou un `setType` inconnu
      sont refusés en 422
- [x] Toute l'ingestion dans **une transaction**
- [x] L'attribut `LOG` de KL-06 est testé, pas `EDIT` : un coach n'écrit jamais
      le réalisé de son athlète
- [x] Une séance libre créée par le téléphone arrive avec `workout = null` et un
      `title`. Le serveur ne crée **aucun** `Workout` en bibliothèque

**Ce qui a été tranché en écrivant le ticket** :

- **Le partage d'autorité est plus fin que « le téléphone gagne ».** §0.3 point 1
  dit « le mobile est la seule source d'écriture du **réalisé** » — pas du
  planning. Le document écrase donc `log`, `startedAt` et `endedAt`, mais `date`
  et `title` ne servent qu'à la **création** : déplacer une séance est un geste de
  programmation (`EDIT`, ouvert au coach), et un téléphone resté trois jours hors
  réseau ramènerait sinon à son ancienne date la séance que le coach vient de
  décaler. `status` ne peut que **clôturer** — les autres valeurs sont acceptées
  sans effet, pour qu'un client qui renvoie le document reçu ne se prenne pas un
  422 sur un `planned` recopié, mais rien ne *déclôture* (§2.3 point 5).
  `completionNotes` s'écrit si le document en porte une et **n'efface jamais**
  celle qui existe : le silence du téléphone n'est pas un ordre d'effacer la note
  d'écart du coach.
- **Le remplacement du réalisé se fait en DEUX `flush()`, et c'est structurel.**
  Doctrine ordonne un flush en insertions, puis mises à jour, puis suppressions :
  effacer et réécrire la même série dans un seul flush enverrait l'`INSERT` avant
  le `DELETE`, donc une violation de `uniq_logged_set_uuid` — une 500 sur le cas
  le plus normal du ticket, un document rejoué. L'alternative « réconcilier les
  lignes par uuid » est pire : déplacer une série d'un `LoggedExercise` à un autre
  la ferait passer par le `deleteDiff` d'une collection en `orphanRemoval`, qui la
  programme pour suppression même si on l'ajoute ensuite ailleurs. Perte de
  données silencieuse sur un chemin rare. On efface, on flush, on réécrit, le tout
  dans une transaction — et l'invariant devient relisible : après l'appel, le
  réalisé **est** le document.
- **`position` n'est pas un champ d'entrée.** L'ordre de la liste fait foi, le
  serveur renumérote. Un rang envoyé à côté de l'ordre du tableau, ce sont deux
  sources pour un seul fait.
- **Les références sont vérifiées avant d'écrire, et refusées en 422 avec le
  chemin du champ.** Un `exerciseId` invisible (portée d'`ExerciseVoter::VIEW`,
  pas une requête maison) et un `sourcePrescribedId` qui désigne la ligne du
  programme d'une **autre** séance sont des erreurs. Les rattacher silencieusement
  à `null` serait pire que l'erreur : le réalisé resterait lisible, mais il
  sortirait de l'historique et des records sans que rien ne le signale. Inconnu et
  interdit rendent la même violation — distinguer ferait de l'API un oracle sur la
  bibliothèque des autres.
- **Un `uuid` de série emprunté à une autre séance rend 409, pas 422.** Le
  document n'est pas malformé, il entre en conflit avec un état existant : le
  client doit régénérer l'identifiant, pas corriger un champ. Sans ce contrôle, le
  cas sortirait en 500 par violation d'unicité.
- **`DELETE` n'accepte que les séances vraiment libres**, et le teste sur trois
  colonnes : `workout`, `sourcePlanTemplate` et `sourcePlanItem`. Une séance de
  plan dont la séance source a été supprimée en bibliothèque a `workout = null`
  sans être libre pour autant. Le refus est un **409** : ce n'est pas une question
  de droit — le propriétaire l'a — c'est l'état de la ressource qui rend le geste
  impossible ici.
- **201 à la création, 200 ensuite.** Rejouer sa file de mutations dit au client
  lesquelles étaient déjà passées, et le corps est identique dans les deux cas.
- **Les violations « faites à la main » empruntent la route de KL-13.** Une
  `ValidationFailedException` levée par le service ressort en 422 avec sa liste de
  champs, exactement comme celles d'un attribut de validation : le client n'a
  qu'un format d'erreur à lire. C'est ce que la recherche dans toute la chaîne des
  causes, écrite en KL-13, rend possible.

### KL-17 — `GET /api/exercises/{id}/history`

**Où** : `src/Controller/Api/ExerciseController.php`,
`src/Service/PerformanceHistoryPayload.php`, `src/Service/PerformanceHistory.php`

**Fini quand** :
- [x] Dernière performance, record, et les 10 dernières séances sur cet exercice
- [x] Consomme `PerformanceHistory`, ne requête pas en direct

**Ce qui a été tranché en écrivant le ticket** :

- **Le ticket a ajouté une lecture à `PerformanceHistory`, pas un contournement.**
  Le service savait dire « la dernière fois » et « le record », pas « les dix
  dernières fois » : `recentSessions()` est écrit **dans** le service, sur le
  même périmètre que les deux autres (échauffement exclu, exercice sauté exclu,
  statut de la séance non filtré, portée du seul utilisateur demandé). Trois
  chiffres lus sur trois définitions différentes de « ce qui compte » ne se
  comparent pas — c'est le sens de « ne requête pas en direct ».
- **Deux requêtes, et bornées toutes les deux.** L'historique d'un exercice
  grossit sans limite : ramener toutes ses séries pour n'en garder que dix
  séances marcherait la première année. On borne d'abord les **séances**
  (`setMaxResults` sur des lignes distinctes), puis on lit les séries de
  celles-là. Un test compte les requêtes, comme pour `bulkFor()` (KL-04).
- **`last` est dérivé de `sessions[0]`, pas relu.** C'est la même chose lue par
  la même requête ; le déduire supprime une lecture **et** la possibilité que les
  deux se contredisent. Le champ reste exposé parce que le client l'a déjà dans
  son bootstrap : le retirer l'obligerait à traiter la fiche d'exercice
  autrement que le reste. Un test unitaire fige l'égalité avec
  `lastPerformance()`.
- **La mise en forme d'une performance devient `PerformanceHistoryPayload`.**
  `BootstrapPayload` la portait ; l'endpoint l'aurait réécrite, et deux écritures
  de « à quoi ressemble une dernière perf » n'auraient divergé qu'un jour, en
  silence, sur un client qui n'a qu'un désérialiseur. Même raison d'être que
  `ScheduledWorkoutPayload` (KL-14) : un seul producteur par structure. Le corps
  de l'endpoint est, au champ `sessions` près, une entrée du tableau `history` du
  bootstrap — et un test compare les deux sous-documents entiers.
- **Introuvable et invisible rendent le MÊME 404**, contrairement à
  `GET /api/schedule/{uuid}` qui distingue 404 et 403. Ce n'est pas la règle
  inverse mais la même règle appliquée : ce qui décide, c'est la **nature de la
  clé**. Un `uuid` posé par le client ne se devine pas ; un identifiant
  séquentiel d'exercice s'énumère en trois lignes, et un 403 y dirait la taille
  et la composition de la bibliothèque perso des autres, exercice par exercice.
  La distinction ne manquerait à personne : le téléphone ne demande l'historique
  que d'un exercice reçu au bootstrap, donc visible.
- **La portée de lecture et la portée de l'historique ne sont pas la même
  chose.** `ExerciseVoter::VIEW` est symétrique (le coach ouvre la fiche de la
  variante maison de son athlète), mais `PerformanceHistory` ne lit que le
  réalisé du **porteur du jeton** : un coach qui ouvre cette fiche voit sa propre
  trajectoire, pas celle de son athlète. Lire le réalisé d'un athlète a son
  endroit — `GET /api/schedule/{uuid}` — où la séance dit de qui elle parle.
- **Aucun identifiant de séance dans la charge utile.** Une séance datée
  s'adresse par son `uuid` partout ailleurs, et l'historique n'a pas vocation à
  en ouvrir une : c'est une trajectoire, une suite de points datés. Deux séances
  du même jour restent deux entrées, départagées par leur rang.
- **C'est le seul écran mobile qui suppose du réseau**, et c'est assumé : le
  bootstrap descend déjà le dernier point et le record de toute la bibliothèque
  (ce que KL-32 affiche en séance, hors réseau). Descendre dix séances par
  exercice pour un écran qu'on ouvre rarement ferait grossir une réponse bornée à
  1 Mo. Consulter une progression n'est pas dérouler une séance.

### KL-18 — Tests fonctionnels de l'API

**Où** : `tests/Controller/ApiEndpointMatrixTest.php`

**Fini quand** :
- [x] Un test par endpoint : cas nominal, non authentifié, token expiré, token
      révoqué, ressource d'un autre utilisateur
- [x] **Un test d'idempotence** : le même document envoyé trois fois donne une
      seule séance datée et un seul jeu de séries
- [x] Un test vérifiant qu'un `PUT` n'écrase jamais le prescrit ni le
      rattachement au plan de la séance datée visée
- [x] **Un test d'appairage** : un code consommé deux fois échoue la seconde
      fois, un code expiré échoue, et un code émis par un utilisateur ne crée
      jamais un token pour un autre
- [x] Un test vérifiant qu'aucune réponse d'API ne contient `notes` de `Workout`
- [x] Un test vérifiant qu'aucune requête `^/api` ne pose de cookie de session

**Ce que le ticket pose et qu'il ne faut pas casser** :

- **Ce fichier ne teste rien de particulier, et c'est son rôle.** Chaque endpoint
  avait déjà le sien (bootstrap, schedule, historique, auth, appairage) ; les
  quatre cases restantes — être authentifié, ne pas ouvrir de session, ne pas
  laisser fuiter le bloc-notes — ne se vérifient utilement que sur la **liste
  entière**. D'où un fournisseur de données unique (`endpoints()`) plutôt que
  huit tests copiés : un endpoint ajouté demain s'écrit une fois et se retrouve
  aussitôt soumis aux quatre gardes.
- **Le seul trou possible est un endpoint absent de la liste**, et il se voit à
  la lecture. Compromis assumé : dériver la liste des routes ne dispenserait pas
  de fabriquer une ressource valide pour chacune.
- **La sentinelle du bloc-notes est en ASCII** (`SENTINELLE-BLOC-NOTES-PRIVE`).
  `AuthController` rend ses réponses par `$this->json()`, sans
  `JSON_UNESCAPED_UNICODE` : une note accentuée sortirait échappée en `\uXXXX`
  et `assertStringNotContainsString` passerait sur une **vraie** fuite. Chercher
  une chaîne sans accent, c'est la chercher quel que soit l'échappement.
- **Le nominal est testé en même temps que les trois refus.** Sans lui, un
  endpoint cassé rendrait 401 partout et passerait toute la matrice.
- **`WWW-Authenticate` n'est exigé que sur les routes gardées par
  `access_control`.** `POST /api/auth/logout` est sous `^/api/auth`, donc
  public pour le pare-feu : sa garde vit dans le contrôleur, et il formule son
  401 lui-même. D'où la colonne `byFirewall` du fournisseur.
- **Le cookie se vérifie sur les DEUX issues**, refus et succès : une réponse
  d'erreur traverse un autre chemin (entry point, `ApiExceptionListener`), et
  c'est exactement là qu'une session pourrait s'ouvrir sans qu'on la voie.
- **La révocation se teste par le vrai geste** (`POST /api/auth/logout`), pas en
  effaçant la ligne à la main : c'est le chemin que le mobile emprunte.
- Piège hérité : le limiteur vit dans un pool de cache **sur disque**, à vider au
  `setUp` ; et ce fichier **nettoie en `tearDown`**, comme `ApiBootstrapTest`.

### KL-19 — `docs/api-mobile.md`

**Fini quand** :
- [x] Chaque endpoint documenté : méthode, charge utile, réponse, codes d'erreur
- [x] Le protocole de synchronisation décrit noir sur blanc (qui fait autorité
      sur quoi, comment les conflits sont tranchés)
- [x] Le protocole d'appairage décrit de bout en bout, avec le format exact de
      la charge utile du QR
- [x] Un exemple `curl` complet par endpoint, réellement exécuté

**Ce que le ticket pose et qu'il ne faut pas casser** :

- **Le document dit le *quoi*, jamais le *pourquoi*.** Le raisonnement vit ici et
  dans `CLAUDE.md` ; le recopier en ferait une seconde source à tenir à jour, qui
  divergerait. `docs/api-mobile.md` renvoie aux deux et s'en tient au contrat.
- **Le partage d'autorité est un tableau champ par champ**, pas un paragraphe :
  c'est la question que le client se pose à chaque ligne de code de synchro.
- **Un `curl` par endpoint, exécuté, réponses collées telles quelles.** Une doc
  d'API dont les exemples n'ont jamais tourné se trompe toujours quelque part —
  ici, l'exécution a fait sortir une limite que personne n'avait vue (ci-dessous).
- **Limite trouvée en exécutant : les horodatages à décalage non nul perdent
  leur fuseau.** `2026-08-02T18:04:00+02:00` est relu `…T18:04:00+00:00` — l'heure
  murale est conservée, le décalage jeté, donc l'instant absolu est faux de deux
  heures (les durées, elles, restent justes). Cause : le décalage n'est pas
  normalisé avant persistance et Doctrine écrit l'heure telle que l'objet la
  porte. **Contournement documenté et vérifié : envoyer tout en UTC (`…Z`).** Le
  correctif change le comportement d'un endpoint livré (KL-16) : il relève d'un
  ticket à part, pas de la documentation.
- **Le tableau final renvoie chaque garde à son fichier de test.** Une
  affirmation de doc qui n'est adossée à rien finit par mentir.

### KL-20 — Export des tokens de design

**Où** : `src/Command/ExportDesignTokensCommand.php`

**Quoi** : les tokens vivent dans `assets/styles/tokens.css`, que React Native ne
sait pas lire. Plutôt que de les recopier à la main dans le repo mobile et de
les laisser diverger, on les publie.

**Fini quand** :
- [x] `php bin/console app:tokens:export` lit `tokens.css` et écrit
      `public/design-tokens.json` (primitives `--kd-*` et tokens sémantiques)
- [x] La commande tourne dans le workflow de build, le fichier est servi sur
      `kadens.antoninpamart.fr/design-tokens.json`
- [x] Un test qui échoue si un token sémantique du CSS n'est pas dans le JSON
- [x] `tools/fetch-fonts.sh` produit aussi les `.ttf` de Barlow et Barlow
      Condensed (React Native ne lit pas le `woff2`)

**Ce qui a été tranché en écrivant le ticket** :

- **Les `var()` sont résolues, et rien d'autre ne l'est.** Un consommateur natif
  ne sait pas suivre une référence : `--color-bg` doit valoir `#dcdcd7`, pas
  `var(--kd-page)`. Mais la commande ne **traduit** pas — une pile de polices
  reste une pile de polices, `--color-scrim` reste un `color-mix()`. Traduire ici
  reviendrait à écrire un moteur CSS partiel en PHP, dont chaque cas non couvert
  serait une valeur fausse et silencieuse ; la fidélité à la source est ce qui
  rend l'export **vérifiable**, et l'adaptation aux API natives est précisément
  le travail de `src/theme/tokens.ts` (KL-22).
- **Une `var()` qui ne se résout pas fait échouer la commande**, cycle compris.
  Un token qui pointe un nom inexistant est une faute de frappe dans
  `tokens.css` : elle doit sortir au build, pas sur un téléphone où la couleur
  manquerait sans rien dire.
- **Le JSON est versionné, et un test le compare à la feuille.** C'est la
  convention déjà tenue par `assets/styles/fonts.css` et
  `_pwa_splash.html.twig` : le fichier est généré, jamais édité à la main, et un
  test échoue quand il a divergé. Le « fini quand » demandait qu'un token
  sémantique absent du JSON casse la suite — le test compare **les documents
  entiers**, donc un token ajouté, renommé, ou dont la valeur a bougé, échoue de
  la même façon. Le rendu est déterministe (aucun horodatage), sans quoi cette
  comparaison serait impossible. La commande tourne quand même dans le workflow
  de build : ce qui part en prod est alors produit par la source.
- **On ne lit que les blocs `:root`.** Une propriété personnalisée posée sur un
  sélecteur de composant est une variable **locale** ; l'exporter donnerait au
  mobile une valeur qui n'a de sens nulle part ailleurs. C'est aussi ce qui
  laisse `--kd-navbar-h` (qui n'existe que sous 560px) hors du champ.
- **Les clés gardent leurs deux tirets** (`"--color-bg"`), et les deux couches
  restent séparées (`primitives` / `semantic`). La séparation n'est pas
  décorative : c'est la règle 1 du design system — une vue ne consomme jamais une
  primitive — et un `tokens.ts` qui les aplatirait la ferait disparaître.
- **Les `.ttf` sont publiés dans `public/fonts/`**, pas rangés à côté des woff2 :
  dans `assets/`, AssetMapper les compilerait en URL digestées, c'est-à-dire un
  méga-octet embarqué en prod pour des fichiers que le web ne demande jamais et
  que le mobile ne saurait pas trouver. Ils ne sont pas subsettés — Google ne
  sert le ttf qu'entier, et un téléphone n'a pas de budget de première peinture.
- **IBM Plex Mono est du lot**, alors que le ticket ne nomme que les deux Barlow.
  KL-22 charge les **trois** familles par `expo-font` : en livrer deux aurait
  garanti un ticket de rattrapage.

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
