<?php
/**
 * Heartbeat watchdog: emails the alert address if the bot goes quiet.
 * Runs on WP-Cron every 15 minutes; re-alerts at most every 6 hours;
 * sends a recovery notice when the heartbeat comes back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCB_Watchdog {

	const HOOK        = 'bcb_heartbeat_watchdog';
	const STALE_AFTER = 1800;       // 30 minutes without a heartbeat = stale
	const REPEAT_AFTER = 21600;     // re-alert at most every 6 hours

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'check' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 120, 'bcb_fifteen_minutes', self::HOOK );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['bcb_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (Crypto Bots)' ),
		);
		return $schedules;
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Age of the last stored heartbeat in seconds, or null if none ever stored. */
	private static function age_seconds() {
		$status = get_option( 'bcb_bot_status' );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return null;
		}
		// Freshness math uses the UTC field; the display 'time' is site-local.
		$ts = strtotime( $status['time_utc'] . ' UTC' );
		return $ts ? ( time() - $ts ) : null;
	}

	public static function check() {
		$age = self::age_seconds();
		if ( null === $age ) {
			return; // no bot has ever reported — nothing to watch yet
		}

		if ( $age < self::STALE_AFTER ) {
			self::maybe_recovery_notice();
			return;
		}

		$last = (int) get_option( 'bcb_stale_alerted', 0 );
		if ( $last && ( time() - $last ) < self::REPEAT_AFTER ) {
			return; // already alerted recently — don't spam
		}
		update_option( 'bcb_stale_alerted', time(), false );

		$status = get_option( 'bcb_bot_status' );
		$mode   = isset( $status['status']['mode'] ) ? $status['status']['mode'] : 'unknown';
		$mins   = (int) round( $age / 60 );

		BCB_Helpers::send_alert(
			sprintf( '[Crypto Bots] ⚠️ Bot heartbeat STALE (%d min)', $mins ),
			"The bot has not checked in for {$mins} minutes.\n\n"
			. "Last heartbeat: {$status['time']} (mode: {$mode})\n\n"
			. "Common causes:\n"
			. "- Site in maintenance or coming-soon mode (blocks bot API reports)\n"
			. "- Bot server offline or cron stopped\n"
			. "- Hosting outage\n\n"
			. "Check the Bot Control card in the dashboard.\n"
			. "— plant-medicine.shop"
		);
	}

	/** Called by the API whenever a fresh heartbeat is stored. */
	public static function heartbeat_received() {
		if ( ! get_option( 'bcb_stale_alerted' ) ) {
			return;
		}
		delete_option( 'bcb_stale_alerted' );
		BCB_Helpers::send_alert(
			'[Crypto Bots] ✅ Bot heartbeat RECOVERED',
			"The bot is checking in again — reporting has resumed.\n\n— plant-medicine.shop"
		);
	}
}
