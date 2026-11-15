#!/usr/bin/env bash
# Builds the installable plugin zip: dist/wp-llm-ml.zip
#
#   bin/build-zip.sh [version]
#
# The version (default: bin/version.sh) is written into the zip's copy of the plugin
# header, the WPLLMML_VERSION constant and readme.txt; the sources keep 0.0.0-dev.
# Upload the zip in WordPress via Plugins → Add New → Upload Plugin.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$("$REPO/bin/version.sh")}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Version must look like 1.2.3, got '$VERSION'" >&2; exit 1; }

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
cp -R "$REPO/wp-content/plugins/wp-llm-ml" "$STAGE/wp-llm-ml"
MAIN="$STAGE/wp-llm-ml/wp-llm-ml.php"
sed -i.bak -e "s/^ \* Version:[[:space:]]*.*/ * Version:           $VERSION/" \
	-e "s/^define( 'WPLLMML_VERSION', '[^']*' );/define( 'WPLLMML_VERSION', '$VERSION' );/" "$MAIN"
sed -i.bak -e "s/^Stable tag:[[:space:]]*.*/Stable tag: $VERSION/" "$STAGE/wp-llm-ml/readme.txt"
find "$STAGE" -name '*.bak' -delete
grep -q "^ \* Version:           $VERSION$" "$MAIN" && grep -q "'WPLLMML_VERSION', '$VERSION'" "$MAIN" \
	|| { echo "Could not set the version in $MAIN" >&2; exit 1; }

mkdir -p "$REPO/dist"
rm -f "$REPO/dist/wp-llm-ml.zip"
( cd "$STAGE" && zip -qr "$REPO/dist/wp-llm-ml.zip" wp-llm-ml -x '*.DS_Store' )
echo "Built $REPO/dist/wp-llm-ml.zip (version $VERSION)"
