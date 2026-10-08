<?php
/**
 * Plugin Name:       WordPress AI Crypto Bot
 * Description:       Dashboard and REST API interface for your crypto trading bots — latest trades, P&L, best performers, and profit wallet destinations.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            rains + Bionic
 * License:           GPL-2.0-or-later
 * Text Domain:       bionic-crypto-bots
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CTB_VERSION', '1.2.0' );
define( 'CTB_FILE', __FILE__ );
define( 'CTB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTB_URL', plugin_dir_url( __FILE__ ) );

require_once CTB_DIR . 'includes/class-ctb-install.php';
require_once CTB_DIR . 'includes/class-ctb-helpers.php';
require_once CTB_DIR . 'includes/class-ctb-stats.php';
require_once CTB_DIR . 'includes/class-ctb-api.php';
require_once CTB_DIR . 'includes/class-ctb-admin.php';
require_once CTB_DIR . 'includes/class-ctb-shortcodes.php';
require_once CTB_DIR . 'includes/class-ctb-watchdog.php';

register_activation_hook( __FILE__, array( 'CTB_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'CTB_Install', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'CTB_Api', 'register_routes' ) );
add_action( 'admin_menu', array( 'CTB_Admin', 'register_menu' ) );
add_action( 'admin_enqueue_scripts', array( 'CTB_Admin', 'enqueue_assets' ) );
add_action( 'admin_init', array( 'CTB_Admin', 'handle_actions' ) );
add_action( 'init', array( 'CTB_Shortcodes', 'register' ) );
add_action( 'init', array( 'CTB_Watchdog', 'init' ) );
register_deactivation_hook( __FILE__, array( 'CTB_Watchdog', 'deactivate' ) );
