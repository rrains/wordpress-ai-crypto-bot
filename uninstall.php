<?php
/**
 * Uninstall cleanup. Only drops data if the admin opted in on the settings page.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'bcb_uninstall_drop_data', '0' ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bcb_trades" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bcb_wallets" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bcb_withdrawals" );

delete_option( 'bcb_db_version' );
delete_option( 'bcb_api_keys' );
delete_option( 'bcb_new_key_plain' );
delete_option( 'bcb_uninstall_drop_data' );
