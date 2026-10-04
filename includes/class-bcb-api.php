<?php
/**
 * REST API endpoints for trading bots.
 *
 * Namespace: bionic-bots/v1
 * Auth:      X-BCB-Key header (or ?key= param) matched against hashed API keys.
 *
 * Routes:
 *   GET  /ping                      – connectivity check
 *   POST /trades                    – log one trade or a batch ({ "trades": [...] })
 *   GET  /wallets                   – profit wallet destinations bots should use
 *   POST /withdrawals               – bot reports a profit transfer to a wallet
 *   GET  /summary                   – dashboard stats as JSON
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCB_Api {

	const NS = 'bionic-bots/v1';

	public static function register_routes() {

		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			)
		);

		register_rest_route(
			self::NS,
			'/trades',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'post_trades' ),
					'permission_callback' => array( __CLASS__, 'check_key' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_trades' ),
					'permission_callback' => array( __CLASS__, 'check_key' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/wallets',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_wallets' ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			)
		);

		register_rest_route(
			self::NS,
			'/withdrawals',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'post_withdrawal' ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			)
		);

		register_rest_route(
			self::NS,
			'/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_summary' ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			)
		);

		register_rest_route(
			self::NS,
			'/heartbeat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'heartbeat' ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			)
		);
	}

	/**
	 * Bots ping this every tick with their status; response carries any queued
	 * dashboard command (pause/resume), one-shot.
	 */
	public static function heartbeat( $request ) {
		$body  = $request->get_json_params();
		$status = is_array( $body ) ? $body : array();
		update_option( 'bcb_bot_status', array( 'time' => BCB_Helpers::now(), 'status' => $status ), false );
		if ( class_exists( 'BCB_Watchdog' ) ) {
			BCB_Watchdog::heartbeat_received();
		}
		$command = get_option( 'bcb_bot_command', '' );
		if ( $command ) {
			delete_option( 'bcb_bot_command' );
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'command' => $command,
			)
		);
	}

	/** API-key permission check. */
	public static function check_key( $request ) {
		$provided = $request->get_header( 'X-BCB-Key' );
		if ( ! $provided ) {
			$provided = $request->get_param( 'key' );
		}
		if ( ! BCB_Helpers::verify_api_key( $provided ) ) {
			return new WP_Error( 'bcb_forbidden', 'Invalid or missing API key.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function ping() {
		return rest_ensure_response(
			array(
				'ok'      => true,
				'service' => 'bionic-crypto-bots',
				'version' => BCB_VERSION,
				'time'    => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Log trades. Idempotent per uuid (retries update instead of duplicating).
	 */
	public static function post_trades( $request ) {
		global $wpdb;
		$t     = BCB_Helpers::tables()['trades'];
		$body  = $request->get_json_params();
		$params = is_array( $body ) && ! empty( $body ) ? $body : $request->get_body_params();

		$items = array();
		if ( isset( $params['trades'] ) && is_array( $params['trades'] ) ) {
			$items = $params['trades'];
		} elseif ( isset( $params['symbol'] ) || isset( $params['pair'] ) ) {
			$items = array( $params );
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'bcb_bad_request', 'No trade data supplied.', array( 'status' => 400 ) );
		}

		$inserted = 0;
		$updated  = 0;
		$errors   = array();
		$alert_lines = array();

		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = "item {$i}: not an object";
				continue;
			}

			$symbol = self::pick( $item, array( 'symbol', 'pair', 'market' ) );
			if ( '' === $symbol ) {
				$errors[] = "item {$i}: missing symbol";
				continue;
			}

			$uuid = self::pick( $item, array( 'uuid', 'id', 'trade_id', 'order_id' ) );
			if ( '' === $uuid ) {
				$uuid = wp_generate_password( 24, false, false );
			} else {
				$uuid = substr( (string) $uuid, 0, 64 );
			}

			$side = strtolower( (string) self::pick( $item, array( 'side', 'type', 'direction' ) ) );
			if ( ! in_array( $side, array( 'buy', 'sell', 'long', 'short' ), true ) ) {
				$side = '';
			}

			$qty    = (float) self::pick( $item, array( 'qty', 'amount', 'quantity', 'size' ) );
			$price  = (float) self::pick( $item, array( 'price', 'avg_price', 'fill_price' ) );
			$value  = (float) self::pick( $item, array( 'quote_value', 'value', 'notional', 'total' ) );
			if ( 0.0 === $value && $qty > 0 && $price > 0 ) {
				$value = $qty * $price;
			}
			$fee    = (float) self::pick( $item, array( 'fee', 'fees', 'commission' ) );
			$pnl    = (float) self::pick( $item, array( 'pnl', 'profit', 'profit_loss', 'realized_pnl' ) );
			$currency = strtoupper( (string) self::pick( $item, array( 'currency', 'quote', 'quote_currency' ) ) );
			if ( '' === $currency ) {
				$currency = 'USDT';
			}

			$data = array(
				'uuid'       => $uuid,
				'bot_id'     => substr( (string) self::pick( $item, array( 'bot_id', 'strategy_id' ) ), 0, 64 ),
				'bot_name'   => substr( (string) self::pick( $item, array( 'bot_name', 'strategy', 'bot' ) ), 0, 191 ),
				'exchange'   => substr( strtolower( (string) self::pick( $item, array( 'exchange', 'venue' ) ) ), 0, 64 ),
				'symbol'     => substr( (string) $symbol, 0, 64 ),
				'side'       => $side,
				'qty'        => $qty,
				'price'      => $price,
				'quote_value' => $value,
				'fee'        => $fee,
				'pnl'        => $pnl,
				'currency'   => substr( $currency, 0, 16 ),
				'opened_at'  => BCB_Helpers::parse_dt( self::pick( $item, array( 'opened_at', 'open_time', 'entry_time' ) ) ),
				'closed_at'  => BCB_Helpers::parse_dt( self::pick( $item, array( 'closed_at', 'close_time', 'exit_time' ) ) ),
				'meta'       => wp_json_encode( isset( $item['meta'] ) ? $item['meta'] : new stdClass() ),
				'created_at' => BCB_Helpers::now(),
			);

			$existed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE uuid = %s", $uuid ) );

			$result = $wpdb->query( self::upsert_sql( $t, $data ) );

			if ( false === $result ) {
				$errors[] = "item {$i}: db error " . $wpdb->last_error;
			} elseif ( $existed ) {
				$updated++;
			} else {
				$inserted++;
				$alert_lines[] = sprintf(
					'%s %s %s %s @ %s %s | fee %s | P&L %s%s',
					$data['bot_name'] ? $data['bot_name'] : 'unknown bot',
					strtoupper( $data['side'] ? $data['side'] : '-' ),
					$data['exchange'] ? $data['exchange'] : '-',
					$data['symbol'],
					rtrim( rtrim( $data['price'], '0' ), '.' ),
					$data['currency'],
					rtrim( rtrim( $data['fee'], '0' ), '.' ),
					$data['pnl'] >= 0 ? '+' : '-',
					rtrim( rtrim( abs( $data['pnl'] ), '0' ), '.' )
				);
			}
		}

		if ( ! empty( $alert_lines ) ) {
			BCB_Helpers::send_alert(
				sprintf( '[Crypto Bots] %d new trade%s reported', count( $alert_lines ), 1 === count( $alert_lines ) ? '' : 's' ),
				"New trade data received:\n\n" . implode( "\n", $alert_lines ) . "\n\n— plant-medicine.shop"
			);
		}

		$status = empty( $errors ) ? 201 : 207;

		return new WP_REST_Response(
			array(
				'ok'       => empty( $errors ),
				'inserted' => $inserted,
				'updated'  => $updated,
				'errors'   => $errors,
			),
			$status
		);
	}

	private static function upsert_sql( $table, $data ) {
		global $wpdb;
		$cols          = array_keys( $data );
		$placeholders  = implode( ', ', array_fill( 0, count( $cols ), '%s' ) );
		$col_list      = implode( ', ', $cols );
		$updates       = array();
		foreach ( $cols as $col ) {
			if ( 'uuid' !== $col ) {
				$updates[] = "{$col} = VALUES({$col})";
			}
		}
		$update_sql = implode( ', ', $updates );

		$sql = "INSERT INTO {$table} ({$col_list}) VALUES (" . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $update_sql;

		// All values passed as strings is fine for MySQL numeric columns.
		return $wpdb->prepare( $sql, array_values( $data ) );
	}

	public static function get_trades( $request ) {
		$limit    = max( 1, min( 200, (int) $request->get_param( 'limit' ) ?: 20 ) );
		$exchange = (string) $request->get_param( 'exchange' );
		$bot      = (string) $request->get_param( 'bot' );
		$trades   = BCB_Stats::latest_trades( $limit, $exchange, $bot );
		return rest_ensure_response( array( 'trades' => $trades, 'count' => count( $trades ) ) );
	}

	/** Bots call this to learn where profits should be sent. */
	public static function get_wallets( $request ) {
		global $wpdb;
		$t      = BCB_Helpers::tables()['wallets'];
		$exchange = strtolower( trim( (string) $request->get_param( 'exchange' ) ) );
		$asset    = strtoupper( trim( (string) $request->get_param( 'asset' ) ) );

		$sql    = "SELECT id, label, exchange, asset, network, address, is_default, active
			FROM {$t} WHERE active = 1";
		$params = array();

		if ( '' !== $exchange ) {
			$sql     .= ' AND (exchange = %s OR exchange = \'\')';
			$params[] = $exchange;
		}
		if ( '' !== $asset ) {
			$sql     .= ' AND asset = %s';
			$params[] = $asset;
		}
		$sql .= ' ORDER BY is_default DESC, id ASC';

		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$default = null;
		foreach ( $rows as $row ) {
			if ( (int) $row['is_default'] ) {
				$default = $row;
				break;
			}
		}

		return rest_ensure_response(
			array(
				'default' => $default,
				'wallets' => $rows,
			)
		);
	}

	/** Bot reports a profit transfer (withdrawal) it executed. */
	public static function post_withdrawal( $request ) {
		global $wpdb;
		$t     = BCB_Helpers::tables()['withdrawals'];
		$body  = $request->get_json_params();
		$params = is_array( $body ) && ! empty( $body ) ? $body : $request->get_body_params();

		$amount = (float) ( $params['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return new WP_Error( 'bcb_bad_request', 'amount must be > 0.', array( 'status' => 400 ) );
		}

		$status = strtolower( (string) ( $params['status'] ?? 'pending' ) );
		if ( ! in_array( $status, array( 'pending', 'completed', 'failed' ), true ) ) {
			$status = 'pending';
		}

		$wpdb->insert(
			$t,
			array(
				'exchange'      => substr( strtolower( (string) ( $params['exchange'] ?? '' ) ), 0, 64 ),
				'asset'         => substr( strtoupper( (string) ( $params['asset'] ?? 'USDT' ) ), 0, 16 ),
				'amount'        => $amount,
				'wallet_address' => substr( (string) ( $params['wallet_address'] ?? $params['address'] ?? '' ), 0, 191 ),
				'txid'          => substr( (string) ( $params['txid'] ?? '' ), 0, 191 ),
				'status'        => $status,
				'note'          => substr( (string) ( $params['note'] ?? '' ), 0, 500 ),
				'created_at'    => BCB_Helpers::now(),
			),
			array( '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		BCB_Helpers::send_alert(
			'[Crypto Bots] Profit withdrawal reported',
			sprintf(
				"A profit transfer was reported:\n\nExchange: %s\nAsset: %s\nAmount: %s\nDestination: %s\nTxID: %s\nStatus: %s\n\n— plant-medicine.shop",
				$params['exchange'] ?? '—',
				$params['asset'] ?? '—',
				$amount,
				$params['wallet_address'] ?? '—',
				$params['txid'] ?? '—',
				$status
			)
		);

		return new WP_REST_Response(
			array(
				'ok' => true,
				'id' => (int) $wpdb->insert_id,
			),
			201
		);
	}

	/** JSON summary for external dashboards / integrations. */
	public static function get_summary() {
		$overview = BCB_Stats::overview();
		return rest_ensure_response(
			array(
				'totals'  => $overview['totals'],
				'today'   => $overview['today'],
				'week'    => $overview['week'],
				'month'   => $overview['month'],
				'best'    => array(
					'symbol'   => $overview['best_symbol'],
					'exchange' => $overview['best_exchange'],
					'bot'      => $overview['best_bot'],
					'trade'    => $overview['best_trade'],
				),
				'series'  => $overview['series'],
			)
		);
	}

	private static function pick( $item, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $item[ $key ] ) && '' !== $item[ $key ] && null !== $item[ $key ] ) {
				return $item[ $key ];
			}
		}
		return '';
	}
}
