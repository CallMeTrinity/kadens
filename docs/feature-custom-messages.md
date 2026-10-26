# Messages programmés

Un texte et une image (le plus souvent un GIF), montrés **une fois** à leurs
destinataires entre deux dates : un anniversaire, un Joyeux Noël. Écrits dans
un back-office réservé à `ROLE_SUPER_ADMIN`, lus dans une modale sur le web et
sur le téléphone.

La fonctionnalité n'est annoncée nulle part, ni dans l'app, ni dans un
changelog : c'est une surprise, elle n'a de sens que si on ne la voit pas venir.

## Qui fait quoi

| Pièce | Rôle |
|---|---|
| `CustomMessage` | Le message : `title` (interne), `body`, `mediaFile`, `startsOn`/`endsOn`, `audience`, `recipients`, `seenBy`, `active`. |
| `CustomMessageRepository::findUnseenFor()` | La seule requête de lecture côté destinataire. |
| `CustomMessageInbox` | Le message du jour (web), les messages à venir (mobile), l'URL signée de l'image. |
| `MessageMediaStorage` | Le fichier, sous `var/uploads/messages`. |
| `Admin\CustomMessageController` | Le back-office `/admin/messages`. |
| `MessageController` | `POST /message/{id}/seen` et `GET /media/message/{id}/{file}`. |
| `Api\MessageController` | `PUT /api/messages/{id}/seen`. |
| `components/_custom_message.html.twig` | La modale, et l'aperçu du back-office. |

Côté téléphone : table locale `customMessage`, mutation `message.seen`, feuille
sur l'écran Aujourd'hui (`kadens-mobile`).

## Invariants

- **`ROLE_SUPER_ADMIN` est plat.** Pas de `role_hierarchy` dans le projet : il
  n'implique pas `ROLE_ADMIN` et `ROLE_ADMIN` n'ouvre pas `/admin`. C'est voulu,
  un admin de la bibliothèque peut être destinataire d'un message et ne doit pas
  le lire avant le jour J. Accordé en console seulement
  (`app:user:promote-super-admin`).
- **Le « vu » vit en base, et il est commun au web et au téléphone**
  (`custom_message_seen`). Fermer la modale (bouton ou croix) l'écrit ; Échap ou
  un clic sur le fond remettent à plus tard (prochaine session du navigateur,
  comme l'invitation à choisir un pseudo).
- **`title` ne sort jamais vers un destinataire** : ni dans la modale, ni dans
  l'API. Il range le message dans le back-office.
- **`body` est du texte brut.** Échappé, puis seuls les sauts de ligne sont
  rendus. Pas de HTML, pas de Markdown.
- **Bornes incluses, jours entiers.** « Aujourd'hui » est le jour du serveur
  sur le web, le jour local du téléphone sur mobile. Un écart de fuseau peut
  donc décaler l'affichage de quelques heures aux bornes, c'est accepté.
- **Une modale à la fois.** Un message en attente fait passer l'invitation à
  choisir un pseudo à la page suivante. Rien ne s'affiche dans `/admin`.
- **Audience explicite.** `ALL` ou `USERS` ; une liste de destinataires vide ne
  veut jamais dire « tout le monde » (validation).
- **L'image n'est jamais ré-encodée.** Pas de passage par le canvas du
  navigateur (`photo_upload_controller.js` aplatirait le GIF en JPEG). Le
  serveur vérifie le format (`getimagesize`), 8 Mo au plus, et retire l'EXIF
  d'un JPEG.
- **L'image se lit par URL signée** (`UriSigner`, `APP_SECRET`), sans session ni
  jeton : le téléphone ne pose pas son jeton sur une requête d'image. Le nom de
  fichier est dans le chemin et doit être celui du message au moment de la
  lecture ; l'URL expire deux jours après `endsOn` (au plus tôt demain, pour
  l'aperçu d'un message passé). Changer `APP_SECRET` invalide les URL déjà
  descendues sur les téléphones jusqu'au bootstrap suivant.
- **Un message adressé à quelqu'un d'autre rend 404**, sur le web comme sur
  l'API : l'identifiant est séquentiel.
- **Le fichier part avec le message** : suppression ou remplacement effacent
  l'ancien après l'écriture en base.

## En production

1. `php bin/console doctrine:migrations:migrate` (`Version20261026090000`).
2. `php bin/console app:user:promote-super-admin <email>`.
3. Ajouter `var/uploads/messages` aux sauvegardes, à côté de
   `var/uploads/goal-photos`.
