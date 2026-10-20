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
		// Only es_ES is bundled: use it for the other Spanish locales too (es_MX, es_CO, es_AR…).
		$locale = determine_locale();
		if ( 0 === strpos( $locale, 'es_' ) && ! file_exists( RA_DIR . 'languages/reticula-academica-' . $locale . '.mo' ) ) {
			load_textdomain( 'reticula-academica', RA_DIR . 'languages/reticula-academica-es_ES.mo', $locale );
		}
		Installer::maybe_upgrade();
		Post_Types::init();
		Inscriptions::init();
		Gating::init();
		Frontend::init();
		Admin::init();
		Settings::init();
		Student_Id::init();
		Grades::init();
		Documents::init();
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
	}

	public function dependency_notice() {
		if ( Tutor::active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Retícula Académica needs Tutor LMS to be installed and active.', 'reticula-academica' ) . '</p></div>';
	}
}
