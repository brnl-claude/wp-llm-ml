<?php
/**
 * Package test (run inside a site where the plugin was installed from the
 * release zip, NOT symlinked): installation from the zip, activation,
 * front end, and an update from a fake GitHub release through WordPress'
 * own upgrader.
 *
 * Env: WPLLMML_ZIP (release zip), WPLLMML_MOCK_API (fake GitHub API base),
 *      WPLLMML_MOCK_VERSION (version served by the fake release),
 *      WPLLMML_SITE_URL (the running test site).
 *
 * @package WPLLMML
 */

require_once __DIR__ . '/assert.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user( 1 );

$file = 'wp-llm-ml/wp-llm-ml.php';
$dir  = WP_PLUGIN_DIR . '/wp-llm-ml';
section( 'Install from the release zip' );
if ( ! is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
	$installer = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
	$result    = $installer->install( getenv( 'WPLLMML_ZIP' ) );
	check( 'zip installs through Plugin_Upgrader', true === $result, is_wp_error( $result ) ? $result->get_error_message() : $result );
} else {
	check( 'plugin present', true );
}
check( 'plugin directory is a real copy, not a symlink', is_dir( $dir ) && ! is_link( $dir ) );
$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
check( 'plugin header readable', 'LLM Multilingual' === $data['Name'] && '' !== $data['Version'] );
check( 'Update URI points to GitHub', 'https://github.com/brnl-claude/wp-llm-ml' === $data['UpdateURI'] );
check( 'zip contains no test or build files', ! is_dir( $dir . '/tests' ) && ! is_file( $dir . '/README.md' ) );
$activated = activate_plugin( $file );
check( 'activates without errors', null === $activated, is_wp_error( $activated ) ? $activated->get_error_message() : '' );
$old_version = $data['Version'];

// Log in like a browser, for admin pages over HTTP (the site runs the files on disk).
$expire                      = time() + DAY_IN_SECONDS;
$token                       = WP_Session_Tokens::get_instance( 1 )->create( $expire );
$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( 1, $expire, 'logged_in', $token );
$admin_cookies               = array(
	AUTH_COOKIE      => wp_generate_auth_cookie( 1, $expire, 'auth', $token ),
	LOGGED_IN_COOKIE => $_COOKIE[ LOGGED_IN_COOKIE ],
);
$admin_page                  = static function () use ( $admin_cookies ) {
	return wp_remote_retrieve_body( wp_remote_get( getenv( 'WPLLMML_SITE_URL' ) . '/wp-admin/index.php', array( 'timeout' => 30, 'cookies' => $admin_cookies ) ) );
};
section( 'No update notice after a fresh installation' );
$dashboard = $admin_page();
check( 'dashboard loads for the admin', false !== strpos( $dashboard, 'id="wpbody"' ) );
check( 'no "updated" notice on a fresh install', false === strpos( $dashboard, 'wpllmml-update-notice' ) );

section( 'Front end after installation' );
WPLLMML\Settings::save(
	array_merge(
		WPLLMML\Settings::all(),
		array(
			'languages'     => array(
				array( 'code' => 'en', 'locale' => 'en_US', 'name' => 'English' ),
				array( 'code' => 'nl', 'locale' => 'nl_NL', 'name' => 'Nederlands' ),
			),
			'default'       => 'en',
			'menu_location' => 'primary',
		)
	)
);
WPLLMML\Translations::flush();
$nl_home = WPLLMML\Duplicator::create( (int) get_option( 'page_on_front' ), 'nl' );
check( 'translation of the home page created', is_int( $nl_home ) );
wp_update_post( array( 'ID' => $nl_home, 'post_status' => 'publish' ) );
$front = getenv( 'WPLLMML_SITE_URL' );
foreach ( array( '/', '/nl/', '/wp-json/wpllmml/v1/languages' ) as $path ) {
	$response = wp_remote_get( $front . $path, array( 'timeout' => 30 ) );
	check( "GET $path → 200", 200 === wp_remote_retrieve_response_code( $response ), is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) );
}
$body = wp_remote_retrieve_body( wp_remote_get( $front . '/nl/', array( 'timeout' => 30 ) ) );
check( '/nl/ served in Dutch with switcher', false !== strpos( $body, '<html lang="nl-NL"' ) && false !== strpos( $body, 'data-wpllmml-lang="en"' ) );

section( 'Update from a GitHub release' );
add_filter(
	'wpllmml_update_api_url',
	static function () {
		return rtrim( getenv( 'WPLLMML_MOCK_API' ), '/' ) . '/github/releases/latest';
	}
);
delete_site_transient( 'wpllmml_latest_release' );
// The fake GitHub runs on localhost:<port>; WordPress only downloads updates
// from public hosts on standard ports, which real GitHub URLs are.
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) {
		$ports[] = (int) wp_parse_url( getenv( 'WPLLMML_MOCK_API' ), PHP_URL_PORT );
		return $ports;
	}
);
$updater = new WPLLMML\Updater();
$updater->boot();
$update = apply_filters( 'update_plugins_github.com', false, $data, $file, array() );
$mock   = getenv( 'WPLLMML_MOCK_VERSION' );
check( 'update offered with the release asset', is_array( $update ) && $mock === $update['version'] && '/github/wp-llm-ml.zip' === wp_parse_url( $update['package'], PHP_URL_PATH ), $update );
$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'wp-llm-ml' ) );
check( '"View details" information', is_object( $info ) && $mock === $info->version && false !== strpos( $info->sections['changelog'], 'something new' ) );

// What wp_update_plugins() stores for a custom Update URI host.
$transient = get_site_transient( 'update_plugins' );
$transient = is_object( $transient ) ? $transient : new stdClass();
$entry                       = (object) $update;
$entry->id                   = $data['UpdateURI'];
$entry->plugin               = $file;
$entry->new_version          = $update['version'];
$transient->response         = isset( $transient->response ) ? $transient->response : array();
$transient->response[ $file ] = $entry;
set_site_transient( 'update_plugins', $transient );

// Same call as the "Update now" button (wp_ajax_update_plugin) and Dashboard → Updates.
$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
$results  = $upgrader->bulk_upgrade( array( $file ) );
$result   = isset( $results[ $file ] ) ? $results[ $file ] : null;
check( 'WordPress upgrader installs the release', $result && ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : wp_json_encode( $upgrader->skin->get_upgrade_messages() ) );
wp_clean_plugins_cache();
$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
check( 'new version on disk', $mock === $after['Version'], $after['Version'] );
check( 'still active after update', is_plugin_active( $file ) );
check( 'settings kept', count( WPLLMML\Settings::get( 'languages' ) ) >= 1 );
echo "  ($old_version → {$after['Version']})\n";
section( 'One-time notice after the update' );
// PHP's opcode cache may serve the old plugin files for a moment after the update.
for ( $try = 0; $try < 10; $try++ ) {
	$dashboard = $admin_page();
	if ( false !== strpos( $dashboard, 'wpllmml-update-notice' ) ) {
		break;
	}
	sleep( 1 );
}
check( 'notice shows the new version', false !== strpos( $dashboard, 'LLM Multilingual was updated to version ' . $mock . '.' ), substr( $dashboard, 0, 200 ) );
check( "notice lists what's new from the zip", false !== strpos( $dashboard, '<li>Test entry with <code>code</code> and <strong>bold</strong></li>' ) && false !== strpos( $dashboard, '<li>Second entry</li>' ) );
preg_match( '/_ajax_nonce\', ("[^"]+")/', $dashboard, $m );
$dismiss = wp_remote_post(
	getenv( 'WPLLMML_SITE_URL' ) . '/wp-admin/admin-ajax.php',
	array(
		'timeout' => 30,
		'cookies' => $admin_cookies,
		'body'    => array(
			'action'      => 'wpllmml_dismiss_update_notice',
			'_ajax_nonce' => isset( $m[1] ) ? json_decode( $m[1] ) : '',
		),
	)
);
check( 'closing the notice is accepted', ! empty( json_decode( wp_remote_retrieve_body( $dismiss ), true )['success'] ), wp_remote_retrieve_body( $dismiss ) );
check( 'notice stays closed', false === strpos( $admin_page(), 'wpllmml-update-notice' ) );

$response = wp_remote_get( $front . '/nl/', array( 'timeout' => 30 ) );
check( 'site works after update', 200 === wp_remote_retrieve_response_code( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'id="wpllmml-detect"' ) );

finish();
