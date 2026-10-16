<?php
/**
 * Uninstall: removes settings, the log and the tracking items. Blog posts are kept.
 *
 * @package Cletbot
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! apply_filters( 'cletbot_delete_data_on_uninstall', true ) ) {
	return;
}

delete_option( 'cletbot_settings' );
delete_option( 'cletbot_log' );
wp_clear_scheduled_hook( 'cletbot_check_mail' );

$cletbot_items = get_posts(
	array(
		'post_type'      => 'cletbot_item',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $cletbot_items as $cletbot_item ) {
	wp_delete_post( $cletbot_item, true );
}
