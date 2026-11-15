<?php
/**
 * Uninstall: removes settings (and translation links only when the admin
 * opted in). Translated pages themselves are never deleted.
 *
 * @package WPLLMML
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpllmml_settings = get_option( 'wpllmml_settings', array() );

if ( is_array( $wpllmml_settings ) && ! empty( $wpllmml_settings['delete_data'] ) ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)", '_wpllmml_lang', '_wpllmml_group', '_wpllmml_ai_translated' ) );
	delete_option( 'wpllmml_settings' );
	delete_option( 'wpllmml_strings' );
}
