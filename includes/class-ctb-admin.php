<?php
/**
 * Admin pages: dashboard + settings (API keys, profit wallets, options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CTB_Admin {

	/** Marker for the clean-URL block written to .htaccess. */
	const HTACCESS_MARKER = 'Crypto Trading Bot';

	public static function register_menu() {
		add_menu_page(
			__( 'Crypto Bots', 'bionic-crypto-bots' ),
			__( 'Crypto Bots', 'bionic-crypto-bots' ),
			'manage_options',
			'crypto-trading-bot',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);
		add_submenu_page(
			'crypto-trading-bot',
			__( 'Dashboard', 'bionic-crypto-bots' ),
			__( 'Dashboard', 'bionic-crypto-bots' ),
			'manage_options',
			'crypto-trading-bot',
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			'crypto-trading-bot',
			__( 'Settings', 'bionic-crypto-bots' ),
			__( 'Settings', 'bionic-crypto-bots' ),
			'manage_options',
			'crypto-trading-bot-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'crypto-trading-bot' ) ) {
			return;
		}
		wp_enqueue_style( 'ctb-admin', CTB_URL . 'assets/css/ctb-admin.css', array(), CTB_VERSION );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	public static function handle_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_POST['ctb_page'] ) ? sanitize_key( $_POST['ctb_page'] ) : '';

		if ( 'keys' === $page ) {
			self::handle_keys_actions();
		} elseif ( 'wallets' === $page ) {
			self::handle_wallets_actions();
		} elseif ( 'options' === $page ) {
			self::handle_options_actions();
		} elseif ( 'bot' === $page ) {
			self::handle_bot_actions();
		}
	}

	/** Queue a pause/resume command for the bot (picked up on its next tick). */
	private static function handle_bot_actions() {
		check_admin_referer( 'ctb_bot' );
		if ( isset( $_POST['ctb_bot_pause'] ) ) {
			update_option( 'ctb_bot_command', 'pause', false );
		} elseif ( isset( $_POST['ctb_bot_resume'] ) ) {
			update_option( 'ctb_bot_command', 'resume', false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot' ) );
		exit;
	}

	private static function handle_keys_actions() {
		check_admin_referer( 'ctb_keys' );

		if ( isset( $_POST['ctb_generate_key'] ) ) {
			$label  = sanitize_text_field( wp_unslash( $_POST['ctb_key_label'] ?? '' ) );
			$label  = '' !== $label ? $label : 'bot-' . gmdate( 'Ymd-Hi' );
			$plain  = CTB_Helpers::generate_api_key();
			$keys   = CTB_Helpers::get_api_keys();
			$keys[] = array(
				'hash'    => CTB_Helpers::hash_key( $plain ),
				'label'   => $label,
				'created' => CTB_Helpers::now(),
			);
			update_option( 'ctb_api_keys', $keys, false );
			// Show the plaintext exactly once, right after generating.
			update_option( 'ctb_new_key_plain', $plain, false );
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=key-created' ) );
			exit;
		}

		if ( isset( $_POST['ctb_revoke_key'] ) ) {
			$index = isset( $_POST['ctb_key_index'] ) ? (int) $_POST['ctb_key_index'] : -1;
			$keys  = CTB_Helpers::get_api_keys();
			if ( isset( $keys[ $index ] ) ) {
				unset( $keys[ $index ] );
				update_option( 'ctb_api_keys', array_values( $keys ), false );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=key-revoked' ) );
			exit;
		}
	}

	private static function handle_wallets_actions() {
		check_admin_referer( 'ctb_wallets' );
		global $wpdb;
		$t = CTB_Helpers::tables()['wallets'];

		if ( isset( $_POST['ctb_add_wallet'] ) ) {
			$address = sanitize_text_field( wp_unslash( $_POST['ctb_wallet_address'] ?? '' ) );
			if ( '' === $address ) {
				wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=wallet-error' ) );
				exit;
			}

			$is_default = ! empty( $_POST['ctb_wallet_default'] ) ? 1 : 0;
			if ( $is_default ) {
				$wpdb->update( $t, array( 'is_default' => 0 ), array( 'is_default' => 1 ) );
			}

			$wpdb->insert(
				$t,
				array(
					'label'      => sanitize_text_field( wp_unslash( $_POST['ctb_wallet_label'] ?? '' ) ),
					'exchange'   => strtolower( sanitize_text_field( wp_unslash( $_POST['ctb_wallet_exchange'] ?? '' ) ) ),
					'asset'      => strtoupper( sanitize_text_field( wp_unslash( $_POST['ctb_wallet_asset'] ?? 'USDT' ) ) ),
					'network'    => sanitize_text_field( wp_unslash( $_POST['ctb_wallet_network'] ?? '' ) ),
					'address'    => $address,
					'is_default' => $is_default,
					'active'     => 1,
					'created_at' => CTB_Helpers::now(),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);

			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=wallet-added' ) );
			exit;
		}

		if ( isset( $_POST['ctb_delete_wallet'] ) ) {
			$id = isset( $_POST['ctb_wallet_id'] ) ? (int) $_POST['ctb_wallet_id'] : 0;
			if ( $id > 0 ) {
				$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=wallet-deleted' ) );
			exit;
		}

		if ( isset( $_POST['ctb_toggle_wallet'] ) ) {
			$id     = isset( $_POST['ctb_wallet_id'] ) ? (int) $_POST['ctb_wallet_id'] : 0;
			$active = isset( $_POST['ctb_wallet_active'] ) ? (int) $_POST['ctb_wallet_active'] : 0;
			if ( $id > 0 ) {
				$wpdb->update( $t, array( 'active' => $active ? 0 : 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=wallet-updated' ) );
			exit;
		}

		if ( isset( $_POST['ctb_make_default'] ) ) {
			$id = isset( $_POST['ctb_wallet_id'] ) ? (int) $_POST['ctb_wallet_id'] : 0;
			if ( $id > 0 ) {
				$wpdb->update( $t, array( 'is_default' => 0 ), array( 'is_default' => 1 ) );
				$wpdb->update( $t, array( 'is_default' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=wallet-updated' ) );
			exit;
		}
	}

	private static function handle_options_actions() {
		check_admin_referer( 'ctb_options' );
		update_option( 'ctb_uninstall_drop_data', ! empty( $_POST['ctb_drop_data'] ) ? '1' : '0' );

		if ( isset( $_POST['ctb_alert_email'] ) ) {
			$email = sanitize_text_field( wp_unslash( $_POST['ctb_alert_email'] ) );
			update_option( 'ctb_alert_email', is_email( $email ) ? $email : '' );
		}

		if ( isset( $_POST['ctb_enable_clean_urls'] ) ) {
			$done = self::enable_clean_urls();
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=' . ( $done ? 'clean-enabled' : 'clean-failed' ) ) );
			exit;
		}

		if ( isset( $_POST['ctb_disable_clean_urls'] ) ) {
			self::disable_clean_urls();
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=clean-disabled' ) );
			exit;
		}

		if ( isset( $_POST['ctb_test_alert'] ) ) {
			$sent = CTB_Helpers::send_alert(
				'[Crypto Bots] Test alert',
				"This is a test alert from your Crypto Bots plugin on plant-medicine.shop.\n\nIf you received this, trade and withdrawal alerts will arrive at this address."
			);
			wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=' . ( $sent ? 'alert-sent' : 'alert-failed' ) ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=crypto-trading-bot-settings&notice=options-saved' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public static function render_dashboard() {
		$exchange_filter = isset( $_GET['exchange'] ) ? sanitize_text_field( wp_unslash( $_GET['exchange'] ) ) : '';
		$bot_filter      = isset( $_GET['bot'] ) ? sanitize_text_field( wp_unslash( $_GET['bot'] ) ) : '';

		$overview = CTB_Stats::overview();
		$trades   = CTB_Stats::latest_trades( 20, $exchange_filter, $bot_filter );
		$totals   = $overview['totals'];

		$exchanges = CTB_Stats::distinct( 'exchange' );
		$bots      = CTB_Stats::distinct( 'bot_name' );

		// Unrealised P&L from a fresh bot heartbeat (open position).
		$unrealised = null;
		$bot_status = get_option( 'ctb_bot_status' );
		if ( is_array( $bot_status ) && ! empty( $bot_status['time'] )
			&& ( time() - (int) strtotime( $bot_status['time'] ) ) < 20 * MINUTE_IN_SECONDS
			&& isset( $bot_status['status']['position']['move_pct'] ) ) {
			$unrealised = array(
				'pct'   => (float) $bot_status['status']['position']['move_pct'] * 100,
				'qty'   => $bot_status['status']['position']['qty'],
				'entry' => $bot_status['status']['position']['entry_price'],
			);
		}
		?>
		<div class="wrap ctb-wrap">
			<h1 class="ctb-title">🤖 Crypto Bots — Dashboard</h1>

			<div class="ctb-cards">
				<div class="ctb-card">
					<span class="ctb-card-label">All-time Net P&amp;L</span>
					<span class="ctb-card-value <?php echo $totals['pnl'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>">
						<?php echo esc_html( CTB_Helpers::format_money( $totals['pnl'], 'USDT', true ) ); ?>
					</span>
					<span class="ctb-card-sub"><?php echo (int) $totals['trades']; ?> trades · <?php echo esc_html( $totals['win_rate'] ); ?>% win rate</span>
				<?php if ( $unrealised ) : ?>
					<span class="ctb-card-sub">Unrealised: <strong class="<?php echo $unrealised['pct'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>"><?php echo esc_html( sprintf( '%+.2f%%', $unrealised['pct'] ) ); ?></strong> — open position (<?php echo esc_html( CTB_Helpers::format_qty( $unrealised['qty'] ) ); ?> BTC @ entry <?php echo esc_html( CTB_Helpers::format_money( $unrealised['entry'], 'GBP' ) ); ?>)</span>
				<?php endif; ?>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Last 30 days</span>
					<span class="ctb-card-value <?php echo $overview['month']['pnl'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>">
						<?php echo esc_html( CTB_Helpers::format_money( $overview['month']['pnl'], 'USDT', true ) ); ?>
					</span>
					<span class="ctb-card-sub"><?php echo (int) $overview['month']['trades']; ?> trades</span>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Last 7 days</span>
					<span class="ctb-card-value <?php echo $overview['week']['pnl'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>">
						<?php echo esc_html( CTB_Helpers::format_money( $overview['week']['pnl'], 'USDT', true ) ); ?>
					</span>
					<span class="ctb-card-sub"><?php echo (int) $overview['week']['trades']; ?> trades</span>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Today</span>
					<span class="ctb-card-value <?php echo $overview['today']['pnl'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>">
						<?php echo esc_html( CTB_Helpers::format_money( $overview['today']['pnl'], 'USDT', true ) ); ?>
					</span>
					<span class="ctb-card-sub"><?php echo (int) $overview['today']['trades']; ?> trades</span>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Fees paid (all-time)</span>
					<span class="ctb-card-value"><?php echo esc_html( CTB_Helpers::format_money( $totals['fees'], 'USDT' ) ); ?></span>
					<span class="ctb-card-sub"><?php echo (int) $totals['symbols']; ?> symbols · <?php echo (int) $totals['exchanges']; ?> exchanges</span>
				</div>
			</div>

			<div class="ctb-section-title">🏆 Best performers</div>
			<div class="ctb-cards ctb-cards-3">
				<?php
				$best_symbol = $overview['best_symbol'];
				$best_ex     = $overview['best_exchange'];
				$best_trade  = $overview['best_trade'];
				?>
				<div class="ctb-card">
					<span class="ctb-card-label">Best performing crypto</span>
					<?php if ( $best_symbol ) : ?>
						<span class="ctb-card-value"><?php echo esc_html( $best_symbol['name'] ); ?></span>
						<span class="ctb-card-sub ctb-pos">
							<?php echo esc_html( CTB_Helpers::format_money( $best_symbol['pnl'], 'USDT', true ) ); ?>
							· <?php echo (int) $best_symbol['trades']; ?> trades
						</span>
					<?php else : ?>
						<span class="ctb-card-sub">No trade data yet.</span>
					<?php endif; ?>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Best performing exchange</span>
					<?php if ( $best_ex ) : ?>
						<span class="ctb-card-value"><?php echo esc_html( ucfirst( $best_ex['name'] ) ); ?></span>
						<span class="ctb-card-sub ctb-pos">
							<?php echo esc_html( CTB_Helpers::format_money( $best_ex['pnl'], 'USDT', true ) ); ?>
							· <?php echo (int) $best_ex['trades']; ?> trades
						</span>
					<?php else : ?>
						<span class="ctb-card-sub">No trade data yet.</span>
					<?php endif; ?>
				</div>
				<div class="ctb-card">
					<span class="ctb-card-label">Best single trade</span>
					<?php if ( $best_trade ) : ?>
						<span class="ctb-card-value ctb-pos"><?php echo esc_html( CTB_Helpers::format_money( $best_trade['pnl'], $best_trade['currency'], true ) ); ?></span>
						<span class="ctb-card-sub">
							<?php
							/* translators: 1: symbol, 2: bot name, 3: date */
							echo esc_html( sprintf( '%1$s · %2$s · %3$s', $best_trade['symbol'], $best_trade['bot_name'] ? $best_trade['bot_name'] : '—', mysql2date( 'j M Y, H:i', $best_trade['created_at'] ) ) );
							?>
						</span>
					<?php else : ?>
						<span class="ctb-card-sub">No profitable trades yet.</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="ctb-section-title">📈 Daily net P&amp;L — last 30 days</div>
			<?php echo self::render_chart( $overview['series'] ); // phpcs:ignore ?>

			<?php echo self::render_bot_card(); // phpcs:ignore ?>

			<div class="ctb-section-title">🧾 Latest 20 trades</div>
			<form method="get" class="ctb-filters">
				<input type="hidden" name="page" value="crypto-trading-bot" />
				<select name="exchange">
					<option value="">All exchanges</option>
					<?php foreach ( $exchanges as $ex ) : ?>
						<option value="<?php echo esc_attr( $ex ); ?>" <?php selected( $exchange_filter, $ex ); ?>><?php echo esc_html( ucfirst( $ex ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="bot">
					<option value="">All bots</option>
					<?php foreach ( $bots as $bot ) : ?>
						<option value="<?php echo esc_attr( $bot ); ?>" <?php selected( $bot_filter, $bot ); ?>><?php echo esc_html( $bot ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button">Filter</button>
			</form>
			<table class="widefat striped ctb-table">
				<thead>
					<tr>
						<th>Closed / logged</th>
						<th>Bot</th>
						<th>Exchange</th>
						<th>Symbol</th>
						<th>Side</th>
						<th>Qty</th>
						<th>Price</th>
						<th>Value</th>
						<th>Fee</th>
						<th>P&amp;L</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $trades ) ) : ?>
						<tr><td colspan="10">No trades reported yet. Point your bots at the <code>bionic-bots/v1</code> API (see Settings → Crypto Bots).</td></tr>
					<?php else : ?>
						<?php foreach ( $trades as $trade ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'j M Y, H:i', $trade['closed_at'] ? $trade['closed_at'] : $trade['created_at'] ) ); ?></td>
								<td><?php echo esc_html( $trade['bot_name'] ? $trade['bot_name'] : '—' ); ?></td>
								<td><?php echo esc_html( ucfirst( $trade['exchange'] ) ); ?></td>
								<td><strong><?php echo esc_html( $trade['symbol'] ); ?></strong></td>
								<td><?php echo esc_html( strtoupper( $trade['side'] ) ); ?></td>
								<td><?php echo esc_html( CTB_Helpers::format_qty( $trade['qty'] ) ); ?></td>
								<td><?php echo esc_html( CTB_Helpers::format_qty( $trade['price'] ) ); ?></td>
								<td><?php echo esc_html( CTB_Helpers::format_money( $trade['quote_value'], $trade['currency'] ) ); ?></td>
								<td><?php echo esc_html( CTB_Helpers::format_money( $trade['fee'], $trade['currency'] ) ); ?></td>
								<td class="<?php echo (float) $trade['pnl'] >= 0 ? 'ctb-pos' : 'ctb-neg'; ?>">
									<strong><?php echo esc_html( CTB_Helpers::format_money( $trade['pnl'], $trade['currency'], true ) ); ?></strong>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="ctb-section-title">💸 Profit withdrawals (reported by bots)</div>
			<table class="widefat striped ctb-table">
				<thead>
					<tr>
						<th>Date</th>
						<th>Exchange</th>
						<th>Asset</th>
						<th>Amount</th>
						<th>Destination</th>
						<th>TxID</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php $withdrawals = CTB_Stats::latest_withdrawals( 10 ); ?>
					<?php if ( empty( $withdrawals ) ) : ?>
						<tr><td colspan="7">No profit transfers reported yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $withdrawals as $w ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'j M Y, H:i', $w['created_at'] ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $w['exchange'] ) ); ?></td>
								<td><?php echo esc_html( $w['asset'] ); ?></td>
								<td><?php echo esc_html( CTB_Helpers::format_money( $w['amount'], $w['asset'] ) ); ?></td>
								<td><code><?php echo esc_html( $w['wallet_address'] ? $w['wallet_address'] : '—' ); ?></code></td>
								<td><code><?php echo esc_html( $w['txid'] ? substr( $w['txid'], 0, 18 ) . '…' : '—' ); ?></code></td>
								<td><?php echo esc_html( ucfirst( $w['status'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Bot Control card: heartbeat status + pause/resume commands.
	 */
	private static function render_bot_card() {
		$status = get_option( 'ctb_bot_status' );
		$cmd    = get_option( 'ctb_bot_command', '' );

		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			$inner = '<span class="ctb-card-sub">No heartbeat received yet. The bot checks in on every tick (every 15 min).</span>';
		} else {
			// Freshness math uses the UTC field; the displayed time is site-local.
			$ts    = strtotime( $status['time_utc'] . ' UTC' );
			$age   = time() - $ts;
			$fresh = $age < 20 * MINUTE_IN_SECONDS;
			$s     = isset( $status['status'] ) && is_array( $status['status'] ) ? $status['status'] : array();
			$mode  = isset( $s['mode'] ) ? $s['mode'] : 'unknown';
			$dot   = ! $fresh ? '🟠 stale' : ( 'paused' === $mode ? '🔴 paused' : '🟢 running' );

			$lines  = '<span class="ctb-card-sub">Last check-in: ' . esc_html( human_time_diff( $ts ) ) . ' ago (' . esc_html( $status['time'] ) . ' site time)</span>';
			$lines .= '<span class="ctb-card-sub">Status: <strong>' . esc_html( $dot ) . '</strong>';
			if ( isset( $s['price'] ) ) {
				$lines .= ' · BTC/GBP last: ' . esc_html( CTB_Helpers::format_money( $s['price'], 'GBP' ) );
			}
			$lines .= '</span>';
			if ( isset( $s['strategy'] ) && '' !== $s['strategy'] ) {
				$lines .= '<span class="ctb-card-sub">Trading strategy: <strong>' . esc_html( $s['strategy'] ) . '</strong> (set by the bot — read-only in the free edition)</span>';
			}

			if ( isset( $s['position'] ) && is_array( $s['position'] ) && isset( $s['position']['qty'] ) ) {
				$pos = $s['position'];
				$lines .= '<span class="ctb-card-sub">Position: ' . esc_html( CTB_Helpers::format_qty( $pos['qty'] ) ) . ' BTC @ entry ' . esc_html( CTB_Helpers::format_money( $pos['entry_price'], 'GBP' ) ) . '</span>';
				if ( isset( $pos['move_pct'] ) ) {
					$move = (float) $pos['move_pct'] * 100;
					$lines .= '<span class="ctb-card-sub ' . ( $move >= 0 ? 'ctb-pos' : 'ctb-neg' ) . '">Unrealised move: ' . esc_html( sprintf( '%+.2f%%', $move ) ) . '</span>';
				}
			} else {
				$lines .= '<span class="ctb-card-sub">Position: flat (no BTC held)</span>';
			}

			$inner = $lines;
		}

		$notice = '';
		if ( 'pause' === $cmd ) {
			$notice = '<div class="notice notice-inline"><p>⏸ Pause requested — the bot will stop on its next tick (within 15 min).</p></div>';
		} elseif ( 'resume' === $cmd ) {
			$notice = '<div class="notice notice-inline"><p>▶️ Resume requested — the bot will continue on its next tick (within 15 min).</p></div>';
		}

		$html  = '<div class="ctb-section-title">🎛 Bot Control</div>';
		$html .= '<div class="ctb-card ctb-bot-card">' . $inner . $notice . '<form method="post" class="ctb-inline-form" style="margin:12px 0 0">';
		$html .= '<input type="hidden" name="ctb_page" value="bot" />';
		$html .= wp_nonce_field( 'ctb_bot', '_wpnonce', true, false );
		$html .= '<button class="button button-secondary" name="ctb_bot_pause" value="1">⏸ Pause bot</button> ';
		$html .= '<button class="button button-primary" name="ctb_bot_resume" value="1">▶️ Resume bot</button>';
		$html .= '</form></div>';
		return $html;
	}

	/** Server-rendered SVG bar chart of daily net P&L. */
	private static function render_chart( $series ) {
		if ( empty( $series ) ) {
			return '<p>No data.</p>';
		}

		$bar_w   = 14;
		$gap     = 8;
		$height  = 140;
		$mid     = 60;
		$max_abs = 0.0001;
		foreach ( $series as $point ) {
			$max_abs = max( $max_abs, abs( (float) $point['pnl'] ) );
		}

		$width = count( $series ) * ( $bar_w + $gap ) + $gap;
		$svg   = '<svg class="ctb-chart" viewBox="0 0 ' . esc_attr( $width ) . ' ' . esc_attr( $height ) . '" role="img" aria-label="Daily net P and L, last 30 days" style="max-width:100%;height:auto;width:' . esc_attr( $width ) . 'px">';
		$svg  .= '<line x1="0" y1="' . $mid . '" x2="' . $width . '" y2="' . $mid . '" stroke="#dcdcde" stroke-width="1" />';

		$x = $gap;
		foreach ( $series as $point ) {
			$pnl  = (float) $point['pnl'];
			$bh   = max( 2, (int) round( ( abs( $pnl ) / $max_abs ) * ( $mid - 8 ) ) );
			$y    = $pnl >= 0 ? $mid - $bh : $mid;
			$date = date_i18n( 'j M', strtotime( $point['date'] . ' 12:00:00' ) );
			$fill = $pnl >= 0 ? '#00a32a' : '#d63638';
			$svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bar_w . '" height="' . $bh . '" rx="2" fill="' . $fill . '"><title>' . esc_attr( $date . ': ' . CTB_Helpers::format_money( $pnl, 'USDT', true ) ) . '</title></rect>';
			$x   += $bar_w + $gap;
		}
		$svg .= '</svg>';

		return '<div class="ctb-chart-wrap">' . $svg . '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** ---- Clean admin URLs (.htaccess) ---- */

	private static function clean_url_slugs() {
		return array( 'crypto-trading-bot', 'crypto-trading-bot-settings' );
	}

	private static function htaccess_file() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}

	public static function clean_urls_enabled() {
		$f = self::htaccess_file();
		if ( ! file_exists( $f ) ) return false;
		$c = (string) file_get_contents( $f );
		return strpos( $c, '# BEGIN ' . self::HTACCESS_MARKER ) !== false;
	}

	private static function clean_url_rules() {
		$rules = array( '<IfModule mod_rewrite.c>', 'RewriteEngine On', 'RewriteCond %{REQUEST_FILENAME} !-f' );
		foreach ( self::clean_url_slugs() as $slug ) {
			$rules[] = 'RewriteRule ^wp-admin/' . $slug . '/?$ wp-admin/admin.php?page=' . $slug . ' [L,QSA]';
		}
		$rules[] = '</IfModule>';
		return $rules;
	}

	public static function enable_clean_urls() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return insert_with_markers( self::htaccess_file(), self::HTACCESS_MARKER, self::clean_url_rules() );
	}

	public static function disable_clean_urls() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return insert_with_markers( self::htaccess_file(), self::HTACCESS_MARKER, array() );
	}

	public static function render_settings() {
		$keys       = CTB_Helpers::get_api_keys();
		$new_key    = get_option( 'ctb_new_key_plain', '' );
		$wallets    = self::get_wallets();
		$rest_base  = rest_url( 'bionic-bots/v1' );
		$notice     = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		?>
		<div class="wrap ctb-wrap">
			<h1 class="ctb-title">⚙️ Crypto Bots — Settings</h1>

			<?php
			$notices = array(
				'key-created'    => array( 'success', 'API key created. Copy it now — it is shown only once.' ),
				'key-revoked'    => array( 'warning', 'API key revoked. Any bot still using it will get 403 responses.' ),
				'wallet-added'   => array( 'success', 'Profit wallet added.' ),
				'wallet-deleted' => array( 'success', 'Profit wallet removed.' ),
				'wallet-updated' => array( 'success', 'Profit wallet updated.' ),
				'wallet-error'   => array( 'error', 'Wallet address is required.' ),
				'options-saved'  => array( 'success', 'Options saved.' ),
				'alert-sent'     => array( 'success', 'Test alert sent — check the inbox (and the spam folder).' ),
				'alert-failed'   => array( 'error', 'Test alert FAILED to send. Check the alert email address, or the site\'s mail configuration.' ),
			);
			if ( $notice && isset( $notices[ $notice ] ) ) {
				printf(
					'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
					esc_attr( $notices[ $notice ][0] ),
					esc_html( $notices[ $notice ][1] )
				);
			}
			?>

			<div class="ctb-section-title">🔑 Bot API keys</div>
			<p>Bots authenticate with a <code>X-CTB-Key</code> header against the REST API at <code><?php echo esc_html( $rest_base ); ?></code>. Keys are stored hashed and cannot be recovered — only replaced.</p>

			<?php if ( $new_key ) : ?>
				<div class="notice notice-success"><p>
					<strong>Your new key (copy now, shown once):</strong><br />
					<code class="ctb-key-plain"><?php echo esc_html( $new_key ); ?></code>
				</p></div>
				<?php delete_option( 'ctb_new_key_plain' ); ?>
			<?php endif; ?>

			<table class="widefat striped ctb-table">
				<thead>
					<tr><th>Label</th><th>Key (hashed)</th><th>Created</th><th>Actions</th></tr>
				</thead>
				<tbody>
					<?php if ( empty( $keys ) ) : ?>
						<tr><td colspan="4">No API keys yet — generate one below.</td></tr>
					<?php else : ?>
						<?php foreach ( $keys as $i => $key ) : ?>
							<tr>
								<td><?php echo esc_html( $key['label'] ); ?></td>
								<td><code><?php echo esc_html( substr( $key['hash'], 0, 12 ) ); ?>…</code></td>
								<td><?php echo esc_html( $key['created'] ); ?></td>
								<td>
									<form method="post" style="display:inline">
										<input type="hidden" name="ctb_page" value="keys" />
										<?php wp_nonce_field( 'ctb_keys' ); ?>
										<input type="hidden" name="ctb_key_index" value="<?php echo esc_attr( $i ); ?>" />
										<button class="button button-link-delete" name="ctb_revoke_key" value="1" onclick="return confirm('Revoke this key?');">Revoke</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<form method="post" class="ctb-inline-form">
				<input type="hidden" name="ctb_page" value="keys" />
				<?php wp_nonce_field( 'ctb_keys' ); ?>
				<input type="text" name="ctb_key_label" placeholder="Label, e.g. binance-grid-bot" />
				<button class="button button-primary" name="ctb_generate_key" value="1">Generate new API key</button>
			</form>

			<div class="ctb-section-title">💸 Profit wallets (where bots send profits)</div>
			<p>These are <strong>public deposit addresses only</strong> — never store exchange API secrets or private keys here. Bots fetch them via <code>GET <?php echo esc_html( $rest_base ); ?>/wallets</code>.</p>

			<table class="widefat striped ctb-table">
				<thead>
					<tr><th>Label</th><th>Exchange</th><th>Asset</th><th>Network</th><th>Address</th><th>Default</th><th>Active</th><th>Actions</th></tr>
				</thead>
				<tbody>
					<?php if ( empty( $wallets ) ) : ?>
						<tr><td colspan="8">No profit wallets yet — add one below.</td></tr>
					<?php else : ?>
						<?php foreach ( $wallets as $wallet ) : ?>
							<tr>
								<td><?php echo esc_html( $wallet['label'] ); ?></td>
								<td><?php echo esc_html( ucfirst( $wallet['exchange'] ) ); ?></td>
								<td><?php echo esc_html( $wallet['asset'] ); ?></td>
								<td><?php echo esc_html( $wallet['network'] ); ?></td>
								<td><code><?php echo esc_html( substr( $wallet['address'], 0, 14 ) . '…' . substr( $wallet['address'], -6 ) ); ?></code></td>
								<td><?php echo (int) $wallet['is_default'] ? '★ Default' : ''; ?></td>
								<td><?php echo (int) $wallet['active'] ? 'Yes' : 'No'; ?></td>
								<td>
									<form method="post" style="display:inline" onclick="return confirm('Are you sure?');">
										<input type="hidden" name="ctb_page" value="wallets" />
										<?php wp_nonce_field( 'ctb_wallets' ); ?>
										<input type="hidden" name="ctb_wallet_id" value="<?php echo esc_attr( $wallet['id'] ); ?>" />
										<button class="button" name="ctb_toggle_wallet" value="1"><?php echo (int) $wallet['active'] ? 'Deactivate' : 'Activate'; ?></button>
										<?php if ( ! (int) $wallet['is_default'] ) : ?>
											<button class="button" name="ctb_make_default" value="1">Make default</button>
										<?php endif; ?>
										<button class="button button-link-delete" name="ctb_delete_wallet" value="1">Delete</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h3>Add a profit wallet</h3>
			<form method="post" class="ctb-wallet-form">
				<input type="hidden" name="ctb_page" value="wallets" />
				<?php wp_nonce_field( 'ctb_wallets' ); ?>
				<p>
					<label>Label<br /><input type="text" name="ctb_wallet_label" placeholder="e.g. Main cold wallet" /></label>
					<label>Exchange (optional)<br /><input type="text" name="ctb_wallet_exchange" placeholder="e.g. binance" /></label>
					<label>Asset<br /><input type="text" name="ctb_wallet_asset" value="USDT" /></label>
					<label>Network<br /><input type="text" name="ctb_wallet_network" placeholder="e.g. TRC20, ERC20, BTC" /></label>
				</p>
				<p>
					<label>Address<br /><input type="text" name="ctb_wallet_address" class="ctb-wide" placeholder="Public wallet address" required /></label>
				</p>
				<p>
					<label><input type="checkbox" name="ctb_wallet_default" value="1" /> Make this the default destination</label>
				</p>
				<p><button class="button button-primary" name="ctb_add_wallet" value="1">Add wallet</button></p>
			</form>

			<div class="ctb-section-title">✉️ Trade alerts</div>
			<form method="post">
				<input type="hidden" name="ctb_page" value="options" />
				<?php wp_nonce_field( 'ctb_options' ); ?>
				<p>An email is sent to this address whenever a bot reports a new trade or a profit withdrawal. Leave empty to disable alerts.</p>
				<p><input type="text" name="ctb_alert_email" value="<?php echo esc_attr( get_option( 'ctb_alert_email', '' ) ); ?>" class="ctb-wide" placeholder="alerts@example.com" /></p>
				<p>
					<button class="button button-primary" name="ctb_save_alert" value="1">Save alert address</button>
					<button class="button" name="ctb_test_alert" value="1">Send test email</button>
				</p>
			</form>

			<div class="ctb-section-title">🔗 Clean admin URLs</div>
		<p>Serves the dashboard at <code>/wp-admin/crypto-trading-bot</code> instead of <code>admin.php?page=…</code>. Writes a marked block into your site's <code>.htaccess</code> (Apache/LiteSpeed hosting).</p>
		<form method="post">
			<input type="hidden" name="ctb_page" value="options" />
			<?php wp_nonce_field( 'ctb_options' ); ?>
			<p>
				<button class="button button-primary" name="ctb_enable_clean_urls" value="1">Enable clean URLs</button>
				<button class="button" name="ctb_disable_clean_urls" value="1">Disable</button>
				<span class="ctb-card-sub">Current state: <strong><?php echo self::clean_urls_enabled() ? 'enabled' : 'disabled'; ?></strong></span>
			</p>
		</form>

		<div class="ctb-section-title">🧹 Data</div>
			<form method="post">
				<input type="hidden" name="ctb_page" value="options" />
				<?php wp_nonce_field( 'ctb_options' ); ?>
				<label><input type="checkbox" name="ctb_drop_data" value="1" <?php checked( get_option( 'ctb_uninstall_drop_data', '0' ), '1' ); ?> /> Delete all trade data when the plugin is uninstalled</label>
				<p><button class="button" name="ctb_save_options" value="1">Save</button></p>
			</form>

			<div class="ctb-section-title">📡 Bot integration cheat-sheet</div>
			<p><code>POST <?php echo esc_html( $rest_base ); ?>/trades</code> — header <code>X-CTB-Key: &lt;your key&gt;</code>, JSON body: <code>{"bot_name":"grid-01","exchange":"binance","symbol":"BTC/USDT","side":"sell","qty":0.05,"price":64000,"pnl":125.50,"closed_at":"2026-09-28T10:00:00Z"}</code></p>
			<p><code>GET <?php echo esc_html( $rest_base ); ?>/wallets?exchange=binance</code> — fetch profit destinations.</p>
			<p><code>POST <?php echo esc_html( $rest_base ); ?>/withdrawals</code> — report a profit transfer: <code>{"exchange":"binance","asset":"USDT","amount":250,"wallet_address":"…","txid":"…","status":"completed"}</code></p>
			<p><code>GET <?php echo esc_html( $rest_base ); ?>/ping</code> — connectivity test.</p>
		</div>
		<?php
	}

	public static function get_wallets() {
		global $wpdb;
		$t = CTB_Helpers::tables()['wallets'];
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY is_default DESC, active DESC, id ASC", ARRAY_A );
	}
}
