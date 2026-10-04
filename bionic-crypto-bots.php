<?php
/**
 * Plugin Name:       WordPress AI Crypto Bot
 * Description:       Dashboard and REST API interface for your crypto trading bots — latest trades, P&L, best performers, and profit wallet destinations.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            rains + Bionic
 * License:           GPL-2.0-or-later
 * Text Domain:       bionic-crypto-bots
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BCB_VERSION', '1.1.0' );
define( 'BCB_FILE', __FILE__ );
define( 'BCB_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCB_URL', plugin_dir_url( __FILE__ ) );

require_once BCB_DIR . 'includes/class-bcb-install.php';
require_once BCB_DIR . 'includes/class-bcb-helpers.php';
require_once BCB_DIR . 'includes/class-bcb-stats.php';
require_once BCB_DIR . 'includes/class-bcb-api.php';
require_once BCB_DIR . 'includes/class-bcb-admin.php';
require_once BCB_DIR . 'includes/class-bcb-shortcodes.php';
require_once BCB_DIR . 'includes/class-bcb-watchdog.php';

register_activation_hook( __FILE__, array( 'BCB_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'BCB_Install', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'BCB_Api', 'register_routes' ) );
add_action( 'admin_menu', array( 'BCB_Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'BCB_Admin', 'enqueue_assets' ) );
add_action( 'admin_init', array( 'BCB_Admin', 'handle_actions' ) );
add_action( 'init', array( 'BCB_Shortcodes', 'register' ) );
add_action( 'init', array( 'BCB_Watchdog', 'init' ) );
register_deactivation_hook( __FILE__, array( 'BCB_Watchdog', 'deactivate' ) );
