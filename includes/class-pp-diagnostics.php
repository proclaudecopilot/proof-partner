<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * №10 — one-click verification of the attribution chain. Run it after any
 * WooCommerce / Smart Coupons / FunnelKit update instead of finding out
 * from an angry partner a month later.
 *
 * These are configuration + data-integrity checks (no fake carts, no test
 * orders): is every hook wired, are the crons alive, is every live coupon
 * healthy, do codes collide, are recent commissions lock-covered, is the
 * ledger internally consistent, what did the last reconciliation find.
 */
class PP_Diagnostics {

	private static function c( $name, $status, $detail = '' ) {
		return array( 'name' => $name, 'status' => $status, 'detail' => $detail );
	}

	public static function run() {
		global $wpdb;
		$out = array();
		$s   = proof_partner_settings();

		$attr   = $GLOBALS['proof_partner_attribution'] ?? null;
		$engine = class_exists( 'PP_Engine' ) ? PP_Engine::instance() : null;

		/* 1–3: hooks wired */
		$gate_ok = $attr && false !== has_filter( 'woocommerce_coupon_is_valid', array( $attr, 'gate' ) );
		$out[]   = self::c( 'Coupon gate (≤1 партньорски код) закачен', $gate_ok ? 'pass' : 'fail',
			$gate_ok ? 'woocommerce_coupon_is_valid → PP_Attribution::gate' : 'Gate-ът НЕ е закачен — втори партньорски код няма да бъде блокиран на сървъра.' );

		$net_ok = $attr && false !== has_action( 'woocommerce_applied_coupon', array( $attr, 'on_applied' ) );
		$out[]  = self::c( 'Safety net след прилагане на купон', $net_ok ? 'pass' : 'fail', '' );

		$lock_ok = $attr
			&& false !== has_action( 'woocommerce_checkout_order_created', array( $attr, 'lock_order' ) )
			&& false !== has_action( 'woocommerce_store_api_checkout_order_processed', array( $attr, 'lock_order' ) );
		$out[] = self::c( 'Attribution lock при checkout (класически + Store API)', $lock_ok ? 'pass' : 'fail', '' );

		/* 4–5: trigger */
		$trigger  = sanitize_key( $s['trigger_status'] );
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$t_exists = isset( $statuses[ 'wc-' . $trigger ] );
		$out[]    = self::c( 'Trigger статус "' . $trigger . '" съществува', $t_exists ? 'pass' : 'fail',
			$t_exists ? '' : 'Статусът не е регистриран — комисиони НИКОГА няма да се създават. Провери плъгина за custom статуси.' );

		// Одит F16: проверяваме ДЕЙСТВИТЕЛНИТЕ callbacks, не пожелателни.
		$hook_ok = $engine
			&& false !== has_action( 'woocommerce_order_status_' . $trigger, array( $engine, 'enqueue_order' ) )
			&& false !== has_action( 'woocommerce_checkout_order_processed', array( $engine, 'on_new_order' ) )
			&& false !== has_action( 'proof_partner_promote_pending', array( $engine, 'promote_pending' ) );
		$out[]   = self::c( 'Engine слуша woocommerce_order_status_' . $trigger, $hook_ok ? 'pass' : 'fail', '' );

		/* 5a: ref архитектура */
		$out[] = self::c( 'Ref линкове: cache-first архитектура', 'pass',
			'?ref= страниците се сервират ОТ КЕША (бързо като нормална страница); кодът се прилага след зареждане през wc-ajax от browser-а. Препоръка: LiteSpeed Cache → Cache → Drop Query String → добави "ref" (една кеш заявка за всички линкове).' );

		/* 5b: Safe Mode + опашка */
		if ( proof_partner_safe_mode() ) {
			$out[] = self::c( '⛑ SAFE MODE Е АКТИВЕН', 'warn', 'Всички интервенции в количката са спрени (ref линкове, gate, съобщения). Счетоводството работи. Изключи го от Settings, когато конфликтът е изяснен.' );
		}
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$failed = count( as_get_scheduled_actions( array( 'group' => 'proof-partner', 'status' => 'failed', 'per_page' => 20 ), 'ids' ) );
			$pend   = count( as_get_scheduled_actions( array( 'group' => 'proof-partner', 'status' => 'pending', 'per_page' => 100 ), 'ids' ) );
			$out[]  = self::c( 'Опашка: здраве', $failed ? 'fail' : ( $pend > 50 ? 'warn' : 'pass' ),
				sprintf( '%d чакащи · %d провалени задачи в групата proof-partner.%s', $pend, $failed, $failed ? ' Виж WooCommerce → Status → Scheduled Actions → Failed.' : '' ) );
		}
		$mail_err = (array) get_option( 'proof_partner_mail_errors', array() );
		$out[] = self::c( 'Изходящи писма', empty( $mail_err ) ? 'pass' : 'warn',
			empty( $mail_err ) ? 'Няма регистрирани провали.' : sprintf( '%d провалени писма (посл.: %s — %s). SendGrid/SMTP проверка.', count( $mail_err ), $mail_err[ count( $mail_err ) - 1 ]['t'], mb_substr( $mail_err[ count( $mail_err ) - 1 ]['msg'], 0, 80 ) ) );

		$bf = get_option( 'proof_partner_backfill', array() );
		$out[] = self::c( 'Наваксване на историята', empty( $bf['started'] ) ? 'warn' : ( empty( $bf['done'] ) ? 'warn' : 'pass' ),
			empty( $bf['started'] )
				? 'Още не е стартирало — отвори Комисиони и то тръгва само.'
				: ( empty( $bf['done'] )
					? sprintf( 'Тече: %d поръчки проверени, %d комисиони създадени. Дръж Комисиони отворен до края.', (int) $bf['scanned'], (int) $bf['created'] )
					: sprintf( 'Завършено %s: %d поръчки, %d създадени комисиони.', $bf['finished'] ?? '', (int) $bf['scanned'], (int) $bf['created'] ) ) );
		$fat = (array) get_option( 'proof_partner_fatal', array() );
		$out[] = self::c( 'Защитен щит (гърмежи)', empty( $fat ) ? 'pass' : 'fail',
			empty( $fat ) ? 'Няма уловени грешки.' : sprintf( '%d уловени: посл. [%s] %s @ %s', count( $fat ), $fat[ count( $fat ) - 1 ]['where'], $fat[ count( $fat ) - 1 ]['msg'], $fat[ count( $fat ) - 1 ]['at'] ) );
		$sw = get_option( 'proof_partner_sweep', array() );
		$out[] = self::c( 'Мрежа за изпуснати (sweep)', empty( $sw['at'] ) ? 'warn' : 'pass',
			empty( $sw['at'] )
				? 'Още не е претърсвано — отвори Комисиони или Таблото, или натисни „Претърси доставени".'
				: sprintf( 'Последно: %s · проверени %d поръчки · създадени %d. Върви без WP-Cron — при всяко отваряне на Таблото/Комисионите/профил.', $sw['at'], (int) $sw['scanned'], (int) $sw['created'] ) );
		$out[] = self::c( 'Опашка (Action Scheduler)', function_exists( 'as_enqueue_async_action' ) ? 'pass' : 'warn',
			function_exists( 'as_enqueue_async_action' ) ? 'Комисионите се създават МИГНОВЕНО (inline при статуса); AS е само авариен fallback при гръм.' : 'AS липсва — без значение: създаването е inline.' );

		/* 6: crons */
		$p_next = wp_next_scheduled( 'proof_partner_promote_pending' );
		$r_next = wp_next_scheduled( 'proof_partner_reconcile' );
		$out[]  = self::c( 'Cron задачи (holding + сверка)', ( $p_next && $r_next ) ? 'pass' : 'warn',
			sprintf( 'Holding: %s · Сверка: %s', $p_next ? gmdate( 'Y-m-d H:i', $p_next ) : 'НЕ Е НАСРОЧЕН', $r_next ? gmdate( 'Y-m-d H:i', $r_next ) : 'НЕ Е НАСРОЧЕН' ) );

		/* 7: coupon audit */
		$maps       = $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE active = 1' );
		$violations = array();
		foreach ( $maps as $m ) {
			$wc_id = (int) $m->wc_coupon_id;
			$post  = $wc_id ? get_post( $wc_id ) : null;
			if ( ! $post ) { $violations[] = strtoupper( $m->coupon_code ) . ': WC купонът липсва'; continue; }
			if ( 'publish' !== $post->post_status ) { $violations[] = strtoupper( $m->coupon_code ) . ': WC статус ' . $post->post_status; }
			$coupon = new WC_Coupon( $wc_id );
			if ( $coupon->get_individual_use() ) { $violations[] = strtoupper( $m->coupon_code ) . ': individual_use е ВКЛЮЧЕН — ще блокира FunnelKit промо стакинга'; }
			$partner = PP_Partners::get( $m->partner_id );
			if ( ! $partner ) { $violations[] = strtoupper( $m->coupon_code ) . ': партньорът липсва'; }
			elseif ( 'active' !== $partner->status ) { $violations[] = strtoupper( $m->coupon_code ) . ': партньорът е ' . $partner->status . ', но кодът е активен'; }
		}
		$out[] = self::c( sprintf( 'Одит на активните кодове (%d)', count( $maps ) ),
			empty( $violations ) ? 'pass' : 'warn',
			empty( $violations ) ? 'Всички съществуват, публикувани са, стакват се с промо кодове (individual_use off), партньорите са активни.' : implode( ' · ', array_slice( $violations, 0, 8 ) ) );

		/* 7a: обратното — спрени кодове / спрени партньори, чийто WC купон е още publish.
		 * Gate-ът (woocommerce_coupon_is_valid) ги отказва при checkout, но е редно и WC да ги знае като draft. */
		$stale = array();
		$all   = $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'coupons' ) );
		foreach ( $all as $m ) {
			$partner = PP_Partners::get( $m->partner_id );
			$off     = (int) $m->active !== 1 || ! $partner || 'active' !== $partner->status;
			if ( ! $off ) continue;
			$post = $m->wc_coupon_id ? get_post( (int) $m->wc_coupon_id ) : null;
			if ( $post && 'publish' === $post->post_status ) {
				$stale[] = strtoupper( $m->coupon_code ) . ( (int) $m->active !== 1 ? ' (кодът е спрян)' : ' (партньорът е ' . ( $partner ? $partner->status : 'липсва' ) . ')' );
			}
		}
		$out[] = self::c( 'Спрени кодове: WC купонът е draft',
			empty( $stale ) ? 'pass' : 'warn',
			empty( $stale ) ? 'Няма спрян код/партньор с още публикуван WC купон.' : 'Още publish в WC (gate-ът ги отказва, но ги прати в draft): ' . implode( ', ', array_slice( $stale, 0, 10 ) ) );

		/* 7b: бял списък за комбиниране */
		$wl = PP_Coupons::allowed_with_partner_list();
		$wl_missing = array();
		foreach ( $wl as $w ) {
			if ( '*' === substr( $w, -1 ) ) continue; // wildcard — не се верифицира
			if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! wc_get_coupon_id_by_code( $w ) ) $wl_missing[] = strtoupper( $w );
		}
		$out[] = self::c( 'Бял списък: кодове, съвместими с партньорски',
			empty( $wl ) ? 'warn' : ( $wl_missing ? 'warn' : 'pass' ),
			empty( $wl )
				? 'Списъкът е празен — всеки промо код ще бъде премахван при партньорски код. Добави разрешените в Settings.'
				: 'Разрешени: ' . strtoupper( implode( ', ', $wl ) ) . ( $wl_missing ? ' · ЛИПСВАЩИ в WC: ' . implode( ', ', $wl_missing ) : '' ) );

		/* 8: collision scan */
		$all   = $wpdb->get_results( 'SELECT coupon_code, partner_id FROM ' . PP_Schema::table( 'coupons' ) );
		$dups  = array(); $near = array(); $seen = array();
		foreach ( $all as $a ) {
			$n = strtolower( preg_replace( '/[^a-z0-9]/i', '', $a->coupon_code ) );
			if ( isset( $seen[ $n ] ) && (int) $seen[ $n ]['pid'] !== (int) $a->partner_id ) {
				$dups[] = strtoupper( $a->coupon_code ) . ' ≡ ' . strtoupper( $seen[ $n ]['code'] );
			}
			$seen[ $n ] = array( 'code' => $a->coupon_code, 'pid' => $a->partner_id );
		}
		$norms = array_keys( $seen );
		for ( $i = 0; $i < count( $norms ); $i++ ) {
			for ( $j = $i + 1; $j < count( $norms ); $j++ ) {
				if ( (int) $seen[ $norms[ $i ] ]['pid'] === (int) $seen[ $norms[ $j ] ]['pid'] ) continue;
				if ( 1 === levenshtein( $norms[ $i ], $norms[ $j ] ) ) {
					$near[] = strtoupper( $seen[ $norms[ $i ] ]['code'] ) . ' ~ ' . strtoupper( $seen[ $norms[ $j ] ]['code'] );
				}
			}
		}
		$out[] = self::c( 'Колизии между кодове',
			$dups ? 'fail' : ( $near ? 'warn' : 'pass' ),
			$dups ? 'Идентични (casing): ' . implode( ' · ', $dups ) : ( $near ? 'На една буква разстояние (различни партньори): ' . implode( ' · ', array_slice( $near, 0, 6 ) ) : 'Няма.' ) );

		/* 9: lock coverage on recent commissions */
		$recent = $wpdb->get_results( 'SELECT order_id FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type='commission' ORDER BY commission_id DESC LIMIT 20" );
		$locked = 0; $checked = 0;
		foreach ( $recent as $r ) {
			$order = wc_get_order( $r->order_id );
			if ( ! $order ) continue;
			$checked++;
			if ( $order->get_meta( PP_Attribution::META_LOCK ) ) $locked++;
		}
		$out[] = self::c( 'Lock покритие на последните комисиони',
			( 0 === $checked || $locked === $checked ) ? 'pass' : 'warn',
			$checked ? sprintf( '%d от %d имат заключена атрибуция (по-стари от v2.2 поръчки нямат — нормално по време на прехода).', $locked, $checked ) : 'Няма комисиони за проверка.' );

		/* 9a: настройката trigger срещу реалните статуси */
		$trig    = proof_partner_trigger_status();
		$all_st  = array_map( function ( $k ) { return preg_replace( '/^wc-/', '', $k ); }, array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() ) );
		$raw     = (string) ( proof_partner_settings()['trigger_status'] ?? '' );
		$trig_ok = in_array( $trig, $all_st, true );
		$has_delivered_status = in_array( 'delivered', $all_st, true );
		$suspicious = $has_delivered_status && 'delivered' !== $trig; // магазинът има delivered, а законът е друг
		$out[]   = self::c( 'Trigger статус (законът за парите)',
			( $trig_ok && ! $suspicious && 0 !== strpos( $raw, 'wc-' ) ) ? 'pass' : 'fail',
			sprintf( 'Записано: "%s" → нормализирано: "%s". Печеливши статуси: [%s]. %s', $raw, $trig, implode( ', ', proof_partner_won_statuses() ),
				! $trig_ok ? 'ТОЗИ СТАТУС НЕ СЪЩЕСТВУВА в WooCommerce — комисиони не се създават! Отвори Settings и избери правилния.'
				: ( $suspicious ? 'Магазинът има статус "delivered", а законът е "' . $trig . '" — доставените поръчки НЕ носят пари и излизат нарушители в Сверката. Смени от Settings или с бутона в Сверката.' : 'Статусът съществува в магазина ✓' ) ) );

		/* 9b: законът Delivered = пари */
		$appr_ids = $wpdb->get_col( "SELECT order_id FROM " . PP_Schema::table( 'commissions' ) . " WHERE type='commission' AND status='approved' AND order_id > 0 ORDER BY commission_id DESC LIMIT 200" );
		$bad = 0;
		foreach ( $appr_ids as $oid ) {
			if ( ! PP_Engine::order_is_won( wc_get_order( $oid ) ) ) $bad++;
		}
		$out[] = self::c( '„За изплащане" само за Delivered поръчки', $bad ? 'fail' : 'pass',
			$bad ? $bad . ' одобрени реда сочат поръчки, които НЕ са Delivered в момента — пусни Сверката и „Върни ги в изчакващи".' : 'Всички одобрени редове са срещу доставени поръчки.' );

		/* 9c: дублирани IBAN-и — два профила, една сметка = миризма на схема */
		$ibans = array();
		foreach ( PP_Partners::all() as $pp ) {
			$ib = strtoupper( preg_replace( '/\s+/', '', (string) ( $pp->iban ?? '' ) ) );
			if ( '' === $ib || in_array( $pp->status, array( 'archived' ), true ) ) continue;
			$ibans[ $ib ][] = $pp->display_name;
		}
		$dups = array_filter( $ibans, function ( $v ) { return count( $v ) > 1; } );
		$out[] = self::c( 'Дублирани IBAN между партньори', $dups ? 'warn' : 'pass',
			$dups ? implode( ' · ', array_map( function ( $names, $ib ) { return substr( $ib, 0, 8 ) . '…: ' . implode( ' + ', $names ); }, $dups, array_keys( $dups ) ) ) . ' — една сметка, няколко профила: провери за раздвоена самоличност.' : 'Всеки IBAN се среща веднъж.' );

		/* 10: ledger sanity */
		$paid_orphan = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE status='paid' AND payout_id=0" );
		$neg_comm    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type='commission' AND amount < 0" );
		$out[] = self::c( 'Вътрешна консистентност на книгата',
			$neg_comm ? 'fail' : ( $paid_orphan ? 'warn' : 'pass' ),
			trim( ( $paid_orphan ? $paid_orphan . ' paid реда без payout_id. ' : '' ) . ( $neg_comm ? $neg_comm . ' комисиони с отрицателна сума (трябва да са reversal тип).' : '' ) ) ?: 'Чисто.' );

		/* 11: reconciliation summary */
		$rep = get_option( PP_Reconcile::OPT, array() );
		if ( empty( $rep['ran_at'] ) ) {
			$out[] = self::c( 'Сверка на книгата', 'warn', 'Още не е минавала — пусни я от Табло → Сверка на книгата.' );
		} else {
			$issues = count( (array) ( $rep['missed'] ?? array() ) ) + count( (array) ( $rep['orphans'] ?? array() ) );
			$out[]  = self::c( 'Сверка на книгата', $issues ? 'warn' : 'pass',
				sprintf( 'Последно: %s · %d пропуснати · %d осиротели.', $rep['ran_at'], count( (array) $rep['missed'] ), count( (array) $rep['orphans'] ) ) );
		}

		return $out;
	}
}
