<?php
/**
 * Wires everything together: menus, REST routes, hooks.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'cletbot', false, dirname( plugin_basename( CLETBOT_FILE ) ) . '/languages' );
		Settings::init();
		Items::init();
		Preview::init();
		Workflow::init();
		Email::init();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_css' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CLETBOT_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'trashed_post', array( __CLASS__, 'post_trashed' ) );
	}

	public static function routes() {
		register_rest_route(
			'cletbot/v1',
			'/whatsapp',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( 'Cletbot\\WhatsApp', 'verify' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( 'Cletbot\\WhatsApp', 'receive' ),
					'permission_callback' => '__return_true', // Authenticated by the X-Hub-Signature-256 HMAC.
				),
			)
		);
		register_rest_route(
			'cletbot/v1',
			'/email',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'Cletbot\\Email', 'webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the secret key in the URL.
			)
		);
	}

	public static function menu() {
		$count = count( Items::awaiting( 99 ) );
		$badge = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';
		add_menu_page( 'CLETBOT', 'CLETBOT' . $badge, 'edit_posts', Items::MENU, '', 'dashicons-format-chat', 26 );
		add_submenu_page( Items::MENU, __( 'Ajustes de CLETBOT', 'cletbot' ), __( 'Ajustes', 'cletbot' ), 'manage_options', Settings::PAGE, array( 'Cletbot\\Settings', 'render' ) );
	}

	public static function admin_css( $hook ) {
		$screen = get_current_screen();
		if ( false !== strpos( (string) $hook, Settings::PAGE ) || ( $screen && Items::POST_TYPE === $screen->post_type ) ) {
			wp_enqueue_style( 'cletbot-admin', CLETBOT_URL . 'assets/css/admin.css', array(), CLETBOT_VERSION );
		}
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">' . esc_html__( 'Ajustes', 'cletbot' ) . '</a>' );
		return $links;
	}

	/** If someone trashes the pending post from the admin, the item stops waiting for an answer. */
	public static function post_trashed( $post_id ) {
		$item = (int) get_post_meta( $post_id, '_cletbot_item', true );
		if ( $item && 'awaiting' === Items::state( $item ) ) {
			Items::set_state( $item, 'rejected' );
		}
	}

	public static function activate() {
		Settings::all();
		Email::reschedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( Email::CRON_HOOK );
	}
}
