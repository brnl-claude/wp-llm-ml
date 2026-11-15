#!/usr/bin/env bash
# Builds the release zip and tests it the way a site owner uses it:
# install the zip in a fresh WordPress, activate it, load pages, then update
# it from a fake GitHub release through WordPress' own upgrader.
#
#   tests/package.sh [work-dir]
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${1:-${TMPDIR:-/tmp}/wpllmml-package}"
PORT=8892
API_PORT=8893

"$REPO/bin/build-zip.sh"
ZIP="$REPO/dist/wp-llm-ml.zip"

# Same zip with a higher version number, served as the "next release".
NEXT_VERSION="99.0.0"
mkdir -p "$WORK"
php -r '
	[$src, $dst, $v] = array_slice($argv, 1);
	copy($src, $dst);
	$z = new ZipArchive(); $z->open($dst);
	$f = "wp-llm-ml/wp-llm-ml.php";
	$s = $z->getFromName($f);
	$s = preg_replace("/^ \* Version:\s+\S+/m", " * Version:           $v", $s);
	$s = preg_replace("/define\( .WPLLMML_VERSION., .[^\x27]+. \)/", "define( \x27WPLLMML_VERSION\x27, \x27$v\x27 )", $s);
	$z->addFromString($f, $s);
	$z->addFromString("wp-llm-ml/whats-new.json", json_encode(array("version" => $v, "entries" => array("Test entry with `code` and **bold**", "Second entry"))));
	$z->close();
' "$ZIP" "$WORK/next.zip" "$NEXT_VERSION"

PLUGIN_ZIP="$ZIP" "$REPO/tests/bin/setup-site.sh" "$WORK" "$PORT"

PHP_CLI_SERVER_WORKERS=2 php -S "localhost:$PORT" -t "$WORK/site" "$REPO/tests/bin/router.php" > "$WORK/server.log" 2>&1 &
SITE_PID=$!
WPLLMML_MOCK_ZIP="$WORK/next.zip" WPLLMML_MOCK_VERSION="$NEXT_VERSION" php -S "localhost:$API_PORT" "$REPO/tests/fixtures/mock-ai.php" > "$WORK/api.log" 2>&1 &
API_PID=$!
trap 'kill $SITE_PID $API_PID 2>/dev/null || true' EXIT
sleep 1

status=0
WPLLMML_ZIP="$ZIP" WPLLMML_MOCK_API="http://localhost:$API_PORT" WPLLMML_MOCK_VERSION="$NEXT_VERSION" \
	WPLLMML_SITE_URL="http://localhost:$PORT" php "$REPO/tests/bin/wp-eval.php" "$WORK/site" "$REPO/tests/integration/test-package.php" || status=1

exit $status
