<?php
/**
 * Uninstall: removes the inscriptions table and options. Programs and terms are kept as regular content.
 *
 * @package ReticulaAcademica
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! apply_filters( 'ra_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ra_inscriptions" ); // phpcs:ignore WordPress.DB
delete_option( 'ra_db_version' );
delete_option( 'ra_flush_rewrite' );
