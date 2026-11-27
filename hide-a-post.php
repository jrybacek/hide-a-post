<?php
/**
 * Plugin Name:       Hide A Post
 * Plugin URI:        https://github.com/jrybacek/hide-a-post
 * Description:       WordPress plugin that hides posts on the site, search, and WordPress admin from non-Administrators using one or more categories.
 * Version:           2.0.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Joe Rybacek
 * Author URI:        https://github.com/jrybacek
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hide-a-post
 *
 * @package Hide_A_Post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HAP_VERSION', '2.0.0' );
define( 'HAP_FILE', __FILE__ );
define( 'HAP_BASENAME', plugin_basename( __FILE__ ) );
define( 'HAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAP_OPTION_NAME', 'hap' );
define( 'HAP_SETTINGS_PAGE', 'hap' );
define( 'HAP_SETTINGS_PATH', 'options-general.php?page=' . HAP_SETTINGS_PAGE );

require_once HAP_DIR . 'includes/class-hap-plugin.php';
require_once HAP_DIR . 'includes/class-hap-settings.php';
require_once HAP_DIR . 'includes/class-hap-query-filter.php';

register_activation_hook( HAP_FILE, array( 'HAP_Plugin', 'activate' ) );

/**
 * Bootstraps the Hide A Post plugin.
 *
 * @since 2.0.0
 *
 * @return HAP_Plugin
 */
function hap_plugin() {
	return HAP_Plugin::instance();
}

add_action( 'plugins_loaded', array( hap_plugin(), 'init' ) );
