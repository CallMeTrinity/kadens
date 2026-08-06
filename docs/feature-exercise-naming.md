# Noms d'exercices bilingues

Cadrage et invariants du nommage des exercices : clé stable, nom anglais,
préférence de langue, recherche par mots-clés, ordre par usage réel.

Ce document fait autorité sur son périmètre. `CLAUDE.md §3` n'en porte que le
résumé.

---

## 0. Le problème

La bibliothèque est francophone, et 99 des 301 entrées traînaient l'anglais entre
parenthèses : `Traction en supination (Chin-up)`. Un mouvement de salle se pense
souvent en anglais, et la recherche ne trouvait ni l'un ni l'autre correctement
(`toLowerCase().includes(query)`, sans accents ni mots-clés — `developpe` ne
trouvait pas « Développé couché »).

Sortir l'anglais du nom demandait de **renommer**. Or `app:import-exercises`
appariait sur le nom exact et ne faisait que du skip : renommer une entrée du
fichier créait un second exercice avec un nouvel `id`, pendant que
`LoggedExercise.exercise`, `PrescribedExercise.exercise`,
`LoggedExerciseRepository::usageForOwner()` et la base locale du téléphone
continuaient de pointer l'ancien. **Le renommage coûtait l'historique.**

---

## 1. `Exercise.refKey` — l'identité séparée du libellé

`VARCHAR(128)`, nullable, **UNIQUE sur toute la table**.

- **Réservée à la bibliothèque globale.** Un exercice perso n'en porte pas :
  l'unicité est globale, et deux utilisateurs important le même fichier la
  violeraient. MariaDB laisse passer plusieurs `NULL` sous un index unique.
- **Immuable une fois posée.** La changer réintroduirait exactement le problème
  qu'elle résout : la base garderait l'ancienne, et l'import créerait un doublon.
  Une clé mal choisie se garde ; c'est un identifiant, pas un libellé, il n'est
  jamais affiché.
- **Dérivée du nom au moment de la création, jamais après.**
  `TextNormalizer::slug()` propose la forme ; le lien s'arrête là.

## 2. `Exercise.nameEn` — une donnée, pas une traduction

`VARCHAR(255)`, nullable.

Ce **n'est pas** de l'i18n Symfony. L'app n'a aucun catalogue (`translations/`
est vide, un seul `|trans` dans tout le projet, hérité de `make:auth`) et n'en
aura pas pour ça : toute l'UI reste française en dur. Seuls les **noms
d'exercices** basculent.

`null` quand le nom français EST déjà l'anglais — « Dips », « Squat »,
« Fartlek », « Burpees ». 23 entrées sur 301 sont dans ce cas, et c'est voulu :
un `nameEn` recopié à l'identique serait du bruit dans le fichier et dans le
texte cherché.

## 3. `User.exerciseLanguage` — NOT NULL, défaut `fr`

Enum `ExerciseLanguage` (`fr` / `en`). Le seul champ non nullable de tout ce qui
ressemble à une préférence : **un affichage n'a pas de « non renseigné »**,
contrairement au reste de la fiche athlète, qui se remplit progressivement.

Se règle dans `/profile/settings`, section « Affichage ».

---

## 4. `ExerciseNaming` — le seul endroit qui décide du libellé

`src/Service/ExerciseNaming.php`, exposé aux templates par `exercise_name()`,
`exercise_alt_name()` et `exercise_search_text()` (`AppExtension`).

Treize points d'affichage lisaient `.name` en direct. Une langue au choix ajoute
trois replis, et treize occasions d'en oublier un. Ils vivent ici, et nulle part
ailleurs — **un template qui écrit `exercise.name` court-circuite la préférence
de l'utilisateur.**

Les trois replis :

1. **Pas d'utilisateur → français.** Page publique de partage, flux ICS, export :
   un lecteur sans compte lit la langue d'origine de la bibliothèque.
2. **Anglais sans `nameEn` → français.** Jamais de trou.
3. **Exercice supprimé → la copie figée qu'on passe en second argument.**

### Le nom vivant prime sur la copie figée

`LoggedExercise.exerciseName` garde le nom au moment de la séance, et la FK est
en `SET NULL`. L'historique affiche pourtant le nom **vivant** tant que
l'exercice existe : `exercise_name(entry.exercise, entry.name)`. Sans ça, une
séance faite avant la bascule resterait écrite en français au milieu d'un écran
anglais, et un snapshot ne peut pas porter deux langues sans une seconde colonne.

Le snapshot ne reprend la main que si l'exercice a été supprimé — c'est la seule
chose qu'il reste alors à afficher, et c'est sa raison d'être.

**Conséquence assumée** : renommer un exercice change son libellé dans
l'historique. C'est voulu. Le snapshot existe pour survivre à une suppression,
pas pour figer un affichage.

La même règle vaut hors Twig : `TrainingStats::records()` groupe sur
`le.exerciseName` (c'est ce que la requête SQL rend) mais résout le libellé sur
la définition vivante, via les entités déjà chargées par `liftedExercises()`.

---

## 5. `app:import-exercises` — convergente, adoptante, jamais destructrice

Quatre passes d'appariement, dans l'ordre :

1. **`refKey === $row['key']`** → mise à jour de `name`, `nameEn`,
   `description`, `activity`, `targetAreas`, `mediaUrl`.
1 bis. **`refKey` dans `formerKeys`** → la clé est ré-estampillée, puis la ligne
   mise à jour. Voir « Quand une clé change quand même » ci-dessous.
2. **Adoption par nom normalisé** parmi les lignes **sans clé**, avec trois
   formes candidates :
   - le `name` du fichier ;
   - `"{name} ({nameEn})"`, l'ancienne convention ;
   - les `formerNames` déclarés sur l'entrée.
   Sur match : la clé est posée, puis les champs écrits.
3. **Création**, clé posée.

### Pourquoi `formerNames` existe

La dérivation ne suffit pas. Quand le nettoyage a **inversé** les deux langues
(`Front Squat (Squat avant)` devenu `Squat avant` / `Front squat`), ou quand le
nom anglais retenu est plus précis que la parenthèse d'origine (`(Seated row)`
devenu `Seated cable row`), aucune règle ne retrouve l'ancien libellé. Le
déclarer est la seule façon honnête de le dire — mieux qu'une heuristique de
similarité qui adopterait de travers, et un mauvais appariement est bien pire
qu'une création qu'on voit passer dans le résumé.

62 entrées en portent aujourd'hui. Le champ n'est **pas** transitoire : c'est
l'échappatoire permanente de tout renommage qu'aucune dérivation ne devine.

### Les garde-fous

- **`formerNames` est essayé en dernier**, après le nom courant. Une entrée dont
  l'ancien libellé est devenu le nom courant d'une **autre** entrée ne doit pas
  la lui voler.
- **Une ligne qui porte déjà une clé n'est jamais adoptée** : elle appartient à
  une autre entrée du fichier.
- **Deux clés identiques dans le fichier font échouer la commande**, avant toute
  écriture. Le contrôle d'unicité de l'ancienne version interrogeait la base
  pendant la boucle, donc avant le flush : deux entrées de même nom passaient
  toutes les deux.
- **La portée est scopée** (`indexExisting()`) : la globale seule par défaut, le
  seul utilisateur en mode `--owner`. L'ancien `findOneBy(['name' => …])` balayait
  toute la table, et faisait sauter une entrée globale parce qu'un utilisateur
  s'était créé le même nom.
- **`--owner` ne pose aucune clé** (`$stampKey`), l'unicité étant globale.
- **Aucune suppression, jamais.** Retirer une entrée du fichier ne retire rien de
  la base : une ligne peut être référencée par un historique, et la commande n'a
  pas à en décider. C'est `/exercise` qui supprime, avec son voter.

### Quand une clé change quand même

Une `refKey` n'est pas censée changer. Mais rien ne l'empêche, et c'est arrivé
dans l'heure qui a suivi la mise en place — `squat` renommé en `barbell-squat` en
même temps que ses libellés.

**L'adoption par nom ne rattrape pas ce cas** : elle refuse toute ligne qui porte
déjà une clé, précisément pour ne pas voler celle d'une autre entrée. Une clé
changée produit donc une **création** — un doublon, et l'historique détaché,
en silence. Le piège est d'autant plus vicieux qu'il ne se voit pas en dev, où la
base a déjà suivi l'édition : c'est en **prod**, dont la ligne porte encore
l'ancienne clé, qu'il se déclenche.

`formerKeys` est le seul chemin de retour :

```json
{ "key": "barbell-squat", "formerKeys": ["squat"], "name": "Squat à la barre", … }
```

Ce n'est pas une invitation à renommer les clés. Une clé n'est jamais affichée,
la garder laide ne coûte rien, et la changer demande de penser à la déclarer.
Mais quand l'oubli arrive, il doit être réparable — et le `--dry-run` le montre
avant qu'il ne coûte quelque chose.

### L'adoption n'est pas un dispositif jetable

Elle reste un filet permanent. Un `ROLE_ADMIN` qui crée « Développé décliné »
dans l'app le crée en global sans clé ; le jour où le fichier gagne cette entrée,
elle est **adoptée** au lieu d'être dupliquée.

### Effet de bord traité

`data/blast-exercise-map.json` et `data/fitnotes-exercise-map.json` ciblent les
exercices par **nom exact**, et `ImportedExerciseMap::resolve()` **lève** si le
nom visé a disparu. 33 valeurs ont été réécrites avec les renommages. Toute
future campagne de renommage doit refaire ce passage.

---

## 6. La recherche : `assets/search.js` et `TextNormalizer`, une seule règle

Trois propriétés, toutes 100 % client (aucun réseau : c'est la condition des
pages auto-suffisantes et du cache offline) :

1. **Sans accents.** `developpe` trouve « Développé ». Personne ne tape ses
   accents dans un champ de recherche, encore moins d'une main sur un téléphone
   posé sur un banc.
2. **Par mots-clés, tous exigés (ET).** « curl halteres » trouve « Curl biceps
   avec haltères », que la sous-chaîne ne trouvait pas — les mots n'y sont pas
   contigus. Un mot-clé de plus restreint toujours : le champ est prévisible.
3. **Bilingue.** `data-filter-text` porte les deux noms
   (`ExerciseNaming::searchText()`).

Le barème pondéré est inchangé (4 exact / 3 préfixe du nom / 2 tous les mots dans
le nom / 1 ailleurs). Ce qui change, c'est la **condition** : sans le ET,
« curl marteau poulie » remonterait tous les curls.

### Les deux écritures bougent ensemble

`App\Service\TextNormalizer::normalize()` et `assets/search.js::normalize()` font
la même chose. Une divergence ferait apparaître à l'écran ce que l'import
considère comme un autre exercice, ou ferait échouer une adoption qui aurait dû
réussir.

`tests/Service/TextNormalizerTest.php` fixe onze cas ; le JS doit les produire à
l'identique. Le seul point où les deux pouvaient diverger était la **ligature**
`œ` : la translittération ICU du PHP écrit « oe », `NFD` ne la décompose pas et
le filtre alphanumérique l'aurait mangée. Le JS la remplace explicitement, avant
`NFD`.

### `data-filter-name` vs `data-filter-text`

- `data-filter-name` = le libellé **affiché**, et lui seul. Il pilote les paliers
  exact/préfixe.
- `data-filter-text` = le reste (second libellé, activité, zones). Le nom y est
  ajouté automatiquement par `fields()` — ne pas l'y répéter.

Le tri alphabétique (`data-sort-name`) suit lui aussi le libellé **affiché** :
classé sur le nom français pendant qu'on lit l'anglais, « Nom (A→Z) » donnerait
un ordre qui ne correspond à rien à l'écran.

---

## 7. L'ordre par usage réel

`LoggedExerciseRepository::usageForOwner()` (KL-51) : une seule requête
d'agrégat, `[exerciseId => ['count', 'lastAt']]`, exercices sautés exclus. Rien
n'est dénormalisé sur `Exercise` — un compteur stocké dériverait à la première
suppression de séance.

Deux points de consommation, deux comportements différents et voulus :

- **Palette du compositeur** : la liste arrive **déjà triée par usage
  décroissant**, puis par nom. Ce qu'on fait le plus souvent est ce qu'on repose
  le plus souvent, et 300 entrées classées A→Z font défiler pour rien dans un
  panneau qu'on manipule au pouce, debout. Le repli alphabétique n'est pas
  cosmétique : sans lui, l'ordre des exercices jamais faits — l'écrasante
  majorité — dépendrait de la stabilité de `usort`, donc de rien.
- **`/exercise`** : l'ordre par défaut ne change pas. L'usage y reste une des
  options du `<select>` (les trois tris de KL-51) et **départage la pertinence**
  quand une recherche est active.

### La portée est le propriétaire, pas le lecteur

`WorkoutController::libraryContext()` lit l'usage de `$workout->getOwner()`, pas
de `$this->getUser()`. Quand un coach compose pour son athlète, c'est
l'historique de **l'athlète** qui rend l'ordre utile — le sien ne dit rien de ce
que l'autre s'entraîne à faire. Même logique que
`PlanTemplateController::ownerOf()`.

---

## 8. API mobile

`GET /api/bootstrap` expose `exercises[].nameEn`. **Additif** : un client qui
l'ignore continue d'afficher `name`, ce que fait l'app Android aujourd'hui — la
préférence de langue reste une affaire de navigateur. Un client qui l'adopte doit
garder le repli sur `name`, `nameEn` étant facultatif par construction.

Le champ n'a pas demandé de forçage du delta : l'import réécrit les lignes qu'il
renseigne, `updatedAt` se pose donc tout seul et le prochain `?since` les
remonte.

`kadens-mobile` n'est pas modifié : ni `src/db/schema.ts`, ni migration Drizzle,
ni les points d'affichage. Chantier suivant.

---

## 9. Marche à suivre en production

```bash
php bin/console doctrine:migrations:migrate
php bin/console app:import-exercises --dry-run   # attendu : 301 adoptés, 0 créés
php bin/console app:import-exercises
```

**Le `--dry-run` n'est pas facultatif.** S'il annonce des créations là où on
attend des adoptions, c'est qu'un `formerNames` manque : appliquer créerait des
doublons et détacherait l'historique. Corriger le fichier, resimuler.

Contrôle d'après : le nombre de lignes globales et l'intervalle d'`id` sont
inchangés.

```sql
SELECT COUNT(*), MIN(id), MAX(id) FROM exercise WHERE owner_id IS NULL;
```
