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
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wpllmml_' ) . '%' ) );
	delete_option( 'wpllmml_settings' );
	delete_option( 'wpllmml_strings' );
	delete_option( 'wpllmml_version' );
	delete_option( 'wpllmml_update_notice' );
	delete_metadata( 'user', 0, 'wpllmml_update_notice_dismissed', '', true );
}
