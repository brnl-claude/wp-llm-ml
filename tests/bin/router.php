<?php
/**
 * Router for PHP's built-in web server: serve existing files, send the rest
 * to WordPress (like the usual .htaccess rules).
 *
 * @package WPLLMML
 */

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( '/' !== $path && is_file( $file ) && ! preg_match( '/\.php$/', $path ) ) {
	return false;
}
if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php)#', $path ) && is_file( $file ) ) {
	return false;
}
if ( is_dir( $file ) && is_file( rtrim( $file, '/' ) . '/index.php' ) && 0 === strpos( $path, '/wp-admin' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	require rtrim( $file, '/' ) . '/index.php';
	return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
