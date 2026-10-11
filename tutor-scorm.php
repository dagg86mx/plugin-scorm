<?php
/**
 * Plugin Name:       Tutor SCORM
 * Description:       Import SCORM 1.2 and SCORM 2004 packages (or whole courses from a ZIP with an outline) into Tutor LMS lessons with a built-in SCORM runtime, progress tracking, reports and automatic lesson completion. A drop-in replacement for xAPI-based content delivery.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            The Future Institute
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tutor-scorm
 * Domain Path:       /languages
 *
 * @package TutorScorm
 */

defined( 'ABSPATH' ) || exit;

define( 'TUTOR_SCORM_VERSION', '1.1.0' );
define( 'TUTOR_SCORM_FILE', __FILE__ );
define( 'TUTOR_SCORM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUTOR_SCORM_URL', plugin_dir_url( __FILE__ ) );

require_once TUTOR_SCORM_DIR . 'includes/class-manifest-parser.php';
require_once TUTOR_SCORM_DIR . 'includes/class-outline-parser.php';
require_once TUTOR_SCORM_DIR . 'includes/class-time.php';
require_once TUTOR_SCORM_DIR . 'includes/class-settings.php';
require_once TUTOR_SCORM_DIR . 'includes/class-installer.php';
require_once TUTOR_SCORM_DIR . 'includes/class-storage.php';
require_once TUTOR_SCORM_DIR . 'includes/class-package-manager.php';
require_once TUTOR_SCORM_DIR . 'includes/class-tracking.php';
require_once TUTOR_SCORM_DIR . 'includes/class-tutor-integration.php';
require_once TUTOR_SCORM_DIR . 'includes/class-player.php';
require_once TUTOR_SCORM_DIR . 'includes/class-rest-controller.php';
require_once TUTOR_SCORM_DIR . 'includes/class-reports.php';
require_once TUTOR_SCORM_DIR . 'includes/class-course-importer.php';
require_once TUTOR_SCORM_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'TutorScorm\\Installer', 'activate' ) );

add_action( 'plugins_loaded', array( 'TutorScorm\\Plugin', 'instance' ), 20 );
