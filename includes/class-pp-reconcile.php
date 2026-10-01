<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Ledger reconciliation — the safety net under the tightrope.
 *
 * Nightly (and on demand) compares reality in both directions:
 *  - MISSED:   orders in/past the trigger status carrying a partner coupon
 *              or attribution lock, but with NO ledger row (plugin was off,
 *              status transition raced, migration gap).
 *  - ORPHANED: pending/approved ledger rows whose order is cancelled /
 *              refunded / failed / deleted — should have been reversed.
 *
 * Fixes reuse the engine itself: create is idempotent, reversal is the
 * same path a live refund takes. No parallel logic to drift.
 */
class PP_Reconcile {

	const OPT      = 'proof_partner_recon';
	const LOOKBACK = 30; // days

	public function __construct() {
		add_action( 'proof_partner_reconcile', array( $this, 'cron_run' ) );
	}

	public function cron_run() {
		$report = self::build_report();
		update_option( self::OPT, $report, false );

		$this->refund_guard();

		// Нощна авто-диагностика: червено = сигнал + Telegram, без да чакаш да погледнеш.
		if ( class_exists( 'PP_Diagnostics' ) && method_exists( 'PP_Diagnostics', 'run' ) ) {
			$checks = (array) PP_Diagnostics::run();
			$fails  = array_values( array_filter( $checks, function ( $c ) { return ( $c['status'] ?? '' ) === 'fail'; } ) );
			if ( $fails ) {
				$names = implode( ' · ', array_slice( wp_list_pluck( $fails, 'name' ), 0, 4 ) );
				proof_partner_feed_push( 'diag', sprintf( '🔴 Диагностика: %d критични — %s', count( $fails ), $names ) );
				$alerts   = (array) get_option( 'proof_partner_alerts', array() );
				$alerts[] = array( 'partner_id' => 0, 'time' => current_time( 'mysql' ),
					'message' => sprintf( 'ДИАГНОСТИКА: %d критични проблема — %s. Отвори Диагностика.', count( $fails ), $names ) );
				update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
			}
		}

		$issues = count( $report['missed'] ) + count( $report['orphans'] ) + count( $report['premature'] ?? array() );
		if ( $issues > 0 ) {
			$alerts   = (array) get_option( 'proof_partner_alerts', array() );
			$alerts[] = array(
				'partner_id' => 0,
				'time'       => current_time( 'mysql' ),
				'message'    => sprintf(
					'СВЕРКА: %d пропуснати комисиони и %d осиротели реда в книгата. Отвори Табло → Сверка на книгата.',
					count( $report['missed'] ), count( $report['orphans'] )
				),
			);
			update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
		}
		return $report;
	}

	/** №9: нощен страж — партньори с прекомерен дял невзети поръчки. */
	private function refund_guard() {
		$s   = proof_partner_settings();
		$pct = (int) ( $s['refund_alert_pct'] ?? 25 );
		$mem = (array) get_option( 'proof_partner_refund_alerted', array() );

		foreach ( PP_Partners::all() as $p ) {
			if ( 'active' !== $p->status ) continue;
			$o = PP_Rest::outcomes_for( (int) $p->partner_id, 90 );
			if ( ( $o['won'] + $o['lost'] ) < 5 ) continue;
			if ( $o['lost_pct'] < $pct ) continue;
			$last = (int) ( $mem[ $p->partner_id ] ?? 0 );
			if ( time() - $last < 7 * DAY_IN_SECONDS ) continue;

			$alerts   = (array) get_option( 'proof_partner_alerts', array() );
			$alerts[] = array(
				'partner_id' => (int) $p->partner_id,
				'time'       => current_time( 'mysql' ),
				'message'    => sprintf( 'НЕВЗЕТИ ПОРЪЧКИ: %s — %.1f%% от решените поръчки за 90 дни са refunded/cancelled/failed (%d от %d). Провери за self-buy схема или проблемен трафик.', $p->display_name, $o['lost_pct'], $o['lost'], $o['won'] + $o['lost'] ),
			);
			update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
			$mem[ $p->partner_id ] = time();
		}
		update_option( 'proof_partner_refund_alerted', $mem, false );
	}

	/* ------------------------------------------------------------------ */
	/* Report                                                               */
	/* ------------------------------------------------------------------ */

	public static function build_report( $days = self::LOOKBACK ) {
		global $wpdb;
		$s        = proof_partner_settings();
		$statuses = proof_partner_won_statuses();
		$since    = gmdate( 'Y-m-d', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );

		// Ledger index: order_ids that already have a live commission row.
		$have = array_flip( array_map( 'intval', (array) $wpdb->get_col(
			"SELECT DISTINCT order_id FROM " . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission'"
		) ) );

		/* MISSED — paged scan of trigger-family orders */
		$missed = array();
		$page   = 1;
		do {
			$ids = wc_get_orders( array(
				'status'       => $statuses,
				'date_created' => '>' . $since,
				'limit'        => 200,
				'page'         => $page,
				'return'       => 'ids',
			) );
			foreach ( $ids as $oid ) {
				if ( isset( $have[ (int) $oid ] ) ) continue;
				$order = wc_get_order( $oid );
				// 4 август мистерията: заявката връща и REFUND записи, а те не са
				// WC_Order → TypeError убиваше целия отчет от първия refund насам.
				if ( ! $order instanceof WC_Order ) continue;

				$lock = $order->get_meta( PP_Attribution::META_LOCK );
				$attr = ( is_array( $lock ) && ! empty( $lock['partner_id'] ) )
					? array( 'partner_id' => (int) $lock['partner_id'], 'coupon_code' => (string) ( $lock['coupon_code'] ?? '' ) )
					: PP_Coupons::attribute_order( $order );
				if ( ! $attr ) continue;

				$partner  = PP_Partners::get( $attr['partner_id'] );
				$missed[] = array(
					'order_id'    => (int) $oid,
					'coupon_code' => $attr['coupon_code'],
					'partner'     => $partner ? $partner->display_name : ( '#' . $attr['partner_id'] ),
					'order_url'   => admin_url( 'post.php?post=' . (int) $oid . '&action=edit' ),
					'date'        => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
				);
				if ( count( $missed ) >= 200 ) break 2; // report cap; fix & rerun
			}
			$page++;
		} while ( count( $ids ) === 200 );

		/* PREMATURE — „За изплащане" редове, чиито поръчки НЕ са Delivered сега */
		$premature = array();
		$appr = $wpdb->get_results(
			"SELECT commission_id, order_id, partner_id, amount, currency, status FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE type = 'commission' AND status IN ('pending','approved') AND order_id > 0 ORDER BY commission_id DESC LIMIT 1000"
		);
		$pnames = array();
		foreach ( PP_Partners::all() as $pp ) $pnames[ (int) $pp->partner_id ] = $pp->display_name;
		$st_map = proof_partner_order_statuses( wp_list_pluck( $appr, 'order_id' ) ); // ЕДНА заявка
		foreach ( $appr as $r ) {
			$st = $st_map[ (int) $r->order_id ] ?? '';
			if ( proof_partner_status_is_won( $st ) ) continue;
			if ( in_array( $st, array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) continue; // това е orphan, долу
			// 5.2.0 архитектурата: изчакваща комисиона + пътуваща поръчка е
			// НОРМАЛНОТО състояние, не аномалия. Преждевременно е само
			// „За изплащане" (approved) без реална доставка.
			if ( 'pending' === $r->status && proof_partner_status_is_transitional( $st ) ) continue;
			$premature[] = array(
				'commission_id' => (int) $r->commission_id,
				'order_id'      => (int) $r->order_id,
				'partner'       => $pnames[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ),
				'amount'        => (float) $r->amount,
				'currency'      => $r->currency,
				'why'           => '' !== $st ? 'поръчката е ' . $st : 'поръчката липсва',
				'order_url'     => admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ),
			);
		}

		/* ORPHANED — live rows whose order died */
		$orphans = array();
		$rows    = $wpdb->get_results(
			"SELECT commission_id, order_id, partner_id, coupon_code, amount, currency FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE type = 'commission' AND status IN ('pending','approved') ORDER BY commission_id DESC LIMIT 1000"
		);
		$names = array();
		foreach ( PP_Partners::all() as $p ) $names[ (int) $p->partner_id ] = $p->display_name;

		foreach ( $rows as $r ) {
			$order = wc_get_order( $r->order_id );
			$why   = '';
			if ( ! $order ) {
				$why = 'поръчката е изтрита';
			} elseif ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
				$why = 'поръчката е със статус ' . $order->get_status();
			}
			if ( '' === $why ) continue;

			$orphans[] = array(
				'commission_id' => (int) $r->commission_id,
				'order_id'      => (int) $r->order_id,
				'partner'       => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ),
				'amount'        => (float) $r->amount,
				'currency'      => $r->currency,
				'why'           => $why,
				'order_url'     => admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ),
			);
		}

		return array(
			'ran_at'        => current_time( 'mysql' ),
			'won_statuses'  => proof_partner_won_statuses(),
			'trigger_raw'   => (string) ( proof_partner_settings()['trigger_status'] ?? '' ),
			'lookback'      => $days,
			'missed'    => $missed,
			'orphans'   => $orphans,
			'premature' => $premature,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Fixes — reuse the engine, no parallel logic                          */
	/* ------------------------------------------------------------------ */

	public static function fix_missed() {
		$report = get_option( self::OPT, array() );
		$engine = PP_Engine::instance();
		$done   = 0;
		foreach ( (array) ( $report['missed'] ?? array() ) as $m ) {
			$engine->maybe_create_commission( (int) $m['order_id'] ); // idempotent
			$done++;
		}
		return $done;
	}

	/**
	 * Санитизация: „За изплащане" без Delivered → обратно в потенциални.
	 * НЕ вярва на запазен отчет (може да е остарял) — сканира живата база
	 * и проверява статуса на ВСЯКА поръчка в момента на изпълнение.
	 * Delivered поръчка не може да бъде демоутната, точка.
	 */
	public static function fix_premature() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT commission_id, order_id, status FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE type = 'commission' AND status IN ('pending','approved') AND order_id > 0
			 ORDER BY commission_id DESC LIMIT 1000"
		);
		$done   = 0;
		$st_map = proof_partner_order_statuses( wp_list_pluck( $rows, 'order_id' ) );
		foreach ( $rows as $r ) {
			$st = $st_map[ (int) $r->order_id ] ?? '';
			if ( proof_partner_status_is_won( $st ) ) continue; // доставена — недосегаема
			if ( in_array( $st, array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) continue; // това е за Сторнирай
			if ( 'pending' === $r->status && proof_partner_status_is_transitional( $st ) ) continue; // чака доставка — нормалното състояние
			$aff = $wpdb->update( PP_Schema::table( 'commissions' ),
				array( 'status' => 'pending', 'note' => 'сверка: поръчката не е ' . proof_partner_trigger_status(), 'updated_at' => current_time( 'mysql' ) ),
				array( 'commission_id' => (int) $r->commission_id, 'status' => 'approved' )
			);
			if ( $aff ) $done++; // броим само РЕАЛНО върнатите, не преминалите през цикъла
		}
		return $done;
	}

	public static function fix_orphans() {
		global $wpdb;
		$report = get_option( self::OPT, array() );
		$engine = PP_Engine::instance();
		$done   = 0;
		foreach ( (array) ( $report['orphans'] ?? array() ) as $o ) {
			$order = wc_get_order( $o['order_id'] );
			if ( $order ) {
				// Реверификация СЕГА: сторно само ако поръчката е терминална в момента.
				if ( ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) continue;
				$engine->reverse_full( (int) $o['order_id'] ); // same path as a live refund
			} else {
				$wpdb->update( PP_Schema::table( 'commissions' ),
					array( 'status' => 'reversed', 'note' => 'reconciliation: order deleted', 'updated_at' => current_time( 'mysql' ) ),
					array( 'commission_id' => (int) $o['commission_id'] )
				);
			}
			$done++;
		}
		return $done;
	}
}
