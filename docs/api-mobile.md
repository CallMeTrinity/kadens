# API mobile de Kadens

Le contrat entre le serveur Symfony et l'app Android « Kadens Live » (KL-21 et
suivants). Onze endpoints, un seul mécanisme d'authentification, un seul format
d'erreur.

Ce document est la **référence d'implémentation du client**. Le *pourquoi* de
chaque décision vit ailleurs et n'est pas recopié ici :

- [`docs/feature-live-tracking.md`](./feature-live-tracking.md) — le cadrage du
  chantier et les 51 tickets.
- [`CLAUDE.md`](../CLAUDE.md) §3 et §6 — les règles verrouillées et ce que chaque
  ticket livré pose.

Tous les exemples `curl` de ce fichier ont été **réellement exécutés** contre le
serveur de développement (`https://127.0.0.1:8000`, compte
`demo-api@kadens.test`) le 2 août 2026 — le 5 août pour §6.10, ajouté par KL-43 —
et les réponses sont collées telles quelles — seules les clés secrètes sont raccourcies. Le `-k` des exemples
n'existe que pour le certificat auto-signé du serveur local : en production, il
n'a rien à faire là.

---

## 1. Le socle

### URL de base

Le client ne code jamais l'URL en dur : il la reçoit dans le QR d'appairage
(§3) et la valide par `GET /api/ping`. En production,
`https://kadens.antoninpamart.fr`.

### Format

- Requêtes et réponses en **JSON**, `Content-Type: application/json` sur tout
  corps envoyé.
- Réponses de succès en UTF-8 non échappé (`JSON_UNESCAPED_UNICODE`) : les noms
  d'exercices français partent en clair, pas en `\uXXXX`. Les réponses d'erreur,
  elles, échappent — sans conséquence, un décodeur JSON rend la même chaîne.
- **Unités normalisées** : charges en **kg** (décimal), distances en **mètres**,
  durées en **secondes**. Jamais de texte mixte type « 5 km ». Le seul champ
  pré-formaté est `summary` (§6.5).
- Dates seules en `AAAA-MM-JJ`, horodatages en **ISO 8601** — voir la limite
  connue du §7 sur les fuseaux.

### Authentification

Un **jeton porteur** dans l'en-tête `Authorization`, et rien d'autre :

```
Authorization: Bearer 9873ffbd6e6138c252ba375ae54c72052341d72977c86d35d9f914acdc036dca
```

- 64 caractères hexadécimaux (256 bits d'aléa). Le serveur n'en stocke que
  l'empreinte SHA-256 : **il est rendu une seule fois**, par la réponse qui
  l'émet. Perdu, il se remplace, il ne se retrouve pas.
- **Expiration glissante de 90 jours** : chaque requête authentifiée repousse
  l'échéance. Un téléphone dont on se sert ne se déconnecte jamais.
- Le pare-feu `^/api` est **stateless** : aucune réponse ne pose de cookie, il
  n'y a ni session ni CSRF à gérer côté client.

**Contrat à respecter : ne jamais envoyer d'en-tête `Authorization` sur
`POST /api/auth/login`, `POST /api/auth/pair` ni `GET /api/app-version`.**
L'authenticator se déclenche sur la seule présence d'un `Bearer`, quelle que soit
la route : un jeton périmé présenté là ferait échouer la requête **avant** le
contrôleur, et la reconnexion serait impossible. Le flux de reconnexion est donc :

```
401 → effacer le jeton local → POST /api/auth/login (sans en-tête)
```

`POST /api/auth/logout`, lui, **exige** l'en-tête : c'est le jeton présenté qu'il
révoque.

### Erreurs

Toutes les erreurs suivent la **RFC 9457**, en `application/problem+json` :

```json
{ "type": "about:blank", "title": "Unauthorized", "status": 401, "detail": "Authentification requise." }
```

| Champ | Rôle |
|---|---|
| `type` | Toujours `about:blank`. Il n'y a pas de catalogue de types à connaître. |
| `title` | Le libellé HTTP du statut, en anglais. Dérivé du code, jamais écrit à la main. |
| `status` | Le code HTTP, répété dans le corps. |
| `detail` | Un message en français, destiné à être lu. **Ne jamais l'analyser** : il change sans préavis, c'est `status` qui décide du comportement. |
| `violations` | *(422 uniquement)* La liste `{field, message}` des champs refusés. |

Aucun détail interne ne sort jamais : ni trace de pile, ni requête SQL, ni nom de
classe, y compris sur une 500. Un 409 dit qu'il y a conflit, pas quelle ligne le
provoque.

**Statuts communs à tous les endpoints**

| Code | Quand |
|---|---|
| `401` | Jeton absent, inconnu, révoqué ou périmé. Les quatre cas rendent la **même** réponse, à dessein. En-tête `WWW-Authenticate: Bearer`. |
| `403` | Authentifié, mais la ressource est à quelqu'un d'autre. |
| `404` | Ressource introuvable — ou invisible, sur les endpoints où distinguer serait un oracle (§6.6). |
| `405` | Mauvaise méthode. En-tête `Allow` avec les méthodes acceptées. |
| `429` | Limiteur de débit. En-tête `Retry-After` en **secondes**, à respecter. |
| `500` | Défaut serveur. Rejouable tel quel : rien n'a été écrit à moitié (§4.4). |

---

## 2. Table des endpoints

| # | Méthode | Chemin | Jeton | Ticket |
|---|---|---|---|---|
| 6.1 | `POST` | `/api/auth/login` | non | KL-11 |
| 6.2 | `POST` | `/api/auth/pair` | non | KL-46 |
| 6.3 | `POST` | `/api/auth/logout` | oui | KL-11 |
| 6.4 | `GET` | `/api/me` | oui | KL-11 |
| 6.4 | `GET` | `/api/ping` | oui | KL-10 |
| 6.5 | `GET` | `/api/bootstrap` | oui | KL-14 |
| 6.6 | `GET` | `/api/exercises/{id}/history` | oui | KL-17 |
| 6.7 | `GET` | `/api/schedule/{uuid}` | oui | KL-15 |
| 6.8 | `PUT` | `/api/schedule/{uuid}` | oui | KL-16 |
| 6.9 | `DELETE` | `/api/schedule/{uuid}` | oui | KL-16 |
| 6.10 | `GET` | `/api/app-version` | **non** | KL-43 |

Deux routes **web** complètent le protocole d'appairage — elles vivent hors de
`^/api`, sur la session du navigateur, et le mobile ne les appelle jamais :
`POST /pairing/code` et `GET /pairing/{id}/status` (§3).

---

## 3. Protocole d'appairage

Le chemin nominal pour connecter un téléphone. La saisie du mot de passe (§6.1)
n'est que le repli quand la caméra refuse.

### 3.1 Ce que le QR contient

**Le QR ne porte jamais de jeton.** Il porte un code de 8 caractères, à usage
unique, valable **2 minutes** — une photo de l'écran ne vaut donc rien deux
minutes plus tard. Le code est stocké haché côté serveur ; ce qui protège, ce
n'est pas son entropie (8 caractères sur 32 symboles, soit 40 bits) mais la
fenêtre courte, l'usage unique et le limiteur de débit.

Format exact de la charge utile encodée, en JSON compact :

```json
{"url":"https://127.0.0.1:8000","code":"NWJEE6V2","exp":"2026-08-02T22:05:13+00:00"}
```

| Clé | Contenu |
|---|---|
| `url` | **Base** du serveur (schéma + hôte + éventuel sous-répertoire), sans chemin d'endpoint. Le client la garde comme « URL de serveur » et la valide par `GET /api/ping`. C'est ce qui règle l'IP LAN en développement sans rien saisir. |
| `code` | Le code en clair, 8 caractères. Alphabet `23456789ABCDEFGHJKLMNPQRSTUVWXYZ` — ni `O`/`0` ni `I`/`1`/`l`, le code devant rester saisissable à la main en repli. |
| `exp` | Échéance ISO 8601. Passée cette date, `POST /api/auth/pair` refuse. |

Le code est **normalisé avant comparaison** (espaces retirés, mis en
majuscules) : la saisie manuelle `« nwjee6v2 »` retombe donc sur la même
empreinte que le scan.

### 3.2 Le déroulé complet

```
Desktop (session web)                     Téléphone
─────────────────────                     ─────────
POST /pairing/code
  → panneau HTML avec le QR
  → invalide les codes non consommés
    du même compte (« un écran, un code »)
                                          scan du QR → {url, code, exp}
                                          GET  {url}/api/ping      (valide l'URL)
                                          POST {url}/api/auth/pair (échange)
                                                                 → 201 {token, user}
GET /pairing/{id}/status  (sondage)
  → {"used":true,"device":"iPhone 15"}
  → confirmation visuelle, arrêt du sondage
```

Trois règles à connaître côté client :

1. **Le compte vient du code, jamais de la requête.** Le jeton émis est celui de
   l'utilisateur qui a *affiché* le QR. Aucun champ du corps ne peut viser un
   autre compte.
2. **Inconnu, expiré et déjà utilisé rendent le même `400`** — pas 401 : il ne
   faut pas réessayer avec le même code, il faut en demander un autre au
   desktop.
3. **L'usage unique est garanti par la base**, pas par une intention du code :
   deux scans simultanés du même QR ne peuvent pas réussir tous les deux.

### 3.3 Vérification de bout en bout

Émission côté desktop (session web, jeton CSRF `pairing_code`) :

```console
$ curl -sk -b cookies.txt -X POST https://127.0.0.1:8000/pairing/code \
       --data-urlencode "_token=$CSRF"
# → 200, panneau HTML contenant le QR et le code en clair : NWJEE6V2
```

État du code avant l'échange :

```console
$ curl -sk -b cookies.txt https://127.0.0.1:8000/pairing/9/status
{"used":false,"device":null,"expired":false}
```

Échange côté téléphone :

```console
$ curl -sk -X POST https://127.0.0.1:8000/api/auth/pair \
       -H 'Content-Type: application/json' \
       -d '{"code":"NWJEE6V2","deviceName":"iPhone 15 (QR)"}'
{"token":"e5a4371416d4…","user":{"id":4,"email":"demo-api@kadens.test","roles":["ROLE_USER"],"coach":false}}
# HTTP 201
```

Un code déjà consommé, rejoué — ici `KGHM64PT`, échangé quelques minutes plus
tôt dans la même session de vérification :

```console
$ curl -sk -X POST https://127.0.0.1:8000/api/auth/pair \
       -H 'Content-Type: application/json' \
       -d '{"code":"KGHM64PT","deviceName":"Pixel 8 (QR)"}'
{"type":"about:blank","title":"Bad Request","status":400,"detail":"Code d'appairage invalide ou expiré."}
# HTTP 400
```

Et le même, en minuscules et entouré d'espaces, pour montrer que la
normalisation ne rouvre rien — elle ne fait que retomber sur la même empreinte :

```console
$ curl -sk -X POST https://127.0.0.1:8000/api/auth/pair \
       -H 'Content-Type: application/json' \
       -d '{"code":" kghm64pt ","deviceName":"Pixel 8 (QR)"}'
{"type":"about:blank","title":"Bad Request","status":400,"detail":"Code d'appairage invalide ou expiré."}
# HTTP 400
```

Confirmation côté desktop, qui arrête son sondage :

```console
$ curl -sk -b cookies.txt https://127.0.0.1:8000/pairing/9/status
{"used":true,"device":"iPhone 15 (QR)","expired":false}
```

`device` est un **snapshot** du nom d'appareil, pas une relation : révoquer le
jeton plus tard ne l'efface pas. Le sondage s'arrête sur `used`, sur `expired`,
ou sur une réponse non-`ok` (le code a été régénéré ailleurs, donc supprimé).
L'état d'un code qui n'est pas le sien rend **404**, jamais 403 : distinguer
confirmerait son existence.

---

## 4. Protocole de synchronisation

### 4.1 Le partage d'autorité, en une phrase

> **Le téléphone fait autorité sur le réalisé. Le serveur fait autorité sur la
> programmation.**

« Le mobile est la seule source d'écriture du réalisé » ne dit rien du planning.
Déplacer une séance, la renommer, la rattacher à un plan sont des gestes de
**programmation**, ouverts au coach sur le web — un téléphone resté trois jours
hors réseau ramènerait sinon la séance que le coach vient de décaler.

Champ par champ, sur `PUT /api/schedule/{uuid}` :

| Champ | Séance inconnue (création) | Séance connue |
|---|---|---|
| `log` | écrit | **écrase intégralement** |
| `startedAt`, `endedAt` | écrits | **écrasent** |
| `date` | écrite (requise) | **ignorée** |
| `title` | écrit (nomme la séance libre) | **ignoré** |
| `status` | `done` clôture, le reste est sans effet | idem — et rien ne **déclôture** |
| `completionNotes` | écrite si présente | écrite si présente, **jamais effacée** |
| `position`, `name` dérivé, tout champ calculé | posés par le serveur | posés par le serveur |

Vérification, sur une séance déjà clôturée — un document qui essaie de tout
changer à la fois :

```console
$ curl -sk -X PUT https://127.0.0.1:8000/api/schedule/019fc483-3f9d-76af-8d5f-f789cddf78d8 \
       -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
       -d '{"date":"2026-12-25","title":"Renommée par le téléphone","status":"planned","log":[]}'
```

```json
{
  "date": "2026-08-02",
  "title": "Haut du corps — force",
  "status": "done",
  "completionNotes": "Dernière série courte, épaule droite sensible.",
  "log": null
}
```

La date, le titre et le statut ont résisté ; la note de clôture est intacte ; le
`log` vide, lui, a bien remplacé le réalisé. C'est exactement le partage
d'autorité.

### 4.2 Idempotence

Deux identifiants sont **posés par le client**, hors réseau, avant que le serveur
sache que quoi que ce soit existe :

- l'`uuid` de la séance datée, dans l'URL du `PUT` ;
- l'`uuid` de chaque série (`log[].sets[].uuid`).

C'est ce qui rend une file de mutations rejouable. Le même document envoyé trois
fois donne **une** séance et **un** jeu de séries ; seul le statut HTTP change :
`201` à la création, `200` ensuite. Cette différence est une information utile —
elle dit au client quelles mutations de sa file étaient déjà passées.

Règle de rédaction du client : **envoyer le document complet**, jamais une série
à la fois. Après l'appel, le réalisé de la séance **est** le document.

### 4.3 Comment les conflits sont tranchés

| Situation | Réponse | Pourquoi |
|---|---|---|
| Le document rejoue un état déjà écrit | `200`, sans changement | Idempotence (§4.2). |
| Le document contredit le planning (`date`, `title`, `status` régressif) | `200`, champs ignorés | Le serveur fait autorité sur la programmation (§4.1). |
| Un `uuid` de série appartient déjà à une **autre** séance | `409` | Le document n'est pas malformé, il entre en conflit avec un état existant. Rien n'est écrit. |
| Un `exerciseId` ou un `sourcePrescribedId` désigne quelque chose d'invisible, d'inconnu, ou la ligne du programme d'une autre séance | `422` + `violations` | Les références se **vérifient avant d'écrire**. Les mettre à `null` laisserait la séance lisible mais muette : plus d'historique, plus de record, plus d'appariement prévu/réalisé, et rien pour le signaler. Inconnu et interdit rendent la même violation. |
| L'`uuid` du corps ne correspond pas à celui de l'URL | `422` | Deux identifiants qui se contredisent dans un upsert, c'est demander au serveur laquelle des deux lignes écrire. |
| La séance existe mais appartient à un autre | `403` | |
| La séance n'existe pas, sur `GET`/`DELETE` | `404` | |

Il n'y a **pas** de fusion automatique, pas de vecteur de version, pas de
résolution à trois voies. Le partage d'autorité est net par champ, ce qui rend
la fusion inutile.

### 4.4 Ce qui n'est jamais écrit à moitié

Le remplacement du réalisé se fait **dans une transaction**. Un `PUT` qui échoue
laisse la séance exactement telle qu'elle était : un `422`, un `409` ou une `500`
sont rejouables tels quels sans nettoyage préalable. Vérifié — après un `409` et
deux `422`, aucune séance fantôme n'apparaît en base.

### 4.5 Le cycle de synchronisation recommandé

```
1. Au démarrage / à la reconnexion :
   GET /api/bootstrap?since=<serverTime du dernier appel réussi>
   → remplacer la bibliothèque par le delta reçu
   → appliquer deleted.exercises / deleted.schedule
   → REMPLACER la fenêtre de séances datées par schedule (voir ci-dessous)
   → stocker serverTime

2. Pendant la séance : tout en local, rien sur le réseau.

3. À la clôture, et à chaque reconnexion, pour chaque séance modifiée :
   PUT /api/schedule/{uuid}   (document complet, rejouable)

4. Écran de progression, en ligne uniquement :
   GET /api/exercises/{id}/history
```

**`window` fait autorité.** La réponse annonce son intervalle en clair et ce
qu'il contient le remplace **en entier** : une séance datée que le client garde
dans cet intervalle et qui n'est pas dans la réponse n'existe plus. Déplacée hors
fenêtre ou supprimée, le geste local est le même — d'où l'absence de pierre
tombale pour un simple déplacement.

**`?since` n'allège que la bibliothèque** (et la liste des disparus). La fenêtre
de séances datées et l'historique partent toujours en entier : la fraîcheur d'une
séance datée n'est portée par aucune colonne (elle dépend de `Workout` → `Block`
→ `PrescribedExercise` → `PrescribedSet`, et aucun niveau n'horodate son parent),
et un delta naïf manquerait en silence le programme que le coach vient de
corriger.

Économie réelle mesurée sur le compte de démonstration : **74 651 octets** pour
un jeu complet, **1 514 octets** pour un delta d'une heure.

---

## 5. Ce qui ne sort jamais de l'API

- **`Workout.notes` et `PlanTemplate.notes`** — le bloc-notes privé du
  propriétaire. Même garde que l'export Excel, le flux ICS et la page publique.
  Un test parcourt tous les endpoints et échoue si la chaîne apparaît quelque
  part. La note d'un **exercice prescrit** (`blocks[].exercises[].notes`), elle,
  sort : elle s'adresse à qui exécute.
- **Le calendrier d'autrui.** La bibliothèque d'exercices est symétrique (soi,
  ses coachs, ses athlètes — une séance composée par le coach pose ses variantes
  maison, elles doivent être lisibles), mais `schedule` ne contient **que** les
  séances datées du porteur du jeton.
- **L'historique d'autrui.** `GET /api/exercises/{id}/history` ne lit que le
  réalisé du porteur du jeton, même sur un exercice de la bibliothèque globale
  pratiqué par tout le monde. Un coach y voit *sa* trajectoire, pas celle de son
  athlète ; le réalisé de l'athlète se lit sur `GET /api/schedule/{uuid}`.
- **Le droit d'écrire le réalisé d'autrui.** Le coach lit (`VIEW`), il n'écrit
  pas (`LOG`, propriétaire seul) : un `PUT` de coach sur la séance de son athlète
  rend `403`.

---

## 6. Référence des endpoints

### 6.1 `POST /api/auth/login`

Connexion par mot de passe — **le repli**, quand la caméra refuse. Pas de jeton
en en-tête.

**Corps**

| Champ | Type | Requis | Contrainte |
|---|---|---|---|
| `email` | string | oui | Chaîne non vide. |
| `password` | string | oui | Chaîne non vide. |
| `deviceName` | string | oui | ≤ 100 caractères. Affiché dans `/profile/settings`, où il sert à décider quoi révoquer. |

**Réponse `201`** — le secret n'existe que dans cette réponse.

```console
$ curl -sk -X POST https://127.0.0.1:8000/api/auth/login \
       -H 'Content-Type: application/json' \
       -d '{"email":"demo-api@kadens.test","password":"demo-api-2026","deviceName":"Pixel 8"}'
{"token":"9873ffbd6e6138c252ba375ae54c72052341d72977c86d35d9f914acdc036dca",
 "user":{"id":4,"email":"demo-api@kadens.test","roles":["ROLE_USER"],"coach":false}}
```

`coach` est dérivable de `roles`, mais il est exposé pour que le client n'ait pas
à connaître la convention de nommage de Symfony.

**Erreurs**

| Code | Cas |
|---|---|
| `400` | Champ manquant ou vide, `deviceName` trop long, corps JSON illisible. |
| `401` | Identifiants invalides. **Email inconnu et mot de passe faux rendent le même corps, au caractère près, et en un temps comparable** : la connexion n'est pas un oracle d'existence de compte. Ne rien déduire de la différence. |
| `429` | Plus de **5 tentatives par minute et par IP**. Rendu avant même la lecture du corps : le bon mot de passe ne passe pas davantage. |

```console
$ for i in 1 2 3 4 5 6; do
    curl -sk -o /dev/null -w "%{http_code} " -X POST https://127.0.0.1:8000/api/auth/login \
         -H 'Content-Type: application/json' \
         -d '{"email":"demo-api@kadens.test","password":"mauvais","deviceName":"Pixel 8"}'
  done
401 401 401 401 401 429    # sur la 6e : retry-after: 58
```

### 6.2 `POST /api/auth/pair`

Échange d'un code de QR contre un jeton. Protocole complet au §3.

**Corps**

| Champ | Type | Requis | Contrainte |
|---|---|---|---|
| `code` | string | oui | 8 caractères, normalisé (trim + majuscules). |
| `deviceName` | string | oui | ≤ 100 caractères. |

**Réponse `201`** : identique à celle de `login`.

**Erreurs** : `400` (champ manquant, ou code inconnu / expiré / déjà consommé —
un seul message pour les trois), `429` (**10 par minute et par IP**, rendu avant
toute lecture de la base pour qu'un quota épuisé ne consomme pas non plus un code
valide).

### 6.3 `POST /api/auth/logout`

Révoque **le jeton présenté**, et lui seul. Les autres appareils du compte
restent connectés — « tout révoquer » est un geste explicite de
`/profile/settings`. Révoquer **supprime** la ligne : aucune lecture ultérieure
n'a besoin de se souvenir d'un état « révoqué ».

```console
$ curl -sk -X POST https://127.0.0.1:8000/api/auth/logout -H "Authorization: Bearer $TOKEN" -i
HTTP/2 204

$ curl -sk https://127.0.0.1:8000/api/ping -H "Authorization: Bearer $TOKEN"
{"type":"about:blank","title":"Unauthorized","status":401,"detail":"Jeton absent ou invalide."}
```

**Réponse `204`**, sans corps. **Erreur** : `401` sans jeton valide — il n'y a
rien à révoquer, quand bien même on saurait qui appelle.

### 6.4 `GET /api/me` et `GET /api/ping`

`ping` est une **sonde muette sur l'identité** : elle sert à valider l'URL de
serveur lue dans le QR et à vérifier qu'un jeton vaut encore quelque chose.

```console
$ curl -sk https://127.0.0.1:8000/api/ping -H "Authorization: Bearer $TOKEN"
{"ok":true,"user":"demo-api@kadens.test"}
```

`me` décrit le compte **et l'appareil courant** :

```console
$ curl -sk https://127.0.0.1:8000/api/me -H "Authorization: Bearer $TOKEN"
{"user":{"id":4,"email":"demo-api@kadens.test","roles":["ROLE_USER"],"coach":false},
 "device":{"name":"Pixel 8","lastUsedAt":"2026-08-02T21:58:55+00:00",
           "lastBootstrapAt":null,"expiresAt":"2026-10-31T21:58:55+00:00"}}
```

`lastUsedAt` bouge à **chaque** requête, `lastBootstrapAt` seulement au
`GET /api/bootstrap` : c'est ce qui distingue « ce téléphone répond » de « ce
téléphone est à jour ». `expiresAt` est l'échéance glissante à 90 jours.

### 6.5 `GET /api/bootstrap`

Tout ce dont le téléphone a besoin pour travailler hors réseau, en une requête.
C'est l'endpoint central : ce que l'app fait hors ligne, elle le fait sur ce
qu'il a descendu.

**Paramètre de requête**

| Nom | Type | Effet |
|---|---|---|
| `since` | ISO 8601 | Delta. N'allège **que** `exercises` et remplit `deleted`. Absent = jeu complet, et `deleted` est vide (un jeu complet remplace tout, il n'y a rien à défalquer). |

**Réponse `200`**

| Clé | Contenu |
|---|---|
| `serverTime` | L'horloge du **serveur**. C'est cette valeur que le client stocke et renvoie en `since` — se fier à la pendule du téléphone ferait dépendre la synchro d'une horloge qu'on ne contrôle pas. |
| `since` | L'écho du paramètre reçu, ou `null`. |
| `window` | `{from, to}` — J-30 → J+14. **Fait autorité** (§4.5). |
| `exercises` | La bibliothèque visible : la sienne, la globale, celle de ses coachs et de ses athlètes acceptés. |
| `schedule` | Les séances datées de la fenêtre **qui se consignent**, structure du §6.7. Voir juste en dessous. |
| `history` | Dernière performance et record par exercice. **Toujours une liste**, jamais un objet indexé par identifiant. |
| `deleted` | `{exercises: [id], schedule: [uuid]}` — ce que la base locale doit oublier. |

**La fenêtre n'est pas tout le calendrier.** Le réalisé se logue en muscu, jamais
en cardio : une séance datée dont le programme ne contient **aucun exercice
d'activité `gym`** ne descend pas. Elle occuperait l'écran du jour sans rien
pouvoir y écrire. Trois exceptions, dans cet ordre :

1. **Une séance qui porte du réalisé descend toujours.** La fenêtre fait autorité
   (§4.5) : ce qu'elle ne contient pas est effacé côté client. Sans cette règle,
   retirer le dernier exercice de muscu d'une séance déjà faite lui supprimerait
   son réalisé.
2. **Une séance sans programme descend toujours** — séance libre du téléphone,
   coquille encore vide posée sur le web.
3. Une séance **mixte** (renforcement puis footing de retour au calme) descend :
   il suffit d'un exercice de muscu.

Corollaire client : une séance qui disparaît de la fenêtre d'un pull à l'autre
peut avoir été supprimée, déplacée hors fenêtre, **ou vidée de sa muscu**. Le
geste local est le même dans les trois cas. `GET /api/schedule/{uuid}` (§6.7), lui,
ne filtre rien : il rend ce qu'on lui désigne.

```console
$ curl -sk "https://127.0.0.1:8000/api/bootstrap" -H "Authorization: Bearer $TOKEN"
```

```json
{
  "serverTime": "2026-08-02T21:58:55+00:00",
  "since": null,
  "window": { "from": "2026-07-03", "to": "2026-08-16" },
  "exercises": [
    {
      "id": 131,
      "name": "Abdominaux à la barre",
      "nameEn": "Hanging leg raise",
      "description": "Avec une roue ou une barre lestée au sol, rouler vers l'avant en gainant puis revenir. Travail intense du gainage.",
      "activity": "gym",
      "targetAreas": ["abs", "obliques"],
      "mediaUrl": null,
      "global": true,
      "updatedAt": "2026-07-22T14:43:19+00:00"
    }
  ],
  "schedule": [],
  "history": [],
  "deleted": { "exercises": [], "schedule": [] }
}
```

Delta d'une heure, sur le même compte — 241 exercices deviennent 0, la fenêtre
reste entière :

```console
$ curl -sk "https://127.0.0.1:8000/api/bootstrap?since=2026-08-02T21:00:00Z" -H "Authorization: Bearer $TOKEN"
```

Réponse résumée (la structure est la même que ci-dessus ; les trois tableaux sont
remplacés ici par leur **nombre d'entrées**) :

| Clé | Jeu complet | Delta d'une heure |
|---|---|---|
| `exercises` | 241 entrées | **0** |
| `schedule` | toute la fenêtre | toute la fenêtre |
| `history` | 1 entrée | 1 entrée |
| `deleted.schedule` | `[]` | `["019fc47d-16e1-7bb4-988f-6d06ce848355"]` |
| Taille | 74 651 octets | **1 514 octets** |

L'uuid listé dans `deleted` est celui d'une séance libre supprimée juste avant
(§6.9).

**`nameEn`** (ajouté) porte le nom anglais de l'exercice, `null` quand le nom
français EST déjà l'anglais (« Dips », « Fartlek »). Le champ est **additif** :
un client qui l'ignore continue d'afficher `name`, ce que fait l'app Android
aujourd'hui — la préférence de langue est pour l'instant une affaire de
navigateur. Un client qui l'adopte doit garder le repli sur `name`, `nameEn`
étant facultatif par construction.

Le champ n'a **pas** demandé de forçage du delta : la commande
`app:import-exercises` réécrit les lignes qu'elle renseigne, `updatedAt` se pose
donc tout seul et le prochain `?since` les remonte.

**Erreurs** : `400` si `since` n'est pas une date ISO 8601 (`?since=hier` est
refusé — le constructeur PHP l'accepterait comme « hier », ce qui produirait une
donnée plausible et fausse), `401` sans jeton.

**Budget** : la réponse est tenue sous **1 Mo** et son coût en requêtes SQL ne
dépend pas du volume de données. Mesuré ci-dessus : 74,6 ko pour 241 exercices.

### 6.6 `GET /api/exercises/{id}/history`

La trajectoire d'un exercice : les **dix dernières séances** où il a été
travaillé. C'est le **seul écran de l'app qui suppose du réseau**, et c'est
assumé — le bootstrap descend déjà le dernier point et le record de toute la
bibliothèque (ce qui s'affiche en séance, hors ligne) ; dix séances par exercice
en plus feraient grossir une réponse bornée à 1 Mo pour un écran qu'on ouvre
rarement.

```console
$ curl -sk https://127.0.0.1:8000/api/exercises/106/history -H "Authorization: Bearer $TOKEN"
```

```json
{
  "exerciseId": 106,
  "last": {
    "date": "2026-08-02",
    "workingSets": 3,
    "tonnageKg": 1760,
    "topWeightKg": 80,
    "sets": [
      { "type": "normal", "count": 2, "reps": 8, "weightKg": 80, "durationSeconds": null, "firstIndex": 1, "lastIndex": 2 },
      { "type": "normal", "count": 1, "reps": 6, "weightKg": 80, "durationSeconds": null, "firstIndex": 3, "lastIndex": 3 }
    ]
  },
  "best": { "date": "2026-08-02", "type": "normal", "reps": 8, "weightKg": 80, "durationSeconds": null },
  "sessions": [ /* … même forme que `last`, dix entrées au plus, la plus récente d'abord */ ]
}
```

- `last` est **exactement** `sessions[0]` : même requête, même règle, aucune
  façon de se contredire. Le champ reste exposé parce que le bootstrap le donne
  déjà sous ce nom.
- `sets` est la vue **condensée** : les séries consécutives identiques sont
  fusionnées (`count`), et `firstIndex`/`lastIndex` conservent leur rang réel.
- **Périmètre** : échauffement exclu, exercice sauté exclu, statut de la séance
  non filtré (le réalisé est un fait dès qu'il est écrit), et **portée du seul
  porteur du jeton**.
- Pas de record sans kilos : une série au poids du corps a une dernière perf, pas
  de `best`.
- Aucun identifiant de séance n'est renvoyé : c'est une trajectoire, pas une
  liste de liens.

**Erreurs** : `401`, et `404` pour un exercice **inconnu comme pour un exercice
invisible** — les deux rendent la même réponse. Contrairement à
`GET /api/schedule/{uuid}`, on ne distingue pas ici, et ce n'est pas la règle
inverse : ce qui décide, c'est la nature de la clé. Un `uuid` posé par le client
ne se devine pas ; un identifiant séquentiel d'exercice s'énumère, et un 403 y
dirait la taille et la composition de la bibliothèque personnelle des autres.

### 6.7 `GET /api/schedule/{uuid}`

Une séance datée seule, dans **la même structure** que celles du bootstrap : il
n'y a qu'un producteur côté serveur, donc un seul désérialiseur à écrire côté
client. Un test compare les deux corps entiers et échoue au premier écart.

```console
$ curl -sk https://127.0.0.1:8000/api/schedule/019fc483-3f9d-76af-8d5f-f789cddf78d8 \
       -H "Authorization: Bearer $TOKEN"
```

```json
{
  "uuid": "019fc483-3f9d-76af-8d5f-f789cddf78d8",
  "date": "2026-08-02",
  "status": "planned",
  "title": "Haut du corps — force",
  "freeform": false,
  "startedAt": null,
  "endedAt": null,
  "completionNotes": null,
  "plan": null,
  "blocks": [
    {
      "id": 136,
      "label": "Bloc principal",
      "role": "main",
      "rounds": 1,
      "exercises": [
        {
          "prescribedId": 325,
          "exerciseId": 106,
          "name": "Développé couché",
          "type": "sets_reps",
          "summary": "4 × 8 @ 80 kg · RPE 8",
          "groupLabel": null,
          "restSeconds": 180,
          "rpe": 8,
          "notes": "Coudes serrés, pause à la poitrine.",
          "sets": [
            { "index": 1, "type": "normal", "reps": 8, "weightKg": 80, "durationSeconds": null },
            { "index": 2, "type": "normal", "reps": 8, "weightKg": 80, "durationSeconds": null },
            { "index": 3, "type": "normal", "reps": 8, "weightKg": 80, "durationSeconds": null },
            { "index": 4, "type": "normal", "reps": 8, "weightKg": 80, "durationSeconds": null }
          ]
        }
      ]
    }
  ],
  "log": null
}
```

**À savoir sur la structure**

| Champ | Remarque |
|---|---|
| `title` | Titre vivant de la séance source, ou snapshot si la source a été supprimée, ou « Séance libre ». Toujours rempli. |
| `freeform` | `true` = séance sans programme (créée par le téléphone, ou source supprimée). `blocks` est alors `[]` — pas une erreur. |
| `plan` | `{id, title}` si la séance vient d'un plan, sinon `null`. |
| `blocks[].rounds` | Tours du bloc entier (circuit). Le bloc est une **section** de la séance. |
| `groupLabel` | `"A1"`, `"A2"`… pour les exercices **liés en superset** dans un bloc. Un libellé dérivé de l'ordre, jamais stocké : ne pas l'analyser pour en déduire une structure, se fier à l'égalité de préfixe. |
| `summary` | La **seule** valeur pré-formatée de l'API (« 4 × 8 @ 80 kg · RPE 8 »). Le cardio ne se saisit pas sur le mobile et ne s'y affiche qu'en lecture : réécrire la mise en forme en TypeScript pour une chaîne qu'on ne fait que peindre serait une duplication sans contrepartie. Les séries, elles, partent en valeurs brutes. |
| `sets` (prescrit) | Une entrée par série, **rang réel** dans `index`. `null` pour un type de prescription qui ne compte pas de séries (`distance_pace`, `duration`…). |
| `log` | `null` tant que rien n'a été consigné. |

**Valeurs d'énumération**

| Enum | Valeurs |
|---|---|
| `status` | `planned`, `done`, `missed` |
| `blocks[].role` | `warmup`, `main`, `cooldown` |
| `type` (prescription) | `sets_reps`, `sets_time`, `amrap`, `for_time`, `distance_pace`, `duration` |
| `type` (série) | `warmup`, `normal`, `degressive`, `to_failure`, `drop_set` |
| `activity` | `gym`, `running`, `swimming`, `cycling`, `mobility`, `other` |
| `targetAreas` | `chest`, `back`, `lower_back`, `traps`, `shoulders`, `biceps`, `triceps`, `forearms`, `abs`, `obliques`, `glutes`, `quadriceps`, `hamstrings`, `adductors`, `calves`, `shins`, `full_body` |

**Erreurs** : `401`, `404` (uuid inconnu), `403` (séance d'un autre — ici on
distingue, cf. §6.6).

### 6.8 `PUT /api/schedule/{uuid}`

L'upsert du réalisé : la séance est créée si l'`uuid` est inconnu, son réalisé
remplacé sinon. Le partage d'autorité est décrit au §4.1, l'idempotence au §4.2.

**Corps**

| Champ | Type | Requis | Contrainte |
|---|---|---|---|
| `uuid` | string | non | S'il est présent, il doit être **égal** à celui de l'URL. |
| `date` | `AAAA-MM-JJ` | **oui** | Utilisée à la création seulement. |
| `title` | string | non | ≤ 255. Création seulement. |
| `status` | enum | non | Seul `done` a un effet. Les autres valeurs passent la validation sans rien faire, pour qu'un client qui recopie le document reçu ne se prenne pas un 422. |
| `startedAt`, `endedAt` | ISO 8601 | non | Écrasent. |
| `completionNotes` | string | non | N'efface jamais l'existante. |
| `log` | tableau | non | ≤ 100 exercices. Remplace **intégralement** le réalisé. |
| `log[].exerciseId` | int | non | Doit désigner un exercice **visible**. Le rattachement à la bibliothèque est ce qui fait entrer le réalisé dans l'historique et les records. |
| `log[].name` | string | non | ≤ 255. Rempli par le serveur depuis la référence quand il manque ; **requis** si aucune référence n'est donnée. |
| `log[].sourcePrescribedId` | int | non | La ligne du programme **de cette séance** dont le réalisé découle. C'est lui, et lui seul, qui apparie prévu et fait. |
| `log[].skipped` | bool | non | Défaut `false`. Un exercice sauté est déclaré, ce n'est pas un trou. |
| `log[].notes` | string | non | Note de l'athlète sur cet exercice. |
| `log[].sets` | tableau | non | ≤ 100 séries. |
| `sets[].uuid` | UUID | **oui** | Posé par le client. Pivot de l'idempotence. |
| `sets[].type` | enum | non | Défaut `normal`. |
| `sets[].reps` | int | non | 0 – 200. |
| `sets[].weightKg` | float | non | 0 – 1000. |
| `sets[].durationSeconds` | int | non | 0 – 86 400. |
| `sets[].rpe` | int | non | 1 – 10. |
| `sets[].completedAt` | ISO 8601 | non | |

**Deux champs n'existent pas, délibérément** : `position` (l'ordre de la liste
fait foi, le serveur renumérote — deux sources pour un seul fait finissent par se
contredire) et tout champ dérivé (`tonnage`, `volume` : le serveur les
recalcule).

**Réponse `201` / `200`** : l'état persisté, relu par le même producteur que le
`GET`.

```console
$ curl -sk -X PUT https://127.0.0.1:8000/api/schedule/019fc483-3f9d-76af-8d5f-f789cddf78d8 \
       -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "date": "2026-08-02",
  "status": "done",
  "startedAt": "2026-08-02T16:04:00Z",
  "endedAt": "2026-08-02T17:11:00Z",
  "completionNotes": "Dernière série courte, épaule droite sensible.",
  "log": [
    { "exerciseId": 106, "sourcePrescribedId": 325, "sets": [
        { "uuid": "019fc484-0000-7000-8000-000000000001", "type": "warmup", "reps": 10, "weightKg": 40 },
        { "uuid": "019fc484-0000-7000-8000-000000000002", "type": "normal", "reps": 8, "weightKg": 80, "rpe": 7 },
        { "uuid": "019fc484-0000-7000-8000-000000000003", "type": "normal", "reps": 8, "weightKg": 80, "rpe": 8 },
        { "uuid": "019fc484-0000-7000-8000-000000000004", "type": "normal", "reps": 6, "weightKg": 80, "rpe": 9 } ] },
    { "exerciseId": 107, "sourcePrescribedId": 326, "skipped": true, "notes": "Machine occupée.", "sets": [] }
  ] }'
```

```json
{ "status": "done",
  "startedAt": "2026-08-02T16:04:00+00:00",
  "endedAt": "2026-08-02T17:11:00+00:00",
  "log": [
    { "exerciseId": 106, "name": "Développé couché", "sourcePrescribedId": 325, "position": 0, "skipped": false, "notes": null,
      "sets": [ { "uuid": "019fc484-0000-7000-8000-000000000001", "position": 0, "type": "warmup", "reps": 10, "weightKg": 40,
                  "durationSeconds": null, "rpe": null, "completedAt": null } ] },
    { "exerciseId": 107, "name": "Développé incliné à la machine convergente", "sourcePrescribedId": 326,
      "position": 1, "skipped": true, "notes": "Machine occupée.", "sets": [] }
  ] }
```

*(réponse abrégée : `name` et `position` ont été posés par le serveur, la
première série sur quatre est montrée)*

**Erreurs**

`422` — une référence invisible et un champ hors bornes, avec le chemin exact :

```console
$ curl -sk -X PUT https://127.0.0.1:8000/api/schedule/019fc47d-16e1-7bb4-988f-6d06ce848399 \
       -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
       -d '{"date":"2026-08-02","log":[{"exerciseId":999999,"sets":[
            {"uuid":"019fc47d-16e1-7d0c-988f-6d06cf23bd99","type":"normal","reps":9999}]}]}'
{"type":"about:blank","title":"Unprocessable Content","status":422,
 "detail":"Les données envoyées sont invalides.",
 "violations":[{"field":"log[0].sets[0].reps","message":"Le nombre de répétitions doit être compris entre 0 et 200."}]}
```

Avec des répétitions valides, la référence sort à son tour, et l'absence de nom
avec elle :

```json
"violations":[
  {"field":"log[0].exerciseId","message":"Exercice inconnu ou inaccessible."},
  {"field":"log[0].name","message":"Le nom de l'exercice est requis quand aucun exercice de la bibliothèque n'est référencé."}]
```

`409` — un `uuid` de série emprunté à une **autre** séance. Le corps ne dit pas
laquelle : le `detail` est générique par construction.

```json
{"type":"about:blank","title":"Conflict","status":409,"detail":"Conflit avec l'état actuel de la ressource."}
```

Plus `401`, `403` (séance d'un autre — un coach compris : il a `EDIT`, pas
`LOG`) et `422` sur un `uuid` de corps qui contredit l'URL.

### 6.9 `DELETE /api/schedule/{uuid}`

Supprime une **séance libre**, et rien d'autre. Le téléphone crée des séances
libres, il peut donc les défaire ; une séance qui porte un programme a été posée
sur le web — depuis la bibliothèque ou par un plan — et c'est là qu'elle se
retire, avec le contexte qui va avec.

```console
$ curl -sk -X DELETE https://127.0.0.1:8000/api/schedule/019fc47d-16e1-7bb4-988f-6d06ce848355 \
       -H "Authorization: Bearer $TOKEN" -i
HTTP/2 204

$ curl -sk -X DELETE https://127.0.0.1:8000/api/schedule/019fc47d-16e1-7bb4-988f-6d06ce848355 \
       -H "Authorization: Bearer $TOKEN"
{"type":"about:blank","title":"Not Found","status":404,"detail":"Ressource introuvable."}
```

Les autres appareils du compte l'apprennent au delta suivant :

```console
$ curl -sk "https://127.0.0.1:8000/api/bootstrap?since=2026-08-02T21:00:00Z" -H "Authorization: Bearer $TOKEN"
… "deleted":{"exercises":[],"schedule":["019fc47d-16e1-7bb4-988f-6d06ce848355"]}
```

**Erreurs**

| Code | Cas |
|---|---|
| `409` | La séance vient de la bibliothèque ou d'un plan. Ce n'est pas une question de droit — le propriétaire a bien le droit — c'est l'état de la ressource qui rend le geste impossible **ici**. Trois colonnes sont testées : une séance de plan dont la source a été supprimée en bibliothèque n'a pas de programme sans être libre pour autant. |
| `403` | Séance d'un autre. La garde est `LOG`, pas `DELETE` : ce que le téléphone efface, c'est du réalisé. |
| `404` | Uuid inconnu — ou déjà supprimé, ce qui rend le `DELETE` sûr à rejouer une fois qu'on traite le 404 comme un succès. |

### 6.10 `GET /api/app-version`

Ce que le serveur attend comme version d'app (KL-43). **Le seul endpoint anonyme
qui ne serve pas à obtenir un jeton** : le plancher doit se lire avant la
connexion, et le jour où l'ancien format n'est plus servi, se connecter est
précisément ce qui ne marche plus.

```console
$ curl -s http://127.0.0.1:8000/api/app-version
{"versionCode":0,"versionName":"0.0.0","minimumVersionCode":0,"apkUrl":null,
 "storeUrl":"https:\/\/store.antoninpamart.fr","installUrl":"http:\/\/127.0.0.1:8000\/app"}
```

Réponse **réellement obtenue le 5 août 2026**, sur un serveur local servi en
clair — d'où l'absence de `-k`, et un `installUrl` en `http`. Aucun jeton dans la
commande : c'est le contrat, pas un raccourci.

| Champ | Rôle |
|---|---|
| `versionCode` | La dernière version **publiée**, au sens Android (`android.versionCode`). Supérieure à la sienne : proposer une mise à jour, sans jamais l'imposer. |
| `versionName` | Son numéro lisible, sans le `v` du tag. À afficher plutôt que le `versionCode`. |
| `minimumVersionCode` | Le **plancher supporté**. En dessous, le client doit s'arrêter : c'est la seule porte de sortie prévue si le format de synchronisation change, et il ne monte qu'à cette occasion. |
| `apkUrl` | L'APK en téléchargement direct, ou `null` tant que rien n'est publié. Secours : il installe, il ne prévient de rien ensuite. |
| `storeUrl` | Le dépôt TNTStore, qui ne référence que les releases GitHub et n'héberge aucun binaire. |
| `installUrl` | La page d'installation du site (`/app`), en absolu. C'est ce qu'un bandeau de mise à jour ouvre. |

**Zéro veut dire « rien de publié »**, et c'est l'élément neutre des deux
comparaisons : rien n'est plus récent que soi, rien n'est sous le plancher. Un
client n'a donc aucun cas particulier à écrire pour l'état d'avant la première
release.

Trois règles côté client :

1. **Appeler sans en-tête `Authorization`** (§1). L'endpoint est anonyme, mais un
   `Bearer` périmé ferait échouer la requête avant le contrôleur.
2. **Persister la réponse.** Un plancher qui ne tiendrait que le temps d'un appel
   réussi disparaîtrait au premier lancement hors réseau, c'est-à-dire là où il
   protège.
3. **Ne rien bloquer faute de réponse.** Ne pas savoir n'est pas « trop vieux ».

Les deux nombres sont **déclarés** côté serveur (`config/services.yaml`), pas lus
chez GitHub à chaque appel : cet endpoint doit répondre même quand le tiers qui
héberge les binaires ne répond pas.

---

## 7. Limites connues

**Les horodatages à décalage non nul sont mal stockés.** Un `startedAt`,
`endedAt` ou `completedAt` envoyé avec un décalage autre que `Z` voit son heure
murale conservée et son décalage **perdu** : `2026-08-02T18:04:00+02:00` est
relu `2026-08-02T18:04:00+00:00`, soit un instant absolu décalé de deux heures.
Les durées restent justes (les deux bornes glissent ensemble), l'instant ne
l'est pas.

> **Contournement côté client, à appliquer dès maintenant : envoyer tous les
> horodatages en UTC (`…Z`).** Vérifié — `2026-08-02T16:04:00Z` est relu
> `2026-08-02T16:04:00+00:00`, à l'identique.

Cause : le décalage n'est pas normalisé avant persistance, et Doctrine écrit
l'heure telle qu'elle est portée par l'objet. Le correctif tient en une
normalisation à la lecture de la charge utile, mais il change le comportement
d'un endpoint déjà livré (KL-16) : il relève d'un ticket à part, pas de la
documentation.

---

## 8. Vérifier ce document

Les gardes décrites ici sont tenues par des tests, pas par la bonne volonté :

| Fichier | Ce qu'il garde |
|---|---|
| `tests/Controller/ApiEndpointMatrixTest.php` | La matrice de **tous** les endpoints : anonyme / expiré / révoqué → 401, nominal → 2xx, aucun cookie, aucune fuite du bloc-notes privé, ressource d'un tiers refusée. |
| `tests/Controller/ApiAuthenticationTest.php` | Le pare-feu, l'ordre des firewalls, l'expiration glissante, le stockage haché. |
| `tests/Controller/ApiAuthEndpointsTest.php` | Connexion, déconnexion, `/api/me`, le 401 uniforme, le limiteur. |
| `tests/Controller/ApiPairingTest.php` | L'appairage : usage unique, expiration, le compte qui vient du code. |
| `tests/Controller/ApiBootstrapTest.php` | Le delta, la fenêtre, les pierres tombales, le budget de 1 Mo. |
| `tests/Controller/ApiScheduleTest.php` | La lecture, l'upsert, l'idempotence à trois envois, le partage d'autorité. |
| `tests/Controller/ApiExerciseHistoryTest.php` | L'historique : périmètre, bornage, portée. |
| `tests/Controller/ApiErrorResponseTest.php` | La forme RFC 9457 et l'absence de fuite interne. |
| `tests/Controller/ApiAppVersionTest.php` | `GET /api/app-version` : répond sans jeton (l'inverse de la matrice, d'où un fichier à part), refuse quand même un `Bearer` inconnu, ne pose aucun cookie. |

```console
$ php vendor/bin/phpunit tests/Controller
```
