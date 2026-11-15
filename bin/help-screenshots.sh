#!/usr/bin/env bash
# Makes the screenshots of the guide (Settings → Languages → How it works)
# in a disposable test site and saves them as
# wp-content/plugins/wp-llm-ml/assets/help/*.webp.
#
#   bin/help-screenshots.sh [work-dir]
#
# Needs what tests/run.sh needs, plus Playwright (npm install playwright) and
# Python 3 with Pillow. Run it again after changing a screen the guide shows.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${1:-${TMPDIR:-/tmp}/wpllmml-help}"
PORT=8890
AI_PORT=8891
OUT="$REPO/wp-content/plugins/wp-llm-ml/assets/help"

"$REPO/tests/bin/setup-site.sh" "$WORK" "$PORT"
WPLLMML_IDS_FILE="$WORK/ids.json" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/integration/test-model.php" > /dev/null

PHP_CLI_SERVER_WORKERS=4 php -S "localhost:$PORT" -t "$WORK/site" "$REPO/tests/bin/router.php" > "$WORK/server.log" 2>&1 &
SITE_PID=$!
PHP_CLI_SERVER_WORKERS=2 php -S "localhost:$AI_PORT" "$REPO/tests/fixtures/mock-ai.php" > "$WORK/mock-ai.log" 2>&1 &
AI_PID=$!
trap 'kill $SITE_PID $AI_PID 2>/dev/null || true' EXIT
sleep 1

WPLLMML_MOCK_AI="http://localhost:$AI_PORT/v1" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/fixtures/help/demo.php"
mkdir -p "$WORK/shots" "$OUT"
( cd "$REPO" && node bin/help-screenshots.js "http://localhost:$PORT" "$WORK/shots" "$WORK/ids.json" "$REPO" "$WORK/site" )

# WebP, at most 1200 px wide: sharp enough, small in the plugin zip.
python3 - "$WORK/shots" "$OUT" <<'PY'
import os, sys
from PIL import Image
src, out = sys.argv[1], sys.argv[2]
for name in sorted(os.listdir(src)):
    if not name.endswith('.png'):
        continue
    image = Image.open(os.path.join(src, name)).convert('RGB')
    if image.width > 1200:
        image = image.resize((1200, round(image.height * 1200 / image.width)), Image.LANCZOS)
    target = os.path.join(out, name[:-4] + '.webp')
    image.save(target, 'WEBP', quality=82, method=6)
    print('%-16s %4dx%-4d %5.1f KB' % (name[:-4], image.width, image.height, os.path.getsize(target) / 1024))
PY
