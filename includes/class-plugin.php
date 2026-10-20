<?php
/**
 * Plugin bootstrap.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static $instance = null;

	private $import_hook = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'tutor-scorm', false, dirname( plugin_basename( TUTOR_SCORM_FILE ) ) . '/languages' );
		// Only es_ES is bundled: use it for the other Spanish locales too (es_MX, es_CO, es_AR…).
		$locale = determine_locale();
		if ( 0 === strpos( $locale, 'es_' ) && ! file_exists( TUTOR_SCORM_DIR . 'languages/tutor-scorm-' . $locale . '.mo' ) ) {
			load_textdomain( 'tutor-scorm', TUTOR_SCORM_DIR . 'languages/tutor-scorm-es_ES.mo', $locale );
		}

		Installer::maybe_upgrade();
		Package_Manager::init();
		Tutor_Integration::init();
		Player::init();
		REST_Controller::init();
		Reports::init();
		Course_Importer::init();

		add_action( 'admin_init', array( 'TutorScorm\\Settings', 'register' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
		add_action( 'deleted_user', array( 'TutorScorm\\Tracking', 'delete_for_user' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TUTOR_SCORM_FILE ), array( $this, 'action_links' ) );
	}

	public static function tutor_active() {
		return function_exists( 'tutor' ) && function_exists( 'tutor_utils' );
	}

	public static function parent_menu() {
		return self::tutor_active() ? 'tutor' : 'edit.php?post_type=' . Package_Manager::POST_TYPE;
	}

	public function admin_menu() {
		$parent = self::parent_menu();
		$this->import_hook = (string) add_submenu_page( $parent, __( 'Import SCORM course', 'tutor-scorm' ), __( 'Import SCORM course', 'tutor-scorm' ), Settings::capability(), Course_Importer::PAGE, array( 'TutorScorm\\Course_Importer', 'render_page' ) );
		add_submenu_page( $parent, __( 'SCORM Reports', 'tutor-scorm' ), __( 'SCORM Reports', 'tutor-scorm' ), Reports::capability(), 'tutor-scorm-reports', array( 'TutorScorm\\Reports', 'render_page' ) );
		add_submenu_page( $parent, __( 'SCORM Settings', 'tutor-scorm' ), __( 'SCORM Settings', 'tutor-scorm' ), 'manage_options', 'tutor-scorm-settings', array( 'TutorScorm\\Settings', 'render_page' ) );
	}

	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ( $screen && Package_Manager::POST_TYPE === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) || ( $this->import_hook && $hook === $this->import_hook ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'tutor-scorm-admin', TUTOR_SCORM_URL . 'assets/js/admin.js', array( 'jquery' ), TUTOR_SCORM_VERSION, true );
			wp_localize_script(
				'tutor-scorm-admin',
				'TutorScormAdmin',
				array(
					'title'  => __( 'Select SCORM ZIP', 'tutor-scorm' ),
					'button' => __( 'Use this file', 'tutor-scorm' ),
				)
			);
		}
	}

	public function dependency_notice() {
		if ( self::tutor_active() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tutor SCORM works best with Tutor LMS. Install and activate Tutor LMS to attach SCORM packages to lessons and complete them automatically.', 'tutor-scorm' ) . '</p></div>';
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Package_Manager::POST_TYPE ) ) . '">' . esc_html__( 'Packages', 'tutor-scorm' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=tutor-scorm-settings' ) ) . '">' . esc_html__( 'Settings', 'tutor-scorm' ) . '</a>'
		);
		return $links;
	}
}
