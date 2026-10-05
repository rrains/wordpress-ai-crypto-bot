<?php
/**
 * DB schema creation + upgrades.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CTB_Install {

	public static function activate() {
		self::create_tables();
		update_option( 'ctb_db_version', CTB_VERSION );
	}

	public static function maybe_migrate_prefix() {
		global $wpdb;
		if ( get_option( 'ctb_prefix_migrated' ) ) {
			return;
		}
		// 1. Rename tables from the old bcb_ prefix.
		foreach ( array( 'trades', 'wallets', 'withdrawals', 'capital' ) as $t ) {
			$old = $wpdb->prefix . 'bcb_' . $t;
			$new = $wpdb->prefix . 'ctb_' . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old
				&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) !== $new ) {
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			}
		}
		// 2. Rename stored options.
		foreach ( array( 'api_keys', 'new_key_plain', 'alert_email', 'strategy', 'bot_status', 'bot_command', 'stale_alerted', 'uninstall_drop_data', 'db_version' ) as $o ) {
			$old = 'bcb_' . $o;
			$new = 'ctb_' . $o;
			$has_old = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $old ) );
			$has_new = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $new ) );
			if ( $has_old && ! $has_new ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", $new, $old ) );
			}
		}
		// 3. The watchdog cron hook was renamed — drop the old schedule; init reschedules the new one.
		wp_clear_scheduled_hook( 'bcb_heartbeat_watchdog' );
		update_option( 'ctb_prefix_migrated', 1 );
	}

	public static function maybe_upgrade() {
		self::maybe_migrate_prefix();
		if ( get_option( 'ctb_db_version' ) !== CTB_VERSION ) {
			self::create_tables();
			update_option( 'ctb_db_version', CTB_VERSION );
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$trades     = $wpdb->prefix . 'ctb_trades';
		$wallets    = $wpdb->prefix . 'ctb_wallets';
		$withdraws  = $wpdb->prefix . 'ctb_withdrawals';

		$sql = "CREATE TABLE {$trades} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(64) NOT NULL default '',
			bot_id varchar(64) NOT NULL default '',
			bot_name varchar(191) NOT NULL default '',
			exchange varchar(64) NOT NULL default '',
			symbol varchar(64) NOT NULL default '',
			side varchar(10) NOT NULL default '',
			qty decimal(28,10) NOT NULL default 0,
			price decimal(28,10) NOT NULL default 0,
			quote_value decimal(28,10) NOT NULL default 0,
			fee decimal(28,10) NOT NULL default 0,
			pnl decimal(28,10) NOT NULL default 0,
			currency varchar(16) NOT NULL default 'USDT',
			opened_at datetime NULL default NULL,
			closed_at datetime NULL default NULL,
			meta longtext NULL,
			created_at datetime NOT NULL default '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY symbol (symbol),
			KEY exchange (exchange),
			KEY created_at (created_at)
		) {$charset};
		CREATE TABLE {$wallets} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			label varchar(191) NOT NULL default '',
			exchange varchar(64) NOT NULL default '',
			asset varchar(16) NOT NULL default 'USDT',
			network varchar(64) NOT NULL default '',
			address varchar(191) NOT NULL default '',
			is_default tinyint(1) NOT NULL default 0,
			active tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL default '1970-01-01 00:00:00',
			PRIMARY KEY  (id)
		) {$charset};
		CREATE TABLE {$withdraws} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			exchange varchar(64) NOT NULL default '',
			asset varchar(16) NOT NULL default 'USDT',
			amount decimal(28,10) NOT NULL default 0,
			wallet_address varchar(191) NOT NULL default '',
			txid varchar(191) NOT NULL default '',
			status varchar(20) NOT NULL default 'pending',
			note text NULL,
			created_at datetime NOT NULL default '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
