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
> _tous_ les endpoints doivent tenir sont désormais tenues au même endroit
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
> **KL-21 livré (03/08/2026)** : le dépôt `kadens-mobile` existe — Expo SDK 57 en
> TypeScript avec `expo-router`, ESLint + Prettier, `app.json` à l'identité
> Kadens (`fr.antoninpamart.kadens`, portrait, `light`, icônes reprises de
> `public/pwa/`), `android/` non versionné, `.env.example` et un README qui
> rappelle l'IP LAN. Le boilerplate du template est retiré pour ne pas laisser un
> thème concurrent de celui que KL-22 va générer.
> **KL-22 livré (03/08/2026)** : le socle de design natif. `npm run sync:tokens`
> traduit `design-tokens.json` en `src/theme/tokens.ts` (généré, versionné,
> jamais édité), `npm run sync:fonts` rapatrie les onze `.ttf` dans
> `assets/fonts/`, et l'échelle typographique — la seule chose que les tokens ne
> portent pas — vit à la main dans `src/theme/typography.ts`, où la règle 4
> sépare les rôles de **structure** (condensé capitales) des rôles de
> **contenu** (Barlow, casse normale). Les polices sont chargées par `useFonts`
> derrière l'écran de démarrage, une graisse = une police enregistrée.
> **KL-23 livré (03/08/2026)** : les huit composants de base (`Button`, `Card`,
> `Chip`, `Field`, `NumberStepper`, `Sheet`, `Header`, `EmptyState`) dans
> `src/components/`, tous peints aux seuls tokens et tous plafonnés au plancher
> tactile de 44 points, désormais nommé une fois pour toutes dans
> `src/theme/layout.ts`. Le `NumberStepper` est le composant du ticket : pas de
> 2,5 kg, répétition à l'appui long, saisie directe en brouillon local (donc
> « 82, » se tape), virgule décimale acceptée et rendue. Pas d'icônes : le
> projet n'embarque pas encore de jeu de glyphes et la question se tranchera
> quand un écran en aura besoin.
> **KL-24 livré (03/08/2026)** : la base locale existe. `expo-sqlite` + Drizzle,
> huit tables dans `src/db/schema.ts` — le prescrit stocké **en un document**
> (`prescribed_snapshot`, remplacé en entier à chaque pull, puisque le mobile ne
> recompose pas), le réalisé **normalisé** (`logged_exercise` / `logged_set`,
> seule partie que le téléphone écrit), plus `exercise_history` (le bootstrap
> descend la dernière perf et le record pour qu'ils s'affichent **hors ligne** :
> sans table, la réponse serait lue puis jetée), `sync_state` (une ligne,
> garantie par un `CHECK`, qui porte aussi l'URL d'API du QR) et
> `mutation_queue`. Migrations générées par `drizzle-kit` dans
> `src/db/migrations/` (versionnées, jamais éditées) et appliquées au démarrage
> par `useMigrations`. Les UUIDv7 sont posés localement, avec compteur monotone
> dans la milliseconde. Un jeu de démonstration `seedDemo()`, gardé par `__DEV__`.
> **KL-25 livré (03/08/2026)** : le client API, dans `src/api/`. Un `request()`
> unique porte le timeout, le rejeu à backoff exponentiel — **réservé aux
> méthodes idempotentes**, un `POST` rejoué émettrait un second jeton — et la
> purge sur `401`. Le jeton vit dans `expo-secure-store`, jamais en base ni dans
> `AsyncStorage` ; la session est un magasin de module (le transport et le futur
> moteur de synchronisation le lisent hors de React) et le garde
> `Stack.Protected` du layout racine fait retomber la pile sur `login` dès
> qu'elle se ferme. Le nom d'appareil vient d'`expo-device`. Vérifié contre le
> **vrai serveur** : 15 scénarios de bout en bout, plus 21 sur le transport.
> **KL-26 livré (03/08/2026)** : l'écran de connexion, dans `src/app/`. Trois
> chemins hiérarchisés — « Scanner le QR » en primaire, « Saisir le code » en
> secondaire, « Email et mot de passe » en dernier repli, sans lien
> d'inscription — et un **quatrième état de session** (`awaitingFirstSync`) qui
> retient l'app sur un écran « Récupération de tes séances » entre un
> `login`/`pair` frais et son premier `GET /api/bootstrap`. Une session
> **restaurée** saute cet écran (elle a déjà un dernier pull en base) : c'est ce
> qui garde le hors-ligne intact au lancement normal. « Scanner le QR » et
> « Saisir le code » menaient jusqu'ici au même écran de saisie manuelle.
> **KL-48 livré (03/08/2026)** : `pairing.tsx` gagne le lecteur de QR
> (`expo-camera`), permission demandée après explication (un refus définitif
> renvoie vers les réglages Android), URL de serveur configurée au scan et
> **remise en place** si le serveur scanné ne répond pas du tout, saisie
> manuelle du code en repli inchangée.
> **KL-27 livré (03/08/2026) — le lot 3 est clos.** Le moteur de synchronisation
> vit dans `src/sync/` : un cycle **push puis pull**, un seul à la fois, qui ne
> lève jamais et ne retient aucun écran. Le pull applique le bootstrap en **une**
> transaction (bibliothèque en delta, fenêtre qui fait autorité, purge de ce
> qu'elle ne contient plus, `sync_state` avancé dans le même geste) ; le push
> dépile `mutation_queue` en FIFO, une mutation à la fois, en relisant le document
> **au moment de l'envoi**. Ce qui n'est pas confirmé par le serveur est
> intouchable : une séance qui a une mutation en file, ou qui est commencée et pas
> terminée, garde son réalisé, ses bornes, son statut et sa note — seule la
> programmation descend. Le compteur d'échecs ne compte que les **refus du
> serveur** (un réseau absent n'est pas une panne, §KL-27), et une mutation
> marquée reste en file, donc continue de protéger sa séance. Déclenchée au
> lancement, au retour au premier plan, au retour du réseau (`expo-network`) et à
> la clôture d'une séance. Vérifié par 55 contrôles contre le vrai Symfony, hors
> React Native.
> **KL-28 livré (04/08/2026) — le lot 4 est ouvert.** L'écran « Aujourd'hui »
> montre enfin des séances : le jour, ses voisins J-2 à J+2, la reprise d'une
> séance ouverte quel que soit son jour, et « Séance libre » dans une barre qui ne
> défile pas. Le ticket a fait naître `src/session/`, où vivent désormais les
> règles de séance que ni la base ni la synchro ne portaient — c'est là que KL-29
> à KL-33 viendront poser cocher, dévier et clôturer. Décision structurante :
> **ouvrir une séance n'empile aucune mutation** (le pull la protège déjà par
> « commencée et pas terminée »), et rattraper la veille ne **déplace** jamais une
> séance : le serveur reste l'autorité sur sa date.
> **KL-29 livré (04/08/2026)** : l'écran Séance en cours. Le prescrit se déroule
> bloc par bloc, les rangs de superset dérivés du préfixe **et** de la contiguïté,
> chaque série est une ligne cochable pré-remplie, et cocher écrit son `LoggedSet`
> **et** empile la mutation dans la même transaction — dix séries, un seul envoi.
> La décision structurante est l'**appariement par rang, en deux files
> (échauffement, travail)** : le contrat ne relie pas une série réalisée à sa ligne
> prescrite, la seule règle possible est celle que `LogComparator` tient déjà, et
> elle rend le cochage **séquentiel**. Le cardio se coche fait / pas fait, sans
> saisie.
> **KL-30 livré (04/08/2026)** : les déviations. On corrige une série, on en
> ajoute, on en supprime, on saute un exercice avec sa raison, on le remplace, on
> en ajoute un hors programme — et rien d'autre : aucun bloc réordonné, aucun
> superset créé, aucun tour modifié. La décision structurante est que **le
> prescrit n'ayant nulle part où accueillir une valeur revue, on ne dévie que sur
> ce qui a été fait** : on coche aux valeurs prescrites, puis on corrige, la ligne
> cochée offrant deux cibles (les valeurs ouvrent la feuille d'ajustement, la case
> décoche). Le remplacement conserve le lien au programme et compte comme une
> **déclaration**, donc survit au retrait de sa dernière série. Le hors-programme
> cesse d'être en lecture seule : `SessionExtra` disparaît au profit d'un type
> d'exercice unique. Vérifié par 123 contrôles hors React Native et 23 contre le
> vrai Symfony.
> **KL-31 livré (04/08/2026)** : le repos, la veille et la notification. Cocher
> une série démarre un décompte qui vit **en mémoire** — le repos n'est pas du
> réalisé, il ne va ni en base ni au serveur — dont l'échéance est un instant
> absolu et non un compteur, seule forme qui survit à la suspension de la boucle
> JS en arrière-plan. La notification est programmée dès le début du repos et
> c'est le gestionnaire global qui la tait si l'app est au premier plan à
> l'échéance ; la vibration se coupe par un **second canal Android**, un canal
> étant figé après sa création. L'écran reste allumé tant que la séance est en
> cours, et seulement là. Les réglages (durée par défaut, vibration) vivent dans
> une table locale `preference` que la purge de synchronisation n'emporte pas.
> Vérifié par 62 contrôles hors React Native.
> **KL-32 livré (04/08/2026)** : l'historique en séance. Sous le nom de chaque
> exercice, « Dernière fois » et « Record », **lus en local** dans
> `exercise_history` que le pull réécrit à chaque bootstrap — donc disponibles
> hors réseau, et jamais recalculés sur le téléphone. Deux lignes au plus, une
> seule quand il n'y a pas de record (poids du corps), aucune quand l'exercice n'a
> jamais été fait : rien ne se rend plutôt qu'un cadre vide. La décision
> structurante est que **l'historique suit le réalisé, pas le prescrit** — un
> exercice remplacé en séance se lit contre l'historique de ce qu'on fait
> vraiment. Vérifié par 27 contrôles hors React Native.
> **KL-33 livré (04/08/2026)** : la clôture. Un écran dédié (`session/[uuid]/close`)
> montre durée, tonnage, séries faites et **écarts au prescrit**, prend une note
> libre, puis clôture — `ended_at` posé, statut `done`, mutation empilée dans la
> même transaction et synchronisation déclenchée dans la foulée. Deux décisions
> structurantes : le résumé se **recalcule en local**, avec la cascade d'axes de
> `LogComparator` et le périmètre de `LogMetrics`, parce qu'un écran de fin de
> séance qui attendrait le serveur serait vide dans un sous-sol ; et **l'abandon
> n'écrit rien** — on remonte, la séance reste ouverte et reprenable, donc le
> retour s'appelle « Reprendre la séance » plutôt qu'« Abandonner ». Clôturer est
> du réalisé (contrairement à ouvrir, KL-28), et c'est terminal : rien ne
> déclôture, ni sur le téléphone ni au serveur. Vérifié par 56 contrôles hors
> React Native.
> **KL-34 livré (04/08/2026)** : la séance vierge. Le ticket arrivait à moitié
> fait — `createFreeWorkout` (KL-28) posait déjà la séance datée du jour, sans
> `workout`, avec son uuid client et son titre daté ; `addExercise` (KL-30) savait
> déjà la garnir. Ce qui manquait était les **facettes** de la bibliothèque locale,
> activité et zone, et c'est là que sont les décisions : une facette **décrit ce
> que la bibliothèque porte**, pas l'enum (proposer « Natation » à qui n'en a
> aucune offre un filtre dont la seule issue est le vide) ; **facette et frappe ne
> répondent pas à la même question** — le nom se tape, l'activité et la zone se
> choisissent — donc les zones n'entrent pas dans le texte cherché, contrairement
> au web où elles sont le seul chemin faute de facette ; et la séance vierge
> **n'a aucun chemin d'écriture à elle**, tout ce qu'elle contient étant du réalisé
> ajouté par le geste de KL-30. `FilterChip` naît au passage, comme `Chip`
> l'annonçait : une marque de lecture ne se tape pas, une facette si. Vérifié par
> 49 contrôles hors React Native et une séance vierge poussée au vrai Symfony —
> `freeform: true`, `blocks: []`, hors plan, et zéro `Workout` en bibliothèque.
> **KL-35 livré (04/08/2026)** : l'écran Réglages, et la **disparition de l'écran
> de diagnostic** — compte, serveur et appareil, état de synchronisation, file
> d'envoi lisible séance par séance, réglages de repos, version. Trois décisions
> structurantes : la dernière synchronisation se lit dans `sync_state` et non dans
> le moteur, dont l'état repart vide à chaque lancement ; **se déconnecter efface
> la base locale mais pas l'URL du serveur**, qui vient de l'appairage et non du
> compte ; et **« tout resynchroniser » ne purge qu'après une synchronisation
> avérée** — cycle réussi, échange réellement eu lieu, file vide — la
> reconstruction étant un cycle complet de plus, rendu exhaustif par le seul fait
> que la purge remet `serverTime` à null. Vérifié par 19 contrôles hors React
> Native sur ces gardes.
> **KL-36 livré (04/08/2026)** : les tests mobile, et une CI qui les fait tourner
> à chaque poussée — 92 contrôles en 10 suites (`jest-expo/android`,
> `@testing-library/react-native`), typage, lint et format compris. La décision
> structurante est qu'**`expo-sqlite` est remplacé par `node:sqlite` et non par un
> bouchon** : ce que ces tests vérifient _est_ du SQLite (transaction repliée,
> cascade, `AUTOINCREMENT` qui ne réattribue rien), et le schéma vient des
> migrations du dépôt plutôt que d'une copie qui dériverait. Deuxième décision :
> **seul `fetch` est bouchonné**, donc tout `src/api` reste dans la boucle
> (timeout, rejeu, `201` contre `200`, taxonomie d'erreurs) — et le `fetch` par
> défaut **échoue en nommant l'appel**, ce qui prouve qu'une écriture de réalisé
> ne part jamais sur le réseau. Le parcours entier est couvert de bout en bout :
> séance programmée, faite hors réseau, déviée, clôturée, puis synchronisée sans
> rien perdre.
> **KL-37 livré (05/08/2026)** : la passe design. Les tokens étaient déjà tenus
> depuis KL-22 — aucun écran ne portait de couleur en dur — donc le ticket était
> ailleurs. Trois décisions structurantes : les **visuels Android sortent du dépôt
> web** (`public/pwa/android/`, `npm run sync:icons`), dans le sens que
> `public/fonts/*.ttf` avait déjà tracé, et depuis une **marque à eux** — la
> variante rouge et noire, pour que l'app ne se confonde pas avec le site sur un
> écran d'accueil ; la **barre basse transpose la forme du web, pas ses
> destinations** —
> Aujourd'hui, Historique, Réglages, l'historique étant né avec elle parce que la
> bande de jours s'arrête à J-2 ; et les **zones sûres ont deux rendus qui ne se
> cumulent jamais**, une barre peinte prenant l'inset en rembourrage là où une
> page qui défile l'ajoute à son dégagement. Au passage, l'échec se dit
> `statusMissed` et non `primary` (même valeur, sens différent) et les icônes
> Lucide sont figées en local comme côté web. Vérifié par 94 contrôles hors React
> Native ; le rendu reste à valider sur l'appareil.
> Prochain ticket : **KL-38** (états vides, erreurs, bandeau hors ligne).

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

`ROADMAP.md §1.5` et `CLAUDE.md §3` disent : _« Aucun log détaillé de séries
réalisées. Strava fait le suivi. »_ Et `ROADMAP.md` Phase 7 point 4 : _« Ne pas
ajouter de log de séries réalisées. La frontière est nette. »_

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

| Sujet         | Choix                                        | Pourquoi pas l'autre                                                                                                                                      |
| ------------- | -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| App           | Expo SDK + expo-router, TypeScript           | Capacitor resterait un webview, donc les travers reprochés à la PWA. Flutter ajouterait Dart à maintenir.                                                 |
| Base locale   | `expo-sqlite` + Drizzle ORM                  | WatermelonDB et PowerSync sont disproportionnés pour un client unique.                                                                                    |
| Sync          | File de mutations maison (~200 lignes)       | Voir ci-dessus.                                                                                                                                           |
| Auth API      | Token opaque en base (`ApiToken`)            | Le JWT n'apporte rien avec un seul client et une seule base, et impose des clés RSA à gérer sur du mutualisé. Le token opaque est révocable pour de vrai. |
| Connexion     | Appairage par QR depuis le desktop (§0.6)    | Le deep link « Se connecter avec Kadens » demande des App Links vérifiés, PKCE et un Custom Tab, pour un geste trimestriel.                               |
| Sérialisation | `symfony/serializer` (déjà installé) + DTO   | API Platform impose un modèle CRUD-ish qui colle mal, et contredit l'esprit « pas de surcouche » du projet.                                               |
| Build         | `expo prebuild` + Gradle dans GitHub Actions | EAS Build ajoute une dépendance à un service tiers pour un besoin que Gradle couvre.                                                                      |
| Distribution  | Dépôt F-Droid statique auto-hébergé          | Cohérent avec la philosophie du projet. Obtainium sur GitHub Releases reste le repli documenté.                                                           |

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

| Vue                  | Ce qu'on y voit                                              | Ticket |
| -------------------- | ------------------------------------------------------------ | ------ |
| `/schedule/{id}`     | La séance datée : prévu et réalisé **côte à côte**, en place | KL-07  |
| Le calendrier        | L'état de chaque séance, et les séances libres « hors plan » | KL-08  |
| `plan_template/show` | Le réalisé **superposé** à la courbe de progression prévue   | KL-49  |
| `/exercise/{id}`     | La trajectoire réelle sur un exercice, tous plans confondus  | KL-50  |
| `/exercise`          | Le tri de la bibliothèque par usage réel                     | KL-51  |

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
`/schedule/{id}` : _« la seule page qui porte la boucle prévu vs réalisé »_.

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
   dit _« La séance datée n'a pas de sens sans sa séance source »_ : ça devient
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

| #     | Ticket                                               | Lot | Taille | Dépend de                  |
| ----- | ---------------------------------------------------- | --- | ------ | -------------------------- |
| KL-01 | Acter la révision de la règle §1.5                   | 0   | S      | —                          |
| KL-02 | Entités du réalisé + migration de `ScheduledWorkout` | 1   | M      | KL-01                      |
| KL-03 | `LogMetrics`                                         | 1   | M      | KL-02                      |
| KL-04 | `PerformanceHistory` (dernière perf + record)        | 1   | M      | KL-02                      |
| KL-05 | `LogComparator` (écart prévu vs réalisé)             | 1   | M      | KL-02, KL-03               |
| KL-06 | Garde d'écriture sur `ScheduledWorkoutVoter`         | 1   | S      | KL-02                      |
| KL-07 | Affichage du réalisé sur `/schedule/{id}`            | 1   | L      | KL-05, KL-06               |
| KL-08 | Séance datée sans source au calendrier               | 1   | S      | KL-07                      |
| KL-09 | Tests du lot 1                                       | 1   | M      | KL-08                      |
| KL-10 | Entité `ApiToken` + authenticator + firewall         | 2   | M      | KL-01                      |
| KL-11 | Endpoints d'authentification (mot de passe, repli)   | 2   | S      | KL-10                      |
| KL-46 | Appairage : entité `PairingCode` + endpoints         | 2   | M      | KL-10                      |
| KL-47 | Page QR d'appairage sur le desktop                   | 2   | M      | KL-46                      |
| KL-12 | Gestion des appareils dans `/profile/settings`       | 2   | M      | KL-10                      |
| KL-13 | Réponses d'erreur normalisées + limitation de débit  | 2   | M      | KL-10                      |
| KL-14 | `GET /api/bootstrap`                                 | 2   | L      | KL-11, KL-04               |
| KL-15 | `GET /api/schedule/{uuid}`                           | 2   | M      | KL-11                      |
| KL-16 | `PUT /api/schedule/{uuid}` idempotent                | 2   | L      | KL-11, KL-02               |
| KL-17 | `GET /api/exercises/{id}/history`                    | 2   | S      | KL-11, KL-04               |
| KL-18 | Tests fonctionnels de l'API                          | 2   | L      | KL-17                      |
| KL-19 | `docs/api-mobile.md`                                 | 2   | M      | KL-18                      |
| KL-20 | Export des tokens de design                          | 2   | S      | KL-01                      |
| KL-21 | Init du dépôt `kadens-mobile`                        | 3   | M      | —                          |
| KL-22 | Socle de design natif                                | 3   | L      | KL-21, KL-20               |
| KL-23 | Composants de base                                   | 3   | L      | KL-22                      |
| KL-24 | Couche SQLite + Drizzle                              | 3   | L      | KL-21                      |
| KL-25 | Client API + stockage sécurisé du token              | 3   | M      | KL-21, KL-11               |
| KL-26 | Écran de connexion                                   | 3   | M      | KL-25, KL-23               |
| KL-48 | Écran de scan du QR d'appairage                      | 3   | M      | KL-26, KL-46               |
| KL-27 | Moteur de synchronisation                            | 3   | L      | KL-24, KL-25, KL-14, KL-16 |
| KL-28 | Écran Aujourd'hui                                    | 4   | M      | KL-27, KL-23               |
| KL-29 | Écran Séance en cours (lecture + cochage)            | 4   | L      | KL-28, KL-15               |
| KL-30 | Déviations en séance                                 | 4   | L      | KL-29                      |
| KL-31 | Timer de repos, veille écran, notification           | 4   | M      | KL-29                      |
| KL-32 | Historique en séance                                 | 4   | M      | KL-29, KL-17               |
| KL-33 | Clôture de séance                                    | 4   | M      | KL-30                      |
| KL-34 | Séance vierge                                        | 4   | L      | KL-33                      |
| KL-35 | Écran Réglages                                       | 4   | S      | KL-27                      |
| KL-36 | Tests mobile                                         | 4   | L      | KL-34                      |
| KL-37 | Passe design complète                                | 5   | L      | KL-35                      |
| KL-38 | États vides, erreurs, bandeau hors ligne             | 5   | M      | KL-37                      |
| KL-39 | Ergonomie de salle + accessibilité                   | 5   | M      | KL-37                      |
| KL-40 | Signature Android                                    | 6   | M      | KL-21                      |
| KL-41 | Workflow de build APK                                | 6   | L      | KL-40, KL-36               |
| KL-42 | Dépôt F-Droid auto-hébergé + publication             | 6   | L      | KL-41                      |
| KL-43 | Page d'installation + contrôle de version in-app     | 6   | M      | KL-42                      |
| KL-44 | Recette finale et documentation                      | 6   | M      | KL-43, KL-39               |
| KL-49 | Réalisé superposé à la progression du plan           | 7   | L      | KL-05, KL-07               |
| KL-50 | Trajectoire d'un exercice sur `/exercise/{id}`       | 7   | M      | KL-04                      |
| KL-51 | Tri de la bibliothèque par usage réel                | 7   | S      | KL-02                      |
| KL-45 | Lecture du réalisé par le coach                      | 7   | M      | KL-07                      |

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
      compte inexistant pour que le _temps_ de réponse ne le trahisse pas non plus

Ce que le ticket pose, et qu'il ne faut pas casser :

- **`api_token.last_bootstrap_at` ne double pas `last_used_at`.** La seconde
  bouge à chaque requête (l'authenticator la repousse), la première ne bougera
  qu'au `GET /api/bootstrap` — **KL-14 en est le seul écrivain**, et un appel qui
  ne rend pas le jeu complet ne doit pas laisser croire que l'appareil est à jour.
  C'est la différence entre « ce téléphone répond » et « ce téléphone travaille
  sur des données à jour », et c'est ce que KL-12 affichera.
- **Le jeton validé est publié sur la requête** (`ApiTokenAuthenticator::REQUEST_ATTRIBUTE`),
  pas relu depuis l'en-tête par le contrôleur. `logout` révoque _celui qu'on
  présente_ et `/api/me` décrit l'appareil courant sans qu'aucun second endroit
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
  conteneur _en plus_ du cookie. Tant que le noyau n'a pas redémarré, ce jeton
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
      _encode_, pas ce que la réponse HTTP rend — le ticket la rendait en JSON
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

- **L'état par défaut de la page est _sans_ code.** Émettre est une écriture, pas
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
  supprime pas, elle cache : il faudrait alors la filtrer dans _chaque_ requête
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
  422 sur un `planned` recopié, mais rien ne _déclôture_ (§2.3 point 5).
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

- **Le document dit le _quoi_, jamais le _pourquoi_.** Le raisonnement vit ici et
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

- [x] Projet Expo TypeScript, `expo-router`, ESLint + Prettier
- [x] `app.json` : nom « Kadens », identifiant `fr.antoninpamart.kadens`,
      orientation portrait, `userInterfaceStyle: light`
- [x] Le dossier `android/` **n'est pas** versionné : le workflow le régénère par
      `expo prebuild`. Toute configuration native passe donc par un plugin
      déclaré dans `app.json`, jamais par une édition manuelle
- [x] README : prérequis, lancement, et **le rappel de l'IP LAN** (l'app doit
      viser l'IP de la machine, pas `localhost`, et Symfony démarre avec
      `--listen-ip=0.0.0.0`)
- [x] `.env` d'exemple avec l'URL de l'API

**Ce qui a été tranché en faisant** :

- **Le boilerplate du template est retiré, pas rangé de côté.** `create-expo-app`
  livre un exemple à deux onglets (composants thémés, icônes React, écran
  « explore ») et un `reset-project.js` qui le déplace dans `app-example/`. Le
  garder, c'est se donner un thème concurrent de celui que KL-22 va générer
  depuis `design-tokens.json`, et deux sources pour une couleur finissent
  toujours par diverger. Ne survivent que la route racine et le layout.
- **`EXPO_PUBLIC_API_URL` est un défaut de développement, pas la configuration.**
  L'URL du serveur arrive par le QR d'appairage (KL-48) et vivra dans la base
  locale (KL-24). Deux détails que `src/config.ts` documente sur place : une
  variable `EXPO_PUBLIC_*` est **inlinée dans le bundle**, donc lisible dans
  l'APK — rien de secret n'y passe jamais — et l'accès doit rester écrit en
  toutes lettres, un `process.env[nom]` dynamique rendrait `undefined`.
- **Le rendu web reste installé, alors qu'aucun ticket ne le cible.** Il a
  d'abord été retiré avec le reste (`react-dom`, `react-native-web`, la clé
  `web` d'`app.json`) au motif que la cible est Android : un `expo start` lancé
  en parallèle a échoué sur `Unable to resolve "react-native-web/dist/index"`.
  `expo-router` l'importe depuis son point d'entrée, et c'est le chemin le plus
  court pour regarder un composant sans téléphone. Il est donc rétabli, avec la
  mention explicite dans le README qu'il n'est qu'un confort — rien ne s'y
  vérifie.
- **iOS, en revanche, sort du dépôt** : l'icône `assets/expo.icon` du template et
  la clé `ios` d'`app.json` sont supprimées, conformément au hors-périmètre
  §0.4. Le code reste multiplateforme par nature ; aucun visuel iOS n'a à être
  maintenu.
- **Les visuels viennent de `public/pwa/`**, produits par
  `tools/build-pwa-icons.php` : l'icône reprend `icon-512.png`, l'icône
  adaptative Android reprend le maskable (marque à 55 % du côté, donc dans la
  zone sûre du disque), et les deux fonds sont le papier `#dcdcd7` de
  `--color-bg`. Redessiner une icône ici aurait créé une deuxième source pour
  une marque déjà générée.
- **La vérification tient sans téléphone** : `tsc --noEmit`, `eslint`,
  `prettier --check`, `expo-doctor` (20/20) et surtout un `expo export` réel
  pour Android **et** pour web — c'est le seul de ces contrôles qui exerce
  vraiment le bundler, et c'est lui qui a fait sortir le point précédent. Le
  bundle Android contient bien l'URL inlinée, vérifié dans le `.hbc`.

### KL-22 — Socle de design natif

**Fini quand** :

- [x] `npm run sync:tokens` télécharge `design-tokens.json` et génère
      `src/theme/tokens.ts` typé. Le fichier généré est versionné mais **jamais
      édité à la main** (même règle que `assets/styles/fonts.css`)
- [x] Polices Barlow, Barlow Condensed et IBM Plex Mono chargées par `expo-font`
- [x] Échelle typographique, espacements, rayon 0, aucune ombre
- [x] **Le condensé capitales ne touche pas au contenu saisi** : titres, boutons
      et onglets en Barlow Condensed capitales ; noms d'exercice et de séance en
      Barlow, casse normale. C'est la règle 4 du design system, elle s'applique
      telle quelle en natif
- [x] Pas de thème sombre (l'identité Presse est papier et encre, un thème sombre
      serait une deuxième identité à maintenir)

**Ce qui a été tranché en le faisant** :

- **La traduction native vit dans `tools/sync-tokens.mjs`, et elle échoue plutôt
  que d'approcher.** C'est la contrepartie du choix de KL-20 : la commande PHP
  résout les `var()` et ne traduit rien d'autre, donc quelqu'un doit convertir
  `color-mix()`, les piles de polices, les `px` et les `em` en valeurs que React
  Native comprend. Toute forme non reconnue **arrête la génération** — un token
  muet deviendrait `undefined`, donc du transparent, sans rien signaler. Même
  raisonnement pour les rayons et les ombres : ils sont **vérifiés nuls**, pas
  recopiés. Le jour où le web gagne une ombre, il faut décider ce qu'elle devient
  en natif, et le script force la décision.
- **Un préfixe sémantique inconnu échoue ; une primitive inconnue est ignorée.**
  Ce n'est pas une incohérence : la couche sémantique est ce que les composants
  consomment (règle 1), rien ne doit s'y perdre en silence, alors que les
  primitives de couleur et de police sont déjà résolues **dans** cette couche —
  les émettre ouvrirait un second chemin vers la même valeur. Seules les
  primitives qui n'ont pas de couche sémantique (espacement, rayon, graisse,
  interlettrage) sont traduites, exactement comme `components.css` les consomme
  en direct.
- **L'échelle typographique n'est pas générée, et elle ne peut pas l'être** :
  côté web elle vit en `clamp()` dans `components.css`, `tokens.css` n'en porte
  rien. `src/theme/typography.ts` en est la transposition à une seule largeur, et
  c'est **là** que se tient la règle 4 : des rôles de _structure_ (condensé
  capitales) et des rôles de _contenu_ (Barlow, casse normale) séparés
  explicitement, pour qu'un nom d'exercice ne puisse pas atterrir en capitales
  condensées par distraction.
- **`letterSpacing` est absolu en React Native**, là où le CSS l'exprime en `em`.
  Les tokens sont donc exposés **en em** et convertis au point d'usage
  (`letterSpacing(em, fontSize)`) : une valeur figée en points ne serait juste
  qu'à une seule taille de police. Et **l'interligne a un plancher à 1** — Android
  rogne le haut des lettres dès que `lineHeight` passe sous la taille de police,
  ce que le web ne fait pas : les `.88`/`.92` du hero ne se transposent pas.
- **Une graisse = une police enregistrée, et `fontWeight` ne choisit rien.**
  Android ne synthétise pas les graisses d'une famille chargée à l'exécution :
  `fontWeight: '700'` sur « Barlow » y rend du régulier, silencieusement. Chaque
  fichier est donc enregistré sous son propre nom et le choix passe par
  `fontFamily(stack, weight)`, dont le typage refuse à la compilation une graisse
  non embarquée.
- **Les polices sont chargées par `useFonts`, pas par le plugin natif
  d'`expo-font`** : le rendu web reste identique au natif et rien ne dépend d'un
  `expo prebuild` réussi pour itérer. L'app ne rend **rien** tant qu'elles ne sont
  pas prêtes (l'écran de démarrage tient la place) — un premier rendu en police
  système suivi d'une bascule ferait sauter toute la mise en page. L'écran de
  démarrage est masqué **aussi en cas d'erreur** : une police manquante dégrade
  l'affichage, elle ne bloque pas l'app.
- **Les `.ttf` sont versionnés dans le dépôt mobile** (ils entrent dans le
  bundle : un build ne doit pas dépendre d'un serveur joignable), mais leur source
  reste `tools/fetch-fonts.sh` côté serveur, comme les visuels viennent de
  `public/pwa/`.

### KL-23 — Composants de base

**Fini quand** :

- [x] `Button` (primaire rouge, secondaire encre, fantôme), `Card`, `Chip`,
      `Field`, `NumberStepper`, `Sheet`, `Header`, `EmptyState`
- [x] Aucune couleur ni police en dur dans un composant, toujours un token
      sémantique (règle 1 du design system)
- [x] Toutes les cibles tactiles à 44 points minimum
- [x] `NumberStepper` : saisie au clavier numérique **et** boutons plus/moins par
      incrément (2,5 kg par défaut). En salle, on ne tape pas au clavier

**Ce qui a été décidé en le faisant** :

- **Le `:hover` du web devient l'état pressé.** Il n'y a pas de survol au doigt,
  et chaque variante de bouton avait son geste d'accentuation : l'aplat rouge
  s'éclaircit (le foncer refermerait le bouton), le contour encre s'inverse, le
  fantôme se pose sur un fond. Transposer le sens plutôt que la déclaration.
- **Le plancher tactile est un chiffre nommé une fois** (`layout.touchTarget`,
  `src/theme/layout.ts`), pas un `44` recopié dans huit fichiers. Deux autres
  constantes l'accompagnent, pour la même raison : l'épaisseur de filet — **pas**
  `StyleSheet.hairlineWidth`, qui vaut moins d'un point sur Android et dissoudrait
  une identité qui tient par ses filets — et la hauteur maximale d'une feuille
  (78 %, comme le `78vh` du web). Ce ne sont pas des tokens : le web les porte
  dans `base.css` ou en `vh`, l'export de KL-20 ne les voit pas.
- **`sm` resserre les gouttières, jamais la hauteur.** Le web réduisait aussi la
  taille du texte ; l'échelle native n'a pas ce cran et en inventer un pour un
  bouton secondaire ne vaut pas le rôle supplémentaire. Un petit bouton reste
  visable au doigt.
- **Un seul rôle typographique ajouté** (`inputValue`, mono 22) : la valeur d'un
  `NumberStepper` se lit à bout de bras, barre en main, et `numeric` (16) est
  calibré pour une colonne de tableau. Écrire `22` dans le composant aurait sorti
  l'échelle de `typography.ts`, qui est justement l'endroit où elle se tient.
- **Trois pièges du `NumberStepper`**, tous invisibles à la lecture du ticket :
    1. **La frappe ne remonte pas au parent.** La saisie vit dans un brouillon de
       texte local jusqu'au relâchement du champ ; convertir à chaque frappe rend
       « 82, » impossible à taper — la virgule seule n'est pas un nombre, la valeur
       serait réécrite sous les doigts. La virgule est acceptée à l'entrée **et**
       rendue à l'affichage : c'est ce que propose le clavier français.
    2. **La répétition lit sa base dans une `ref`**, pas dans la prop `value` :
       elle avance plus vite que les rendus du parent, et une closure sur `value`
       collerait le compteur à `value + step`. Le pas de 2,5 kg sans répétition,
       c'est seize appuis pour aller de 60 à 100 kg.
    3. **`onPressIn` applique le pas, `onPress` ne le double pas.** Le premier
       donne le retour immédiat qu'on veut en salle, mais TalkBack n'émet **que**
       `onPress` : un drapeau distingue les deux chemins, sinon le lecteur d'écran
       n'incrémenterait rien. Les timers sont nettoyés au démontage — un écran qui
       disparaît pendant un appui laisserait un intervalle tourner.
- **Pas d'icônes.** Le bouton de retour et la fermeture d'une feuille disent
  « Retour » et « Fermer » en toutes lettres. Embarquer un jeu de glyphes
  (`lucide-react-native` + `react-native-svg`) est une décision qui engage le
  bundle et le rendu web : elle se prendra quand un écran en aura vraiment
  besoin, pas pour un chevron. L'identité Presse est typographique, le mot n'y
  détonne pas.
- **Un `Chip` ne se tape pas.** C'est une marque de lecture, comme `.kd-badge`
  côté web. Le jour où un filtre en aura besoin, ce sera un autre composant avec
  son plancher tactile — pas une prop `onPress` greffée sur celui-ci. Il porte
  deux façons de dire quelque chose, et elles ne se mélangent pas : un `tone`
  (statut, accent) où la couleur parle, et un `rank` catégoriel rendu par un
  filet gauche dans l'échelle de gris.
- **Le `Header` porte lui-même la zone sûre du haut.** Sinon chaque écran doit
  choisir entre un `SafeAreaView` (qui laisse une bande de fond papier au-dessus
  de l'en-tête) et un rembourrage doublé. Corollaire : un écran l'emploie à la
  racine, **hors** `SafeAreaView`.
- **La `Sheet` : `flexShrink`, pas `flex: 1`.** C'est le maillon qui manque au
  web sous une autre forme (`flex: 1` + `min-height: 0`) : sans lui, un contenu
  long pousse l'en-tête hors de l'écran au lieu de défiler sous lui — et avec
  `flex: 1`, une feuille courte s'étirerait inutilement jusqu'aux 78 %. Le voile
  est une cible de fermeture, le bouton retour d'Android ferme aussi, et le
  dégagement bas suit `insets.bottom` (barre gestuelle).
- **La vérification tient sans téléphone** : `tsc --noEmit`, `eslint`,
  `prettier --check`, et un `expo export` réel pour Android **et** pour web —
  le seul de ces contrôles qui exerce vraiment le bundler. `src/app/index.tsx`
  reste l'écran de vérification et montre désormais les huit composants au lieu
  de l'échelle : c'est là qu'on voit sur un vrai écran qu'une cible se vise au
  doigt et qu'une feuille monte du bas.

### KL-24 — Couche SQLite + Drizzle

**Fini quand** :

- [x] Schéma local miroir de §2.2 : `exercise`, `scheduled_workout` (qui porte le
      prévu **et** le réalisé, comme côté serveur), `prescribed_snapshot`,
      `logged_exercise`, `logged_set`, plus `sync_state` et `mutation_queue`
- [x] Migrations locales versionnées et rejouables
- [x] `mutation_queue` : `id`, `type`, `payload`, `attempts`, `lastError`,
      `createdAt`
- [x] Les UUID sont générés **localement** à la création (UUIDv7, ordonnable par
      le temps)
- [x] Un jeu de données de démonstration injectable en dev

**Ajouté au périmètre du ticket** : une huitième table, `exercise_history`. Le
bootstrap descend `history` pour que la dernière performance et le record
s'affichent **en séance, hors ligne** ; sans table pour les recevoir, la réponse
serait lue puis jetée, et KL-32 supposerait du réseau — ce que §KL-17 réserve au
seul `GET /api/exercises/{id}/history`.

### KL-25 — Client API et stockage du token

**Fini quand** :

- [x] Client typé partagé, timeout, retry avec backoff exponentiel
- [x] Token dans `expo-secure-store`, jamais dans `AsyncStorage`
- [x] Un 401 purge le token et renvoie vers l'écran de connexion
- [x] Le nom d'appareil envoyé au login vient de `expo-device`

**Ce qui a été tranché en le faisant** :

- **Le rejeu se décide sur la méthode, jamais sur le résultat.** « Retry avec
  backoff exponentiel » se lit comme une propriété du client ; appliqué à tout,
  il fabrique des dégâts. Un `POST /api/auth/login` dont la réponse s'est perdue
  a peut-être abouti : le rejouer émet un **second jeton que personne ne
  détient**, qui apparaîtra comme un appareil fantôme dans `/profile/settings` et
  vivra 90 jours. `GET`, `PUT` et `DELETE` sont idempotents par construction dans
  cette API (§4.2) et se rejouent trois fois ; les `POST`, une seule.
- **Un `429` n'est pas rejoué, alors qu'il est passager.** Le serveur dit combien
  de temps attendre, et c'est jusqu'à ~60 s sur la connexion : dormir une minute
  à l'intérieur d'un appel, c'est une interface figée sans rien à montrer — et
  c'est aussi ce qui fait tourner le compteur du limiteur. L'échéance
  (`retryAfterSeconds`) remonte à l'appelant, qui sait, lui, s'il peut revenir
  plus tard. C'est la même frontière que partout ailleurs dans le projet : le
  transport transporte, il ne décide pas de la politique.
- **Trois classes d'erreur, pas un code.** `NetworkError` / `TimeoutError` /
  `ApiError`, plus `isTransient()`. La file de mutations (KL-27) n'a qu'une
  question à poser à chaque échec — « est-ce que ça vaut le coup de réessayer ? »
  — et y répondre en lisant un message serait dépendre d'un texte que le contrat
  interdit explicitement d'analyser.
- **Un appel authentifié sans jeton ne part pas.** Il ferme la session sur place
  et lève un `401` local : le laisser partir nu userait un aller-retour pour
  apprendre ce qu'on savait déjà, et sur un réseau absent il échouerait en
  `NetworkError`, donc en « réessaie plus tard » — la session resterait ouverte
  sans jeton, indéfiniment.
- **Le `401` purge, et c'est le garde de navigation qui redirige.**
  `Stack.Protected` (expo-router) **retire** l'écran de la pile au lieu de rendre
  une redirection : le routeur retombe seul sur `login`. Aucun écran n'a donc à
  intercepter d'erreur d'authentification, et il n'existe pas de chemin où l'on
  reste sur un écran de séance avec un jeton mort.
- **L'URL du serveur est injectée, pas lue en base.** Elle vit dans
  `sync_state.apiUrl` (KL-48), mais la relire à chaque requête ferait ouvrir
  SQLite à un client HTTP pour savoir où appeler. Elle est posée une fois au
  démarrage par le layout racine, seul endroit où `@/api` et `@/db` se
  rencontrent. Corollaire : `setApiBaseUrl()` ne persiste rien, qui la change
  écrit aussi `sync_state`.
- **La session est un magasin de module, pas un contexte React.** Le jeton est lu
  par du code qui n'est pas un composant — le transport, et demain le moteur de
  synchronisation qui tourne quand aucun écran n'est monté. Elle expose trois
  états et non deux : sans un `unknown` le temps que le trousseau réponde, le
  premier rendu se confondrait avec « déconnecté » et l'écran de connexion
  clignoterait à chaque lancement. C'est aussi pour ça que l'écran de démarrage
  reste levé jusqu'à la restauration.
- **`useSession()` ne rend jamais le jeton.** Seul `currentToken()`, réservé au
  transport, le donne : ce qu'on ne passe pas en props ne finit pas dans un
  journal de rendu.
- **`requireAuthentication` du magasin sécurisé est refusé**, et ce n'est pas un
  oubli : le jeton est lu par **chaque** requête, y compris par un push qui
  tourne pendant qu'on est barre en main. Demander une empreinte à ce moment-là
  rendrait la synchronisation impossible. Ce qui protège ici, c'est le
  chiffrement au repos par l'Android Keystore, pas un geste par requête.
- **`signOut` efface le jeton local même si la révocation échoue.** Quelqu'un qui
  se déconnecte hors réseau doit être déconnecté ; le jeton resté vivant côté
  serveur se révoque depuis `/profile/settings` et périmera de lui-même.
  L'inverse laisserait un accès utilisable sur un téléphone qu'on croit fermé.
- **Un `DELETE` qui rend `404` est un succès**, le contrat le dit. Sans ça, une
  mutation dont la réponse s'est perdue resterait bloquée en tête de file pour
  toujours.
- **`src/app/login.tsx` est une coquille assumée**, née de la troisième case du
  ticket : « renvoie vers l'écran de connexion » a besoin d'une destination. Elle
  ne porte que le repli mot de passe et n'anticipe aucune décision de KL-26, qui
  la remplace (QR en primaire, code en secondaire, mot de passe en dernier).
- **Vérification** : `tsc --noEmit`, `eslint`, `prettier --check`, `expo export`
  pour Android **et** web (le seul contrôle qui exerce le bundler, et qui montre
  la route `login` enregistrée), puis deux bancs d'essai hors React Native — le
  paquet `src/api` est bundlé pour Node avec `expo-secure-store` et `expo-device`
  bouchonnés. Le premier (21 contrôles) exerce le transport contre un serveur
  HTTP local : nombre de tentatives par méthode, croissance du délai, timeout,
  annulation, `Retry-After` lu et non rejoué, corps illisible, `204`, purge du
  jeton sur `401` et **absence** de purge sur un `401` non authentifié. Le second
  (15 contrôles) fait tourner le **vrai** client contre le **vrai** Symfony :
  login, `ping`, `me` (le nom d'appareil relu est bien celui d'`expo-device`),
  bootstrap complet et delta, `?since` illisible en `400`, upsert `201` puis
  rejeu `200` sans duplication, `422` avec le chemin du champ, `DELETE` puis
  rejeu, et un jeton révoqué depuis l'extérieur qui purge le trousseau.

### KL-26 — Écran de connexion

**Fini quand** :

- [x] Écran d'accueil proposant **« Scanner le QR » en action primaire**, et
      « Saisir le code » puis « Email et mot de passe » en actions secondaires
- [x] Le formulaire mot de passe existe mais n'est pas le chemin par défaut
- [x] Session restaurée au lancement si le token est valide
- [x] Premier bootstrap déclenché après connexion, avec un état de chargement
      honnête (« Récupération de tes séances »)
- [x] Aucun lien « créer un compte » (il n'y a pas d'inscription publique)

**Livré le 03/08/2026.** Quatre décisions prises en cours de route :

1. **`SessionState` gagne un quatrième champ, `awaitingFirstSync`, plutôt qu'un
   quatrième statut.** Le garde de `_layout.tsx` n'avait jusqu'ici que deux cases
   (`signedIn` / `!signedIn`) ; « en train de récupérer sa première séance » n'est
   pas un statut de connexion, c'est une étape _après_ que la connexion a réussi.
   `openSession` (un `login` ou un `pair` frais) le pose à `true` ; `restoreSession`
   le pose à `false` dans les deux branches. C'est ce deuxième point qui compte :
   une session restaurée au lancement **ne repasse pas** par l'écran de bootstrap,
   parce que sa base locale porte déjà le dernier pull — l'y forcer à chaque
   ouverture d'app contredirait le hors-ligne, alors que la vraie mise à jour d'une
   session restaurée est le travail du **déclenchement au lancement** que KL-27
   posera sur le moteur de synchronisation, pas de cet écran.
2. **Le nouvel écran `bootstrapping.tsx` ne persiste pas la réponse du
   bootstrap.** Il appelle `GET /api/bootstrap` (sans `since`, premier pull
   complet) pour deux raisons seulement — valider que le serveur répond, honorer
   « état de chargement honnête » — puis referme le garde avec
   `completeFirstSync()` sans toucher à `src/db`. Écrire le document en base,
   en transaction, en tenant `sync_state` (fenêtre, `lastPulledAt`) est le rôle
   déclaré de **KL-27**, qui en sera **le seul écrivain** (`docs/api-mobile.md`
   et le CLAUDE.md mobile le disent déjà pour les autres champs de
   `sync_state`) : le dupliquer ici referait ce travail hors de ses garanties.
   Un échec de ce premier appel n'enferme pas l'utilisateur : « Réessayer » relance
   le même appel, « Continuer sans mes séances » referme le garde quand même — la
   base locale reste vide jusqu'au prochain pull, mais l'app reste utilisable.
3. **« Scanner le QR » et « Saisir le code » mènent au même écran.** Sans
   `expo-camera` (réservé à KL-48), il n'y a rien à distinguer aujourd'hui entre
   les deux : les deux boutons de l'écran d'accueil poussent vers `pairing.tsx`,
   qui n'implémente que la saisie manuelle du code de 8 caractères (clavier en
   majuscules, normalisé côté client comme côté serveur). **KL-48 a complété**
   cet écran d'une caméra sans le remplacer — le champ manuel y reste le repli.
4. **Le formulaire mot de passe de KL-25 est déplacé, pas réécrit.**
   `login.tsx` devient l'écran de choix (plus de formulaire dedans) ;
   `login-password.tsx` reprend le contenu tel quel. Les trois écrans
   (`login`, `pairing`, `login-password`) rejoignent le même groupe
   `Stack.Protected guard={!signedIn}` du layout racine.

Vérifié : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android et web (quatre routes statiques rendues :
`/login`, `/pairing`, `/login-password`, `/bootstrapping`). Pas de vérification
sur un vrai téléphone à ce stade : rien ici n'exerce encore `expo-secure-store`
au-delà de ce que KL-25 avait déjà vérifié, et il n'y a pas de caméra à tester
avant KL-48.

### KL-48 — Écran de scan du QR d'appairage

**Où** : repo `kadens-mobile`

**Fini quand** :

- [x] `expo-camera` avec demande de permission **expliquée avant** de la
      déclencher (un refus définitif ne se rattrape que dans les réglages
      Android)
- [x] Le scan lit `{url, code, exp}` et **configure l'URL de l'API au passage** :
      c'est ce qui rend l'app utilisable sans aucune saisie, y compris en
      développement contre une IP LAN
- [x] Saisie manuelle du code de 8 caractères en repli, clavier en majuscules
- [x] Erreurs traitées : code expiré, code déjà utilisé, réseau absent, permission
      caméra refusée
- [x] Le token reçu part directement dans `expo-secure-store`, il n'est jamais
      journalisé ni écrit en base locale
- [x] Un QR d'une autre application est rejeté proprement, sans plantage

**Livré le 03/08/2026.** `pairing.tsx` (KL-26) se complète d'un lecteur de QR
sans se réécrire — la saisie manuelle reste le repli. Trois décisions prises
en cours de route :

1. **`signInWithPairingQr` (nouveau, `src/api/auth.ts`) pose l'URL de base
   _avant_ l'échange, et la remet à sa valeur précédente seulement si l'appel
   échoue par réseau ou délai.** Un refus du serveur (code expiré ou déjà
   consommé) ne revert pas l'URL : le serveur a répondu, elle est donc bonne.
   Un QR qui pointe vers un serveur injoignable ne doit pas stranger la
   saisie manuelle de repli sur une URL morte pour le reste de la session.
2. **`src/api` ne connaît toujours pas `@/db`** (règle posée par KL-25). La
   fonction retourne l'`apiUrl` scannée sans l'écrire ; c'est l'écran
   d'appairage — seul point qui connaît les deux couches, même statut que
   `_layout.tsx` pour la restauration du jeton — qui appelle
   `patchSyncState({ apiUrl })` **après** un succès seulement.
3. **`exp` n'est pas revérifiée côté client.** `parsePairingQrPayload`
   (`src/api/pairingQr.ts`) valide juste la forme de la charge utile et lève
   une erreur dédiée sinon, sans jamais laisser remonter un `JSON.parse` brut
   (c'est ce qui couvre « QR d'une autre application » proprement). Comparer
   l'échéance à l'horloge du téléphone ferait dépendre le verdict d'un
   désaccord d'horloge, alors que le serveur est déjà seul maître de
   l'échéance à l'échange, et « inconnu / expiré / déjà consommé » rendent le
   même message par construction (§3.1) — dupliquer la vérification ici
   aurait donné deux verdicts possibles pour un même code.

Vérifié : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android et web. **Build natif sur appareil non
concluant** : `expo run:android` sur un appareil réel échoue à la compilation
Kotlin de `expo-dev-menu` et `expo-log-box`, **confirmé préexistant et sans
rapport avec ce ticket** en rejouant le même build sur l'état d'avant KL-48
(sans `expo-camera`) — échec identique. Problème de toolchain (Kotlin/AGP/RN)
à diagnostiquer séparément.

### KL-27 — Moteur de synchronisation

**Quoi** : le cœur technique du projet. À écrire avec soin, c'est là que les
bugs coûteux se logent.

**Fini quand** :

- [x] **Pull** : `GET /api/bootstrap?since=…`, application en transaction, mise à
      jour de `lastPulledAt`. Une nuance sur le `since`, voir le point 1 ci-dessous
- [x] **Push** : dépilage de `mutation_queue` en FIFO, une mutation à la fois,
      suppression sur succès
- [x] Une mutation en échec est **rejouée**, jamais perdue. Après 5 échecs, elle
      est marquée et remontée dans les réglages, pas silencieusement abandonnée
- [x] Déclenchement : au lancement, au retour au premier plan, au retour du
      réseau (`expo-network`), et à la clôture d'une séance
      (`syncOnWorkoutClosed()`, que KL-33 appellera)
- [x] **Le push passe toujours avant le pull** : sinon un bootstrap écraserait
      localement une séance pas encore envoyée
- [x] Aucune fenêtre où une séance en cours peut être perdue : la base locale est
      la source de vérité tant que le log n'est pas confirmé par le serveur
- [x] La synchronisation ne bloque **jamais** l'interface

**Livré le 03/08/2026.** Le moteur vit dans `src/sync/` (dépôt `kadens-mobile`),
importé par `@/sync`. Sept décisions prises en cours de route, à ne pas
redécouvrir :

1. **Le `since` envoyé est `sync_state.serverTime`, pas `lastPulledAt`.** Le
   ticket nommait le second ; c'est le premier qui est juste, et le contrat le dit
   déjà (§6.5) : `serverTime` est l'horloge du **serveur** au dernier pull réussi,
   `lastPulledAt` celle du téléphone. Deux pendules qui divergent de trente
   secondes suffiraient à sauter un exercice modifié entre les deux, sans rien
   pour le signaler. `lastPulledAt` reste écrit — c'est ce que l'écran de réglages
   affichera comme « dernière synchro » — mais il ne pilote rien.
2. **Une séance non confirmée par le serveur est intouchable, et « non
   confirmée » a deux définitions.** Une mutation en file (épuisée comprise), ou
   une séance commencée et pas terminée. La seconde est de la ceinture par-dessus
   les bretelles : KL-29 empilera une mutation dès la première série cochée, mais
   une séance ouverte dont rien n'a encore été coché n'en a pas, et l'exigence du
   ticket est absolue. Sur une séance protégée, le pull applique la
   **programmation** (date, titre, plan, blocs — le coach a pu corriger) et **rien
   d'autre** : ni le réalisé, ni `startedAt`/`endedAt`, ni `status`, ni la note de
   clôture. Écraser `status` serait le pire des trois — le document relu au push
   suivant repartirait en `planned`, et la clôture serait perdue au moment même où
   on essaie de l'envoyer.
3. **Le compteur d'échecs ne compte que les refus du serveur.** Réseau absent,
   délai dépassé, `429`, `5xx` : le cycle s'arrête, `lastError` s'affiche, et
   `attempts` **ne bouge pas**. Le sous-sol d'une salle de sport est le cas
   d'usage nominal du chantier ; y épuiser en cinq lancements une mutation
   parfaitement valide afficherait une panne là où il n'y a qu'un mur de béton.
   Un refus définitif (`409`, `422`, `403`), lui, compte **et** laisse passer la
   suivante : le problème est dans ce document-là, le laisser en tête de file
   bloquerait les séances des autres jours pour toujours.
4. **`deleted.schedule` n'est pas appliqué séparément, `deleted.exercises` si.**
   L'asymétrie n'est pas un oubli : `?since` n'allège que la bibliothèque, dont le
   jeu reçu est donc _partiel_ — sans la liste des disparus, un exercice supprimé
   resterait local à vie. La fenêtre de séances datées, elle, part toujours
   entière : « absente du jeu reçu » suffit à décider, et c'est cette purge qui
   borne la base (sans elle, chaque jour qui passe y laisserait une séance de
   plus).
5. **Un exercice supprimé côté serveur n'est pas supprimé localement s'il est
   référencé par un réalisé non confirmé.** `logged_exercise.exercise_id` est en
   `SET NULL` : le supprimer viderait la référence, et le document poussé ensuite
   arriverait sans `exerciseId`. La séance resterait lisible — le nom est un
   snapshot — mais elle sortirait de l'historique et des records, silencieusement.
6. **Empiler une mutation est un geste synchrone qui prend l'exécuteur de
   l'appelant** (`enqueueSchedulePut(uuid, tx)`). Écrire une série et empiler son
   envoi doivent tenir dans la **même** transaction : l'app tuée entre les deux
   laisserait un réalisé que rien ne signale comme non poussé, et le pull suivant
   l'effacerait sans un mot. D'où le type `Writer` (`@/db`) et des fonctions non
   `async` dans tout `src/sync`.
7. **L'écran de bootstrap de KL-26 persiste enfin ce qu'il descend.** Il appelait
   `GET /api/bootstrap` sans rien en faire, faute de moteur — la base restait vide
   jusqu'au déclencheur suivant. Il passe désormais par `syncNow('first-sync')`,
   qui reste le seul écrivain de `sync_state`.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web, puis un **banc d'essai de 55 contrôles
contre le vrai Symfony** — `src/sync` bundlé pour Node, `expo-sqlite` posé sur
`node:sqlite`, le reste bouchonné. Il exerce ce qu'aucune vérification statique ne
voit : le pull complet puis le delta, la fenêtre qui fait autorité, une séance
protégée dont le réalisé et la clôture survivent à un serveur qui la dit
`planned`, une séance en cours épargnée sans mutation, la coalescence (dix
modifications, une entrée), la suppression qui remplace l'envoi en attente,
l'upsert `201` puis le rejeu `200` sans doublon avec les uuid de séries posés par
le client, le document relu **au moment** du push (une série ajoutée après
l'enfilement part bien), le réseau coupé qui n'incrémente rien, un `422` qui
compte et laisse passer la suivante, les cinq refus puis le marquage, le
réarmement, et le cycle complet dans l'ordre push → pull.

**Pas de contrôle sur appareil** : le build natif reste bloqué par le problème de
toolchain Kotlin/AGP constaté en KL-48 (préexistant, sans rapport). C'est la
limite connue de ce ticket — le moteur n'a pas encore tourné sur un vrai réseau
mobile, avec de vraies bascules d'`AppState` et d'`expo-network`. La carte
« Synchro » de `src/app/index.tsx` est là pour ça le jour où le build repassera.

---

## Lot 4 — L'exécution de séance

### KL-28 — Écran Aujourd'hui

**Fini quand** :

- [x] Les séances programmées du jour, lues en local
- [x] Bouton « Démarrer » par séance, et « Séance libre » toujours accessible
- [x] Reprise d'une séance en cours si l'app a été fermée
- [x] Accès aux jours voisins (J-2 à J+2), pour rattraper la veille
- [x] État vide traité (aucune séance programmée)

**Livré le 04/08/2026.** Sept décisions prises en cours de route, à ne pas
redécouvrir :

1. **Un module `src/session/` est né, et c'est la vraie nouveauté du ticket.**
   `@/db` sait écrire des lignes, `@/sync` sait échanger avec le serveur ; ni
   l'un ni l'autre ne sait ce que « démarrer une séance » veut dire. Ces règles
   (poser `started_at` mais pas le statut, refuser une séance close, faire naître
   une séance libre à la date du jour) sont du **domaine** : laissées dans un
   écran, elles seraient invisibles au suivant. KL-29, KL-30 et KL-33 y poseront
   cocher, dévier et clôturer. Cinq fichiers : `days.ts` (arithmétique de
   calendrier et libellés), `queries.ts` (requêtes pures), `hooks.ts` (les mêmes
   sur `useLiveQuery`), `start.ts`, `index.ts`.
2. **Démarrer n'empile aucune mutation.** La règle « écrire du réalisé, c'est
   empiler sa mutation dans la même transaction » vaut pour le **réalisé** — une
   série, une clôture — pas pour l'ouverture : rien n'a encore été fait, et le
   pull protège déjà par son **second** critère, « commencée et pas terminée ».
   KL-27 l'avait anticipé mot pour mot. Le gain est concret : une séance ouverte
   puis refermée sans rien cocher ne part pas afficher un `startedAt` orphelin au
   calendrier web, et une séance libre abandonnée n'y apparaît pas vide. **Vérifié
   dans les deux sens** : un pull qui ignore la séance libre locale ne l'emporte
   pas, et un pull qui renvoie la séance programmée avec `startedAt: null` ne
   l'écrase pas.
3. **Rattraper la veille n'est pas déplacer une séance.** Ouvrir la séance d'hier
   pose ses bornes et rien d'autre : la `date` appartient au serveur (§4.1), la
   séance reste datée d'hier partout, ce qui est la vérité de ce qui s'est passé.
4. **La sélection du jour est un écart, pas une date.** L'état de l'écran est
   `offset ∈ [-2, +2]` ; le jour affiché s'en déduit. C'est ce qui le garde juste
   quand minuit passe pendant que l'app dort — une date absolue aurait demandé un
   recalage à la main, donc un second état décrivant ce que le premier dit déjà.
   `useToday()` relit la date locale au retour au premier plan.
5. **La carte d'une séance ne peut pas annoncer « 5 exercices ».** Le programme
   vit dans `prescribed_snapshot`, et l'invariant de la base dit que lister un
   jour ne doit pas remonter le plus gros document qu'elle contient. Le compter
   demanderait `json_array_length`, donc l'extension json1 sur tous les Android
   visés, que le projet a déjà refusé de supposer. La carte montre ce que les
   colonnes de la séance datée et le réalisé (normalisé, indexé) savent dire :
   plan, statut, séries consignées, attente de synchronisation.
6. **Un seul bouton primaire, et c'est le plus urgent.** Règle 2 du design
   system : reprendre s'il y a une séance ouverte, sinon démarrer la première
   séance actionnable du jour. Trois séances programmées le même jour donneraient
   sinon trois rouges qui ne disent plus rien. La bande de jours n'est pas un
   `Chip` (qui ne se tape pas, KL-23) mais un vrai contrôle, avec son plancher
   tactile.
7. **L'écran de vérification du socle devient `/diagnostics`.** Il occupait la
   route `index` ; le supprimer aurait été plus propre s'il ne portait pas encore
   la **seule déconnexion de l'app** et les seuls contrôles d'appareil du
   chantier (API, synchro, base locale). KL-35 le remplace par les vrais réglages.

Deux ajouts hors liste, jugés dans l'esprit du ticket : `src/app/session/[uuid].tsx`,
**coquille assumée** que KL-29 remplit — « Démarrer » avait besoin d'une
destination, et sans elle l'action ne serait pas vérifiable (même statut que
`login.tsx` en KL-25) ; et une feuille de confirmation sur « Séance libre », avec
titre pré-rempli et daté, parce qu'un appui malheureux créerait sinon une séance
datée que rien ne permet encore de supprimer depuis le téléphone.

**Vérification** : `typecheck`, `lint`, `prettier --check`, `expo export` pour
Android **et** web (neuf routes statiques rendues), plus un banc d'essai de
**44 contrôles hors React Native** — `src/session` bundlé pour Node, `expo-sqlite`
posé sur `node:sqlite`, migration réelle appliquée. Il exerce l'arithmétique de
calendrier (fin de mois, fin d'année, les deux changements d'heure), l'idempotence
de la reprise, le refus d'une séance close, l'absence de mutation, les lectures de
l'écran, et surtout l'**interaction avec le pull** décrite au point 2. **Pas de
contrôle sur appareil** : le build natif reste bloqué par le problème de toolchain
Kotlin/AGP de KL-48, préexistant. C'est la limite connue de ce ticket — le rendu,
les cibles tactiles et la bascule de minuit n'ont pas été vus sur un vrai
téléphone.

### KL-29 — Écran Séance en cours

**Fini quand** :

- [x] Le prescrit s'affiche bloc par bloc, dans l'ordre, avec les rangs de
      superset (A1/A2) **dérivés de l'ordre**, jamais stockés
- [x] Chaque série est une ligne cochable, pré-remplie par le prescrit
- [x] Cocher une série écrit un `LoggedSet` en base locale et empile un `PUT` de
      la séance datée dans `mutation_queue` (les mutations d'une même séance se
      coalescent : inutile d'en empiler une par série)
- [x] Progression visible (séries faites sur séries prévues)
- [x] Les exercices cardio sont en lecture seule, cochables fait / pas fait
- [x] L'écran survit à une mise en arrière-plan et à une coupure réseau
- [x] Rien n'est jamais perdu si l'app est tuée

**Livré le 04/08/2026.** Huit décisions prises en cours de route, à ne pas
redécouvrir :

1. **Une série réalisée s'apparie à sa ligne prescrite par le RANG, dans deux
   files séparées — et ce n'est pas un choix d'écran.** Le contrat ne transporte
   **aucune** référence de la série vers la ligne : `sourcePrescribedId` vit sur
   l'exercice, et `position` n'est même pas envoyée au serveur (« l'ordre de la
   liste fait foi, le serveur renumérote », §6.8). La seule règle possible est
   donc celle que `LogComparator` tient déjà (KL-05, décision 3) : le n-ième
   réalisé de travail coche la n-ième ligne de travail, l'échauffement comptant
   dans sa propre file. Deux files, parce qu'un échauffement prescrit mais non
   fait — le cas courant — décalerait sinon toutes les séries de travail d'un
   cran. **Corollaire ergonomique assumé : cocher est séquentiel.** Seule la
   première ligne non cochée de sa file est actionnable, seule la dernière cochée
   se décoche. Un « trou » (cocher la 3 en laissant la 1 vide) ne survivrait pas à
   un aller-retour serveur : il repartirait comme « une série faite » et
   reviendrait apparié à la première ligne. Mieux vaut une ligne inerte, qui dit
   pourquoi elle l'est, qu'une coche qui se déplace toute seule. Vérifié dans les
   deux sens : le réalisé renvoyé par le serveur avec ses positions renumérotées à
   partir de 0 se relit **exactement** aux mêmes lignes.
2. **`prescribed_snapshot` se lit ici, et nulle part ailleurs.** L'invariant de
   KL-24 interdit de remonter le plus gros document de la base pour _lister_ un
   jour ; il n'a jamais interdit de le lire pour _dérouler_ une séance, ce qui est
   exactement le seul endroit où il sert. C'est la première lecture du document
   depuis qu'il existe.
3. **Trois lectures vives et non une.** `useLiveQuery` n'écoute que la table du
   `from` (piège déjà relevé en KL-28) : le programme, les exercices réalisés et
   les séries sont trois requêtes, montées sur trois tables. Une jointure unique
   n'aurait été republiée que par sa table de tête, et le déroulé serait resté
   figé sur les deux autres — sans erreur, ce qui est le pire cas.
4. **Un réalisé sans rattachement vaut mieux qu'une série perdue.**
   `logged_exercise.exercise_id` porte une clé étrangère et les clés étrangères
   sont actives : un exercice que la bibliothèque locale ignore ferait **échouer
   l'insertion**, donc perdre la série au moment où on la coche. Le repli met
   `exerciseId` à `null` et garde le nom, donc le réalisé reste lisible partout ;
   il coûte le rattachement à l'historique et aux records de cette ligne-là. C'est
   le même problème que KL-27 avait rencontré dans l'autre sens, et la sortie
   propre reste celle qu'il avait identifiée : retirer cette FK vers un cache
   partiel, à traiter avec KL-35.
5. **La progression compte des gestes, pas du volume.** L'échauffement entre donc
   dans le total, contrairement au tonnage et aux records où il est exclu partout :
   ce qui se lit ici, c'est ce qu'il reste à faire, et un échauffement reste à
   faire. Un exercice cardio compte pour une unité — il n'a qu'une chose à dire —
   et un exercice sauté sort du décompte, puisqu'il est réglé (sinon la
   progression ne pourrait jamais atteindre son terme).
6. **Décocher efface aussi l'exercice réalisé devenu vide, et empile quand même
   la mutation.** Un `logged_exercise` sans série, sans note et non sauté
   signifierait « fait, zéro série » une fois poussé. Et la mutation part même
   quand il ne reste rien : `log: []` est ce qui efface le réalisé côté serveur,
   ne rien empiler laisserait le pull suivant remettre la série qu'on vient de
   retirer.
7. **« On ne consigne que dans une séance ouverte » est une garde du domaine, pas
   de l'écran.** Les trois écritures la franchissent, dans leur transaction :
   terminée, on n'écrit plus (pas de reprise après clôture, §2.3 point 5) ; pas
   commencée, on n'écrit pas non plus — un réalisé sans borne de départ décrirait
   une séance qu'on n'a pas faite, et le pull ne protégerait même pas la séance,
   faute de `started_at`. Même raison que pour `beginWorkout`, qui refuse déjà une
   séance close : une règle qui ne vit que dans un composant est invisible au
   composant suivant.
8. **Le réalisé que le programme ne réclame pas est affiché, pas ignoré.** Une
   série de plus qu'annoncé, un exercice hors programme : KL-29 n'en crée aucun,
   mais le pull peut en descendre, et du réalisé invisible serait la pire
   trahison de « rien n'est jamais perdu ». Ils se rendent en lecture, les séries
   surnuméraires à la suite des lignes prescrites (comme le tableau du web, qui
   peut déjà avoir plus de lignes que le prescrit), les exercices dans une
   section « Hors programme ».

Deux ajouts hors liste, jugés dans l'esprit du ticket : `src/components/units.ts`
(le pendant natif d'`UnitFormatter` — une charge et une durée doivent se lire à
l'identique sur le téléphone et sur `/schedule/{id}`, sinon comparer les deux
écrans demanderait de traduire de tête), et un bandeau « Démarrer » quand on
arrive sur une séance jamais commencée : l'écran serait sinon inerte sans dire
pourquoi.

**Pas de glyphe, une fois de plus** : la case à cocher est un carré au filet, qui
se remplit à l'encre. Un « ✓ » dépendrait de ce que Barlow contient, et KL-23
avait tranché « pas d'icônes tant qu'un écran n'en a pas besoin » — celui-ci n'en
a pas eu besoin.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web, plus un banc d'essai de **97 contrôles
hors React Native** — `src/session` bundlé pour Node, `expo-sqlite` posé sur
`node:sqlite`, la vraie migration appliquée. Il exerce l'appariement en deux
files, les groupes de superset (contiguïté comprise : A1/A2 puis B1/B2 ne se
recollent pas), la coalescence (dix séries cochées, une seule mutation), le refus
d'une ligne hors tour, la suppression en cascade de l'exercice vidé, le cardio, le
repli sur une bibliothèque incomplète, le document poussé, la protection du pull
(le réalisé survit, la correction du coach descend quand même) et **l'aller-retour
serveur** décrit au point 1.

**Le build natif repasse.** `npm run android` a produit l'APK et installé l'app
sur un appareil réel : l'échec de compilation Kotlin d'`expo-dev-menu` /
`expo-log-box`, constaté en KL-48 puis rejoué en KL-27 et KL-28, ne s'est pas
reproduit. La limite qui plombait les trois derniers tickets est levée.
**Limite de ce ticket** : le rendu n'a pas été observé, le téléphone étant en
cours d'utilisation. Restent à valider à l'œil les cibles tactiles des lignes de
série, la densité sur une séance de douze exercices et la lisibilité de la case à
cocher à bout de bras.

### KL-30 — Déviations

**Fini quand** :

- [x] Modifier le poids, les reps ou la durée d'une série
- [x] Ajouter une série à un exercice, en supprimer une
- [x] Marquer un exercice comme sauté (avec une raison optionnelle)
- [x] Remplacer un exercice par un autre de la bibliothèque locale
- [x] Ajouter un exercice non prévu
- [x] **Aucune réorganisation de blocs, aucun superset créé, aucun tour modifié**
      (§0.3 point 3). Si le besoin remonte pendant le développement, il devient
      un ticket web, pas un ticket mobile
- [x] Le prescrit reste visible à côté de la valeur saisie, pour voir l'écart

**Livré le 04/08/2026.** Huit décisions prises en cours de route, à ne pas
redécouvrir :

1. **On ne dévie que sur ce qui a été FAIT, et ce n'est pas un choix d'écran.**
   Le prescrit ne bouge jamais (§0.3) : `prescribed_snapshot` est remplacé en
   entier à chaque pull, et le réalisé n'existe pas avant d'être coché. Il n'y a
   donc **aucun endroit où écrire** « la série 3 se fera à 82,5 kg ». On coche
   aux valeurs prescrites — le geste nominal en salle, on fait ce qui est écrit —
   puis on corrige. La conséquence ergonomique est la forme de la ligne : non
   cochée, elle coche d'un appui n'importe où ; cochée, elle se scinde en deux
   cibles au plancher tactile — la zone de valeurs ouvre la feuille
   d'ajustement, la case décoche. Un appui long aurait tenu dans une seule
   cible, mais il ne se voit nulle part et se découvre par accident.
2. **Le type de série ne s'édite pas.** Il décide de la **file d'appariement**
   (échauffement ou travail) : le changer déplacerait le rang de la série et de
   toutes les suivantes, donc la lecture prévu/réalisé de la séance entière, ici
   comme sur `/schedule/{id}`. Pour un gain faible — le type vient du programme
   et il est juste dans le cas normal. Le **RPE**, lui, est saisissable : le
   modèle le porte par série, le prescrit affiche déjà une cible, et aucun autre
   écran ne permettra jamais de le renseigner.
3. **Supprimer une série est plus permissif que la décocher, et c'est cohérent.**
   Le cochage séquentiel de KL-29 existe pour empêcher un **trou** — cocher la 3
   en laissant la 1 vide produirait un réalisé qui reviendrait apparié à la
   première ligne. Supprimer au milieu ne fait pas de trou : la file se resserre,
   les rangs suivants remontent d'un cran, et c'est exactement ce que décrit une
   série qu'on n'a finalement pas faite. La case ne décoche donc que la dernière,
   la feuille supprime n'importe laquelle.
4. **Le remplacement conserve le lien au programme, et c'est ce qui le distingue
   d'un ajout.** `sourcePrescribedId` reste, seules la référence et le nom
   changent : `/schedule/{id}` lit alors « prévu développé couché, fait au
   guidé » au lieu d'un trou d'un côté et d'un intrus de l'autre. Le contrat
   l'autorise explicitement — il vérifie que la ligne du programme appartient à
   cette séance, pas qu'elle porte le même exercice (vérifié contre le vrai
   serveur). Mais il est **refusé dès qu'une série est consignée** : elle a été
   faite sur l'exercice d'origine, la rattacher à un autre la ferait entrer dans
   l'historique et les records de la mauvaise machine. Le chemin pour ce cas-là
   existe déjà — sauter avec sa raison, puis ajouter l'autre hors programme.
5. **Le remplacement est une DÉCLARATION, au même titre que « sauté » et que la
   note.** C'est le défaut que le banc d'essai a trouvé : décocher la dernière
   série d'un exercice remplacé le rendait « vide », le nettoyage de KL-29
   l'effaçait, et la séance repartait au serveur comme si rien n'avait été
   substitué. La base ne peut pas voir seule qu'il y a substitution (le prescrit
   vit dans un document JSON), donc l'appelant le passe à
   `dropEmptyLoggedExercise`. Un exercice **hors programme** vide est épargné
   pour la même raison : aucune ligne prescrite ne le ferait revenir.
6. **Un seul type d'exercice, prescrit ou non.** `SessionExercise.prescribed`
   devient nullable et `SessionExtra` disparaît. Sans ça, rendre le hors-programme
   éditable demandait un second composant d'affichage, un second chemin
   d'écriture et une seconde façon de compter — pour décrire la même chose. Un
   exercice ajouté **n'entre ni au numérateur ni au dénominateur** de la
   progression : elle dit ce qu'il reste à faire du programme, et trois séries en
   plus ne rapprochent pas de sa fin.
7. **La raison d'un saut et la note d'exercice sont le même champ.** Le modèle
   n'en a qu'un (`LoggedExercise.notes`) et en inventer un second côté mobile
   donnerait un texte que le serveur ne saurait pas où mettre. Ne plus sauter ne
   l'efface donc pas : ce que l'athlète a écrit lui appartient, et c'est ce qui
   fait survivre la ligne au nettoyage.
8. **Les bornes du contrat sont tenues à l'écriture, pas au push.** `reps` 0-200,
   charge 0-1000, durée 0-86 400, RPE 1-10 : une valeur hors bornes ne serait
   refusée qu'au push, en `422`, sur un réalisé déjà consigné — et la file la
   marquerait au bout de cinq essais. Une valeur impossible à saisir vaut mieux
   qu'une séance bloquée. Même logique pour la référence d'un exercice choisi,
   qui repasse par la garde de clé étrangère de KL-29.

Un ajout hors liste, jugé nécessaire au ticket : la **recherche dans la
bibliothèque locale** (`session/library.ts`), qui replie les accents à la main —
`LIKE` de SQLite n'ignore la casse qu'en ASCII, « developpe » ne trouverait pas
« Développé couché » dans une bibliothèque entièrement française, et
`String.normalize` dépend de la variante d'Hermes embarquée (même arbitrage que
les noms de jours en KL-28). Elle servira telle quelle à **KL-34**.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web (neuf routes, aucune fantôme), plus
**123 contrôles hors React Native** — `src/session` bundlé pour Node,
`expo-sqlite` posé sur `node:sqlite`, la vraie migration appliquée — et **23
contrôles contre le vrai Symfony**. Les premiers exercent les corrections et
leurs bornes, le pré-remplissage d'une série ajoutée, la suppression au milieu
sans trou, le saut et sa raison, le remplacement et son refus, l'ajout et le
retrait hors programme, la cascade, le document poussé et la recherche. Les
seconds répondent à la seule question que le local ne peut pas trancher : le
serveur accepte-t-il ces documents ? Oui — remplacement avec lien conservé,
exercice sans lien, série surnuméraire, saut sans série, rejeu sans doublon — et
il refuse bien une référence invisible en `422` avec le chemin du champ, sans
rien écrire.

**Limite de ce ticket** : le rendu n'a pas été observé sur l'appareil. Restent à
valider à l'œil les deux cibles d'une ligne cochée (elles se partagent une ligne
de 44 points), la lisibilité du prévu affiché à côté du saisi, et la feuille
d'ajustement au pouce, gants aux mains.

### KL-31 — Timer de repos, veille, notification

**Fini quand** :

- [x] Timer démarré automatiquement à la validation d'une série
- [x] Durée par défaut réglable, ajustable en un geste (+ 15 s / - 15 s)
- [x] `expo-keep-awake` actif pendant toute la séance, relâché à la clôture
- [x] Notification locale à la fin du repos si l'app est en arrière-plan
- [x] Vibration courte, désactivable dans les réglages

**Livré le 04/08/2026.** Le repos vit dans `src/session/rest.ts`, la veille dans
`src/session/wake.ts`, les réglages dans une table locale `preference`. Neuf
décisions prises en le faisant :

1. **Le repos n'est pas du réalisé, donc il ne va nulle part.** Ni en base, ni
   dans le document poussé — le contrat n'a aucun champ pour lui, et il ne décrit
   pas ce qui a été fait mais ce qu'on est en train d'attendre. Conséquence
   directe : c'est le **seul état de `@/session` qui vit en mémoire**, dans un
   magasin de module sur le patron de la session d'`@/api`. Il doit survivre au
   démontage d'un écran (on ouvre la feuille d'ajustement, on revient à
   « Aujourd'hui »), pas au redémarrage de l'app. Corollaire assumé : une app
   **tuée** pendant un repos perd son décompte ; la notification déjà programmée,
   elle, survit, ce qui est exactement ce qu'on veut d'une app tuée en
   arrière-plan. Une app **relancée**, en revanche, purge ce qui reste programmé
   (`initRestNotifications()`) : sans décompte à l'écran, une notification
   annoncerait la fin de quelque chose d'invisible.
2. **L'échéance est un instant, jamais un compteur qu'on décrémente.** `endsAt`
   est un horodatage absolu et le restant se recalcule à chaque tick. Un compteur
   décrémenté d'une seconde par tick dériverait sur trois minutes, et surtout
   serait faux au retour d'arrière-plan : Android suspend la boucle JS et les
   intervalles ne rattrapent pas leur retard. Le retour au premier plan force un
   tick immédiat, sinon l'écran afficherait une valeur périmée pendant une seconde
   entière — c'est-à-dire au moment précis où on reprend le téléphone.
3. **Personne ne décide d'afficher la notification, et c'est ce qui la rend
   juste.** Elle est programmée **dès le début du repos** et c'est le gestionnaire
   global (`setNotificationHandler`) qui la tait si l'app se trouve au premier
   plan à l'échéance. Décider nous-mêmes au moment de l'échéance, en lisant
   `AppState`, supposerait que la boucle JS tourne encore à cet instant — ce
   qu'Android ne garantit pas, et c'est justement le cas où la notification sert.
4. **Deux canaux Android, un qui vibre et un muet.** Un canal de notification est
   **figé après sa création** : seuls son nom et sa description restent
   modifiables. Une préférence « vibration » branchée sur un seul canal n'aurait
   donc plus aucun effet dès la deuxième ouverture de l'app. On déclare les deux
   et c'est la notification qui choisit le sien au moment d'être programmée.
5. **`Vibration` du cœur de React Native, pas `expo-haptics`.** Le haptique est un
   retour tactile sous le doigt, calibré pour être discret. Ici le téléphone est
   posé sur un banc ou dans une poche, et l'information doit traverser un
   survêtement. Deux impulsions courtes séparées, pour que ça ne se confonde pas
   avec un message reçu.
6. **La ligne prescrite l'emporte sur le réglage.** `restSeconds` vient du
   programme : quelqu'un a écrit 180 secondes sur du lourd et 45 sur de
   l'accessoire, une durée par défaut qui écraserait ça viderait le champ de son
   sens. Le réglage ne sert que là où le programme se tait — ce qui est le cas de
   la quasi-totalité des séances et de **toutes** les séances libres. Un repos
   prescrit à **zéro** est une consigne d'enchaîner (superset) : on la respecte en
   n'affichant aucune barre.
7. **Ajuster un repos ne modifie pas le réglage par défaut.** « Ce repos-ci sera
   plus long » et « mes repos durent deux minutes » sont deux choses distinctes ;
   reporter l'une sur l'autre ferait dériver le défaut au fil d'une séance sans
   que personne ne l'ait demandé. Un ajustement qui passe **sous** le restant
   termine le repos sans vibrer ni notifier — on regardait l'écran, on vient d'en
   décider, avertir n'apprendrait rien. Et « + 15 s » sur un repos **terminé** le
   relance : c'est le geste naturel quand on décide de souffler un peu plus.
8. **La veille est conditionnée, donc pas `useKeepAwake()`.** Le hook
   d'`expo-keep-awake` tient la veille tant que le composant est monté, sans
   condition — or l'écran de séance se monte aussi pour relire une séance close ou
   pas encore commencée, et garder l'écran allumé jusqu'à ce que la batterie tombe
   n'a alors aucun sens. Le verrou porte une **étiquette** nommée : deux
   activations sous la même étiquette se relâchent d'un seul appel, et un
   remontage ne laisse pas de verrou orphelin qui allumerait l'écran jusqu'au
   prochain redémarrage de l'app.
9. **Les réglages sont une table locale, et ils ne se synchronisent pas.**
   `preference`, une ligne garantie par un `CHECK (id = 1)`, même patron que
   `sync_state`. Une table plutôt qu'un magasin clé/valeur parce que ces valeurs
   se **lisent** en séance et se **règlent** ailleurs : montées sur `useLiveQuery`
   elles se republient d'elles-mêmes, sans qu'aucun code n'ait à prévenir
   personne. Elles restent locales à l'appareil — la durée de repos de mon
   téléphone ne regarde pas le calendrier — et `wipe()` ne les emporte **pas** :
   le « resynchroniser tout » de KL-35 purge ce qui se retéléchargera, une durée
   de repos ne revient de nulle part.

Deux ajouts hors liste, jugés nécessaires au ticket. Une carte « Repos » dans
l'écran de diagnostic : sans elle, « durée par défaut réglable » et « vibration
désactivable » restaient un mécanisme que rien ne pilote, l'écran de réglages
étant KL-35 — qui la reprendra telle quelle, avec la déconnexion et la file en
échec. Et `accessibilityLabel` sur `Button`, parce que « − 15 s » porte un signe
moins Unicode : il se lit à l'œil et pas du tout à la voix.

**La barre de repos est en bas de l'écran**, ancrée, et la page gagne un
dégagement tant qu'elle est là — sinon elle masquerait la dernière ligne de la
séance, c'est-à-dire précisément la série qu'on vient de cocher. Ce dégagement est
la hauteur **mesurée** de la barre (`onLayout`), pas un nombre écrit à la main :
elle change avec la longueur du nom d'exercice et avec la taille de police du
système. Le décompte n'est **pas** une zone vive pour TalkBack : un nombre qui
change chaque seconde et s'annonce à chaque fois rendrait l'écran inutilisable au
lecteur d'écran.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web (neuf routes, aucune fantôme), plus
**62 contrôles hors React Native** — `src/session/rest.ts` bundlé pour Node avec
horloge et intervalles virtuels, et la migration `0001_preference` appliquée pour
de vrai sur `node:sqlite`. Ils exercent les bornes (plancher 15 s, plafond une
heure), le décompte et son immunité aux fractions de seconde, l'ajustement dans
les deux sens, la fin de repos et sa vibration, la vibration coupée, le canal
choisi, le **retard** (app revenue au premier plan longtemps après l'échéance : on
ne vibre pas à contretemps, la notification a déjà averti), la relance depuis un
repos terminé, le repli prescrit → réglage, la permission refusée (le décompte
tourne quand même), le gestionnaire global dans ses trois cas, et
l'**entrelacement des programmations** — ajuster pendant qu'une programmation n'a
pas encore rendu son identifiant ne doit pas laisser une notification orpheline
qui sonnerait à l'ancienne échéance. Côté base : les défauts de colonne, l'upsert
partiel, et le singleton refusé par la base.

**Limites de ce ticket.** Le rendu n'a pas été observé sur l'appareil : restent à
valider à l'œil la barre au pouce, la lisibilité du chrono posé sur un banc, et le
fait que la notification arrive bien quand l'app est en arrière-plan écran
éteint. La barre n'est pas encore protégée de la **barre gestuelle Android** (les
zones sûres sont KL-39, et aucun écran du dépôt ne les traite encore). La
notification n'a **pas d'icône monochrome** dédiée : Android affichera la
silhouette par défaut de l'icône d'app, à reprendre dans la passe design (KL-37).
Enfin, `expo-notifications` et `expo-keep-awake` sont des modules **natifs** :
`npm run android` est obligatoire, un rechargement de Metro ne suffit pas.

### KL-32 — Historique en séance

**Fini quand** :

- [x] Sous chaque exercice : « Dernière fois » et « Record », lus en local
      (donc disponibles hors réseau, ils viennent du bootstrap)
- [x] Affichage compact, sur deux lignes maximum
- [x] Absence d'historique traitée sans case vide disgracieuse

**Livré le 04/08/2026.** La lecture vit dans `src/session/`
(`exerciseHistoryQuery`, `useSessionHistory`, `exerciseIdOf`/`exerciseIdsOf`),
l'affichage dans l'écran de séance. Aucune table, aucune migration, aucun appel
réseau : `exercise_history` existait déjà, remplie par le pull depuis KL-27 —
c'est **exactement** ce pour quoi elle avait été créée. Six décisions prises en le
faisant :

1. **L'historique suit le réalisé, pas le prescrit.** `exerciseIdOf()` lit
   `logged.exerciseId` d'abord, `prescribed.exerciseId` ensuite — la même règle
   de priorité que le nom affiché (KL-30). Un exercice remplacé en séance se lit
   donc contre l'historique de **ce qu'on fait**, pas de ce qui était prévu :
   afficher le record du développé couché en chargeant une machine convergente
   serait pire que de ne rien afficher.
2. **Ce qui s'affiche est ce que le serveur a confirmé, pas ce qui est en
   train d'être fait.** Rien n'est recalculé localement à partir du réalisé en
   cours. Conséquence assumée : une séance poussée puis redescendue dans la
   journée peut faire de « la dernière fois » ce qu'on vient de faire — d'où la
   **date affichée en clair**, avec « aujourd'hui » et « hier » comme seuls
   repères relatifs. Recalculer en local aurait donné deux sources pour un même
   fait, exactement ce que le dépôt refuse ailleurs (`sync/engine.ts`).
3. **Une lecture pour tout le déroulé, pas une par exercice.** Le hook remonte
   une seule requête vive indexée par identifiant d'exercice ; sa clé de
   dépendance est la liste **triée et dédupliquée** des exercices travaillés, ce
   qui la laisse stable quand le déroulé se reconstruit — c'est-à-dire à chaque
   série cochée. Sans le tri, ajouter un exercice hors programme aurait remonté
   la requête pour un ensemble inchangé.
4. **Rien à dire ne se dessine pas.** Un exercice jamais fait n'a pas d'entrée du
   tout (le serveur ne descend pas d'entrées creuses, `PerformanceHistory`) et le
   composant n'est pas monté ; un exercice fait au poids du corps a une dernière
   fois mais **pas de record** — il n'y a pas de record sans kilos (§6.6) — et la
   ligne manquante n'est pas remplacée par un tiret.
5. **Ça ne ressemble pas à une ligne de série.** Ni filet, ni fond, ni case :
   deux lignes de texte, libellé en mono capitales, valeur en mono comme les
   charges de la séance. Une peau de ligne de série, à cet endroit, s'appuierait
   du pouce par erreur entre deux séries. Elles sont placées **au-dessus** des
   séries, entre le nom et la première ligne : « la dernière fois, j'avais fait
   quoi ? » se pose en chargeant la barre, pas après.
6. **La charge se factorise quand elle est la même partout.** Les séries arrivent
   déjà condensées par le serveur (consécutives identiques fusionnées) ; le
   résumé les joint et sort la charge en fin de ligne quand elle est commune —
   « 2 × 8 reps, 6 reps · 80 kg » — pour tenir sur une ligne. Le **type** de la
   série record (à l'échec, drop set) n'est pas affiché : c'est la charge qui se
   compare, et la nuance appartient à la fiche d'exercice (KL-50).

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check`,
`npx expo export` pour Android, et **27 contrôles hors React Native** — les
fonctions pures sont extraites du source par script, jamais recopiées.
`exerciseIdOf`/`exerciseIdsOf` s'exécutent telles quelles (`program.ts`
n'importe que des types) : priorité au réalisé, dédoublonnage, tri, exclusion des
lignes sans référence. Le formatage est exercé sur le groupe unique, la charge
partagée, les charges qui divergent, le poids du corps, la série en durée, la
décimale à la virgule, la performance sans groupe, le record sans effort chiffré,
et les dates aux bornes qui comptent (le jour même, la veille, le passage
d'année, le changement d'heure).

**Limites de ce ticket.** Le rendu n'a pas été observé sur l'appareil : restent à
valider à l'œil la discrétion des deux lignes au milieu du déroulé, et le fait
qu'elles ne se confondent pas avec une série. Un exercice travaillé **deux fois**
dans la même séance affiche le même point aux deux endroits, ce qui est exact
mais redondant. Enfin, la trajectoire complète
(`GET /api/exercises/{id}/history`, KL-17) reste sans écran : elle est servie et
typée, personne ne l'ouvre.

### KL-33 — Clôture de séance

**Fini quand** :

- [x] Écran de résumé : durée, tonnage, séries faites, écarts au prescrit
- [x] Champ de notes libre
- [x] La clôture empile la mutation finale et déclenche une synchronisation
- [x] Une séance clôturée hors réseau se voit « en attente de synchronisation »,
      et l'état disparaît une fois confirmée
- [x] Abandon possible sans clôture (la séance reste ouverte et reprenable, son
      statut ne passe pas à `DONE`)
- [x] **Pas de reprise après clôture** : une séance clôturée est close (§2.3
      point 5). Refaire la même séance dans la journée crée une séance libre

**Livré le 04/08/2026.** Le domaine gagne deux fichiers (`session/summary.ts`, le
résumé et les écarts, purs ; `session/close.ts`, l'écriture terminale) et
l'écran de séance gagne un voisin : `src/app/session/[uuid]/close.tsx`. Le
fichier de route `[uuid].tsx` est devenu `[uuid]/index.tsx` — sans quoi la
clôture n'aurait pas pu être un **écran** de la séance, seulement une route
sœur nommée à côté d'elle. Sept décisions prises en le faisant :

1. **Le résumé se recalcule sur le téléphone, et doit rendre le même verdict que
   le serveur.** `LogMetrics` et `LogComparator` font déjà exactement ça, mais
   l'écran de clôture s'ouvre **avant le moindre envoi**, au sous-sol : le
   demander au serveur, ce serait un écran vide au moment précis où il sert.
   D'où un calcul local qui reprend le périmètre de l'un (échauffement hors
   volume, exercice sauté compté à part et sans tonnage même s'il porte des
   séries abandonnées) et la cascade de l'autre (tonnage, charge, répétitions,
   durée, nombre de séries ; un axe muet d'un côté ne tranche jamais). Les six
   états sont ceux de `LogDeviation`, valeurs et libellés compris. Un mobile qui
   dirait « allégé » là où `/schedule/{id}` dit « tenu » vaudrait moins que pas
   de résumé du tout.
2. **La durée diverge du serveur, volontairement.** `LogMetrics::durationSeconds()`
   rend `null` tant qu'une borne manque — une durée « jusqu'à maintenant »
   bougerait à chaque rafraîchissement d'une page web. Ici c'est l'inverse qu'on
   veut : l'écran est ouvert pendant que la séance dure encore. Elle court donc
   jusqu'à l'instant présent et se fige à la clôture, c'est-à-dire au moment où
   la valeur part. Le chrono vit dans **son propre composant** : un rendu par
   seconde de l'écran entier ferait sauter la saisie de la note juste en dessous.
3. **Clôturer est du réalisé ; ouvrir ne l'était pas.** `beginWorkout` n'empile
   aucune mutation (KL-28 : rien n'a encore été fait, et le pull protège déjà la
   séance). La clôture est l'inverse exact — le fait accompli — donc elle écrit
   sa mutation dans la **même transaction**, comme chaque série cochée. C'est
   aussi ce qui fait partir ce qui n'avait rien dit jusque-là : une sortie cardio
   cochée, une séance libre restée vide.
4. **La synchronisation part du domaine, pas de l'écran.** `closeWorkout()`
   appelle `syncOnWorkoutClosed()` après avoir validé sa transaction — le
   commentaire de `sync/triggers.ts` annonçait « un geste d'écran », c'était une
   erreur de placement : tout chemin de clôture doit déclencher l'envoi, et
   KL-34 en ouvrira un second. Le repos en cours s'arrête au passage : il
   n'appartient à aucun écran (KL-31), rien d'autre ne le couperait, et un
   décompte qui survivrait à la séance ferait vibrer le téléphone sous la douche.
5. **L'abandon n'écrit rien, donc il n'a pas de bouton.** La façon la plus sûre
   de tenir « la séance reste ouverte et reprenable » est de **ne rien faire** :
   on remonte, le statut ne bouge pas, « Aujourd'hui » la remet en tête. Un
   bouton « Abandonner » laisserait croire qu'on jette le réalisé, alors qu'il
   est déjà écrit, déjà en file, déjà en sécurité. Le retour s'appelle donc
   « Reprendre la séance ».
6. **La note de clôture ne s'efface pas.** Le contrat dit `completionNotes`
   « n'efface jamais l'existante » (§4.1) : écrire `null` localement sur un champ
   laissé vide ferait diverger les deux bases au premier aller-retour, le serveur
   gardant ce que le téléphone vient de perdre. Une note vide n'est donc pas
   écrite. Le champ, lui, se pré-remplit sans effet de bord : tant que rien n'est
   tapé, c'est la valeur enregistrée qui s'affiche, et un pull ne peut pas
   réécrire une saisie en cours.
7. **La clôture ne démonte pas l'écran, elle le retourne.** Le même écran passe
   en « Séance terminée », la note devient un texte, et la marque de
   synchronisation vit en **lecture vive** : « À synchroniser » hors réseau,
   « Synchronisée » dès que le push aboutit, sans que rien n'ait à prévenir
   l'écran. C'est la démonstration visible de « rien n'est perdu », et c'est ce
   qui rend la case vérifiable en mode avion. Sortir vide la pile (`dismissAll`)
   plutôt que de revenir en arrière : derrière, il y a une séance qu'on ne peut
   plus dérouler.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check`,
`npx expo export` pour Android **et** pour le web (dont le manifeste de routes
confirme `/session/[uuid]` et `/session/[uuid]/close`), et **56 contrôles hors
React Native** — les fonctions pures sont extraites du source par script, jamais
recopiées. Le résumé est exercé via `buildProgram`, donc sur de vraies entrées :
durée (absente, en cours, figée, fin antérieure au début, date illisible), les
quatre états non mesurables (sauté avec séries, hors programme, non réalisé,
cardio coché ou non), la cascade complète (tonnage, charge, répétitions, durée,
compte), le cas emblématique de KL-05 (plus lourd mais moins de travail = allégé),
l'échauffement qui ne pèse jamais, l'exercice remplacé, et les compteurs du
résumé (tonnage, séries de travail, échauffement à part, prescrit, exercices,
sautés, hors programme, poids du corps, séance vide).

**Limites de ce ticket.** Le rendu n'a pas été observé sur l'appareil : restent à
valider à l'œil la lisibilité des quatre grands chiffres à bout de bras, et le
fait que le bouton de clôture ne se trouve pas sous la barre gestuelle Android
(les zones sûres sont KL-39 ; cet écran prend l'`inset` du bas comme
« Aujourd'hui », mais rien n'est vérifié sur un vrai téléphone). Le garde de
navigation n'est pas exercé non plus : `Stack.Protected` ne rend ses écrans que
session ouverte, et l'export statique n'y entre pas — un nom de route erroné ne
se verrait qu'au lancement. Côté serveur, rien n'a été rejoué : `PUT` n'a pas
changé de forme, la clôture n'ajoute aucun champ au document, et §4.1 montre
déjà en `curl` réel qu'un `status: done` clôture et que rien ne déclôture. Enfin,
le résumé d'une séance **poussée puis redescendue** est celui du serveur (le pull
remplace le réalisé) : il ne peut plus être comparé au calcul local une fois la
mutation confirmée.

### KL-34 — Séance vierge

**Fini quand** :

- [x] Démarrage sans prescrit, à la date du jour
- [x] Recherche d'exercice dans la bibliothèque **locale** (donc hors réseau),
      avec filtre par activité et zone
- [x] Ajout d'exercices au fil de la séance
- [x] L'app crée une **séance datée sans `workout`**, avec son propre `uuid` et
      un `title` saisi ou daté par défaut. Elle apparaît au calendrier web en
      « hors plan » (KL-08)
- [x] Aucun `Workout` n'est créé en bibliothèque (décision actée)

**Livré le 04/08/2026.** Le ticket arrivait à moitié fait : `createFreeWorkout`
(KL-28) posait déjà la séance datée, et `addExercise` (KL-30) savait déjà la
garnir. Ce qui manquait était les **facettes** de la bibliothèque, et c'est là
que sont les décisions.

**Ce qui a été tranché en le faisant.**

- **La séance vierge n'a aucun chemin d'écriture à elle.** Elle se garnit par le
  geste « ajouter un exercice hors programme » de KL-30, sans exception. Tout ce
  qu'elle contient est du **réalisé** — il n'y a rien d'autre, puisqu'il n'y a pas
  de prescrit — donc rien à inventer : ni entité, ni requête, ni écran. C'est ce
  qui explique qu'un ticket noté « L » se réduise à des facettes.
- **Facette et frappe ne répondent pas à la même question.** Le nom se **tape**
  (« je sais ce que je veux »), l'activité et la zone se **choisissent** (« je
  cherche quoi faire »). La séance vierge est le seul écran où la seconde question
  se pose vraiment. Conséquence à ne pas casser : les zones n'entrent **pas** dans
  le texte cherché, contrairement au web où `data-filter-text` recopie leurs
  libellés — là-bas c'est le seul chemin faute de facette, ici la facette existe,
  et deux chemins pour le même fait finissent par se contredire.
- **Une facette décrit ce que la bibliothèque porte, elle n'annonce pas l'enum.**
  `libraryActivities` / `libraryAreas` dérivent les rangées du contenu réel :
  proposer « Natation » à qui n'a aucun exercice de natation offre un filtre dont
  la seule issue est une liste vide. Les deux rangées ne se cadrent pas sur la
  même liste — les activités sur la bibliothèque entière, les zones sur la
  bibliothèque **réduite à l'activité retenue** (choisir « Course à pied » doit
  faire disparaître « Pectoraux ») mais **pas** sur la zone déjà choisie, sinon sa
  propre rangée se réduirait à elle-même et on ne pourrait plus en changer.
- **Changer d'activité relâche toujours la zone.** Une règle qu'on peut énoncer
  plutôt qu'un nettoyage au cas par cas, qui demanderait de connaître les zones de
  l'activité _suivante_ — que le rendu en cours n'a pas. Sans ça, un filtre resté
  actif sort de sa rangée et devient indéfaisable, devant une liste vide sans
  raison visible.
- **L'ordre des facettes est celui de déclaration des enums serveur**, jamais
  l'alphabétique ni la fréquence : c'est l'ordre du web, et pour les zones il est
  déjà anatomique (celui que `TargetRegion` formalise), donc « Pectoraux » voisine
  « Dos » et pas « Quadriceps ». Une rangée qui se réordonne se re-cherche.
- **Les rangées défilent horizontalement.** Treize zones (le compte réel de la
  bibliothèque) enroulées au plancher tactile de 44 points mangeraient quatre
  lignes de feuille — donc la liste de résultats, qui est ce qu'on est venu voir.
  Le prix est réel : ce qui dépasse à droite ne se voit pas. Il est payable parce
  que les rangées sont ordonnées et réduites à ce qui existe. Une rangée d'un seul
  choix ne se rend pas : elle ne filtre rien, elle occupe la place.
- **`FilterChip` est un composant à part, pas un `Chip` avec un `onPress`.**
  C'est ce que `Chip` annonçait mot pour mot (« le jour où un filtre en aura
  besoin, ce sera un autre composant, avec son plancher tactile ») : un `Chip` est
  une marque de lecture, une facette est un contrôle, et les greffer ensemble
  aurait donné un composant parfois au plancher tactile et parfois non. L'état
  retenu **s'inverse à l'encre** et ne rougit pas, contrairement au web
  (`.kd-libfilter--on` en `primary-tint`) : la règle 2 réserve le rouge à l'action
  primaire, à l'intensité et à l'échec, et c'est déjà ce que la bande de jours de
  KL-28 avait tranché.
- **Dans une séance vierge, l'en-tête « Hors programme » devient « Exercices ».**
  Il n'existe aucun programme dont on puisse être hors ; le garder ferait lire la
  séance entière comme une longue déviation.
- **Le vide se distingue.** « Aucun exercice ne correspond à ces filtres » quand
  une facette est active, « la bibliothèque est celle du dernier bootstrap » sinon.
  Sans ça, on cherche la panne du mauvais côté.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web (dix routes, aucune fantôme), plus **49
contrôles hors React Native** — `src/session/library.ts` et `labels.ts` bundlés
pour Node, jamais recopiés — et **une séance vierge poussée au vrai Symfony**.
Les premiers exercent l'ordre canonique des deux rangées (les 17 zones et les 6
activités y passent toutes, une oubliée serait filtrable mais jamais proposée),
leur dérivation du contenu réel, le cumul des deux facettes (« Salle de sport »
**et** « Dos », pas l'un ou l'autre), le croisement impossible qui rend vide, un
exercice sans zone qui ne répond à aucune facette de zone, la composition avec la
recherche accentuée de KL-30, le fait que « pectoraux » tapé ne trouve rien (les
zones ne sont pas dans le texte), et que tous les libellés sont bien traduits. Le
second répond à ce que le local ne peut pas trancher : `PUT /api/schedule/{uuid}`
sur un uuid neuf rend `freeform: true`, `blocks: []`, `plan: null`, le titre
saisi conservé, et les deux exercices du log — dont un **sans série**, le cas
« ajouté mais pas encore fait ». En base : `workout_id` nul,
`source_plan_item_id` nul (donc bucket « hors plan » du calendrier), et **zéro
`Workout` en bibliothèque** pour ce compte.

**Limite de ce ticket** : le rendu n'a pas été observé sur l'appareil. Restent à
valider à l'œil le défilement horizontal des rangées au pouce (et le fait qu'il
ne vole pas le geste vertical de la feuille), la lisibilité de treize pilules de
zone à bout de bras, et la place que les deux rangées laissent vraiment à la
liste dans une feuille bornée à 78 %.

### KL-35 — Écran Réglages

**Fini quand** :

- [x] Compte, déconnexion, version de l'app et du build
- [x] État de synchronisation : dernière réussite, mutations en attente,
      mutations en échec avec possibilité de les rejouer
- [x] Durée de repos par défaut, vibration
- [x] Bouton « Resynchroniser tout » (purge locale et bootstrap complet)

**Livré le 04/08/2026.** `src/app/settings.tsx`, quatre cartes — compte,
synchronisation, repos, application — et la disparition de l'écran de
diagnostic, qui portait ces morceaux depuis KL-25 faute d'un endroit à eux.

**Ce qui a été tranché en le faisant.**

- **L'écran de diagnostic disparaît, il ne se replie pas.** Ping, bootstrap
  manuel, compteurs de tables, galerie de composants : c'était l'outillage d'un
  socle qu'on construisait, pas une fonction de l'app, et le garder aurait fait
  deux écrans se partager la déconnexion. Seul le **jeu de démonstration**
  survit, dans la carte « Application » et sous `__DEV__` — rien d'autre ne
  remplit une base sans serveur, et `seedDemo()` refuse déjà de s'exécuter en
  production. Survit aussi le **test de repos**, mais pas au même titre : ce
  n'est pas un outil de développement, c'est le seul moyen de savoir _avant_ une
  séance si ce téléphone laisse passer la notification (canal muet, mode
  silencieux, permission refusée). Le découvrir barre en main serait le
  découvrir trop tard.
- **La dernière synchronisation se lit en base, pas dans le moteur.**
  `useSyncStatus()` vit en mémoire et repart vide à chaque lancement : un écran
  qui n'aurait lu que lui annoncerait « jamais synchronisé » sur une base
  descendue une heure plus tôt. D'où `useSyncState()` (`sync/hooks.ts`), lecture
  vive de `sync_state`, qui porte `lastPulledAt` / `lastPushedAt` et la fenêtre
  couverte. Le moteur ne dit plus que ce qui se passe **maintenant** — la phase
  et la dernière erreur.
- **Se déconnecter efface la base locale ; l'URL du serveur y survit.** La
  première moitié était déjà décidée (`seed.ts`) : le réalisé d'un compte n'a
  rien à faire sur l'appareil une fois le jeton parti. La seconde est neuve —
  `sync_state.apiUrl` vient du **QR d'appairage**, pas du compte, et l'effacer
  déconnecterait l'app de son serveur au point que le repli « email et mot de
  passe » n'aurait plus où appeler. `clearDatabase()` la préserve donc, pour les
  deux gestes qui l'appellent. L'ordre compte aussi : **la base part avant le
  jeton**. L'app tuée entre les deux vaut mieux avec une base vide et un jeton
  valide (le pull la remplit) qu'avec le réalisé d'un compte déconnecté et plus
  aucun écran pour l'atteindre.
- **« Tout resynchroniser » ne purge qu'après une synchronisation avérée.**
  C'est le seul geste destructeur de l'app, et `resyncAll()` (`sync/reset.ts`) le
  garde par trois conditions, dans cet ordre : un cycle complet doit réussir
  (il pousse ce qui attend **et** prouve que le serveur répond) ; ce cycle doit
  avoir réellement échangé — un `ok: true` **sans pull** est un cycle _sauté_,
  session fermée, et purger là viderait l'app sans rien pour la recombler ; et
  la file doit être vide, ce qui reste après un push réussi étant précisément ce
  que le serveur **refuse**. Aucun chemin de « pull seul » n'a été inventé pour
  l'occasion : la reconstruction est un second cycle complet, rendu exhaustif par
  le seul fait que la purge remet `serverTime` à null — le `?since` disparaît,
  le serveur renvoie tout.
- **Le compteur de tentatives ne s'affiche qu'à partir d'un refus du serveur.**
  Une séance qui attend du réseau n'a rien d'un échec ; lui coller « 0/5 » et un
  message d'erreur ferait lire une panne là où il n'y a qu'un mur de béton. La
  condition est `attempts > 0`, et elle est exacte parce que le compteur ne bouge
  **que** sur un refus définitif (`sync/queue.ts`). Même raison pour l'erreur
  globale, qui se rend en gris quand `offline` est vrai et en rouge sinon.
- **Une seule action primaire, et elle n'est pas toujours là.** Le rouge va à
  « Synchroniser » **quand la file n'est pas vide**, et à rien du tout sinon
  (règle 2). Un écran de réglages où tout est à jour n'a aucune action urgente,
  et un rouge permanent aurait cessé de vouloir dire quelque chose — c'est déjà
  ce que KL-28 avait tranché pour les cartes du jour.
- **La version vient du manifeste embarqué, pas d'`expo-application`.** L'app
  n'embarque pas `expo-updates` : le manifeste est figé au build et ne **peut
  pas** diverger du binaire installé, donc `Constants.expoConfig` dit la vérité
  sans ajouter un module natif (donc un rebuild) pour une valeur qu'on a déjà. La
  question se reposera avec un vrai besoin quand KL-43 comparera cette version à
  celle du dépôt F-Droid. Deux corollaires : `android.versionCode` est désormais
  **déclaré** dans `app.json` (sinon « build » serait une devinette), et en
  développement l'écran affiche « développement » plutôt qu'un numéro qui ferait
  croire à une version distribuée.
- **Le compte d'une session restaurée se complète en tâche de fond.**
  `restoreSession()` ne rend qu'un jeton, pour que l'app s'ouvre hors ligne
  (KL-25) : sans rattrapage, l'écran afficherait indéfiniment un compte inconnu
  sur un téléphone parfaitement connecté. Un `refreshMe()` au montage, échec
  ignoré — et c'est du même coup l'endroit où un jeton **révoqué depuis
  `/profile/settings`** se découvre, le `401` purgeant la session et le garde de
  navigation faisant le reste.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npx prettier --check .`,
`npx expo export` pour Android **et** web (dix routes, `/settings` présente,
`/diagnostics` disparue), plus **19 contrôles hors React Native** sur
`resyncAll()` — `src/sync/reset.ts` bundlé pour Node avec des stubs à la place du
moteur, de la file et de la base, jamais recopié. Ils exercent ce que ni le
typage ni le bundler ne disent : les deux refus (serveur injoignable, cycle
sauté, file non vide) laissent la base **intacte** et ne lancent aucun second
cycle ; le chemin nominal purge **entre** les deux cycles et pas ailleurs ; une
reconstruction qui échoue rapporte l'échec sans le maquiller en refus (la purge,
elle, a bien eu lieu) ; et les deux cycles partent en `manual`, donc délibérés,
donc hors du plancher anti-rafale du moteur.

**Limites de ce ticket** : le rendu n'a pas été observé sur l'appareil. Rien n'a
été rejoué contre le vrai Symfony non plus, et c'est assumé — l'écran n'ajoute
**aucun appel** au contrat (`login`, `logout`, `bootstrap` existent depuis le lot
2), il ne fait qu'ordonner des gestes déjà vérifiés. Restent donc à voir à
l'œil : la file en échec avec de vraies mutations refusées, la déconnexion
complète sur un téléphone appairé, et le fait que « tout resynchroniser » ramène
bien une base identique.

### KL-36 — Tests mobile

**Fini quand** :

- [x] Jest configuré, `@testing-library/react-native`
- [x] **Le moteur de synchronisation est testé en priorité** : file rejouée,
      échec puis succès, mutation en double, application d'un delta, ordre
      push avant pull
- [x] Les réducteurs de séance testés (cocher, dévier, sauter, clôturer)
- [x] Un test de bout en bout du parcours « séance programmée, entièrement hors
      réseau, puis synchronisée »
- [x] Les tests tournent en CI sur chaque push

**Livré le 04/08/2026.** **92 contrôles, 10 suites** — `src/**/__tests__/`, les
utilitaires dans `src/test/`, la CI dans `.github/workflows/ci.yml` (typage,
lint, format, tests, sur chaque poussée). Préréglage `jest-expo/android` : le
dépôt ne vise qu'Android, et le préréglage universel ferait tourner chaque test
trois fois dont deux sur des cibles qu'on ne livre pas.

**Ce qui a été tranché en le faisant.**

- **`expo-sqlite` est remplacé par `node:sqlite`, pas par un bouchon.** C'est la
  décision structurante du ticket. Ce que ces tests ont à vérifier **est du
  SQLite** : une transaction qui se replie, un `ON DELETE CASCADE` qui emporte
  les séries, un `AUTOINCREMENT` qui ne réattribue pas un rang libéré, un
  `ON CONFLICT DO UPDATE` qui remplace au lieu de doubler. Un faux qui rendrait
  des lignes toutes prêtes ne dirait rien de tout ça — il dirait que le faux est
  d'accord avec lui-même. `node:sqlite` est la même bibliothèque que celle
  qu'embarque `expo-sqlite`, sans module natif à compiler ; il n'y a donc rien à
  simuler, seulement une API à traduire dans l'autre
  (`__mocks__/expo-sqlite.ts`, appliqué **automatiquement** par Jest à tout ce
  qui importe le module). Le seul point non évident de la traduction :
  `executeSync()` doit rendre à la fois les lignes lues et le compte de lignes
  modifiées, là où `node:sqlite` sépare `run()` et `all()` — les appeler tous les
  deux exécuterait la requête deux fois, donc doublerait un `INSERT` en silence.
  Le tri se fait sur `columns()`, une seule branche s'exécute.
- **Le schéma des tests vient des migrations du dépôt.** `src/test/database.ts`
  rejoue `src/db/migrations/`, les mêmes fichiers que le téléphone applique au
  démarrage. Un schéma recopié dériverait au premier `npm run db:generate` et les
  tests continueraient de passer sur une base que l'app n'a plus ; en prime, une
  suite qui tourne prouve que les migrations s'appliquent sur une base vierge. On
  **vide** entre deux tests plutôt que de rouvrir, `db/client.ts` ouvrant sa
  connexion au chargement du module et la gardant (choix documenté là-bas) —
  `sqlite_sequence` compris, sans quoi l'`AUTOINCREMENT` de `mutation_queue`
  compterait d'un test à l'autre.
- **Seul `fetch` est bouchonné, jamais `@/api`.** Remplacer les endpoints par des
  espions ferait des tests qui vérifient les espions : plus de timeout, plus de
  rejeu, plus de `201` contre `200`, plus de taxonomie d'erreurs — c'est-à-dire
  plus rien de ce qui décide du comportement de la file. En bouchonnant le
  transport, un test de push exerce vraiment `putSchedule`, `ApiError` et
  `isTransient`. Corollaire assumé : les trois tentatives du transport coûtent du
  vrai temps sur les scénarios hors réseau (une seconde et demie par appel
  idempotent), et c'est justement ce comportement qu'on veut voir tourner.
- **Le `fetch` par défaut échoue en nommant l'appel** (`src/test/setup.ts`).
  Ce n'est pas un confort mais un garde-fou : `@/session` écrit du réalisé **hors
  réseau**, et un import mal placé qui ferait partir une requête depuis une
  écriture locale passerait sinon inaperçu — les tests réussiraient en s'appuyant
  sur un serveur qui n'existe pas.
- **Les jeux de données sont des charges utiles, et la base se remplit par un
  vrai pull.** `src/test/fixtures.ts` construit du `BootstrapPayload` et
  `seedBootstrap()` applique `applyBootstrap`. Un test qui insérerait ses lignes
  à la main décrirait un état que le serveur ne sait pas produire, et il
  continuerait de passer le jour où le contrat bouge. Conséquence : dans une
  suite de séance, la mise en place est déjà un morceau du parcours testé.
- **L'horloge se fige là où le moteur la lit.** Le plancher anti-rafale de dix
  secondes se mesure sur `Date.now()` et l'état du moteur vit au niveau du
  module, donc d'un test à l'autre : sans horloge tenue par le test, « le réseau
  revient » serait sauté par un plancher parfaitement légitime hérité du test
  précédent. Même raison pour `waitForIdle()` (`src/test/sync.ts`), qui compte en
  tours de boucle et non en `Date.now()`.
- **On attend le moteur en l'observant, on ne le relance pas.**
  `closeWorkout()` déclenche sa synchronisation sans l'attendre (KL-33), et un
  `await syncNow()` pour la rejoindre en **enchaînerait** un second à la fin
  (`engine.ts`) : le test relancerait indéfiniment ce qu'il essaie d'attendre.
  D'où `waitForIdle()`, qui lit la phase publiée. Rien n'a été ajouté côté app
  pour ça, et c'est le point : personne n'attend `syncNow()`.
- **Deux modules Expo demandent un double, pour des raisons opposées.**
  `expo-sqlite` parce qu'on veut le **vrai** comportement ;
  `expo-notifications` parce que le module refuse de se charger en test (il
  détecte Expo Go par `Constants.appOwnership`, que le préréglage simule) et que
  toute suite important `@/session` en hérite via `rest.ts`. Le second ne vérifie
  donc rien et ne reprend que la surface appelée : le repos n'entre en base nulle
  part et n'a rien à dire au serveur, ce qu'il a d'observable se vérifie sur un
  téléphone.
- **`tsconfig` déclare ses `types`.** TypeScript 6 n'inclut plus les paquets
  `@types` tout seul : `["jest", "node"]` est le seul moyen d'avoir les globales
  des suites et `node:sqlite`, et ça garde la liste lisible. La CI tourne sur
  **Node 24** — `node:sqlite` est sans drapeau depuis la 22.13, et l'alternative
  (`better-sqlite3`) ajouterait un module natif à compiler pour un SQLite que
  Node embarque déjà.
- **Piège de `@testing-library/react-native` v14** : `render` et `fireEvent` sont
  **asynchrones**. Oublier l'`await` ne donne pas un test qui échoue à
  l'assertion mais un `screen` vide, avec « `render` function has not been
  called » — noté dans `CLAUDE.md`, parce que le message ne désigne pas la cause.

**Ce que ces tests ne couvrent pas, et pourquoi.** Aucun écran n'est monté : le
seul test de rendu porte sur `NumberStepper`, qui est le seul composant du dépôt
à porter de la logique (bornes, virgule décimale, libellés TalkBack) plutôt que
de la mise en page. Rendre `session/[uuid]/index.tsx` demanderait `expo-router`,
Reanimated et les polices, pour vérifier une composition qui se voit mieux à
l'œil — c'est ce que KL-37 à KL-39 iront regarder sur l'appareil. Le repos
(`rest.ts`) reste hors périmètre pour la même raison : ce qu'il a d'observable
est un canal Android et une vibration.

---

## Lot 5 — Design et finitions

### KL-37 — Passe design complète

**Fini quand** :

- [x] Tous les écrans repris à l'identité Presse : papier froid, encre quasi
      noire, un seul accent rouge, rayon 0, aucune ombre
- [x] **Le rouge ne sort que pour l'action primaire, l'intensité et l'échec.**
      Toute catégorie (activité, zone, rôle de bloc) se code par son rang dans
      l'échelle de gris, jamais par une teinte inventée
- [x] Icône de l'app générée par `tools/build-pwa-icons.php` — **depuis
      `assets/icons/kadens-red-black.png`**, pas depuis `kadens.png` comme le
      ticket l'écrivait : une variante de la marque est née en cours de route
      pour que l'app se distingue du site sur un écran d'accueil. Elle arrive
      déjà réduite au K, il n'y a donc plus de traits de vitesse à isoler par
      composantes connexes ; c'est le fond blanc qu'il faut retirer (voir la
      décision 1 ci-dessous)
- [x] Écran de démarrage natif cohérent avec celui de la PWA — même traitement
      (marque centrée sur papier), marque distincte pour la même raison
- [x] Navigation basse à trois entrées, cohérente avec le web
- [x] Zones sûres respectées (barre gestuelle Android)

**Livré le 05/08/2026.** Le ticket arrivait à moitié fait sur ses deux premières
cases : le socle de KL-22 ayant posé les tokens dès le départ, aucun écran ne
portait de couleur en dur, d'ombre ni de rayon parasite. Ce qui restait était
ailleurs — les **visuels de l'app**, la **barre basse**, les **zones sûres** —
et six décisions ont été prises en le faisant.

1. **Les visuels Android sortent du dépôt web, et d'une marque à eux.** Les
   fichiers d'`assets/images/` étaient des **copies manuelles** des visuels PWA,
   en RGB sans alpha. Conséquence invisible en revue et bien visible sur un
   lanceur : `adaptiveIcon.backgroundColor` ne se voyait jamais, le fond blanc
   étant cuit dans l'image. `tools/build-pwa-icons.php` gagne donc une section
   Android (`public/pwa/android/`) et le mobile un `npm run sync:icons`, dans le
   sens que les polices avaient déjà tracé : `public/fonts/*.ttf` ne sert lui non
   plus jamais au web, il existe pour le téléphone. Corollaire technique : tout
   est transparent sauf `icon.png`, parce que sur Android la couleur de fond se
   déclare et se compose à l'affichage.
   **Le script a désormais deux sources**, et c'est le point : le site garde le
   lockup complet (`kadens.png`), l'app prend la variante rouge et noire
   (`kadens-red-black.png`), pour être reconnaissable au milieu d'un écran
   d'accueil — deux icônes de la même famille au même endroit se confondent.
   Cette seconde source arrive **déjà réduite au K, sur fond blanc opaque** :
   `isolateGlyph()` n'y sert à rien (il raisonne sur l'alpha, et une image sans
   alpha n'a qu'une composante, le canevas entier), et il n'y a de toute façon
   pas de traits de vitesse à retirer. C'est `unmatte()` qui la prépare, en
   **récupérant l'antialiasing plutôt qu'en seuillant** : les deux teintes de la
   marque ayant chacune un canal à zéro, ce canal vaut exactement `255·(1−a)` et
   donne l'opacité du pixel. Un seuil aurait dentelé les diagonales du K, qui
   sont tout ce que cette marque a à montrer.
2. **La silhouette de notification et la couche monochrome ne sont qu'un alpha.**
   Android ignore les canaux de couleur des deux et teinte lui-même. `flatten()`
   les aplatit donc sur une seule couleur en conservant l'alpha : ça ne change
   rien à l'écran, mais un fichier ouvert dans un visualiseur dit ce qu'il est.
   Ça referme au passage la limite laissée par KL-31, où le repos s'annonçait
   avec la silhouette par défaut de l'icône d'app.
3. **La barre basse transpose la forme du web, pas ses destinations.** Le web
   navigue en Séances / Plans / Calendrier et aucune des trois n'existe ici : le
   téléphone ne compose pas, il déroule. Ce qui se transpose est le dessin
   (56 points, filet d'encre, liséré haut sur l'actif, trois cibles au pouce),
   appliqué aux trois questions de cette app-là — **Aujourd'hui, Historique,
   Réglages**. L'historique est donc né avec la barre : la bande de jours s'arrête
   à J-2, et « qu'est-ce que j'ai fait » n'avait pas d'écran. Sa portée est celle
   de la base locale, donc la fenêtre du serveur (J-30 → J+14), et l'écran le
   **dit** plutôt que de s'arrêter sans prévenir — poser une seconde borne ici
   aurait créé une fenêtre de plus à tenir d'accord avec celle de §4.5.
4. **L'historique retient ce qui a eu lieu, pas ce qui est passé.** Clôturée ici
   (`ended_at`) ou tranchée par le serveur (`done`, `missed`). Une séance
   d'avant-hier jamais ouverte n'est pas de l'historique, c'est un trou — et la
   bande de jours la montre déjà.
5. **Une séance en cours vit au-dessus de la barre, pas dedans.** Elle reste un
   écran de pile : trois onglets sous le pouce y disputeraient la place à la
   seule cible qui compte. En sortir, c'est revenir, pas changer d'onglet. C'est
   aussi ce qui a fait choisir les composants **sans style** d'`expo-router/ui`
   plutôt que le `<Tabs>` classique : ce dernier étend le navigateur de React
   Navigation, dont la barre se configure (teinte active, badges) mais ne se
   dessine pas — or l'identité Presse n'est pas une configuration.
6. **Les zones sûres ont deux rendus, jamais cumulés.** Android dessine de bord à
   bord depuis le SDK 54. Le haut était déjà réglé une fois pour toutes par
   `Header` ; le bas ne l'était nulle part, et il n'a pas une seule réponse : une
   **barre peinte** prend l'inset en rembourrage (elle peint sous la barre
   gestuelle, ses cibles remontent), une **page qui défile** l'ajoute à son
   dégagement de contenu. Cumuler les deux creuse deux fois le même vide — d'où
   la barre d'action de « Aujourd'hui », qui a cessé de le compter le jour où la
   barre d'onglets s'est posée sous elle. La règle est écrite dans
   `components/Header.tsx`, là où le lecteur rencontre déjà la question.

**Deux corrections de fond au passage.** Le rouge d'un **échec** se dit
`statusMissed` et non `primary` : les deux partagent leur valeur aujourd'hui, pas
leur sens, et le web pose déjà `--color-status-missed` sur `.kd-flash--error` —
trois écrans suivaient l'accent. Et l'indicateur d'attente du bootstrap passe à
l'encre : attendre n'est ni une action primaire, ni de l'intensité, ni un échec.
Le jeu de glyphes arrivé avec la barre (Lucide, figé en local dans
`components/Icon.tsx`, recopié depuis `assets/icons/lucide/` du dépôt web) referme
enfin la note de KL-23 sur le bouton de retour, qui n'avait pas d'icône faute de
jeu.

**Vérifié** : `npm run typecheck`, `npm run lint`, `npm run format:check`, **94
contrôles hors React Native** en 11 suites (deux neufs, sur ce qui entre dans
l'historique et sur le décompte de séries de sa requête jumelle, celle-ci n'ayant
plus de filtre de date pour rattraper une dérive), et `npx expo export` pour
Android **et** web — aucune route fantôme, le groupe `(tabs)` ne s'ajoutant pas à
l'URL.

**Limites de ce ticket.** Rien n'a été observé sur l'appareil : l'icône du
lanceur et sa version thématisée, la silhouette du repos dans la barre de statut,
l'écran de démarrage et son enchaînement avec l'app, les trois onglets au pouce
restent à valider à l'œil — et `react-native-svg` étant un module **natif**,
`npm run android` est obligatoire, un rechargement Metro ne suffit pas. Un point
précis à regarder : la **teinte du trait gestuel** d'Android sur la barre
d'onglets claire. Le système la choisit seul, l'app ne la pilote pas (ce serait
`expo-navigation-bar`, un module de plus) ; si le trait ressort clair sur clair,
c'est une ligne à ajouter, pas une reprise. Le fond de l'écran de démarrage suit
le manifest de la PWA (`#ffffff`) et non le papier de l'app (`#dcdcd7`) : c'est
« cohérent avec la PWA » au pied de la lettre, au prix du même très léger saut de
valeur que le web a déjà au lancement. Enfin la variante rouge et noire fait
**512 px** là où le lockup du site en fait 1254 : rien n'est agrandi (l'icône de
repli sort à 512, le plus gros besoin d'Android étant la mipmap xxxhdpi à 192),
mais une source plus grande laisserait plus de marge le jour où un format
l'exigerait. Ses teintes (`#FF0000`, `#000000`) ne sont pas celles des tokens
(`#d8261e`, `#0b0b0b`) — c'est assumé : une marque n'est pas de l'interface, et
le logo du site n'est pas dans la palette non plus.

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
- [ ] Clavier qui n'osbrue pas la vue
- [ ] fix le bug quand on entre une valeur numérique du premier chiffre qui n'est pas pris en compte
- [ ] Ajouter une nouvelle série n'ouvre pas la modale par défaut
- [ ] La vue des derière fois et record est amélioré et explicite (tableau, plutot que des lignes qui peuvent être tronqué)
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
