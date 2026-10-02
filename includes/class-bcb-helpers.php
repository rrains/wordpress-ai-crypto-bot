<?php
/**
 * Shared helpers: table names, formatting, key generation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCB_Helpers {

	public static function tables() {
		global $wpdb;
		return array(
			'trades'      => $wpdb->prefix . 'bcb_trades',
			'wallets'     => $wpdb->prefix . 'bcb_wallets',
			'withdrawals' => $wpdb->prefix . 'bcb_withdrawals',
		);
	}

	/** Current datetime in site timezone, MySQL format. */
	public static function now() {
		return current_time( 'mysql' );
	}

	public static function format_money( $amount, $currency = 'USDT', $signed = false ) {
		$amount   = (float) $amount;
		$decimals = ( abs( $amount ) > 0 && abs( $amount ) < 1 ) ? 4 : 2;
		$value    = number_format_i18n( $amount, $decimals );
		$prefix   = '';

		if ( $signed && $amount > 0 ) {
			$prefix = '+';
		} elseif ( $signed && $amount < 0 ) {
			$prefix = '−';
			$value  = number_format_i18n( abs( $amount ), $decimals );
		}

		return $prefix . $value . ' ' . $currency;
	}

	public static function format_qty( $qty ) {
		$qty = (float) $qty;
		if ( 0.0 === $qty ) {
			return '0';
		}
		$formatted = rtrim( rtrim( number_format( $qty, 8, '.', ',' ), '0' ), '.' );
		return $formatted;
	}

	/** Generate a new API key. Returns plaintext; only the SHA-256 hash is stored. */
	public static function generate_api_key() {
		return 'bcbk_' . wp_generate_password( 40, false, false );
	}

	public static function hash_key( $key ) {
		return hash( 'sha256', trim( (string) $key ) );
	}

	public static function get_api_keys() {
		$keys = get_option( 'bcb_api_keys', array() );
		return is_array( $keys ) ? $keys : array();
	}

	/** Verify a provided key against stored hashes. */
	public static function verify_api_key( $provided ) {
		if ( ! is_string( $provided ) || '' === trim( $provided ) ) {
			return false;
		}
		$hash = self::hash_key( $provided );
		foreach ( self::get_api_keys() as $stored ) {
			if ( is_array( $stored ) && isset( $stored['hash'] ) && hash_equals( $stored['hash'], $hash ) ) {
				return true;
			}
		}
		return false;
	}

	/** Configured alert email (empty = alerts off). */
	public static function alert_email() {
		$to = trim( (string) get_option( 'bcb_alert_email', '' ) );
		return is_email( $to ) ? $to : '';
	}

	/** Send an alert email if one is configured. Returns true/false/null (null = no recipient). */
	public static function send_alert( $subject, $body ) {
		$to = self::alert_email();
		if ( '' === $to ) {
			return null;
		}
		return wp_mail( $to, $subject, $body );
	}

	/** Parse a bot-supplied datetime; null when empty/invalid. Accepts ISO-8601 or MySQL. */
	public static function parse_dt( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
