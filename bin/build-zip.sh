#!/usr/bin/env bash
# Builds an installable plugin zip: dist/wp-llm-ml.zip
# Upload it in WordPress via Plugins → Add New → Upload Plugin.
set -euo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$REPO/dist"
rm -f "$REPO/dist/wp-llm-ml.zip"
( cd "$REPO/wp-content/plugins" && zip -qr "$REPO/dist/wp-llm-ml.zip" wp-llm-ml -x '*.DS_Store' )
echo "Built $REPO/dist/wp-llm-ml.zip"
