# Récit d'objectif (#42)

Un objectif n'est plus seulement une date et une cible. Il porte un **récit** :
une suite de blocs qui raconte la préparation et le jour J, comme un article de
blog. Privé par défaut, publiable sur un lien public si le propriétaire le
décide.

## 1. Ce qui a été décidé

- **Un article en blocs**, pas un champ Markdown ni un journal daté. Un bloc est
  du texte, une photo, une activité importée ou une séance faite. Même logique
  que la séance (une suite ordonnée de blocs), et les photos ou activités se
  placent dans le fil du texte, pas dans une galerie à part.
- **Privé + publication opt-in.** Le récit se lit par le propriétaire et son
  coach (GoalVoter). Publier ouvre `/s/goal/{slug}`, comme le partage d'une
  séance ou d'un plan, mais sur demande explicite.
- **Deux sortes de références** : les activités importées (Intervals.icu) et les
  séances cochées faites (muscu comprise, avec son réalisé).

## 2. Mise en route

- **Utilisateur** : fiche objectif → « Écrire le récit » (`/goal/{id}/story`).
  Ajouter des blocs en fin de récit, les monter/descendre, les commenter. Retour
  sur la fiche pour lire le rendu, puis « Publier le récit » si on veut le
  partager.
- **Serveur** : rien à installer. Les photos s'écrivent sous
  `var/uploads/goal-photos/`, créé au premier envoi. Deux points d'attention :
  - **sauvegardes** : ce dossier est la seule donnée de l'app hors base, il doit
    entrer dans la sauvegarde du mutualisé ;
  - **taille d'upload** : la photo est réduite dans le navigateur (≈ 2000 px,
    quelques centaines de Ko), donc la limite PHP par défaut suffit. Sans JS,
    une photo brute de téléphone peut la dépasser : le message « Photo trop
    lourde pour le serveur » le dit.

## 3. Invariants à ne pas casser

- **Un bloc de référence ne recopie aucun chiffre.** Il pointe l'entité et se
  rend par `GoalStory`, qui lit les mêmes sources que la page de la séance
  datée : `ImportedActivityPresenter` pour une sortie, `LogMetrics` (réalisé de
  salle) et les activités rattachées pour une séance. Le prescrit n'entre jamais
  dans le récit : on raconte ce qui a eu lieu.
- **On ne raconte que les siens, et que du fait.** `GoalStory::acceptsActivity`
  / `acceptsSession` : même propriétaire que l'objectif, et une séance doit être
  `DONE`. Un identifiant d'un autre compte rend 404 (un refus ne confirme pas
  qu'un identifiant existe). La fenêtre des candidats (16 semaines avant
  l'échéance, 2 après) n'est qu'un confort d'affichage, pas une règle.
- **Références en SET NULL.** Retirer une séance du calendrier ou purger un
  import n'efface pas ce que l'auteur a écrit autour : le bloc devient orphelin
  (`GoalBlock::isOrphan`) et ne rend plus que son commentaire.
- **Ordre : `position` fait foi**, contiguë 0..n-1, tenue par `Goal::appendBlock`
  / `removeBlock` / `moveBlock`. Lire le récit passe par `getOrderedBlocks()`,
  qui retrie : la collection n'est triée qu'au chargement.
- **Les photos ne sont jamais dans `public/`.** Elles se servent par
  `app_goal_photo` (GoalVoter::VIEW, cache privé) ou `app_public_share_goal_photo`
  (publication revérifiée à chaque requête, cache public d'une heure). Le nom de
  fichier est aléatoire et revérifié par `GoalPhotoStorage::path()` : rien
  d'autre qu'un nom produit par `store()` ne se résout en chemin.
- **Aucune extension d'image côté serveur.** Pas de GD ni d'Imagick à demander
  au mutualisé : la réduction se fait dans le navigateur
  (`photo_upload_controller.js`), le serveur ne fait que `getimagesize()`.
- **Aucun EXIF ne sort.** Le ré-encodage canvas perd les métadonnées (position
  GPS comprise) ; pour un envoi qui l'aurait contourné, `stripJpegMetadata()`
  retire APP1/APP13 d'un JPEG. Un fichier illisible est laissé intact plutôt que
  cassé.
- **Les fichiers suivent l'ORM.** `Goal::$blocks` est en `cascade: remove` +
  `orphanRemoval`, et `GoalPhotoCleanupListener` efface les photos au
  `postFlush` (jamais avant la validation de la transaction). Supprimer un bloc
  ou un objectif par l'ORM nettoie donc le disque.
- **Publier est un geste de propriétaire.** Le coach co-écrit le récit (EDIT),
  mais `publish` le refuse en 403 : rendre public, c'est exposer le contenu et
  les photos de l'athlète. Le bandeau de publication ne s'affiche pas pour lui.
- **La page publique raconte, elle ne prépare pas.** Intitulé, échéance, cible,
  présentation (`description`), récit, résultat. Jamais les plans rattachés, les
  séances à venir, ni `completionNotes` d'une séance. Elle passe par le même
  composant que la fiche privée (`components/_goal_story.html.twig`,
  `public: true`), qui ne pose alors aucun lien vers l'intérieur de l'app.
- **Le slug est stable.** Posé à la première publication, il ne bouge plus :
  renommer l'objectif ou dépublier puis republier garde le même lien. Dépublié,
  le lien rend 404, comme un slug inconnu.

## 4. Limites connues

- **Orientation sans JS** : une photo envoyée sans passer par la réduction perd
  son EXIF, donc son indication d'orientation. Elle peut s'afficher couchée.
- **Suppression d'un compte** : faite par la base (CASCADE), elle ne passe pas
  par l'ORM et laisse les photos sur le disque. Elles sont inertes : plus aucun
  bloc ne les cite, aucune route ne les sert.
- **Ajout en fin de récit seulement**, puis monter/descendre. Pas encore de
  glisser-déposer ni d'insertion entre deux blocs.
- **HEIC** : seul Safari sait le décoder. Ailleurs, la réduction échoue, le
  fichier part tel quel et le serveur le refuse (JPEG, PNG ou WebP).
- **Pas d'API mobile** : le récit n'existe que sur le web.
