<?php
/**
 * Plugin Name: WP Media Check
 * Description: Restrict Delete media if used in your WordPress.
 * Author: WP Square
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Version: 1.0
 * Text Domain: wp-media-check
 *
 * @package WPCLR-Media-Check
 * */

namespace WPSQR_WPMDC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the current version of the this plugin.
 */
define( 'WPSQR_WPMDC_VERSION', '1.0.0' );

/**
 * Defines the url of the this plugin.
 */
define( 'WPSQR_WPMDC_DIR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Defines the path of the this plugin.
 */
define( 'WPSQR_WPMDC_DIR_PATH', plugin_dir_path( __FILE__ ) );

/**
 * The core plugin class that is used to define core working
 * of plugin.
 */
require WPSQR_WPMDC_DIR_PATH . 'inc/class-wpmdc.php';

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, [ __NAMESPACE__ . '\WPSQR_WPMDC', 'activate' ] );
register_deactivation_hook( __FILE__, [ __NAMESPACE__ . '\WPSQR_WPMDC', 'deactivate' ] );

// Initialize the core plugin class.
WPSQR_WPMDC::get_instance();
