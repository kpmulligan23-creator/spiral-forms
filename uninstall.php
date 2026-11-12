<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is kept unless SPIRAL_FORMS_REMOVE_DATA is true in wp-config.php,
 * so an accidental delete/reinstall never loses entries.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'SPIRAL_FORMS_REMOVE_DATA' ) || ! SPIRAL_FORMS_REMOVE_DATA ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spiral_forms_entries" ); // phpcs:ignore
$ids = get_posts( array( 'post_type' => 'spiral_form', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
delete_option( 'spiral_forms_settings' );
delete_option( 'spiral_forms_db_version' );
delete_site_transient( 'spiral_forms_release' );
