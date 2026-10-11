<?php
/**
 * Uninstall: removes data only when enabled in the plugin settings.
 *
 * @package TutorScorm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$tutor_scorm_settings = get_option( 'tutor_scorm_settings', array() );
if ( empty( $tutor_scorm_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$tutor_scorm_packages = get_posts(
	array(
		'post_type'      => 'tutor_scorm',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $tutor_scorm_packages as $tutor_scorm_package_id ) {
	wp_delete_post( $tutor_scorm_package_id, true );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tutor_scorm_tracking" ); // phpcs:ignore WordPress.DB
delete_post_meta_by_key( '_tutor_scorm_package_id' );
delete_option( 'tutor_scorm_settings' );
delete_option( 'tutor_scorm_db_version' );

$tutor_scorm_uploads = wp_upload_dir( null, false );
$tutor_scorm_dir     = trailingslashit( $tutor_scorm_uploads['basedir'] ) . 'tutor-scorm';
if ( is_dir( $tutor_scorm_dir ) ) {
	$tutor_scorm_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $tutor_scorm_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $tutor_scorm_items as $tutor_scorm_item ) {
		if ( $tutor_scorm_item->isDir() && ! $tutor_scorm_item->isLink() ) {
			@rmdir( $tutor_scorm_item->getPathname() ); // phpcs:ignore
		} else {
			@unlink( $tutor_scorm_item->getPathname() ); // phpcs:ignore
		}
	}
	@rmdir( $tutor_scorm_dir ); // phpcs:ignore
}
