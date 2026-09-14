# Import du réalisé cardio (Intervals.icu)

La montre enregistre la sortie, Intervals.icu la reçoit, Kadens la relit et la
rattache à la séance datée. Le cardio ne se saisit jamais dans Kadens : son
réalisé s'importe.

## 1. Pourquoi Intervals.icu

Décision du 13/09/2026, après trois sources écartées :

- **Strava** : l'API standard exige un abonnement payant depuis juin 2026, et
  ses conditions restreignent l'affichage des données d'un athlète à un tiers
  (le coach).
- **Garmin officiel** : le Connect Developer Program est réservé aux entreprises
  et refuse les demandes personnelles.
- **Clients Garmin non officiels** (garth, python-garminconnect) : ils imitent
  l'app mobile pour passer la protection anti-bot de Garmin. Hors CGU, cassés
  sans prévenir en mars 2026, impossibles en PHP.

Intervals.icu est gratuit, synchronisé officiellement avec Garmin, et expose une
API REST documentée (`GET https://intervals.icu/api/v1/docs`) avec une clé
personnelle.

## 2. Mise en route (utilisateur)

1. Dans Intervals.icu, connecter Garmin **directement** (Settings, Connections).
   Une activité arrivée par Strava n'est pas exposée par l'API d'Intervals : elle
   est ignorée à l'import et le message de synchro le dit.
2. Intervals.icu, Settings, Developer Settings : générer la clé d'API.
3. Kadens, `/profile/settings`, carte « Activités cardio » : coller la clé.
   Elle est validée par un appel réel avant d'être gardée.
4. « Synchroniser » à chaque fois qu'on veut rapatrier ses sorties.

## 3. Reprise d'historique (console)

Le bouton ne remonte que 30 jours à la première synchro. Pour le passé :

```bash
php bin/console app:intervals:import athlete@example.com --since=2025-01-01           # dry-run
php bin/console app:intervals:import athlete@example.com --since=2025-01-01 --force   # écrit
```

- Utilise la clé enregistrée dans `/profile/settings` : connecter d'abord.
- **Dry-run par défaut**, comme les reprises de salle. Il calcule réellement le
  rapprochement contre la base, sans demander de streams ni rien écrire.
- **Séances libres** : une activité reconnue sans aucune séance prévue ce jour-là
  devient une séance libre faite (titre = nom de la sortie). `--no-free-sessions`
  les laisse à rattacher.
- Rythme tenu automatiquement (400 ms entre deux streams, quota Intervals de
  2 500 requêtes par 15 min) : compter environ 7 min pour 1 000 activités.
- Écrit par lots de 40. Une panne ou un quota épuisé conserve ce qui précède ;
  relancer la même commande reprend sans doublon.
- Ne recule jamais la fenêtre du bouton web (`syncedThrough`).

## 4. Mise en route (serveur)

- `APP_SECRET_BOX_KEY` : 64 caractères hexadécimaux
  (`php -r 'echo bin2hex(random_bytes(32));'`), dans `.env.local` en prod. Les
  valeurs de `.env.dev` et `.env.test` sont jetables et ne vont jamais en prod.
- La changer rend illisibles les clés enregistrées : chaque utilisateur doit
  recoller la sienne.
- `ext-sodium` doit être actif (livré par défaut avec PHP 8.4).
- Migration `Version20260913100000`.

## 5. Invariants à ne pas casser

- **Idempotence par (`source`, `externalId`).** Contrainte unique en base. La
  liste des identifiants connus est lue en une requête avant tout appel de
  streams : relancer ne réimporte rien et ne coûte qu'un appel de liste.
- **Rattachement unique ou rien** (`ActivityMatcher`). Candidate = même
  propriétaire, même jour **local**, statut prévu ou fait, sans activité déjà
  rattachée, prescrit contenant l'activité. Une seule : on rattache et la séance
  passe en `DONE`. Zéro ou plusieurs : l'activité attend un rattachement manuel.
  Un faux rattachement déplace un chiffre des stats sans rien signaler.
- **Séance libre : historique seulement, jamais au clic web.** Le cardio se
  planifie dans Kadens ; au quotidien, une sortie sans séance prévue signale un
  oubli de planification, pas une séance à inventer. Même en historique : jamais
  quand plusieurs séances pouvaient correspondre, jamais pour un type non reconnu
  (un renfo enregistré à la montre doublerait la séance loguée sur le mobile).
- **Uuid déterministe de la séance libre** :
  `TrainingHistoryImporter::uuidFor('intervals|<externalId>')`. Une séance déjà
  présente sous cet uuid est reprise, jamais recréée.
- **Détacher une activité de SA séance libre supprime la séance** si elle ne
  porte rien d'autre (ni programme, ni réalisé de salle, ni autre activité).
  Même règle quand la déconnexion purge les activités. Une séance libre créée à
  la main, ou reconnue par un autre uuid, n'est jamais supprimée.
- **Rattacher, c'est consigner : attribut `LOG`.** Le coach lit les activités
  rattachées (`VIEW`), il ne rattache ni ne détache, et n'a aucune action sur la
  connexion Intervals de son athlète.
- **Détacher ne touche pas au statut** : la séance a pu être faite sans que ce
  soit cette activité-là.
- **Déconnecter garde les activités** par défaut. Les supprimer est une case à
  cocher explicite.
- **La clé n'est jamais réaffichée**, ni en clair ni scellée.
- **Zones Kadens figées à l'import** (`ActivityStreamAnalyzer`). Ce sont les
  zones du profil, celles des prescriptions, pas celles d'Intervals. Modifier ses
  zones ne recalcule pas l'historique. Un trou de plus de 30 s entre deux
  échantillons est une pause et ne compte dans aucune zone ; sous Z1, on compte
  en Z1.
- **Fenêtre glissante avec recouvrement** (`IntervalsImporter`). Première synchro :
  30 jours. Ensuite : depuis `syncedThrough − 7 jours`, pour rattraper une montre
  synchronisée en retard.
- **Lot borné** : `IntervalsImporter::BATCH` activités par clic (un appel de
  streams chacune). `syncedThrough` n'avance que jusqu'à la dernière activité
  traitée ; le clic suivant reprend.
- **Stats** (`TrainingStats`) : par séance faite et par activité, le réel importé
  remplace le prescrit, distance et durée ensemble. Une activité non rattachée ne
  compte nulle part. Le réel arrive par un agrégat SQL, jamais hydraté.
- **Allure dérivée**, jamais stockée (`ImportedActivity::getPaceSecondsPerKm`),
  sur le temps en mouvement.

## 6. Limites connues

- Pas de synchro automatique (ni webhook ni cron) : c'est un bouton.
- Une coquille Strava réapparaît dans le compteur « ignorée » à chaque synchro
  tant qu'elle est dans la fenêtre.
- Les splits comptent le temps de pause qu'ils contiennent.
- Le mobile n'affiche pas encore les activités importées : une séance libre
  créée par l'historique y apparaît faite, sans programme ni séries.
- Une montre coupée puis relancée un jour sans séance prévue produit, en
  historique, deux séances libres au lieu d'une.
