# Design system — Kadens

Référence visuelle de l'application. Elle décrit l'identité, les tokens et les
patterns de composants tirés des maquettes. La source de vérité des valeurs est
[`assets/styles/tokens.css`](../assets/styles/tokens.css) : ce document explique
comment et quand les utiliser, il ne les redéfinit pas.

Origine : maquette Claude Design « Kadens — Éditeur de plan », variante retenue
**1a — Carnet clair**. Toutes les autres vues (bibliothèque, séance, calendrier,
synthèse) en sont des déclinaisons.

---

## 1. Identité — « Carnet clair »

Papier et encre. Fond chaud légèrement crème, texte encre foncée, un seul accent
franc (terracotta) et un accent secondaire sobre (olive) qui sert de **code
couleur métier**, pas de décoration. Densité éditoriale : beaucoup de labels en
mono capitales façon carnet d'entraînement, des cartes nettes à coins arrondis,
des filets fins plutôt que des ombres lourdes.

Principe : **la couleur porte du sens.** Terracotta = course/trail et actions
primaires. Olive = muscu/renfo. Rien n'est coloré « pour faire joli ».

---

## 2. Couleurs

### Neutres papier
Du fond de page au plus clair. La hiérarchie de profondeur va à l'envers de
l'habitude : plus une surface est « haute » (carte, en-tête), plus elle est
claire (`--color-surface-raised` = `#fffdf9`), posée sur un fond de page plus
foncé (`--color-bg` = `#e9e6df`).

| Token | Valeur | Usage |
|---|---|---|
| `--color-bg` | `#e9e6df` | fond de page |
| `--color-surface` | `#f7f5f0` | fond de carte / conteneur |
| `--color-surface-raised` | `#fffdf9` | en-têtes, cartes internes, cellules |
| `--color-surface-subtle` | `#f2ede3` | encarts, champ slug |
| `--color-fill` | `#f0ebe1` | tags / badges neutres |
| `--color-track` | `#efe8dc` | pistes de barres, séparateurs |

### Encre (texte)
| Token | Valeur | Usage |
|---|---|---|
| `--color-text` | `#1a1712` | texte principal |
| `--color-text-strong` | `#2a2318` | intitulés forts sur carte |
| `--color-text-secondary` | `#7a7264` | texte secondaire |
| `--color-text-soft` | `#8a8272` | descriptions |
| `--color-text-faint` | `#a99f8d` | labels mono, texte estompé |
| `--color-text-placeholder` | `#b0a794` | placeholders, valeurs très pâles |

### Accent primaire — Terracotta
| Token | Valeur | Usage |
|---|---|---|
| `--color-primary` | `#b7532e` | boutons primaires, liens, accents |
| `--color-primary-hover` | `#8f3f22` | survol, texte fort sur tint |
| `--color-primary-tint` | `#fbeee7` | fond teinté (badge actif, tuile course) |
| `--color-on-primary` | `#ffffff` | texte sur fond terracotta plein |

### Code couleur activité
Terracotta et olive servent à distinguer les familles d'activité dans les
maquettes (matrice de plan, calendrier, synthèse). À câbler sur le champ
`activity` de l'entité `Exercise` / la famille de la séance.

| Famille | Accent | Tint | Texte sur tint |
|---|---|---|---|
| Course / trail (`run`) | `--color-activity-run` `#b7532e` | `--color-activity-run-tint` `#fbeee7` | `--color-activity-run-text` `#a2694c` |
| Muscu / renfo (`gym`) | `--color-activity-gym` `#5c6b3a` | `--color-activity-gym-tint` `#eef1e3` | `--color-activity-gym-text` `#6a7541` |

> Les maquettes ne couvrent que deux familles visuellement. Les autres activités
> du modèle (natation, vélo, mobilité) devront recevoir leur propre paire
> accent/tint quand elles seront designées — les ajouter alors comme primitives
> `--kd-*` puis tokens `--color-activity-*`, jamais en dur.

### Statuts prévu / réalisé
Alignés sur l'enum `ScheduledStatus`.

| Token | Valeur | Statut |
|---|---|---|
| `--color-status-done` | `#4c7a3a` | `DONE` — fait |
| `--color-status-planned` | `#a99f8d` | `PLANNED` — prévu |
| `--color-status-missed` | `#c0392b` | `MISSED` — manqué |

---

## 3. Typographie

Trois familles, chacune avec un rôle strict.

| Token | Famille | Rôle |
|---|---|---|
| `--font-display` | Space Grotesk | titres (`h1`), gros chiffres de synthèse, titres de tuiles |
| `--font-body` | Instrument Sans | corps de texte, libellés courants, boutons |
| `--font-mono` | JetBrains Mono | eyebrows en capitales, méta, badges, valeurs de paramètres |

Échelle observée dans les maquettes (indicative, pas normative au pixel près) :

- Titre de page : `600 24–27px` display
- Titre de carte : `600 15px` display
- Gros chiffre (KPI) : `700 26–30px` display
- Corps : `400 13px` body
- Corps petit : `400 12px` body
- Label mono capitale (eyebrow) : `600 10–11px` mono, `letter-spacing` `.06`–`.16em`, `text-transform: uppercase`
- Méta mono : `500–600 10–12px` mono

Les eyebrows en mono capitales sont une signature de l'identité : sections de
filtre, rôles de bloc (`ÉCHAUFFEMENT`, `CORPS DE SÉANCE`), légendes de synthèse.

### Chargement des polices
**Self-hostées depuis la Phase 9** (offline-first, aucune dépendance Google
Fonts). Les fichiers `woff2` sont dans `assets/fonts/` (subsets latin +
latin-ext), les `@font-face` dans `assets/styles/fonts.css`, importé par
`app.css` et donc chargé via AssetMapper (`importmap('app')` émet le `<link>`
CSS ; les `url()` sont réécrites vers les chemins digestés). Le `base.html.twig`
ne référence plus aucune police externe.

> Régénération : les `woff2` et `fonts.css` sont produits par un script de fetch
> jetable (récupère le CSS Google Fonts en UA Chrome pour obtenir du woff2, filtre
> latin/latin-ext, télécharge et réécrit les `url()` en local). À relancer si les
> familles ou graisses déclarées changent. Le service worker les met en cache au
> runtime (cache-first sur `/assets/*`, immuables car digestés) : pas besoin de les
> lister dans le précache.

---

## 4. Rayons, ombres, espacements

- **Rayons** : `--kd-radius-md` (8px) pour boutons/champs, `--kd-radius-lg`
  (11px) et `--kd-radius-xl` (12px) pour les cartes, `--kd-radius-pill` (20px)
  pour les pills/tags, `--kd-radius-full` pour les pastilles.
- **Ombres** : `--shadow-card` (`0 6px 28px rgba(0,0,0,.09)`) sur les cartes
  flottantes ; `--shadow-accent` sous les tuiles terracotta pleines (objectif).
  L'identité privilégie les **filets `1px`** (`--color-border`) à l'ombre.
- **Espacements** : échelle `--kd-space-*` en base 4px. Padding de carte usuel :
  22–30px horizontal, 20–26px vertical.

---

## 5. Patterns de composants (tirés des maquettes)

Descriptions de référence pour l'implémentation Twig/CSS. Aucun de ces composants
n'est encore codé — ce sont des cibles.

- **Carte** : `--color-surface-raised`, bordure `1px --color-border`, rayon
  `--kd-radius-lg`, en-tête séparé par un filet `--color-divider`.
- **Bouton primaire** : fond `--color-primary`, texte `--color-on-primary`,
  rayon `--kd-radius-md`, `600 12px` body. Bordure de même couleur que le fond.
- **Bouton secondaire** : fond `--color-surface-raised`, bordure
  `--color-border-strong`, texte `--color-text-strong`.
- **Badge / pill** : `--color-fill` + texte `--color-text-secondary` (neutre),
  ou `--color-primary-tint` + texte `--color-primary-on-tint` (accent). Rayon
  `--kd-radius-pill`, souvent en `--font-mono`.
- **Champ de recherche** : bordure `--color-border-strong`, placeholder
  `--color-text-placeholder`, icône `⌕`.
- **Tuile de séance** (matrice/calendrier) : filet gauche `3px` à la couleur de
  l'activité, fond tint correspondant, rayon `0 7px 7px 0`. La tuile
  « objectif » est terracotta plein avec `--shadow-accent`.
- **Cellule de grille** (matrice semaines×jours, calendrier) : `min-height` ~82–92px,
  bordure `--color-border-cell`, technique de bordures collées `margin:-1px 0 0 -1px`.
- **Bloc de séance** : carte avec en-tête (pastille activité + rôle mono capitale
  + compteur de tours `↻ N` en pill terracotta), lignes d'exercices séparées par
  `--color-divider-soft`, paramètre à droite en pill mono `--color-fill`.
- **Anneau de progression** (synthèse) : `conic-gradient(--color-primary 0 X%,
  --kd-chart-track X% 100%)`, disque intérieur `--color-surface-raised`.
- **Histogramme** : barres `--color-primary` (fait) vs `--kd-chart-todo` (à venir).

Écrans de référence dans la maquette : `1a` éditeur de plan (matrice), `2a`
bibliothèque, `2b` consultation de séance, `2c` calendrier, `2d` synthèse.

---

## 6. Règles

1. **Jamais de couleur/typo en dur** dans un template ou un composant. Toujours
   via un token sémantique (`--color-*`, `--font-*`).
2. **La couleur porte du sens.** Ne pas réutiliser terracotta ou olive hors de
   leur rôle (accent primaire / code activité).
3. **Nouvelle valeur = nouvelle primitive `--kd-*` d'abord**, puis token
   sémantique. On n'expose jamais une primitive directement aux vues.
4. **Toute évolution se répercute** ici et dans `CLAUDE.md` (§ Design).
