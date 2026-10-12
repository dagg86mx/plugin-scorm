<?php
/**
 * Plugin Name:       Retícula Académica
 * Description:       Organiza cursos de Tutor LMS en carreras y tetramestres. Los alumnos se inscriben a una carrera, pagan sus cuotas con el checkout de Tutor, ven qué curso sigue y avanzan de tetramestre automáticamente.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            The Future Institute
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reticula-academica
 * Domain Path:       /languages
 *
 * @package ReticulaAcademica
 */

defined( 'ABSPATH' ) || exit;

define( 'RA_VERSION', '1.0.0' );
define( 'RA_FILE', __FILE__ );
define( 'RA_DIR', plugin_dir_path( __FILE__ ) );
define( 'RA_URL', plugin_dir_url( __FILE__ ) );

require_once RA_DIR . 'includes/class-progress.php';
require_once RA_DIR . 'includes/class-installer.php';
require_once RA_DIR . 'includes/class-tutor.php';
require_once RA_DIR . 'includes/class-curriculum.php';
require_once RA_DIR . 'includes/class-inscriptions.php';
require_once RA_DIR . 'includes/class-post-types.php';
require_once RA_DIR . 'includes/class-gating.php';
require_once RA_DIR . 'includes/class-frontend.php';
require_once RA_DIR . 'includes/class-admin.php';
require_once RA_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'ReticulaAcademica\\Installer', 'activate' ) );

add_action( 'plugins_loaded', array( 'ReticulaAcademica\\Plugin', 'instance' ), 20 );
