#!/usr/bin/env bash
# Prints the GitHub release notes for a version: install/update instructions
# plus that version's entry from the readme.txt changelog.
#
#   bin/release-notes.sh 0.1.0
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:?usage: bin/release-notes.sh X.Y.Z}"
README="$REPO/wp-content/plugins/wp-llm-ml/readme.txt"

changes=$(awk -v v="= $VERSION =" '
	$0 == v { on = 1; next }
	on && /^= / { exit }
	on && /^== / { exit }
	on { print }
' "$README" | sed '/^[[:space:]]*$/d')

cat <<EOF
## Wijzigingen / Changes

${changes:-* (no changelog entry)}

## Installeren

1. Download **wp-llm-ml.zip** hieronder (bij *Assets*). Niet uitpakken.
2. WordPress → **Plugins → Nieuwe plugin → Plugin uploaden** → kies het zipbestand → **Nu installeren** → **Activeren**.
3. **Instellingen → Languages**: talen toevoegen, *Install language packs*, taalwisselaar in het menu zetten.

Probeer een nieuwe versie altijd eerst op een testsite (staging).

## Bijwerken

Na installatie meldt WordPress nieuwe releases vanzelf onder **Dashboard → Updates**, net als andere plugins. Je kunt ook de nieuwe zip uploaden: WordPress vraagt dan of je de huidige versie wilt vervangen.

Vereist WordPress 6.5+ en PHP 7.4+. Getest met WordPress 7.1, Elementor 4.2, GeneratePress 3.6 en Yoast SEO 28.
EOF
