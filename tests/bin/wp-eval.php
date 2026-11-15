<?php
/**
 * Tiny stand-in for `wp eval-file`: loads WordPress and runs a PHP file.
 *
 * Usage: php tests/bin/wp-eval.php <wordpress-root> <file.php> [args...]
 * Set WPLLMML_TEST_URI to simulate a front-end request URI (e.g. /nl/).
 *
 * @package WPLLMML
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php wp-eval.php <wordpress-root> <file.php>\n" );
	exit( 2 );
}
$wp_root = rtrim( $argv[1], '/' );
$script  = $argv[2];

$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = getenv( 'WPLLMML_TEST_URI' ) ? getenv( 'WPLLMML_TEST_URI' ) : '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

if ( ! is_file( $wp_root . '/wp-content/database/wp.sqlite' ) ) {
	define( 'WP_INSTALLING', true );
}
require $wp_root . '/wp-load.php';
require $script;
