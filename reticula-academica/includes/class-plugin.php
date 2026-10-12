<?php
/**
 * Plugin bootstrap.
 *
 * @package ReticulaAcademica
 */

namespace ReticulaAcademica;

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
		load_plugin_textdomain( 'reticula-academica', false, dirname( plugin_basename( RA_FILE ) ) . '/languages' );
		Installer::maybe_upgrade();
		Post_Types::init();
		Inscriptions::init();
		Gating::init();
		Frontend::init();
		Admin::init();
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function dependency_notice() {
		if ( Tutor::active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Retícula Académica needs Tutor LMS to be installed and active.', 'reticula-academica' ) . '</p></div>';
	}
}
