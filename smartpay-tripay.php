<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://adhityasukma.github.io
 * @since             0.0.1
 * @package           Smartpay_Tripay
 *
 * @wordpress-plugin
 * Plugin Name:       SmartPay - Tripay
 * Plugin URI:        https://adhityasukma.github.io
 * Description:       Simplest way to integration with payment gateways tripay.co.id
 * Version:           0.0.7
 * Author:            Adhitya Sukma
 * Author URI:        https://adhityasukma.github.io
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       smartpay-tripay
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 0.0.1 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'SMARTPAY_TRIPAY_VERSION', '0.0.7' );
define( 'SMARTPAY_TRIPAY_FILE', __FILE__ );
define( 'SMARTPAY_TRIPAY_DIR', __DIR__);
define( 'SMARTPAY_TRIPAY_ADMIN_DIR', plugin_dir_path( __FILE__ ) . '/admin' );
define( 'SMARTPAY_TRIPAY_PUBLIC_DIR', plugin_dir_path( __FILE__ ) . '/public' );
define( 'SMARTPAY_TRIPAY_INC_DIR', plugin_dir_path( __FILE__ ) . '/includes' );
define( 'SMARTPAY_TRIPAY_LIB_DIR', __DIR__ . '/lib' );
define( 'SMARTPAY_TRIPAY_PLUGIN_ASSEST', plugins_url( 'assets', __FILE__ ) );
define( 'SMARTPAY_TRIPAY_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMARTPAY_TRIPAY_DIR_URL', plugin_dir_url( __FILE__ ) );


/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-smartpay-tripay-activator.php
 */
function activate_smartpay_tripay() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-smartpay-tripay-activator.php';
	Smartpay_Tripay_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-smartpay-tripay-deactivator.php
 */
function deactivate_smartpay_tripay() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-smartpay-tripay-deactivator.php';
	Smartpay_Tripay_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_smartpay_tripay' );
register_deactivation_hook( __FILE__, 'deactivate_smartpay_tripay' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-smartpay-tripay.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    0.0.1
 */
function run_smartpay_tripay() {

	$plugin = new Smartpay_Tripay();
	$plugin->run();

}
run_smartpay_tripay();
