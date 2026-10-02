<?php
/**
 * P&L and performance statistics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BCB_Stats {

	/**
	 * Full dashboard overview.
	 *
	 * @return array {
	 *     @type array $totals   All-time totals: trades, wins, losses, pnl, fees, win_rate.
	 *     @type array $today    Net P&L + trade count for today.
	 *     @type array $week     Net P&L + trade count for last 7 days.
	 *     @type array $month    Net P&L + trade count for last 30 days.
	 *     @type array $best_symbol  Best performing symbol row.
	 *     @type array $best_exchange Best performing exchange row.
	 *     @type array $best_trade   Single best trade row.
	 *     @type array $worst_trade  Single worst trade row.
	 *     @type array $series      Daily net P&L for last 30 days.
	 * }
	 */
	public static function overview() {
		global $wpdb;
		$t = BCB_Helpers::tables()['trades'];

		$now      = new DateTime( 'now', wp_timezone() );
		$today    = ( clone $now )->setTime( 0, 0, 0 );
		$week     = ( clone $today )->modify( '-6 days' );
		$month    = ( clone $today )->modify( '-29 days' );
		$series   = ( clone $today )->modify( '-29 days' );

		$today_s  = $today->format( 'Y-m-d H:i:s' );
		$week_s   = $week->format( 'Y-m-d H:i:s' );
		$month_s  = $month->format( 'Y-m-d H:i:s' );
		$series_s = $series->format( 'Y-m-d H:i:s' );

		$totals = $wpdb->get_row(
			"SELECT COUNT(*) AS trades,
				COALESCE(SUM(pnl), 0) AS pnl,
				COALESCE(SUM(fee), 0) AS fees,
				SUM(pnl > 0) AS wins,
				SUM(pnl < 0) AS losses,
				COUNT(DISTINCT symbol) AS symbols,
				COUNT(DISTINCT exchange) AS exchanges
			FROM {$t}",
			ARRAY_A
		);

			$close_col = 'COALESCE(closed_at, created_at)';

			$window = function ( $since ) use ( $wpdb, $t, $close_col ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS trades, COALESCE(SUM(pnl), 0) AS pnl
						FROM {$t} WHERE {$close_col} >= %s",
						$since
					),
					ARRAY_A
				);
			return array(
				'trades' => (int) $row['trades'],
				'pnl'    => (float) $row['pnl'],
			);
		};

		$best_group = function ( $column ) use ( $wpdb, $t ) {
			return $wpdb->get_row(
				"SELECT {$column} AS name,
					COALESCE(SUM(pnl), 0) AS pnl,
					COUNT(*) AS trades,
					SUM(pnl > 0) AS wins
				FROM {$t}
				WHERE {$column} <> ''
				GROUP BY {$column}
				HAVING COUNT(*) > 0
				ORDER BY pnl DESC
				LIMIT 1",
				ARRAY_A
			);
		};

		$best_trade = $wpdb->get_row(
			"SELECT * FROM {$t} WHERE pnl > 0 ORDER BY pnl DESC LIMIT 1",
			ARRAY_A
		);
		$worst_trade = $wpdb->get_row(
			"SELECT * FROM {$t} WHERE pnl < 0 ORDER BY pnl ASC LIMIT 1",
			ARRAY_A
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(COALESCE(closed_at, created_at)) AS d, COALESCE(SUM(pnl), 0) AS pnl
				FROM {$t}
				WHERE COALESCE(closed_at, created_at) >= %s
				GROUP BY DATE(COALESCE(closed_at, created_at))
				ORDER BY d ASC",
				$series_s
			),
			ARRAY_A
		);

		$by_day = array();
		foreach ( (array) $rows as $row ) {
			$by_day[ $row['d'] ] = (float) $row['pnl'];
		}

		$daily = array();
		$cursor = clone $series;
		while ( $cursor <= $now ) {
			$key     = $cursor->format( 'Y-m-d' );
			$daily[] = array(
				'date' => $key,
				'pnl'  => isset( $by_day[ $key ] ) ? $by_day[ $key ] : 0.0,
			);
			$cursor->modify( '+1 day' );
		}

		$totals['trades']   = (int) $totals['trades'];
		$totals['wins']     = (int) $totals['wins'];
		$totals['losses']   = (int) $totals['losses'];
		$totals['pnl']      = (float) $totals['pnl'];
		$totals['fees']     = (float) $totals['fees'];
		$totals['symbols']  = (int) $totals['symbols'];
		$totals['exchanges'] = (int) $totals['exchanges'];
		$totals['win_rate'] = $totals['trades'] > 0 ? round( ( $totals['wins'] / $totals['trades'] ) * 100, 1 ) : 0.0;

		return array(
			'totals'        => $totals,
			'today'         => $window( $today_s ),
			'week'          => $window( $week_s ),
			'month'         => $window( $month_s ),
			'best_symbol'   => $best_group( 'symbol' ),
			'best_exchange' => $best_group( 'exchange' ),
			'best_bot'      => $best_group( 'bot_name' ),
			'best_trade'    => $best_trade,
			'worst_trade'   => $worst_trade,
			'series'        => $daily,
		);
	}

	/** Latest trades, newest first. */
	public static function latest_trades( $limit = 20, $exchange = '', $bot = '' ) {
		global $wpdb;
		$t    = BCB_Helpers::tables()['trades'];
		$sql  = "SELECT * FROM {$t} WHERE 1=1";
		$args = array();

		if ( '' !== $exchange ) {
			$sql   .= ' AND exchange = %s';
			$args[] = $exchange;
		}
		if ( '' !== $bot ) {
			$sql   .= ' AND (bot_id = %s OR bot_name = %s)';
			$args[] = $bot;
			$args[] = $bot;
		}

		$sql   .= ' ORDER BY created_at DESC, id DESC LIMIT %d';
		$args[] = (int) $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
	}

	/** Distinct exchanges / bots seen, for filter dropdowns. */
	public static function distinct( $column ) {
		global $wpdb;
		$t = BCB_Helpers::tables()['trades'];
		if ( ! in_array( $column, array( 'exchange', 'bot_name', 'symbol' ), true ) ) {
			return array();
		}
		$rows = $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$t} WHERE {$column} <> '' ORDER BY {$column} ASC" );
		return (array) $rows;
	}

	/** Leaderboard rows (top N by net P&L). */
	public static function leaderboard( $column, $limit = 5 ) {
		global $wpdb;
		$t = BCB_Helpers::tables()['trades'];
		if ( ! in_array( $column, array( 'symbol', 'exchange', 'bot_name' ), true ) ) {
			return array();
		}
		return $wpdb->get_results(
			"SELECT {$column} AS name, COALESCE(SUM(pnl), 0) AS pnl, COUNT(*) AS trades, SUM(pnl > 0) AS wins
			FROM {$t}
			WHERE {$column} <> ''
			GROUP BY {$column}
			ORDER BY pnl DESC
			LIMIT " . (int) $limit,
			ARRAY_A
		);
	}

	/** Latest reported profit withdrawals. */
	public static function latest_withdrawals( $limit = 10 ) {
		global $wpdb;
		$t = BCB_Helpers::tables()['withdrawals'];
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} ORDER BY created_at DESC, id DESC LIMIT %d", (int) $limit ),
			ARRAY_A
		);
	}
}
