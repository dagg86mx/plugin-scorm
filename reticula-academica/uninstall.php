<?php
/**
 * Uninstall (only when the ra_delete_data_on_uninstall filter returns true): removes tables and options.
 * Programs, terms, templates, student IDs (user meta) and generated PDFs are kept.
 *
 * @package ReticulaAcademica
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! apply_filters( 'ra_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ra_inscriptions" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ra_student_ids" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ra_documents" ); // phpcs:ignore WordPress.DB
delete_option( 'ra_settings' );
delete_option( 'ra_default_templates' );
delete_option( 'ra_db_version' );
delete_option( 'ra_flush_rewrite' );
