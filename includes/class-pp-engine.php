<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Engine {

	private static $instance = null;

	/** Singleton: hooks are registered exactly once; reconcile reuses this. */
	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public function __construct() {
		self::$instance = $this;
		$this->register_hooks();
	}

	/**
	 * ЕДИНСТВЕНОТО място за hook регистрации. Одит F01 (P0): дотук тези
	 * редове стояха като мъртъв код вътре в enqueue_order() след return —
	 * артефакт от batch редакция в 4.4.0 — и НИКОГА не са се изпълнявали.
	 * Проверява се от harness hook-теста при всеки пакет.
	 */
	public function register_hooks() {
		// AS job: пълната обработка, не само създаване (одит F01 бележка).
		add_action( 'proof_partner_process_order', array( $this, 'process_order_job' ) );

		// Изчакваща комисиона ОЩЕ при поръчката.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order_obj' ), 30, 1 );

		// Доставка → комисионата става реална.
		/* Proof 1.9.5: и двата „спечелени" статуса създават комисиона (Приключена без да мине през Delivered се губеше). */
		foreach ( proof_partner_won_statuses() as $won_st ) add_action( 'woocommerce_order_status_' . $won_st, array( $this, 'enqueue_order' ), 20 );

		// Сторно пътища.
		add_action( 'woocommerce_order_status_refunded',  array( $this, 'reverse_full' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'reverse_full' ), 20 );
		add_action( 'woocommerce_order_status_failed',    array( $this, 'reverse_full' ), 20 );
		add_action( 'woocommerce_order_refunded',         array( $this, 'handle_partial_refund' ), 20, 2 );

		// Watchdog за custom статуси.
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_watchdog' ), 30, 3 );

		// Cron callbacks (насрочването е при activation/version-gate).
		add_action( 'proof_partner_promote_pending', array( $this, 'promote_pending' ) );
		add_action( 'proof_partner_tiers_daily', array( $this, 'apply_tiers' ) );
		if ( ! wp_next_scheduled( 'proof_partner_tiers_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:10' ), 'daily', 'proof_partner_tiers_daily' );
		}
	}

	/** AS job: пълната идемпотентна обработка на една поръчка. */
	public function process_order_job( $order_id ) {
		$this->maybe_create_commission( (int) $order_id );
		$this->on_won_existing( (int) $order_id );
	}

	/**
	 * №1: комисионата се създава през Action Scheduler опашка — retry при
	 * timeout, лог на всеки опит, никаква зависимост от това дали живият
	 * request доживява края си. Inline fallback ако AS липсва.
	 */
	/** Нова поръчка с наш код → комисионата се появява ВЕДНАГА като „Изчакваща". */
	public function on_new_order( $order_id ) {
		try { $this->maybe_create_commission( (int) $order_id ); }
		catch ( \Throwable $e ) { proof_partner_log_shield( 'on_new_order', $e ); }
	}
	public function on_new_order_obj( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) $this->on_new_order( $order->get_id() );
	}

	public function enqueue_order( $order_id ) {
		// МИГНОВЕНО: комисионата се създава инлайн в момента на статуса —
		// без да зависим от WP-Cron/AS loopback (мъртви на този сървър =
		// поръчки от 02.08 и 10.08 така и не станаха комисиони).
		// Създаването е идемпотентно, така че AS fallback-ът е безопасен.
		try {
			$this->maybe_create_commission( $order_id );
			$this->on_won_existing( (int) $order_id );
			return;
		} catch ( \Throwable $e ) {
			// Инлайн гръм (timeout клас) → опашката поема с retry.
			if ( function_exists( 'as_enqueue_async_action' )
				&& ! as_has_scheduled_action( 'proof_partner_process_order', array( (int) $order_id ), 'proof-partner' ) ) {
				as_enqueue_async_action( 'proof_partner_process_order', array( (int) $order_id ), 'proof-partner' );
				return;
			}
		}
		$this->maybe_create_commission( $order_id );
	}

	/* ------------------------------------------------------------------ */
	/* Sweep: крон-независима мрежа за изпуснати поръчки                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Претърсва последните 45 дни поръчки в trigger статус и създава
	 * липсващите комисиони. Не зависи от WP-Cron: вика се тротлирано от
	 * реални заявки (админ отваря Табло/Комисиони) и ръчно с бутон.
	 */
	/** Отложен sweep: заявката отговаря мигновено, тежкото върви след flush. */
	public static function sweep_async() {
		if ( get_transient( 'proof_partner_sweep_lock' ) ) return;
		add_action( 'shutdown', function () {
			if ( function_exists( 'fastcgi_finish_request' ) ) @fastcgi_finish_request();
			ignore_user_abort( true );
			PP_Engine::sweep();
		}, 99 );
	}

	/** Тих режим: при наваксване не заливаме фийда и Telegram. */
	public static $quiet = false;
	public static $quiet_count = 0;

	public static function sweep( $force = false, $with_backfill = null ) {
		// Одит F08: GET/фонов sweep НЕ пише историята — наваксването на
		// стари поръчки тръгва само от изричния бутон (POST force).
		if ( null === $with_backfill ) $with_backfill = (bool) $force;
		if ( ! $force && get_transient( 'proof_partner_sweep_lock' ) ) return get_option( 'proof_partner_sweep', null );
		set_transient( 'proof_partner_sweep_lock', 1, 5 * MINUTE_IN_SECONDS );
		global $wpdb;
		$inst = self::$instance ? self::$instance : new self();
		$out  = array( 'at' => current_time( 'mysql' ), 'scanned' => 0, 'missing' => 0, 'created' => 0 );

		self::$quiet = true; self::$quiet_count = 0;
		try {
			// ---- ФАЗА 1: BACKFILL на цялата история (еднократно, на порции) ----
			$bf = get_option( 'proof_partner_backfill', array( 'done' => 0, 'last_id' => 0, 'scanned' => 0, 'created' => 0, 'started' => '' ) );
			if ( $with_backfill && empty( $bf['done'] ) ) {
				if ( empty( $bf['started'] ) ) $bf['started'] = current_time( 'mysql' );
				// Директно по ID > last_id: HPOS таблицата ако е активна, иначе posts.
				$hpos = function_exists( 'wc_get_container' ) && get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
				if ( $hpos ) {
					$batch = $wpdb->get_col( $wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND id > %d ORDER BY id ASC LIMIT 60",
						(int) $bf['last_id'] ) );
				} else {
					$batch = $wpdb->get_col( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND ID > %d ORDER BY ID ASC LIMIT 60",
						(int) $bf['last_id'] ) );
				}
				$batch = array_map( 'intval', (array) $batch );
				if ( $batch ) {
					$in   = implode( ',', $batch );
					$have = $wpdb->get_col( 'SELECT DISTINCT order_id FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission' AND order_id IN ({$in})" );
					$miss = array_values( array_diff( $batch, array_map( 'intval', $have ) ) );
					foreach ( $miss as $oid ) {
						$o = wc_get_order( $oid );
						if ( ! $o || ! proof_partner_status_is_won( $o->get_status() ) ) continue;
						$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission'" );
						$inst->maybe_create_commission( $oid );
						if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission'" ) > $before ) $bf['created']++;
					}
					$bf['scanned'] += count( $batch );
					$bf['last_id']  = max( $batch );
				} else {
					$bf['done'] = 1;
					$bf['finished'] = current_time( 'mysql' );
					proof_partner_feed_push( 'diag', sprintf( '✅ Наваксване завършено: проверени %d поръчки, създадени %d комисиони.', (int) $bf['scanned'], (int) $bf['created'] ) );
				}
				update_option( 'proof_partner_backfill', $bf, false );
			}

			// ---- ФАЗА 2: редовната 45-дневна мрежа ----
			// Одит F15: при force мрежата е CURSOR-на — обхожда целия прозорец
			// на страници по 300 (иначе първите 300 най-нови блокират старите).
			$net_page = $force ? max( 1, (int) get_option( 'proof_partner_net_cursor', 1 ) ) : 1;
			$ids = wc_get_orders( array(
				'status'        => proof_partner_won_statuses(),
				// Ръчният бутон гледа 120 дни назад (възстановяване на цели
				// пропуснати месеци); фоновата мрежа остава 45 — идемпотентно е.
				'date_modified' => '>' . ( time() - ( $force ? 120 : 45 ) * DAY_IN_SECONDS ),
				'limit'         => 300,
				'paged'         => $net_page,
				'return'        => 'ids',
			) );
			if ( $force ) {
				if ( count( $ids ) === 300 ) update_option( 'proof_partner_net_cursor', $net_page + 1, false );
				else delete_option( 'proof_partner_net_cursor' ); // обиколката приключи — следващият force почва отначало
			}
			$out['scanned']  = count( $ids );
			$out['net_page'] = $net_page;
			$out['net_more'] = ( $force && count( $ids ) === 300 ) ? 1 : 0;

			// ---- ФАЗА 2б (само force): НЕдоставените с код → „Изчакваща" ----
			// Изравнява фийда с книгата: всяка „Поръчка #X с код" от събитията
			// получава ред (пътуващите — изчакващ), не чака куриера.
			/* Proof 1.9.5: и фоновата мрежа го прави (45 дни), но само за поръчки, които реално носят партньорски код — SQL предфилтър, без wc_get_order за останалите. */
			{
				$p2 = $force ? max( 1, (int) get_option( 'proof_partner_net2_cursor', 1 ) ) : 1;
				$tids = wc_get_orders( array(
					'status'        => array( 'pending', 'processing', 'on-hold', 'shipped', 'out-for-delivery' ),
					'date_created'  => '>' . ( time() - ( $force ? 120 : 45 ) * DAY_IN_SECONDS ),
					'limit'         => 300,
					'paged'         => $p2,
					'return'        => 'ids',
				) );
				$tids = array_map( 'intval', (array) $tids );
				if ( $tids ) {
					$codes = array_map( 'strtolower', (array) $wpdb->get_col( 'SELECT coupon_code FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE active = 1' ) );
					if ( $codes ) {
						$in_t = implode( ',', $tids ); $in_c = implode( ',', array_map( function ( $c ) use ( $wpdb ) { return $wpdb->prepare( '%s', $c ); }, $codes ) );
						$with_code = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'coupon' AND order_id IN ({$in_t}) AND LOWER(order_item_name) IN ({$in_c})" ) );
						$have_t = array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT order_id FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission' AND order_id IN ({$in_t})" ) );
						$tids = array_values( array_diff( $with_code, $have_t ) );
					} else { $tids = array(); }
				}
				if ( ! $force ) $tids = array_slice( $tids, 0, 60 );
				foreach ( $tids as $tid ) {
					try { $inst->maybe_create_commission( (int) $tid ); }
					catch ( \Throwable $te ) { proof_partner_log_shield( 'net_pending', $te ); }
				}
				if ( $force && count( $tids ) === 300 ) { update_option( 'proof_partner_net2_cursor', $p2 + 1, false ); $out['net_more'] = 1; }
				elseif ( $force ) delete_option( 'proof_partner_net2_cursor' );
				$out['scanned'] += count( $tids );
			}
			if ( $ids ) {
				$in   = implode( ',', array_map( 'intval', $ids ) );
				$have = $wpdb->get_col( 'SELECT DISTINCT order_id FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission' AND order_id IN ({$in})" );
				$miss = array_values( array_diff( array_map( 'intval', $ids ), array_map( 'intval', $have ) ) );
				$out['missing'] = count( $miss );
				// БЕЗ slice: 40-те "най-нови липсващи" бяха все БЕЗКОДОВИ поръчки,
				// не се създаваха, оставаха първи → кодовите ПО-НАЗАД в списъка
				// не се стигаха НИКОГА. Обработваме цялата страница, под щит.
				foreach ( $miss as $oid ) {
					$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission'" );
					// Одит F14 по дух: ЕДНА отровна поръчка не убива мрежата.
					// Преди: гръм тук спираше целия sweep на всеки пас — и понеже
					// редът не се създаваше, оставаше първи в списъка ЗАВИНАГИ.
					try { $inst->maybe_create_commission( $oid ); }
					catch ( \Throwable $oe ) {
						proof_partner_log_shield( 'net_order_' . $oid, $oe );
						if ( empty( $out['poison'] ) ) $out['poison'] = '#' . $oid . ': ' . $oe->getMessage();
						continue;
					}
					if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission'" ) > $before ) $out['created']++;
				}
			}

			// ---- ФАЗА 3: доставено + минал срок = НЕПЛАТЕНО (за изплащане) ----
			$inst->promote_pending();

			// ---- Играта: подиум-стража + дайджест (собствени тротъли) ----
			if ( class_exists( 'PP_Game' ) ) { try { PP_Game::tick(); } catch ( \Throwable $ge ) { proof_partner_log_shield( 'game_tick', $ge ); } }

			// ---- ФАЗА 4: самосверка (лека, тротлирана на 1 час) ----
			if ( ! get_transient( 'proof_partner_selfcheck_lock' ) ) {
				set_transient( 'proof_partner_selfcheck_lock', 1, HOUR_IN_SECONDS );
				$out['autofix'] = self::self_reconcile();
			}
		} catch ( \Throwable $e ) {
			$out['error'] = $e->getMessage();
			proof_partner_log_shield( 'sweep', $e );
		}

		self::$quiet = false;
		if ( self::$quiet_count > 0 ) {
			proof_partner_feed_push( 'diag', sprintf( '🔄 Наваксани %d комисиони (тих режим — без отделни известия).', self::$quiet_count ) );
		}
		$out['backfill'] = get_option( 'proof_partner_backfill', array() );
		update_option( 'proof_partner_sweep', $out, false );
		return $out;
	}

	/**
	 * Самосверка: строи отчет, поправя каквото има, логва. Връща поправеното.
	 * Лека — САМО сверка, без наваксване/мрежа (те са в sweep).
	 */
	public static function self_reconcile() {
		if ( ! class_exists( 'PP_Reconcile' ) ) return array();
		try {
			$rep = PP_Reconcile::build_report();
		} catch ( \Throwable $e ) {
			proof_partner_log_shield( 'recon_build', $e );
			update_option( PP_Reconcile::OPT, array( 'ran_at' => current_time( 'mysql' ),
				'error' => $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(),
				'missed' => array(), 'premature' => array(), 'orphans' => array() ), false );
			return array();
		}
		update_option( PP_Reconcile::OPT, $rep, false );
		$fixed = array();
		if ( ! empty( $rep['missed'] ) )    { $r = PP_Reconcile::fix_missed();    if ( $r ) $fixed['създадени пропуснати'] = (int) $r; }
		if ( ! empty( $rep['premature'] ) ) { $r = PP_Reconcile::fix_premature(); if ( $r ) $fixed['„За изплащане" върнати в изчакващи'] = (int) $r; }
		if ( ! empty( $rep['orphans'] ) )   { $r = PP_Reconcile::fix_orphans();   if ( $r ) $fixed['затворени осиротели'] = (int) $r; }
		if ( $fixed ) {
			$log   = (array) get_option( 'proof_partner_autofix', array() );
			$parts = array();
			foreach ( $fixed as $k => $v ) $parts[] = "$k: $v";
			$log[] = array( 't' => current_time( 'mysql' ), 'msg' => implode( ' · ', $parts ) );
			update_option( 'proof_partner_autofix', array_slice( $log, -50 ), false );
			proof_partner_feed_push( 'diag', '🤖 Самосверка: ' . implode( ' · ', $parts ) );
			// отчетът СЛЕД поправките
			update_option( PP_Reconcile::OPT, PP_Reconcile::build_report(), false );
		}
		return $fixed;
	}

	/** Won преход на ВЕЧЕ създадена (изчакваща) комисиона: чисти маркера и пали точките. */
	public function on_won_existing( $order_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT commission_id, partner_id, base_amount, note FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE type = 'commission' AND order_id = %d AND status IN ('pending','approved') LIMIT 1", $order_id ) );
		if ( ! $row ) return;
		if ( false !== strpos( (string) $row->note, '[чака доставка]' ) ) {
			$wpdb->update( PP_Schema::table( 'commissions' ),
				array( 'note' => trim( str_replace( '[чака доставка]', '', (string) $row->note ) ), 'updated_at' => current_time( 'mysql' ) ),
				array( 'commission_id' => (int) $row->commission_id ) );
		}
		// Точките/играта: идемпотентни по ref_key — безопасно при всеки won преход.
		do_action( 'proof_partner_commission_created', (int) $order_id, (int) $row->partner_id, (float) $row->base_amount );
	}

	/** Самопокупка: поръчка с данните на самия партньор. */
	public static function is_self_purchase( $order, $partner ) {
		if ( ! $order || ! $partner ) return false;
		$be = strtolower( trim( (string) $order->get_billing_email() ) );
		$bp = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );
		$pe = strtolower( trim( (string) ( $partner->email ?? '' ) ) );
		if ( ! $pe && ! empty( $partner->user_id ) ) { $u = get_userdata( (int) $partner->user_id ); if ( $u ) $pe = strtolower( $u->user_email ); }
		$pp = preg_replace( '/\D+/', '', (string) ( $partner->phone ?? '' ) );
		if ( $be && $pe && $be === $pe ) return true;
		if ( $bp && $pp && strlen( $bp ) >= 9 && substr( $bp, -9 ) === substr( $pp, -9 ) ) return true;
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Creation                                                            */
	/* ------------------------------------------------------------------ */

	public function maybe_create_commission( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) return; // и срещу WC_Order_Refund

		// Idempotency: one commission row per (order, partner), enforced in code.
		$existing = $wpdb->get_var( $wpdb->prepare(
			'SELECT commission_id FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE order_id = %d AND type = 'commission'",
			$order->get_id()
		) );
		if ( $existing ) return;

		// Attribution: the checkout-time LOCK wins. It froze partner, code and
		// rate when the sale happened — remapping a code or changing a rate
		// during the COD delivery window must not move or reprice this sale.
		$lock       = $order->get_meta( PP_Attribution::META_LOCK );
		$note_extra = '';

		if ( is_array( $lock ) && ! empty( $lock['partner_id'] ) && ( $locked_partner = PP_Partners::get( (int) $lock['partner_id'] ) ) ) {
			$partner = $locked_partner;
			$attr    = array( 'partner_id' => (int) $lock['partner_id'], 'coupon_code' => (string) ( $lock['coupon_code'] ?? '' ), 'anomaly' => '' );
			$rate    = isset( $lock['rate'] ) && null !== $lock['rate'] ? (float) $lock['rate'] : (float) $partner->rate;

			if ( 'archived' === $partner->status ) {
				PP_Attribution::olog( $order, 'Комисиона НЕ е създадена: партньорът е архивиран.' );
				$order->add_order_note( 'Proof Partner: locked attribution found but partner is archived — no commission.' );
				return;
			}
			if ( 'active' !== $partner->status ) {
				$note_extra = 'locked attribution honored; partner ' . $partner->status . ' at trigger';
			}
		} else {
			$attr = PP_Coupons::attribute_order( $order );
			/* Proof: без купон при checkout → Sublium подновяване на партньорски абонамент? (class-pp-proof.php) */
			$attr = apply_filters( 'proof_partner_fallback_attribution', $attr, $order );
			if ( ! $attr ) return;

			$partner = PP_Partners::get( $attr['partner_id'] );
			if ( ! $partner || 'active' !== $partner->status ) return;
			$rate = isset( $attr['rate'] ) && null !== $attr['rate'] ? (float) $attr['rate'] : (float) $partner->rate;
			if ( ! empty( $attr['note'] ) ) $note_extra = trim( $note_extra . ' ' . $attr['note'] );
		}

		/* Proof: начална дата на проследяване — поръчки ПРЕДИ нея са история (кодът е работил и преди програмата) */
		if ( ! empty( $partner->tracked_from ) && $order->get_date_created() && $order->get_date_created()->date( 'Y-m-d' ) < substr( (string) $partner->tracked_from, 0, 10 ) ) {
			PP_Attribution::olog( $order, sprintf( 'Без комисиона: поръчката е от %s, партньорът се следи от %s (история).', $order->get_date_created()->date( 'Y-m-d' ), substr( $partner->tracked_from, 0, 10 ) ) );
			return;
		}

		// Fraud guard 1: self-referral. Partner buying with their own code
		// gets the discount but never a commission.
		$s = proof_partner_settings();
		if ( ! empty( $s['block_self_referrals'] ) && $this->is_self_referral( $order, $partner ) ) {
			$order->add_order_note( sprintf(
				'Proof Partner: SELF-REFERRAL blocked — code %s belongs to the buyer (%s). No commission created.',
				$attr['coupon_code'], $partner->display_name
			) );
			PP_Attribution::olog( $order, sprintf( 'Комисиона блокирана: self-referral (%s е на самия купувач).', strtoupper( $attr['coupon_code'] ) ) );
			return;
		}

		$base = $this->commission_base( $order );
		if ( $base <= 0 ) return;

		$amount = round( $base * $rate / 100, 2 );
		/* Proof: EPACK/combo правилата (фиксирана сума или друг %) могат да подменят сметката — class-pp-proof.php */
		$calc = apply_filters( 'proof_partner_commission_calc', null, $order, $partner, $rate, $base, $attr );
		if ( is_array( $calc ) && isset( $calc['amount'] ) ) {
			$amount = round( (float) $calc['amount'], 2 );
			if ( isset( $calc['rate'] ) ) $rate = (float) $calc['rate'];
			if ( ! empty( $calc['note'] ) ) $note_extra = trim( $note_extra . ' ' . $calc['note'] );
		}
		$fixed = isset( $partner->pay_model ) && 'fixed' === $partner->pay_model; /* Proof 1.6: фиксиран договор по фактура — продажбата се записва, комисионата е 0 */
		if ( $fixed ) { $note_extra = trim( $note_extra . ' [фиксиран договор · без комисиона · би било ' . number_format( $amount, 2 ) . ']' ); $amount = 0.0; }
		if ( $amount <= 0 && ! $fixed ) return;

		$now = current_time( 'mysql' );
		// ЗАКОНЪТ ЗА ДАТАТА: комисионата се СЪЗДАВА чак при доставка, но
		// живее в МЕСЕЦА НА ПОРЪЧКАТА — отчети, справки и класации гледат
		// кога е продадено, не кога куриерът е стигнал или скриптът е минал.
		$d    = $order->get_date_created();
		$when = $d ? $d->date( 'Y-m-d H:i:s' ) : $now;
		$selfbuy = self::is_self_purchase( $order, $partner );
		$ins = $wpdb->insert( PP_Schema::table( 'commissions' ), array(
			'order_partner_key' => $order->get_id() . '-' . (int) $partner->partner_id, // №1: UNIQUE в базата — дубликат е физически невъзможен
			'order_id'     => $order->get_id(),
			'partner_id'   => (int) $partner->partner_id,
			'coupon_code'  => $attr['coupon_code'],
			'type'         => 'commission',
			'base_amount'  => $base,
			'rate_applied' => $rate,
			'amount'       => $amount,
			'currency'     => $order->get_currency(),
			'status'       => $fixed ? 'fixed' : 'pending',
			'products'     => self::products_summary( $order ),
			'note'         => ( $selfbuy ? '[самопокупка] ' : ( proof_partner_status_is_won( $order->get_status() ) ? '' : '[чака доставка] ' ) ) . trim( $attr['anomaly'] . ( $note_extra ? ' | ' . $note_extra : '' ), ' |' ),
			'created_at'   => $when,
			'updated_at'   => $now,
		) );
		if ( false === $ins ) return; // UNIQUE guard: паралелен процес ни изпревари — това е желаният изход
		/* 2.0: разбивката по оферти (PP_Offers::write_items) */
		do_action( 'proof_partner_commission_row', (int) $wpdb->insert_id, $order, $partner, is_array( $calc ) ? $calc : null );

		if ( $selfbuy ) {
			$alerts   = (array) get_option( 'proof_partner_alerts', array() );
			$alerts[] = array( 'partner_id' => (int) $partner->partner_id, 'time' => current_time( 'mysql' ),
				'message' => sprintf( '🛒 Самопокупка: %s поръча със собствения си код (#%d) — комисионата чака ръчно одобрение.', $partner->display_name, $order->get_id() ) );
			update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
		}
		if ( self::$quiet || $selfbuy ) {
			if ( ! $selfbuy ) self::$quiet_count++;
		} else {
			proof_partner_feed_push( 'commission', sprintf( '%s: %s · поръчка #%d · %.2f EUR',
				proof_partner_status_is_won( $order->get_status() ) ? 'Комисиона' : 'Изчакваща комисиона (чака доставка)',
				$partner->display_name, $order->get_id(), $amount ) );
		}
		if ( proof_partner_status_is_won( $order->get_status() ) ) {
			do_action( 'proof_partner_commission_created', $order->get_id(), (int) $partner->partner_id, (float) $base );
		}
		PP_Attribution::olog( $order, sprintf(
			'Комисиона създадена: %s %s (%s%% върху %s) → %s.',
			number_format( $amount, 2 ), $order->get_currency(),
			rtrim( rtrim( number_format( $rate, 3 ), '0' ), '.' ), number_format( $base, 2 ), $partner->display_name
		) );

		$order->add_order_note( sprintf(
			'Proof Partner: commission %s %s (%s%% of %s base) → %s [%s], pending.',
			number_format( $amount, 2 ), $order->get_currency(), rtrim( rtrim( number_format( $rate, 3 ), '0' ), '.' ),
			number_format( $base, 2 ), $partner->display_name, $attr['coupon_code']
		) );

		// Order meta → Metorik / P&L cost layer. The affiliate cost becomes
		// visible per order instead of hiding outside the margin model.
		$this->write_cost_meta( $order, $amount, $partner->partner_id, $attr['coupon_code'] );

		// Fraud guard 2: velocity / coupon-leak alert (alert only, never blocks).
		$this->velocity_check( $partner, $order );
	}

	/**
	 * Human product snapshot for the ledger, AffiliateWP-style cross-check:
	 * "Daily Greens ×3, ThyroZen ×1". Stored at creation so it survives
	 * product renames/deletions.
	 */
	public static function products_summary( WC_Order $order ) {
		$parts = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$name = wp_strip_all_tags( $item->get_name() );
			$name = preg_replace( '/\s+/', ' ', trim( $name ) );
			$qty  = (int) $item->get_quantity();
			$parts[] = $name . ( $qty > 1 ? ' ×' . $qty : '' );
		}
		$out = implode( ', ', $parts );
		return function_exists( 'mb_substr' ) ? mb_substr( $out, 0, 500 ) : substr( $out, 0, 500 );
	}

	private function is_self_referral( WC_Order $order, $partner ) {
		$user = get_user_by( 'id', $partner->user_id );

		if ( $user ) {
			$billing = strtolower( trim( (string) $order->get_billing_email() ) );
			if ( '' !== $billing && $billing === strtolower( trim( $user->user_email ) ) ) return true;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id && $customer_id === (int) $partner->user_id ) return true;

		return false;
	}

	private function write_cost_meta( WC_Order $order, $amount, $partner_id, $coupon_code ) {
		$order->update_meta_data( '_proof_partner_cost', wc_format_decimal( $amount, 2 ) );
		$order->update_meta_data( '_proof_partner_id', (int) $partner_id );
		$order->update_meta_data( '_proof_partner_coupon', $coupon_code );
		$order->save();
	}

	/**
	 * A partner code suddenly doing multiples of its own baseline volume is
	 * the classic coupon-site leak signature. Compare this week's commission
	 * count vs the prior 8-week weekly average.
	 */
	private function velocity_check( $partner, WC_Order $order ) {
		global $wpdb;
		$s          = proof_partner_settings();
		$min_weekly = max( 1, absint( $s['velocity_min_weekly'] ) );
		$multiplier = max( 2, (float) $s['velocity_multiplier'] );
		$t          = PP_Schema::table( 'commissions' );
		$now_ts     = current_time( 'timestamp' );

		$week_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE partner_id = %d AND type = 'commission' AND created_at >= %s",
			$partner->partner_id, gmdate( 'Y-m-d H:i:s', $now_ts - 7 * DAY_IN_SECONDS )
		) );
		if ( $week_count < $min_weekly ) return;

		$prior = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE partner_id = %d AND type = 'commission' AND created_at >= %s AND created_at < %s",
			$partner->partner_id,
			gmdate( 'Y-m-d H:i:s', $now_ts - 63 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d H:i:s', $now_ts - 7 * DAY_IN_SECONDS )
		) );
		$avg_weekly = $prior / 8;

		// New partner (no baseline) tripping the absolute floor is itself the
		// leak scenario; established partner must exceed multiplier × baseline.
		if ( $avg_weekly > 0 && $week_count < $multiplier * $avg_weekly ) return;

		$alerts = get_option( 'proof_partner_alerts', array() );
		if ( ! is_array( $alerts ) ) $alerts = array();

		// One alert per partner per 24h — no notice spam.
		foreach ( $alerts as $a ) {
			if ( (int) $a['partner_id'] === (int) $partner->partner_id
				&& strtotime( $a['time'] ) > $now_ts - DAY_IN_SECONDS ) return;
		}

		$alerts[] = array(
			'partner_id' => (int) $partner->partner_id,
			'time'       => current_time( 'mysql' ),
			'message'    => sprintf(
				'VELOCITY: %s — %d commissions in 7 days (8-week baseline: %.1f/week). Possible coupon leak; check where the code is circulating. Last order #%d.',
				$partner->display_name, $week_count, $avg_weekly, $order->get_id()
			),
		);
		update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
		$order->add_order_note( 'Proof Partner: velocity alert triggered for ' . $partner->display_name . ' — see Proof Partner admin.' );
	}

	/**
	 * Commission base: product line totals AFTER discounts, excluding shipping
	 * (always), excluding excluded products, net of refunds.
	 * VAT: included or excluded per settings (include_vat). When included,
	 * refunds are grossed up by the line's actual tax ratio so partial refunds
	 * stay exact per line regardless of the VAT rate.
	 */
	public function commission_base( WC_Order $order ) {
		$excluded    = proof_partner_excluded_product_ids();
		$s           = proof_partner_settings();
		$include_vat = ! empty( $s['include_vat'] );
		$base        = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pid = (int) $item->get_product_id();
			$vid = (int) $item->get_variation_id();
			if ( in_array( $pid, $excluded, true ) || ( $vid && in_array( $vid, $excluded, true ) ) ) continue;

			$line_ex   = (float) $item->get_total();      // after discount, ex tax
			$refund_ex = (float) $order->get_total_refunded_for_item( $item_id );
			$net_ex    = $line_ex - $refund_ex;
			if ( $net_ex <= 0 ) continue;

			if ( $include_vat ) {
				$tax_ratio = $line_ex > 0 ? ( (float) $item->get_total_tax() ) / $line_ex : 0.0;
				$base     += $net_ex * ( 1 + $tax_ratio );
			} else {
				$base += $net_ex;
			}
		}
		return round( $base, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Reversal                                                            */
	/* ------------------------------------------------------------------ */

	public function reverse_full( $order_id ) {
		do_action( 'proof_partner_commission_reversed', (int) $order_id );
		global $wpdb;
		$rows    = $this->commission_rows( $order_id );
		$touched = false;

		foreach ( $rows as $row ) {
			if ( 'commission' !== $row->type ) continue;

			if ( in_array( $row->status, array( 'pending', 'approved' ), true ) ) {
				$wpdb->update( PP_Schema::table( 'commissions' ),
					array( 'status' => 'reversed', 'note' => trim( $row->note . ' | full reversal (order status)' ), 'updated_at' => current_time( 'mysql' ) ),
					array( 'commission_id' => $row->commission_id )
				);
				$this->note( $order_id, sprintf( 'Proof Partner: commission #%d reversed (%s %s).', $row->commission_id, $row->amount, $row->currency ) );
				$touched = true;
			} elseif ( 'paid' === $row->status && ! $this->has_reversal( $order_id, $row->partner_id ) ) {
				// Already paid out: insert negative clawback row that nets against the next payout.
				$this->insert_reversal_row( $row, -1 * (float) $row->amount, 'clawback: order reversed after payout' );
				$this->note( $order_id, sprintf( 'Proof Partner: PAID commission #%d clawed back via reversal row (-%s %s).', $row->commission_id, $row->amount, $row->currency ) );
				$touched = true;
			}
		}

		if ( $touched ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_proof_partner_cost', '0.00' );
				$order->save();
				PP_Attribution::olog( $order, 'Комисионата е сторнирана (пълно връщане/отказ на поръчката).' );
			}
		}
	}

	public function handle_partial_refund( $order_id, $refund_id ) {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;

		$rows = $this->commission_rows( $order_id );
		foreach ( $rows as $row ) {
			if ( 'commission' !== $row->type ) continue;

			$new_base   = $this->commission_base( $order ); // already nets out refunds
			$new_amount = round( $new_base * (float) $row->rate_applied / 100, 2 );

			if ( in_array( $row->status, array( 'pending', 'approved' ), true ) ) {
				if ( $new_amount <= 0 ) {
					$wpdb->update( PP_Schema::table( 'commissions' ),
						array( 'status' => 'reversed', 'base_amount' => $new_base, 'amount' => 0,
						       'note' => trim( $row->note . ' | fully refunded' ), 'updated_at' => current_time( 'mysql' ) ),
						array( 'commission_id' => $row->commission_id )
					);
				} else {
					$wpdb->update( PP_Schema::table( 'commissions' ),
						array( 'base_amount' => $new_base, 'amount' => $new_amount,
						       'note' => trim( $row->note . ' | recalculated after partial refund' ), 'updated_at' => current_time( 'mysql' ) ),
						array( 'commission_id' => $row->commission_id )
					);
				}
				$this->note( $order_id, sprintf( 'Proof Partner: commission #%d recalculated to %s after refund.', $row->commission_id, number_format( $new_amount, 2 ) ) );
				$order->update_meta_data( '_proof_partner_cost', wc_format_decimal( max( 0, $new_amount ), 2 ) );
				$order->save();
			} elseif ( 'paid' === $row->status ) {
				$delta = round( $new_amount - (float) $row->amount, 2 );
				if ( $delta < 0 && ! $this->has_reversal( $order_id, $row->partner_id ) ) {
					$this->insert_reversal_row( $row, $delta, 'clawback: partial refund after payout' );
					$this->note( $order_id, sprintf( 'Proof Partner: clawback %s created for paid commission #%d.', number_format( $delta, 2 ), $row->commission_id ) );
					$order->update_meta_data( '_proof_partner_cost', wc_format_decimal( max( 0, $new_amount ), 2 ) );
					$order->save();
				}
			}
		}
	}

	private function insert_reversal_row( $row, $amount, $note ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( PP_Schema::table( 'commissions' ), array(
			'order_id'     => $row->order_id,
			'partner_id'   => $row->partner_id,
			'coupon_code'  => $row->coupon_code,
			'type'         => 'reversal',
			'base_amount'  => 0,
			'rate_applied' => $row->rate_applied,
			'amount'       => round( $amount, 2 ), // negative
			'currency'     => $row->currency,
			'status'       => 'approved',          // nets against next payout
			'note'         => $note,
			'created_at'   => $now,
			'updated_at'   => $now,
		) );
	}

	private function has_reversal( $order_id, $partner_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT commission_id FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE order_id = %d AND partner_id = %d AND type = 'reversal'",
			$order_id, $partner_id
		) );
	}

	private function commission_rows( $order_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE order_id = %d', absint( $order_id )
		) );
	}

	private function note( $order_id, $text ) {
		$order = wc_get_order( $order_id );
		if ( $order ) $order->add_order_note( $text );
	}

	/* ------------------------------------------------------------------ */
	/* Holding period                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * №4: нива — дневна оценка на rolling 30 дни; ставката се сменя сама.
	 * Lock-ът при checkout замразява ставката per поръчка, така че смяната
	 * важи само занапред — никакво пренаписване на история.
	 */
	public function apply_tiers() {
		if ( empty( proof_partner_settings()['tiers_enabled'] ) ) return;
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		foreach ( PP_Partners::all() as $p ) {
			if ( 'active' !== $p->status ) continue;
			$sum = (float) $wpdb->get_var( $wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM ' . PP_Schema::table( 'commissions' ) . "
				 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND created_at >= %s",
				$p->partner_id, $since
			) );
			$t = proof_partner_tier_for( $sum );
			if ( ! $t ) return; // няма конфигурирани нива
			$new_rate = (float) $t['tier']['rate'];
			if ( abs( $new_rate - (float) $p->rate ) < 0.0001 ) continue;
			PP_Partners::update( $p->partner_id, array( 'rate' => $new_rate ) );
			$dir = $new_rate > (float) $p->rate ? '⬆' : '⬇';
			proof_partner_feed_push( 'tier', sprintf( '%s Ниво: %s → %s (%s%%) при %.0f €/30д', $dir, $p->display_name, $t['tier']['name'], rtrim( rtrim( number_format( $new_rate, 3 ), '0' ), '.' ), $sum ) );
			$alerts   = (array) get_option( 'proof_partner_alerts', array() );
			$alerts[] = array( 'partner_id' => (int) $p->partner_id, 'time' => current_time( 'mysql' ),
				'message' => sprintf( 'НИВО %s: %s вече е %s (%s%%) — %.2f € за 30 дни.', $dir, $p->display_name, $t['tier']['name'], rtrim( rtrim( number_format( $new_rate, 3 ), '0' ), '.' ), $sum ) );
			update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
		}
	}

	/**
	 * ЗАКОНЪТ: „За изплащане" изисква поръчката да е Delivered СЕГА.
	 * Всичко останало е потенциално (pending) или сторнирано.
	 */
	public static function order_is_won( $order ) {
		if ( ! $order ) return false;
		return in_array( $order->get_status(), proof_partner_won_statuses(), true );
	}

	public function promote_pending() {
		global $wpdb;
		$s      = proof_partner_settings();
		$days   = max( 0, absint( $s['holding_days'] ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );

		// Редове без поръчка (ръчни, opening) минават по чист срок.
		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . PP_Schema::table( 'commissions' ) . "
			 SET status = 'approved', updated_at = %s
			 WHERE status = 'pending' AND order_id = 0 AND created_at <= %s
			   AND ( note IS NULL OR note NOT LIKE '[самопокупка]%%' )",
			current_time( 'mysql' ), $cutoff
		) );

		// Редове с поръчка: срокът Е минал И поръчката Е Delivered/completed СЕГА.
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT commission_id, order_id FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE status = 'pending' AND type = 'commission' AND order_id > 0 AND created_at <= %s
			   AND ( note IS NULL OR note NOT LIKE '[самопокупка]%%' ) LIMIT 500",
			$cutoff
		) );
		$st_map = proof_partner_order_statuses( wp_list_pluck( $rows, 'order_id' ) );
		foreach ( $rows as $r ) {
			if ( ! proof_partner_status_is_won( $st_map[ (int) $r->order_id ] ?? '' ) ) continue; // остава потенциална
			$wpdb->update( PP_Schema::table( 'commissions' ),
				array( 'status' => 'approved', 'updated_at' => current_time( 'mysql' ) ),
				array( 'commission_id' => (int) $r->commission_id )
			);
		}

		// САМОЛЕЧЕНИЕ (закона Delivered = пари): одобрени редове, чиито
		// поръчки НЕ са Delivered в момента, се демоутват автоматично на
		// всеки час — терминални → сторно, останалите → потенциални.
		// Никакво чакане на ръчна Сверка.
		$bad = $wpdb->get_results(
			'SELECT commission_id, order_id FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE status = 'approved' AND type = 'commission' AND order_id > 0
			 ORDER BY commission_id DESC LIMIT 300"
		);
		$st_map = proof_partner_order_statuses( wp_list_pluck( $bad, 'order_id' ) );
		foreach ( $bad as $r ) {
			$st = $st_map[ (int) $r->order_id ] ?? '';
			if ( proof_partner_status_is_won( $st ) ) continue;
			if ( in_array( $st, array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) {
				$this->reverse_full( $r->order_id );
				continue;
			}
			$wpdb->update( PP_Schema::table( 'commissions' ),
				array( 'status' => 'pending', 'note' => 'автосверка: поръчката не е Delivered', 'updated_at' => current_time( 'mysql' ) ),
				array( 'commission_id' => (int) $r->commission_id, 'status' => 'approved' )
			);
		}
	}

	/**
	 * Watchdog: поръчка НАПУСКА Delivered → живата ѝ комисиона се демоутва
	 * веднага. Терминален статус → сторно; всичко друго (връщане в
	 * processing, custom статуси) → обратно в pending = потенциална.
	 */
	public function status_watchdog( $order_id, $from, $to ) {
		global $wpdb;
		$won = proof_partner_won_statuses();
		if ( in_array( preg_replace( '/^wc-/', '', $to ), $won, true ) ) return; // влиза в Delivered — engine-ът си знае

		$live = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE order_id = %d AND type = 'commission' AND status IN ('pending','approved')",
			$order_id
		) );
		if ( ! $live ) return;

		// Всичко, което НЕ е won и НЕ е преходно (чакащи обработка/доставка),
		// е връщане — включително custom статуси от чужди плъгини.
		if ( ! proof_partner_status_is_transitional( $to ) ) {
			$this->reverse_full( $order_id ); // идемпотентно
			return;
		}

		$wpdb->query( $wpdb->prepare(
			'UPDATE ' . PP_Schema::table( 'commissions' ) . "
			 SET status = 'pending', updated_at = %s
			 WHERE order_id = %d AND type = 'commission' AND status IN ('pending','approved')",
			current_time( 'mysql' ), $order_id
		) );
		PP_Attribution::olog( $order_id, sprintf( 'Комисионата върната в „Изчакващи": поръчката напусна Delivered (%s → %s).', $from, $to ) );
	}
}
