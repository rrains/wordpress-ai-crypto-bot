<?php
/**
 * Public shortcode: [bcb_crypto_dashboard]
 *
 * By default visible only to logged-in admins. Use public="yes" to make it
 * publicly readable (read-only, no keys required).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCB_Shortcodes {

	public static function register() {
		add_shortcode( 'bcb_crypto_dashboard', array( __CLASS__, 'render' ) );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'public' => 'no',
				'trades' => 20,
			),
			$atts,
			'bcb_crypto_dashboard'
		);

		$public = in_array( strtolower( (string) $atts['public'] ), array( 'yes', 'true', '1' ), true );
		if ( ! $public && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$overview = BCB_Stats::overview();
		$trades   = BCB_Stats::latest_trades( (int) $atts['trades'] );
		$totals   = $overview['totals'];

		ob_start();
		?>
		<div class="bcb-public">
			<h3 class="bcb-public-title">🤖 Bot performance</h3>
			<ul class="bcb-public-stats">
				<li>
					<span class="bcb-stat-label">All-time P&amp;L</span>
					<span class="bcb-stat-value <?php echo $totals['pnl'] >= 0 ? 'bcb-pos' : 'bcb-neg'; ?>">
						<?php echo esc_html( BCB_Helpers::format_money( $totals['pnl'], 'USDT', true ) ); ?>
					</span>
				</li>
				<li>
					<span class="bcb-stat-label">30-day P&amp;L</span>
					<span class="bcb-stat-value <?php echo $overview['month']['pnl'] >= 0 ? 'bcb-pos' : 'bcb-neg'; ?>">
						<?php echo esc_html( BCB_Helpers::format_money( $overview['month']['pnl'], 'USDT', true ) ); ?>
					</span>
				</li>
				<li>
					<span class="bcb-stat-label">Win rate</span>
					<span class="bcb-stat-value"><?php echo esc_html( $totals['win_rate'] ); ?>%</span>
				</li>
				<li>
					<span class="bcb-stat-label">Best crypto</span>
					<span class="bcb-stat-value">
						<?php echo $overview['best_symbol'] ? esc_html( $overview['best_symbol']['name'] ) : '—'; ?>
					</span>
				</li>
				<li>
					<span class="bcb-stat-label">Best exchange</span>
					<span class="bcb-stat-value">
						<?php echo $overview['best_exchange'] ? esc_html( ucfirst( $overview['best_exchange']['name'] ) ) : '—'; ?>
					</span>
				</li>
			</ul>

			<table class="bcb-public-trades">
				<thead>
					<tr><th>Date</th><th>Symbol</th><th>Side</th><th>Value</th><th>P&amp;L</th></tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $trades, 0, (int) $atts['trades'] ) as $trade ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'j M H:i', $trade['closed_at'] ? $trade['closed_at'] : $trade['created_at'] ) ); ?></td>
							<td><?php echo esc_html( $trade['symbol'] ); ?></td>
							<td><?php echo esc_html( strtoupper( $trade['side'] ) ); ?></td>
							<td><?php echo esc_html( BCB_Helpers::format_money( $trade['quote_value'], $trade['currency'] ) ); ?></td>
							<td class="<?php echo (float) $trade['pnl'] >= 0 ? 'bcb-pos' : 'bcb-neg'; ?>">
								<?php echo esc_html( BCB_Helpers::format_money( $trade['pnl'], $trade['currency'], true ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $trades ) ) : ?>
						<tr><td colspan="5">No trades yet.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}
