<?php
/**
 * Plugin Name:       LLM Multilingual
 * Plugin URI:        https://github.com/brnl-claude/wp-llm-ml
 * Description:       Lightweight multilingual plugin without nags, upsells or tracking. One page per language with /nl/-style URL prefixes, browser language detection, a language switcher, hreflang and AI-friendly output, Elementor and GeneratePress support, and optional AI translation and spell checking through any OpenAI-compatible API (for example HostYourAI).
 * Version:           0.0.0-dev
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            wp-llm-ml contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-llm-ml
 * Domain Path:       /languages
 * Update URI:        https://github.com/brnl-claude/wp-llm-ml
 *
 * @package WPLLMML
 */

defined( 'ABSPATH' ) || exit;

// Set to the release version in the zip by bin/build-zip.sh.
define( 'WPLLMML_VERSION', '0.0.0-dev' );
define( 'WPLLMML_FILE', __FILE__ );
define( 'WPLLMML_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPLLMML_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: WPLLMML\Foo_Bar        -> includes/class-foo-bar.php
 *             WPLLMML\Admin\Foo_Bar  -> includes/admin/class-foo-bar.php
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'WPLLMML\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, 8 ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file  = WPLLMML_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

require WPLLMML_DIR . 'includes/functions.php';

/*
 * Boot immediately (not on plugins_loaded): the request language must be known
 * before any plugin or WordPress itself loads translations.
 */
WPLLMML\Plugin::instance()->boot();

register_activation_hook( __FILE__, array( 'WPLLMML\\Plugin', 'activate' ) );
