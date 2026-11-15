#!/usr/bin/env bash
# Builds a disposable WordPress test site (SQLite, no MySQL needed) with the
# same stack as thelibrarywithin.com: GeneratePress, Elementor, Yoast SEO,
# plus this plugin (symlinked, unless PLUGIN_ZIP is set), then seeds sample
# content.
#
# Usage: tests/bin/setup-site.sh <target-dir> [port]
# Then:  php -S localhost:<port> -t <target-dir>/site tests/bin/router.php
set -euo pipefail

TARGET="${1:?target dir}"
PORT="${2:-8890}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WP_VERSION="${WP_VERSION:-7.1.4}"
ELEMENTOR_VERSION="${ELEMENTOR_VERSION:-4.2.2}"
YOAST_VERSION="${YOAST_VERSION:-28.2}"
FORMINATOR_VERSION="${FORMINATOR_VERSION:-1.58.4}"
GP_VERSION="${GP_VERSION:-3.6.1}"
SQLITE_VERSION="${SQLITE_VERSION:-3.1.3}"

mkdir -p "$TARGET/dl"
cd "$TARGET/dl"
# Downloads are cached per version. "latest" (WordPress, Elementor, Yoast SEO)
# means the newest stable release and is always downloaded again; the weekly
# compatibility run uses it.
fetch() { if [ -f "$2" ] && [ "${3:-}" != latest ]; then return 0; fi; curl -sSfL -o "$2" "$1"; }
plugin() { if [ "$2" = latest ]; then echo "https://downloads.wordpress.org/plugin/$1.latest-stable.zip"; else echo "https://downloads.wordpress.org/plugin/$1.$2.zip"; fi; }
if [ "$WP_VERSION" = latest ]; then
	fetch "https://wordpress.org/latest.zip" wordpress-latest.zip latest
else
	fetch "https://wordpress.org/wordpress-${WP_VERSION}.zip" "wordpress-${WP_VERSION}.zip"
fi
fetch "$(plugin sqlite-database-integration "$SQLITE_VERSION")" "sqlite-${SQLITE_VERSION}.zip" "$SQLITE_VERSION"
fetch "$(plugin elementor "$ELEMENTOR_VERSION")" "elementor-${ELEMENTOR_VERSION}.zip" "$ELEMENTOR_VERSION"
fetch "$(plugin wordpress-seo "$YOAST_VERSION")" "yoast-${YOAST_VERSION}.zip" "$YOAST_VERSION"
fetch "$(plugin forminator "$FORMINATOR_VERSION")" "forminator-${FORMINATOR_VERSION}.zip" "$FORMINATOR_VERSION"
fetch "https://downloads.wordpress.org/theme/generatepress.${GP_VERSION}.zip" "generatepress-${GP_VERSION}.zip"

cd "$TARGET"
rm -rf site
unzip -q "dl/wordpress-${WP_VERSION}.zip" && mv wordpress site
( cd site/wp-content/plugins && unzip -q "../../../dl/sqlite-${SQLITE_VERSION}.zip" && unzip -q "../../../dl/elementor-${ELEMENTOR_VERSION}.zip" && unzip -q "../../../dl/yoast-${YOAST_VERSION}.zip" && unzip -q "../../../dl/forminator-${FORMINATOR_VERSION}.zip" )
( cd site/wp-content/themes && unzip -q "../../../dl/generatepress-${GP_VERSION}.zip" )
version_of() { grep -m1 -oE "$2" "$1" | grep -oE "[0-9][0-9.]*[0-9]" | head -1; }
echo "Stack: WordPress $(version_of site/wp-includes/version.php "wp_version = '[^']+'"), Elementor $(version_of site/wp-content/plugins/elementor/elementor.php 'Version: .*'), Yoast SEO $(version_of site/wp-content/plugins/wordpress-seo/wp-seo.php 'Version: .*'), Forminator $(version_of site/wp-content/plugins/forminator/forminator.php 'Version: .*'), GeneratePress ${GP_VERSION}"
# Development: symlink the plugin. Package test (PLUGIN_ZIP set): leave it
# out, the test installs it from the zip like a site owner would.
if [ -z "${PLUGIN_ZIP:-}" ]; then
	ln -sfn "$REPO/wp-content/plugins/wp-llm-ml" site/wp-content/plugins/wp-llm-ml
fi

SITE="$TARGET/site"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    site/wp-content/plugins/sqlite-database-integration/db.copy > site/wp-content/db.php

cat > site/wp-config.php <<EOF
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'wp.sqlite' );
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'test-' . \$k ); }
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://localhost:${PORT}' ); define( 'WP_SITEURL', 'http://localhost:${PORT}' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'FS_METHOD', 'direct' );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
EOF

php "$REPO/tests/bin/wp-eval.php" "$SITE" "$REPO/tests/fixtures/seed.php"
echo "Test site ready in $SITE (http://localhost:${PORT})"
