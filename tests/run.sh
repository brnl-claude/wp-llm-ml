#!/usr/bin/env bash
# Runs the whole integration suite against a disposable WordPress site.
#
#   tests/run.sh [work-dir]
#
# Needs PHP 8.x with pdo_sqlite and curl, and network access to wordpress.org
# for the first run (downloads are cached in <work-dir>/dl).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${1:-${TMPDIR:-/tmp}/wpllmml-test}"
PORT=8890
AI_PORT=8891

"$REPO/tests/bin/setup-site.sh" "$WORK" "$PORT"

PHP_CLI_SERVER_WORKERS=4 php -S "localhost:$PORT" -t "$WORK/site" "$REPO/tests/bin/router.php" > "$WORK/server.log" 2>&1 &
SITE_PID=$!
PHP_CLI_SERVER_WORKERS=2 php -S "localhost:$AI_PORT" "$REPO/tests/fixtures/mock-ai.php" > "$WORK/mock-ai.log" 2>&1 &
AI_PID=$!
trap 'kill $SITE_PID $AI_PID 2>/dev/null || true' EXIT
sleep 1

status=0
echo "== Model tests";  WPLLMML_IDS_FILE="$WORK/ids.json" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/integration/test-model.php" || status=1
echo "== HTTP tests";   php "$REPO/tests/integration/test-http.php" "http://localhost:$PORT" || status=1
echo "== AI tests";     WPLLMML_MOCK_AI="http://localhost:$AI_PORT/v1" WPLLMML_SITE_URL="http://localhost:$PORT" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/integration/test-ai.php" || status=1
echo "== Sync tests";   WPLLMML_MOCK_AI="http://localhost:$AI_PORT/v1" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/integration/test-sync.php" || status=1

# Browser test of the Elementor editor (top-bar language switcher + sync).
# Runs when Playwright is installed (CI installs it), or is forced with
# WPLLMML_BROWSER=1.
if [ "${WPLLMML_BROWSER:-auto}" != 0 ] && ( cd "$REPO" && node -e "require('playwright')" ) 2>/dev/null; then
	echo "== Browser tests (Elementor editor)"
	WPLLMML_MOCK_AI="http://localhost:$AI_PORT/v1" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/fixtures/ai-on.php" || status=1
	# Without WP-Cron, as on hosts where it does not run: the editor starts the sync.
	mkdir -p "$WORK/site/wp-content/mu-plugins" && cp "$REPO/tests/fixtures/no-cron.php" "$WORK/site/wp-content/mu-plugins/"
	node "$REPO/tests/browser/elementor-switcher.js" "http://localhost:$PORT" "$WORK/ids.json" "${WPLLMML_SHOTS:-}" || status=1
	node "$REPO/tests/browser/bulk-translate.js" "http://localhost:$PORT" "${WPLLMML_SHOTS:-}" || status=1
elif [ "${WPLLMML_BROWSER:-auto}" = 1 ]; then
	echo "== Browser tests: Playwright not found (npm install playwright)"; status=1
fi

if grep -E "PHP (Fatal|Warning|Notice|Deprecated)" "$WORK/site/debug.log" 2>/dev/null | grep -q "plugins/wp-llm-ml/"; then
	echo "== PHP messages from the plugin:"
	grep -E "PHP (Fatal|Warning|Notice|Deprecated)" "$WORK/site/debug.log" | grep "plugins/wp-llm-ml/"
	status=1
fi
exit $status
