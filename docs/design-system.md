# Design system — Kadens

Référence visuelle de l'application. Elle décrit l'identité, les tokens et les
patterns de composants. La source de vérité des valeurs est
[`assets/styles/tokens.css`](../assets/styles/tokens.css) : ce document explique
comment et quand les utiliser, il ne les redéfinit pas.

Origine : maquette Claude Design « Séance — Refonte », qui a servi de test à la
nouvelle direction avant sa généralisation. Elle remplace l'identité « Carnet
clair » (papier crème, terracotta, olive, Space Grotesk / Instrument Sans /
JetBrains Mono) sur **toutes** les vues.

---

## 1. Identité — « Presse »

Registre éditorial sportif. Papier froid, encre quasi noire, **un seul accent**
rouge. Aucune forme arrondie, aucune ombre : tout se joue au filet `1px` et aux
aplats. Titres en condensé capitales, valeurs et métadonnées en mono. La densité
vient du contraste typographique, pas de la couleur.

Deux principes tiennent tout le reste :

1. **La couleur porte du sens — et il n'y en a qu'une.** Le rouge marque les
   actions primaires, l'intensité et l'échec. Tout le reste vit en niveaux de
   gris. Une catégorie (activité, région musculaire, rôle de bloc) se code par
   sa **place dans l'échelle catégorielle**, pas par une teinte propre.
2. **Le contraste avant la pâleur.** Un texte estompé l'est parce que l'encre
   pleine est à 19:1, pas parce qu'il est pâle. Tout token `--color-text-*` tient
   au moins 4.5:1 sur `--color-surface-raised`.

---

## 2. Couleurs

### Neutres papier
La hiérarchie de profondeur va à l'envers de l'habitude : plus une surface est
« haute », plus elle est claire, posée sur un fond de page plus foncé.

| Token | Valeur | Usage |
|---|---|---|
| `--color-bg` | `#dcdcd7` | fond de page |
| `--color-surface` | `#f7f7f5` | fond de carte / conteneur |
| `--color-surface-raised` | `#ffffff` | en-têtes, cartes internes, cellules |
| `--color-surface-subtle` | `#fbfbf9` | encarts, ligne de série de travail |
| `--color-surface-hover` | `#fafaf8` | survol de ligne |
| `--color-fill` | `#f3f3f1` | tags / badges neutres |
| `--color-track` | `#ecece8` | pistes de barres, séparateurs |
| `--color-scrim` | encre à 42 % | voile sous un calque : modale, feuille mobile |

### Aplat encre
Le hero de séance, les en-têtes de tableau et l'onglet actif s'inversent. Une
famille dédiée évite d'écrire des `rgba()` blancs à la main.

| Token | Valeur | Usage |
|---|---|---|
| `--color-surface-ink` | `#0b0b0b` | fond des aplats inversés |
| `--color-on-ink` | `#ffffff` | texte sur aplat encre |
| `--color-on-ink-muted` | `rgba(255,255,255,.60)` | texte secondaire sur encre |
| `--color-on-ink-faint` | `rgba(255,255,255,.45)` | labels mono sur encre |
| `--color-border-on-ink` | `rgba(255,255,255,.18)` | filets sur encre |
| `--color-primary-on-ink` | `#f0544c` | accent lisible sur encre |

### Encre (texte)
Tous ces tokens sont garantis **≥ 4.5:1 sur `--color-surface-raised`**.

| Token | Valeur | Contraste | Usage |
|---|---|---|---|
| `--color-text` | `#0b0b0b` | 19:1 | texte principal |
| `--color-text-strong` | `#1a1a1a` | 16:1 | intitulés forts sur carte |
| `--color-text-secondary` | `#5c5c56` | 7.4:1 | texte secondaire |
| `--color-text-soft` | `#6e6e68` | 5.6:1 | descriptions |
| `--color-text-faint` | `#75756e` | 5.2:1 | labels mono, texte estompé |
| `--color-text-placeholder` | `#7a7a73` | 4.8:1 | placeholders |

### Accent — rouge
| Token | Valeur | Usage |
|---|---|---|
| `--color-primary` | `#d8261e` | boutons primaires, liens, intensité (5.0:1) |
| `--color-primary-hover` | `#a81a14` | survol d'un **texte** rouge (7.4:1) |
| `--color-primary-bright` | `#f03127` | survol d'un **aplat** rouge plein |
| `--color-primary-tint` | `#fbe9e8` | fond teinté |
| `--color-primary-track` | `#e8c9c7` | piste de jauge d'intensité |
| `--color-on-primary` | `#ffffff` | texte sur aplat rouge |

> Deux survols distincts, ce n'est pas une redondance : un aplat rouge doit
> **s'éclaircir** au survol (le foncer le referme), un texte rouge doit
> **foncer**. Confondre les deux donne un bouton primaire qui s'éteint.

### Échelle catégorielle
Remplace l'ancien code couleur par activité. Elle sert aux **séries d'un
graphique** et aux aplats de catégorie, jamais à du texte : ordonnée du plus
dense au plus clair, elle se lit comme une hiérarchie de volume.

| Token | Valeur | Classe utilitaire |
|---|---|---|
| `--color-cat-1` | `#0b0b0b` | `.kd-cat--1` |
| `--color-cat-2` | `#4a4a46` | `.kd-cat--2` |
| `--color-cat-3` | `#8a8a82` | `.kd-cat--3` |
| `--color-cat-4` | `#c9c9c2` | `.kd-cat--4` |

### Code activité
L'activité est portée par l'**icône** (voir `_activity.html.twig`, source unique
du couple icône ↔ modificateur). Le rang catégoriel ne fait que la classer. Les
cinq activités sont couvertes — l'ancienne palette n'en codait que deux, faute
d'une troisième couleur disponible.

| Famille | Modificateur | Rang |
|---|---|---|
| Course / trail | `run` | `--color-activity-run` = cat-1 |
| Muscu / renfo | `gym` | `--color-activity-gym` = cat-2 |
| Natation | `swim` | `--color-activity-swim` = cat-3 |
| Vélo | `bike` | `--color-activity-bike` = cat-3 |
| Mobilité | `mobility` | `--color-activity-mobility` = cat-4 |

### Régions anatomiques
`TargetRegion` regroupe les 17 `TargetArea` en quatre grands ensembles, qui se
mappent un pour un sur l'échelle catégorielle via `TargetRegion::rank()`.
Ventiler les 17 zones donnerait une barre empilée illisible.

| Région | Rang |
|---|---|
| Bas du corps | 1 |
| Haut du corps | 2 |
| Tronc | 3 |
| Corps entier | 4 |

### Types de série détaillée
Alignés sur l'enum `SetType`, rendus par `components/_set_type.html.twig` en
pastille sigle carrée : `W` échauffement, `D` dégressive, `F` à l'échec, `DS`
drop set. `NORMAL` n'affiche rien.

Deux axes seulement, contre quatre teintes auparavant : l'**encre** pour ce qui
structure la série, le **rouge** pour ce qui la pousse ; le **plein** pour le
travail effectif, le **contour** pour ce qui n'en est pas (échauffement) ou n'en
est qu'une prolongation (drop set). La lettre identifie, la couleur tranche.

| Type | Sigle | Rendu | Token |
|---|---|---|---|
| `WARMUP` | `W` | contour encre | `--color-set-warmup` |
| `DEGRESSIVE` | `D` | encre plein | `--color-set-degressive` |
| `TO_FAILURE` | `F` | rouge plein | `--color-set-failure` |
| `DROP_SET` | `DS` | contour rouge | `--color-set-dropset` |

### Statuts prévu / réalisé
Alignés sur `ScheduledStatus`. Sémantiques, donc distincts de l'échelle
catégorielle. Le rouge de `MISSED` est cohérent avec son usage « écart, échec ».

| Token | Valeur | Statut |
|---|---|---|
| `--color-status-done` | `#0b0b0b` | `DONE` — fait |
| `--color-status-planned` | `#8a8a82` | `PLANNED` — prévu |
| `--color-status-missed` | `#d8261e` | `MISSED` — manqué |

---

## 3. Typographie

Trois familles, chacune avec un rôle strict.

| Token | Famille | Rôle |
|---|---|---|
| `--font-display` | Barlow Condensed | titres, boutons, onglets, gros chiffres |
| `--font-body` | Barlow | corps de texte, **noms saisis par l'utilisateur** |
| `--font-mono` | IBM Plex Mono | eyebrows capitales, méta, badges, valeurs |

**Règle de casse, non négociable :** le condensé en capitales est réservé aux
**libellés de structure** (titre de page, titre de carte, rôle de bloc, bouton,
onglet). Un contenu saisi — nom d'exercice, de séance, de plan, d'athlète,
intitulé d'objectif — reste en **Barlow, casse normale**. Barlow Condensed en
capitales sur un nom propre devient illisible.

Échelle de référence :

- Titre de page : `800`, `clamp(34px, 6vw, 52px)`, `line-height .92`, uppercase
- Titre de séance (hero) : `800`, `clamp(40px, 8vw, 84px)`, `line-height .88`
- Titre de carte / section : `700 19px` display, `letter-spacing .04em`, uppercase
- Rôle de bloc : `700 22px` display, uppercase
- Valeur de KPI : `800 40px` display, `font-variant-numeric: tabular-nums`
- Bouton / onglet : `700 15px` display, `letter-spacing .1em`, uppercase
- Corps : `400–600 14–16px` body
- Label mono capitale : `600 10–11px` mono, `letter-spacing .06`–`.16em`

Les gros chiffres portent tous `font-variant-numeric: tabular-nums` : sans ça,
une valeur qui change de largeur fait sauter la mise en page.

### Chargement des polices
**Self-hostées** (offline-first, aucune dépendance Google Fonts). Les `woff2`
sont dans `assets/fonts/` (subsets latin + latin-ext), les `@font-face` dans
`assets/styles/fonts.css`, importé par `app.css` et chargé via AssetMapper (les
`url()` sont réécrites vers les chemins digestés).

> Régénération : [`tools/fetch-fonts.sh`](../tools/fetch-fonts.sh). Les familles
> et graisses vivent dans le tableau `FAMILIES` en tête du script — c'est la
> seule chose à modifier. `fonts.css` est **généré**, ne jamais l'éditer à la
> main.

---

## 4. Formes, espacements

- **Rayons : aucun.** Tous les `--kd-radius-*` valent `0`. Les noms subsistent
  pour que `components.css` n'ait pas à être réécrit ; ne pas les supprimer.
- **Ombres : aucune.** `--shadow-card` et `--shadow-accent` valent `none`. Un
  élément **flottant** (modale, panneau de menu, popover) se détache par un
  contour `1px solid var(--color-text)`, jamais par une élévation simulée.
- **Espacements** : échelle `--kd-space-*` en base 4px.
- **Ordre des feuilles** (`app.css`) : `tokens` → `fonts` → `base` →
  `components`. `base.css` porte des défauts surchargeables, jamais l'inverse.

---

## 5. Responsive

### Trois paliers, et rien d'autre

| Palier | Cible | Ce qui bascule |
|---|---|---|
| `560px` | téléphone | une colonne, nav en barre basse, calendrier en agenda vertical, filtres d'index repliés |
| `900px` | tablette | éditeurs à deux volets empilés, nav condensée, repères de hero sous le titre |
| `1200px` | petit portable | palette de l'éditeur de trame sous la grille |

> **Ils ne peuvent pas être tokenisés.** `@media` n'accepte pas `var()`, et le
> projet n'a pas d'étape de build CSS (AssetMapper, pas de PostCSS). C'est une
> convention documentée, à tenir à la main. Avant d'ajouter une valeur, vérifier
> qu'aucun des trois paliers ne fait l'affaire — la dispersion précédente (neuf
> valeurs entre 480 et 1100) rendait le comportement imprévisible.

### Règles
- Approche **desktop-first** assumée : uniquement des `max-width`.
- Préférer une valeur fluide à un point de rupture quand c'est possible :
  `clamp()` pour la typographie, `min()` pour les gouttières.
- **Gotcha** : `.kd-page` a des gouttières fluides
  (`min(var(--kd-space-8), 4vw)`). Tout élément qui mord dessus par marge
  négative — le hero de séance — doit reprendre **exactement** la même
  expression, sinon un liseré de fond apparaît sur les côtés.
- Un contenu large (tableau, grille, diagramme) défile dans **son propre**
  conteneur `overflow-x: auto`. La page ne défile jamais horizontalement.
- **Piège `backdrop-filter`** : une valeur autre que `none` fait de l'élément le
  bloc conteneur de ses descendants en `position: fixed` **et** crée un contexte
  d'empilement. Un enfant `position: fixed` se cale alors sur lui, pas sur le
  viewport. C'est ce qui a rendu toute la navigation mobile inutilisable :
  `.kd-nav`, enfant de `.kd-header`, se posait sur une boîte de 52px de haut et
  recouvrait l'avatar. Le flou est neutralisé sous 560px — ne pas le réintroduire.
- **Piège de cascade — où écrire une surcharge responsive.** Une `@media`
  **n'ajoute aucune spécificité**. Une surcharge écrite *avant* la règle de base
  du composant qu'elle vise est donc annulée par cette règle de base, qui gagne
  en étant simplement plus loin dans la feuille. Le bloc `@media (max-width:
  560px)` de la section header a fait exactement ça : son dégagement de
  `.kd-page` était écrasé par le raccourci `padding` de la section « Mise en
  page », et son `bottom: var(--kd-navbar-h)` sur `.kd-editform__bar` par le
  `bottom: 0` du composant, 6 500 lignes plus bas. Résultat, fin de page et
  bouton « Enregistrer » sous la barre de nav, alors que le CSS *semblait* les
  traiter. **Règle : une surcharge responsive vit avec son composant, après sa
  définition** — jamais regroupée par palier en tête de feuille. Le bloc du
  header ne garde que ce qu'il définit lui-même (`.kd-header`, `.kd-nav`,
  `--kd-navbar-h`).
- **Hauteur de la barre basse** : une seule source, `--kd-navbar-h`, déclarée dans
  le palier 560px. Toute barre collante posée au-dessus s'en sert, et
  `.kd-page` s'en sert pour dégager sa fin de page — la barre est `fixed`, elle
  ne pousse rien. La variable compte l'`env(safe-area-inset-bottom)` que la barre
  prend en padding : c'est la **place occupée**, pas la hauteur du dessin.
- **Rien d'important ne dépend d'un survol.** Le survol ne fait qu'accélérer un
  chemin qui existe au clic. Les contrôles révélés au survol restent visibles en
  retrait sous `@media (hover: none)`, et un aperçu en `popover="manual"` doit se
  garder derrière `(hover: hover) and (pointer: fine)` : un tap émet un
  `mouseenter` synthétique sans `mouseleave`, le panneau resterait collé.

---

## 6. Accessibilité

Le socle vit dans [`assets/styles/base.css`](../assets/styles/base.css).

- **`<meta name="viewport">`** dans `base.html.twig`. Elle a été absente
  longtemps : sans elle, aucune media query ne se déclenche sur téléphone.
- **Lien d'évitement** `.kd-skip` vers `<main id="main">`, visible au focus.
- **`.kd-sr-only`** pour le texte réservé aux lecteurs d'écran. Ne jamais
  masquer un libellé avec `display: none` s'il porte le nom accessible d'un
  contrôle : `display: none` le retire de l'arbre d'accessibilité.
- **`:focus-visible` global** : anneau `2px solid var(--color-text)` +
  `outline-offset: 2px`, inversé sur les aplats encre. Ne jamais poser
  `outline: none` sans remplacement au moins aussi contrasté (WCAG 1.4.11 exige
  3:1 — l'ancien halo teinté ne les tenait pas).
- **`prefers-reduced-motion: reduce`** neutralise transitions et animations.
- **Cibles tactiles** : sous `@media (pointer: coarse)`, plancher de 44×44 px.
- **Sémantique native d'abord** : `<details>` pour les accordéons et les menus,
  `<dialog>` pour les modales, `<table>` pour les données tabulaires. On y gagne
  gratuitement le clavier, l'échappement et le repli sans JS.
- **Amélioration progressive** : les onglets rendent **tous** les panneaux côté
  serveur, chacun précédé de son titre. Le contrôleur `tabs` révèle la barre
  (rendue `hidden`), masque les titres et pose l'ARIA. Sans JS, la page reste
  complète et imprimable.
- **Impression** : `base.css` force l'ouverture des `<details>` et des panneaux
  d'onglets masqués.

---

## 7. Patterns de composants

Classes `.kd-*` dans `assets/styles/components.css`.

### Transverses
- **Carte** (`.kd-card`) : `--color-surface-raised`, filet `1px`, en-tête séparé
  par `--color-divider`.
- **Boutons** (`.kd-btn`) : display 700 15px, uppercase, `letter-spacing .1em`.
  `--primary` (aplat rouge), `--secondary` (contour encre, s'inverse au survol),
  `--ghost`, `--onink` (variante sur aplat encre), `--sm`, `--block`, `--danger`.
- **Badge** (`.kd-badge`) : mono capitales, contour `1px`, transparent. Le code
  activité pose un filet gauche `3px` au rang catégoriel.
- **Menu de compte** (`.kd-usermenu`) et **kebab** (`.kd-kebab`) : `<details>`
  natif + contrôleur `dismiss` (clic extérieur, Échap) pour le seul confort.
  Panneau à contour encre.
- **Modale** : `<dialog>` natif, contour encre.

### Page de consultation d'une séance
Composant partagé avec la page publique : `components/_workout_read.html.twig`,
décomposé en `_workout_program`, `_workout_sets_table`, `_workout_analysis`.

- **Hero** (`.kd-wk__hero`) : aplat encre pleine largeur, eyebrow rouge, titre
  condensé, repères en colonnes séparées par des filets. Le bloc `actions` du
  composant accueille la barre du propriétaire — la page publique le laisse
  vide, ce qui garantit qu'aucune commande ne peut y fuiter.
- **Bandeau de KPI** (`.kd-wk__kpis`) : grille auto-fit, filets verticaux
  devenant horizontaux sur téléphone. Jauge d'intensité à **10 crans**, à
  l'échelle du RPE, pour éviter toute conversion mentale.
- **Onglets** (`.kd-wk__tab`) : filet bas sur l'onglet actif.
- **Bloc en accordéon** (`.kd-block`) : `<details open>`, numéro en gris clair,
  rôle en condensé capitales, résumé mono poussé à droite.
- **Tableau de séries** (`.kd-settable`) : en-tête en aplat encre, série de
  travail ordinaire marquée d'un filet gauche et d'un fond. **Une ligne = une
  série**, y compris quand la saisie est scalaire (`PlanFlattener::setLines`
  déroule « 3 × 15 » en trois lignes) : la répétition est le prix d'une lecture
  identique quel que soit le mode de saisie. Deux colonnes ne s'affichent que si
  elles ont quelque chose à dire : « % du max » seulement si les charges varient,
  « Type » seulement si une série est qualifiée. **Largeur plafonnée à 34rem** —
  étiré sur un grand écran, le tableau éloignait les reps de la charge de
  plusieurs centaines de pixels. Sous 560px il se comprime au lieu de défiler :
  un défilement horizontal imbriqué dans une page qui ne défile pas n'a aucun
  repère visuel, on rate des colonnes sans savoir qu'elles existent.
- **Analyse** (`.kd-analysis`) : barre empilée + légende, barres horizontales,
  timeline de durée. Rendu 100 % serveur, aucune bibliothèque de graphiques.

---

## 8. Règles

1. **Jamais de couleur/typo en dur** dans un template ou un composant. Toujours
   via un token sémantique (`--color-*`, `--font-*`).
2. **La couleur porte du sens, et il n'y a qu'une couleur.** Le rouge est
   réservé aux actions primaires, à l'intensité et à l'échec. Une catégorie se
   code par son rang dans `--color-cat-*`, pas par une teinte inventée.
3. **Nouvelle valeur = nouvelle primitive `--kd-*` d'abord**, puis token
   sémantique. On n'expose jamais une primitive directement aux vues.
4. **Le condensé capitales ne touche pas au contenu saisi** (cf. §3).
5. **Toute évolution se répercute** ici et dans `CLAUDE.md` (§5 Design system).
