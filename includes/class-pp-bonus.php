<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Proof 2.1: Бонус за мениджъра (performance bonus) — САМО процентът.
 * Пренесено от ANSA Partner 5.15–5.17 (AP_Bonus) без еднократния бонус за нов партньор.
 *
 * Условията са НА ПРОФИЛА (wp-admin → Proof Partner → Достъп до портала) и важат само за
 * вид достъп „мениджър“ (PP_Manager::type); „потребител“ не трупа нищо. Сметката е върху
 * ЦЯЛАТА програма, без край, с една начална дата:
 *
 *   X % (по подразбиране 5) от реализирания оборот на ВСИЧКИ партньорски поръчки от
 *   „началната дата“ (from) нататък, където оборот = базата на комисионата (стойността
 *   на продуктите СЛЕД отстъпката на клиента, без доставка, нето от връщания) МИНУС
 *   комисионата на партньора.
 *
 * „Реализирана“ поръчка = ДОСТАВЕНА: поръчката е в „спечелен“ статус в WooCommerce СЕГА
 * (proof_partner_won_statuses()). Комисионата на партньора става „За изплащане“ чак след
 * периода на задържане (holding_days), но мениджърът не чака:
 *   - approved / paid редове → реализирани;
 *   - pending редове → реализирани, ако поръчката е доставена в момента (без [самопокупка] —
 *     тя чака ръчно одобрение); ако поръчката още пътува → не се брои;
 *   - reversed не влиза; редовете „корекция“ (clawback след изплащане) намаляват
 *     комисионата и оборота на същата поръчка; напълно върната поръчка отпада;
 *   - фиксираните договори (pay_model = fixed, редове със статус `fixed`, 0 € комисиона в книгата)
 *     не влизат — възнаграждението им е по фактура и „нетният оборот“ не може да се сметне по ред.
 *
 * Нищо не се записва при изчисление — таблото е жива сметка върху книгата. Записват се
 * само ИЗПЛАЩАНИЯТА към мениджъра (таблица proof_partner_manager_payouts: до коя дата, сума).
 */
class PP_Bonus {

	const META = 'proof_partner_manager_bonus';

	public static function defaults() {
		return array( 'from' => current_time( 'Y-m-d' ), 'pct' => 5.0 );
	}

	public static function sanitize( array $b ) {
		$d = self::defaults();
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $b['from'] ?? '' ) ) ? (string) $b['from'] : $d['from'];
		return array(
			'from' => $from,
			'pct'  => max( 0, min( 100, round( (float) str_replace( ',', '.', (string) ( $b['pct'] ?? $d['pct'] ) ), 3 ) ) ),
		);
	}

	/** Условията на профила (липсващи = по подразбиране). `enabled` идва от вида достъп: само „мениджър“ трупа бонус. */
	public static function terms( $user_id ) {
		$m = get_user_meta( (int) $user_id, self::META, true );
		$t = self::sanitize( is_array( $m ) ? $m : array() );
		$t['enabled'] = PP_Manager::is_manager( $user_id ) && 'manager' === PP_Manager::type( $user_id ) ? 1 : 0;
		return $t;
	}

	public static function save_terms( $user_id, array $b ) {
		update_user_meta( (int) $user_id, self::META, self::sanitize( $b ) );
	}

	public static function enabled_for( $user_id ) {
		return ! empty( self::terms( $user_id )['enabled'] );
	}

	public static function table() {
		return PP_Schema::table( 'manager_payouts' );
	}

	/* ------------------------------------------------------------------ */
	/* Сметката                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Реализираните поръчки от $from нататък, по (поръчка, партньор), с нетна комисиона и нетна база.
	 * Връща масив от обекти: order_id, partner_id, date (Y-m-d), base, commission, mgr_base.
	 * Реализирана = доставена — approved/paid редове + pending редове, чиято поръчка е в
	 * спечелен статус в WooCommerce сега (периодът на задържане на партньора не отлага мениджъра).
	 */
	public static function orders( $from ) {
		global $wpdb;
		$t = PP_Schema::table( 'commissions' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id, partner_id,
			        MIN(CASE WHEN type = 'commission' THEN created_at END) AS order_date,
			        SUM(CASE WHEN type = 'commission' THEN base_amount ELSE 0 END) AS base,
			        SUM(amount) AS commission,
			        MAX(CASE WHEN type = 'commission' THEN rate_applied ELSE 0 END) AS rate,
			        MAX(CASE WHEN type = 'commission' AND status = 'pending' THEN 1 ELSE 0 END) AS awaiting
			 FROM {$t}
			 WHERE order_id > 0 AND type IN ('commission','reversal')
			   AND ( status IN ('approved','paid')
			         OR ( status = 'pending' AND type = 'commission' AND ( note IS NULL OR note NOT LIKE %s ) ) )
			 GROUP BY order_id, partner_id
			 HAVING order_date IS NOT NULL AND order_date >= %s
			 ORDER BY order_date ASC, order_id ASC",
			'%' . $wpdb->esc_like( '[самопокупка]' ) . '%',
			$from . ' 00:00:00'
		) );
		// Изчакващите в книгата се броят само ако поръчката е доставена СЕГА (статусът в WooCommerce, не в книгата).
		$check = array();
		foreach ( (array) $rows as $r ) if ( ! empty( $r->awaiting ) ) $check[] = (int) $r->order_id;
		$st_map = $check ? proof_partner_order_statuses( $check ) : array();
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( ! empty( $r->awaiting ) && ! proof_partner_status_is_won( $st_map[ (int) $r->order_id ] ?? '' ) ) continue; // още пътува — чака доставка
			$net_c = round( (float) $r->commission, 2 );
			if ( $net_c <= 0 ) continue; // напълно върната / изтеглена след изплащане — няма оборот
			$base = round( (float) $r->base, 2 );
			$rate = (float) $r->rate;
			if ( $rate > 0 ) {
				// clawback след изплащане не пренаписва base_amount — базата се сваля пропорционално на нетната комисиона
				$est = round( $net_c * 100 / $rate, 2 );
				if ( $est < $base ) $base = $est;
			}
			$out[] = (object) array(
				'order_id'   => (int) $r->order_id,
				'partner_id' => (int) $r->partner_id,
				'date'       => substr( (string) $r->order_date, 0, 10 ),
				'base'       => $base,
				'commission' => $net_c,
				'mgr_base'   => max( 0.0, round( $base - $net_c, 2 ) ),
			);
		}
		return $out;
	}

	/**
	 * Поръчки, които наистина ЧАКАТ ДОСТАВКА (общ брой): изчакващ ред в книгата И поръчка, която още
	 * не е в спечелен статус. Доставените в период на задържане не са тук — те са реализирани (orders()).
	 */
	public static function waiting_count( $from ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT order_id FROM " . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission' AND status = 'pending' AND order_id > 0 AND created_at >= %s", $from . ' 00:00:00' ) );
		if ( ! $rows ) return 0;
		$st_map = proof_partner_order_statuses( wp_list_pluck( $rows, 'order_id' ) );
		$n = 0;
		foreach ( $rows as $r ) if ( ! proof_partner_status_is_won( $st_map[ (int) $r->order_id ] ?? '' ) ) $n++;
		return $n;
	}

	/** Месеците от $from до днес (включително), като 'YYYY-MM'. */
	private static function month_keys( $from ) {
		$keys  = array();
		$start = strtotime( substr( $from, 0, 7 ) . '-01 00:00:00' );
		$now   = current_time( 'timestamp' );
		if ( ! $start ) return $keys;
		$end   = strtotime( gmdate( 'Y-m-01', $now ) );
		for ( $t = $start, $i = 0; $t <= $end && $i < 240; $t = strtotime( '+1 month', $t ), $i++ ) $keys[] = gmdate( 'Y-m', $t );
		return $keys;
	}

	/**
	 * Цялата сметка за един мениджър.
	 * $until (Y-m-d) — „до коя дата“ за формата за изплащане; по подразбиране днес.
	 */
	public static function compute( $user_id, $until = '' ) {
		$user  = get_user_by( 'id', (int) $user_id );
		$terms = self::terms( $user_id );
		$today = current_time( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $until ) ) $until = $today;

		$out = array(
			'manager'  => $user ? array( 'id' => (int) $user->ID, 'login' => $user->user_login, 'name' => $user->display_name ?: $user->user_login ) : null,
			'terms'    => $terms,
			'today'    => $today,
			'totals'   => array( 'orders' => 0, 'turnover' => 0.0, 'commissions' => 0.0, 'base' => 0.0, 'pct_amount' => 0.0, 'earned' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'partners_with_orders' => 0, 'waiting' => 0 ),
			'months'   => array(),
			'partners' => array(),
			'payouts'  => array(),
			'paid_until' => '',
			'cut'      => array( 'until' => $until, 'earned' => 0.0, 'paid' => 0.0, 'due' => 0.0 ),
		);
		if ( ! $user || empty( $terms['enabled'] ) ) return $out;

		$from   = $terms['from'];
		$orders = self::orders( $from );
		$pct    = (float) $terms['pct'];

		$pmap = array();
		foreach ( PP_Partners::all() as $p ) $pmap[ (int) $p->partner_id ] = $p;

		// По месеци + по партньори.
		$months = array();
		$blank  = array( 'ym' => '', 'orders' => 0, 'turnover' => 0.0, 'commissions' => 0.0, 'base' => 0.0, 'pct_amount' => 0.0, 'total' => 0.0, 'paid' => false );
		foreach ( self::month_keys( $from ) as $ym ) $months[ $ym ] = array( 'ym' => $ym ) + $blank;
		$by_partner = array();
		$cut_pct_base = 0.0;
		foreach ( $orders as $o ) {
			$ym = substr( $o->date, 0, 7 );
			if ( ! isset( $months[ $ym ] ) ) $months[ $ym ] = array( 'ym' => $ym ) + $blank;
			$months[ $ym ]['orders']++;
			$months[ $ym ]['turnover']    += $o->base;
			$months[ $ym ]['commissions'] += $o->commission;
			$months[ $ym ]['base']        += $o->mgr_base;
			if ( $o->date <= $until ) $cut_pct_base += $o->mgr_base;

			if ( ! isset( $by_partner[ $o->partner_id ] ) ) {
				$p = $pmap[ $o->partner_id ] ?? null;
				$by_partner[ $o->partner_id ] = array( 'partner_id' => $o->partner_id, 'partner' => $p ? $p->display_name : ( '#' . $o->partner_id ), 'status' => $p ? $p->status : '', 'orders' => 0, 'turnover' => 0.0, 'commissions' => 0.0, 'base' => 0.0, 'pct_amount' => 0.0, 'last_order' => '' );
			}
			$bp = &$by_partner[ $o->partner_id ];
			$bp['orders']++;
			$bp['turnover']    += $o->base;
			$bp['commissions'] += $o->commission;
			$bp['base']        += $o->mgr_base;
			if ( $o->date > $bp['last_order'] ) $bp['last_order'] = $o->date;
			unset( $bp );
		}

		// Изплащания към мениджъра.
		$payouts    = self::payouts( $user_id );
		$paid_total = 0.0; $paid_until = '';
		foreach ( $payouts as $pr ) { $paid_total += (float) $pr['amount']; if ( $pr['until'] > $paid_until ) $paid_until = $pr['until']; }

		$T = &$out['totals'];
		foreach ( $months as $ym => &$m ) {
			$m['pct_amount'] = round( $m['base'] * $pct / 100, 2 );
			$m['total']      = $m['pct_amount'];
			$m['paid']       = '' !== $paid_until && gmdate( 'Y-m-t', strtotime( $ym . '-01' ) ) <= $paid_until;
			foreach ( array( 'turnover', 'commissions', 'base' ) as $k ) $m[ $k ] = round( $m[ $k ], 2 );
			$T['orders']      += $m['orders'];
			$T['turnover']    += $m['turnover'];
			$T['commissions'] += $m['commissions'];
			$T['base']        += $m['base'];
			$T['pct_amount']  += $m['pct_amount'];
		}
		unset( $m );
		foreach ( array( 'turnover', 'commissions', 'base', 'pct_amount' ) as $k ) $T[ $k ] = round( $T[ $k ], 2 );
		$T['earned'] = $T['pct_amount'];
		$T['paid']   = round( $paid_total, 2 );
		$T['due']    = round( $T['earned'] - $T['paid'], 2 );
		$T['partners_with_orders'] = count( $by_partner );
		$T['waiting'] = self::waiting_count( $from );
		unset( $T );

		foreach ( $by_partner as &$bp ) {
			foreach ( array( 'turnover', 'commissions', 'base' ) as $k ) $bp[ $k ] = round( $bp[ $k ], 2 );
			$bp['pct_amount'] = round( $bp['base'] * $pct / 100, 2 );
		}
		unset( $bp );
		usort( $by_partner, function ( $a, $b ) { return $b['base'] <=> $a['base']; } );

		$cut_earned = round( $cut_pct_base * $pct / 100, 2 );
		$out['cut']  = array( 'until' => $until, 'earned' => $cut_earned, 'paid' => round( $paid_total, 2 ), 'due' => round( $cut_earned - $paid_total, 2 ) );
		$out['months']   = array_values( array_reverse( $months ) ); // най-новият най-отгоре
		$out['partners'] = array_values( $by_partner );
		$out['payouts']  = $payouts;
		$out['paid_until'] = $paid_until;
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Изплащания към мениджъра                                             */
	/* ------------------------------------------------------------------ */

	public static function payouts( $user_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY until DESC, id DESC', (int) $user_id ) );
		return array_map( function ( $r ) {
			$by = $r->created_by ? get_user_by( 'id', (int) $r->created_by ) : null;
			return array(
				'id'         => (int) $r->id,
				'until'      => (string) $r->until,
				'amount'     => round( (float) $r->amount, 2 ),
				'note'       => (string) $r->note,
				'created_at' => (string) $r->created_at,
				'by'         => $by ? ( $by->display_name ?: $by->user_login ) : '',
			);
		}, (array) $rows );
	}

	/** Записва „изплатено по банка до $until: $amount“. Само администратор (REST го пази). */
	public static function add_payout( $user_id, $until, $amount, $note = '' ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! PP_Manager::is_manager( $user_id ) ) return new WP_Error( 'pp_404', 'Няма такъв мениджърски профил.', array( 'status' => 404 ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $until ) || ! strtotime( $until ) ) return new WP_Error( 'pp_date', 'Невалидна дата „изплатено до“.', array( 'status' => 400 ) );
		$amount = round( (float) str_replace( ',', '.', (string) $amount ), 2 );
		if ( $amount <= 0 ) return new WP_Error( 'pp_amount', 'Сумата трябва да е положителна.', array( 'status' => 400 ) );
		$ok = $wpdb->insert( self::table(), array(
			'user_id'    => $user_id,
			'amount'     => $amount,
			'until'      => $until,
			'note'       => sanitize_text_field( (string) $note ),
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
		) );
		if ( ! $ok ) return new WP_Error( 'pp_db', 'Записът не мина.', array( 'status' => 500 ) );
		$u = get_user_by( 'id', $user_id );
		proof_partner_feed_push( 'payout', sprintf( '💶 Бонус мениджър: %s · %.2f EUR (до %s)', $u ? ( $u->display_name ?: $u->user_login ) : ( '#' . $user_id ), $amount, $until ) );
		return (int) $wpdb->insert_id;
	}

	public static function delete_payout( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) );
		if ( ! $row ) return new WP_Error( 'pp_404', 'Няма такова изплащане.', array( 'status' => 404 ) );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		proof_partner_feed_push( 'payout', sprintf( '↩ Изтрито изплащане на бонус мениджър #%d (%.2f EUR до %s)', (int) $id, (float) $row->amount, $row->until ) );
		return true;
	}

	/** Профилите за избор в приложението (администратор): id, име, активен, вид (бонус = мениджър). */
	public static function managers() {
		return array_map( function ( $u ) {
			return array( 'id' => (int) $u->ID, 'login' => $u->user_login, 'name' => $u->display_name ?: $u->user_login, 'enabled' => PP_Manager::user_enabled( $u->ID ), 'type' => PP_Manager::type( $u->ID ), 'bonus' => self::enabled_for( $u->ID ) );
		}, PP_Manager::users() );
	}
}
