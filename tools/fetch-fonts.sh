#!/usr/bin/env bash
#
# Kadens — récupération des polices self-hostées (offline-first, Phase 9).
#
# Télécharge les woff2 depuis Google Fonts dans assets/fonts/ et régénère
# assets/styles/fonts.css. Ne garde que les subsets latin + latin-ext : le
# projet est francophone, le vietnamien et le cyrillique n'ont pas à peser.
#
# Usage :  ./tools/fetch-fonts.sh
#
# Les familles et graisses vivent dans le tableau FAMILIES ci-dessous — c'est
# la seule chose à modifier quand l'identité typographique évolue. Le fichier
# généré ne doit jamais être édité à la main.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FONT_DIR="$ROOT/assets/fonts"
OUT="$ROOT/assets/styles/fonts.css"

# UA moderne : sans lui, Google Fonts sert du ttf au lieu du woff2.
UA="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

# « Nom Google | slug de fichier | graisses »
FAMILIES=(
  "Barlow|barlow|400 500 600 700"
  "Barlow Condensed|barlow-condensed|500 600 700 800"
  "IBM Plex Mono|ibm-plex-mono|400 500 600"
)

SUBSETS="latin latin-ext"

rm -f "$FONT_DIR"/*.woff2
mkdir -p "$FONT_DIR"

{
  echo "/* ============================================================================"
  echo "   Kadens — polices self-hostées (offline-first)."
  echo "   Généré par tools/fetch-fonts.sh depuis Google Fonts (subsets latin +"
  echo "   latin-ext). Ne pas éditer à la main : relancer le script si les familles"
  echo "   ou les graisses changent."
  echo "   Les url() relatives sont réécrites par AssetMapper vers les chemins digestés."
  echo "   ============================================================================ */"
} > "$OUT"

for entry in "${FAMILIES[@]}"; do
  IFS='|' read -r family slug weights <<< "$entry"
  query="${family// /+}"

  for weight in $weights; do
    css="$(curl -sf -A "$UA" "https://fonts.googleapis.com/css2?family=${query}:wght@${weight}&display=swap")"

    for subset in $SUBSETS; do
      # Le CSS de Google est une suite de « /* subset */ » + bloc @font-face.
      block="$(awk -v want="/* $subset */" '
        $0 == want { grab = 1; next }
        /^\/\*/    { grab = 0 }
        grab       { print }
      ' <<< "$css")"

      url="$(sed -n 's/.*src: url(\([^)]*\)).*/\1/p' <<< "$block")"
      range="$(sed -n 's/.*unicode-range: \(.*\);/\1/p' <<< "$block")"

      if [ -z "$url" ]; then
        echo "!! subset $subset introuvable pour $family $weight" >&2
        exit 1
      fi

      file="${slug}-${weight}-${subset}.woff2"
      curl -sf -o "$FONT_DIR/$file" "$url"

      {
        echo ""
        echo "/* $family $weight — $subset */"
        echo "@font-face {"
        echo "  font-family: '$family';"
        echo "  font-style: normal;"
        echo "  font-weight: $weight;"
        echo "  font-display: swap;"
        echo "  src: url('../fonts/$file') format('woff2');"
        echo "  unicode-range: $range;"
        echo "}"
      } >> "$OUT"
    done
  done
done

echo "OK — $(ls -1 "$FONT_DIR" | wc -l | tr -d ' ') woff2 dans assets/fonts/, fonts.css régénéré."
