<?php
/**
 * Uninstall cleanup. Only drops data if the admin opted in on the settings page.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'ctb_uninstall_drop_data', '0' ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ctb_trades" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ctb_wallets" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ctb_withdrawals" );

delete_option( 'ctb_db_version' );
delete_option( 'ctb_api_keys' );
delete_option( 'ctb_new_key_plain' );
delete_option( 'ctb_uninstall_drop_data' );
