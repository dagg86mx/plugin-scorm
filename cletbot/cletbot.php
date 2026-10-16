<?php
/**
 * Plugin Name:       CLETBOT
 * Description:       Publica en el blog lo que le envíes por correo o WhatsApp: texto, PDF, Word o fotos. Claude lo convierte en una entrada, te responde con el enlace a la vista previa y la publica cuando contestas «SI».
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            The Future Institute
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cletbot
 * Domain Path:       /languages
 *
 * @package Cletbot
 */

defined( 'ABSPATH' ) || exit;

define( 'CLETBOT_VERSION', '1.0.0' );
define( 'CLETBOT_FILE', __FILE__ );
define( 'CLETBOT_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLETBOT_URL', plugin_dir_url( __FILE__ ) );

require_once CLETBOT_DIR . 'includes/class-parser.php';
require_once CLETBOT_DIR . 'includes/class-extractor.php';
require_once CLETBOT_DIR . 'includes/class-ai.php';
require_once CLETBOT_DIR . 'includes/class-log.php';
require_once CLETBOT_DIR . 'includes/class-settings.php';
require_once CLETBOT_DIR . 'includes/class-items.php';
require_once CLETBOT_DIR . 'includes/class-preview.php';
require_once CLETBOT_DIR . 'includes/class-email.php';
require_once CLETBOT_DIR . 'includes/class-whatsapp.php';
require_once CLETBOT_DIR . 'includes/class-workflow.php';
require_once CLETBOT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Cletbot\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Cletbot\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Cletbot\\Plugin', 'instance' ) );
