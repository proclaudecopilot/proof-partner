<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Static helpers: create / map / lookup partner coupons.
 */
class PP_Coupons {

	/** Non-fatal warning from the last create_for_partner() call (typo-distance). */
	public static $last_warning = '';

	/** Case/symbol-insensitive canonical form: MAYA-PROOF ≡ mayaproof. */
	private static function normalize( $code ) {
		return strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $code ) );
	}

	/**
	 * №5 Collision guard.
	 * Hard block: a code whose normalized form equals another partner's code
	 * (two people whose codes differ only by case or symbols).
	 * Warning: Levenshtein distance 1 from another partner's code — one typo
	 * by a customer sends the commission to the wrong partner. Warns, does
	 * not block (distance-1 legit pairs exist, e.g. MAYAPROOF / MAYASPROOF).
	 */
	private static function collision_check( $code, $partner_id ) {
		global $wpdb;
		self::$last_warning = '';
		$norm = self::normalize( $code );

		$existing = $wpdb->get_results( 'SELECT coupon_code, partner_id FROM ' . PP_Schema::table( 'coupons' ) );
		foreach ( $existing as $e ) {
			if ( (int) $e->partner_id === (int) $partner_id ) continue;
			$enorm = self::normalize( $e->coupon_code );

			if ( $enorm === $norm ) {
				return new WP_Error( 'ap_collision', sprintf(
					'Code %s collides with existing code %s (partner #%d) — they differ only by case/symbols and WooCommerce treats them as the same coupon.',
					strtoupper( $code ), strtoupper( $e->coupon_code ), (int) $e->partner_id
				) );
			}
			if ( 1 === levenshtein( $norm, $enorm ) && '' === self::$last_warning ) {
				self::$last_warning = sprintf(
					'Внимание: %s е на една буква разстояние от %s (партньор #%d) — клиентска правописна грешка би пратила комисионата при грешния партньор.',
					strtoupper( $code ), strtoupper( $e->coupon_code ), (int) $e->partner_id
				);
			}
		}
		return true;
	}

	/* -------------------- №7 click counters (daily aggregate) ---------- */

	const CLICKS_OPT = 'proof_partner_clicks';

	/* ---------------- Политика v4: бял списък за комбиниране ----------- */

	/** Кодове, позволени в комбинация с партньорски (settings, wildcard '*'). */
	public static function allowed_with_partner_list() {
		static $list = null;
		if ( null !== $list ) return $list;
		$raw  = (string) ( proof_partner_settings()['allowed_with_partner'] ?? '' );
		$list = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $c ) {
			$c = wc_format_coupon_code( trim( $c ) );
			if ( '' !== $c ) $list[] = $c;
		}
		return $list;
	}

	public static function is_allowed_with_partner( $code ) {
		$code = wc_format_coupon_code( $code );
		if ( '' === $code ) return false;
		if ( self::get_mapping_by_code( $code ) ) return true; // партньорски кодове са си свои
		foreach ( self::allowed_with_partner_list() as $allowed ) {
			if ( substr( $allowed, -1 ) === '*' ) {
				if ( 0 === strpos( $code, rtrim( $allowed, '*' ) ) ) return true;
			} elseif ( $allowed === $code ) {
				return true;
			}
		}
		return false;
	}

	public static function count_click( $code ) {
		$code = wc_format_coupon_code( $code );
		$o    = get_option( self::CLICKS_OPT, array() );
		if ( ! is_array( $o ) ) $o = array();
		$d = current_time( 'Y-m-d' );
		if ( ! isset( $o[ $d ] ) ) $o[ $d ] = array();
		$o[ $d ][ $code ] = ( $o[ $d ][ $code ] ?? 0 ) + 1;
		if ( count( $o ) > 60 ) { ksort( $o ); $o = array_slice( $o, -60, null, true ); }
		update_option( self::CLICKS_OPT, $o, false );
	}

	public static function clicks_30d( $code ) {
		$code = wc_format_coupon_code( $code );
		$o    = get_option( self::CLICKS_OPT, array() );
		if ( ! is_array( $o ) ) return 0;
		$cut = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		$sum = 0;
		foreach ( $o as $day => $codes ) {
			if ( $day < $cut ) continue;
			$sum += (int) ( $codes[ $code ] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Create a WooCommerce coupon and map it to a partner.
	 * individual_use is OFF by design: non-partner promos (FunnelKit cart
	 * thresholds) СЕ стакват с партньорския код. Ексклузивност
	 * партньорски-срещу-партньорски пази server gate-ът (PP_Attribution::gate),
	 * не WC individual_use — той би блокирал и легитимния промо стакинг.
	 *
	 * @return int|WP_Error mapping row id
	 */
	public static function create_for_partner( $partner_id, $code, $discount_type = 'percent', $amount = 5, $allow_adopt = true ) {
		$partner_id = absint( $partner_id );
		$code       = wc_format_coupon_code( sanitize_text_field( $code ) );

		if ( ! $partner_id || ! PP_Partners::get( $partner_id ) ) return new WP_Error( 'ap_partner', 'Partner not found.' );
		if ( '' === $code ) return new WP_Error( 'ap_code', 'Coupon code is required.' );
		if ( ! in_array( $discount_type, array( 'percent', 'fixed_cart' ), true ) ) return new WP_Error( 'ap_type', 'Invalid discount type.' );

		$amount = (float) str_replace( ',', '.', (string) $amount );
		if ( $amount < 0 ) return new WP_Error( 'ap_amount', 'Invalid discount amount.' );
		if ( 'percent' === $discount_type && $amount > 100 ) return new WP_Error( 'ap_amount', 'Percent discount cannot exceed 100.' );

		$collision = self::collision_check( $code, $partner_id );
		if ( is_wp_error( $collision ) ) return $collision;

		// Existing WC coupon with this code?
		$existing_id = wc_get_coupon_id_by_code( $code );
		if ( $existing_id ) {
			if ( ! $allow_adopt ) {
				return new WP_Error( 'ap_exists', sprintf( 'Кодът %s вече съществува в магазина — избери друг.', strtoupper( $code ) ) );
			}
			// Adopt it (migration case: keep live AffWP codes working) but refuse if mapped to another partner.
			$mapped = self::get_mapping_by_code( $code );
			if ( $mapped && (int) $mapped->partner_id !== $partner_id ) {
				return new WP_Error( 'ap_mapped', sprintf( 'Coupon %s is already mapped to partner #%d.', $code, $mapped->partner_id ) );
			}
			$coupon = new WC_Coupon( $existing_id );
			$coupon->set_individual_use( false ); // gate-ът пази партньорската ексклузивност; промота стакват
			$coupon->update_meta_data( '_proof_partner_id', $partner_id );
			$coupon->save();
			if ( $mapped ) return (int) $mapped->id; // already fully mapped
			return self::insert_mapping( $code, $existing_id, $partner_id );
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->set_individual_use( false ); // gate-ът пази партньорската ексклузивност; промота стакват
		$coupon->update_meta_data( '_proof_partner_id', $partner_id );
		$wc_id = $coupon->save();

		if ( ! $wc_id ) return new WP_Error( 'ap_wc', 'WooCommerce coupon creation failed.' );
		return self::insert_mapping( $code, $wc_id, $partner_id );
	}

	private static function insert_mapping( $code, $wc_id, $partner_id ) {
		global $wpdb;
		$ok = $wpdb->insert( PP_Schema::table( 'coupons' ), array(
			'coupon_code'  => $code,
			'wc_coupon_id' => absint( $wc_id ),
			'partner_id'   => absint( $partner_id ),
			'active'       => 1,
			'created_at'   => current_time( 'mysql' ),
		) );
		if ( ! $ok ) return new WP_Error( 'ap_db', 'Mapping insert failed: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	public static function get_mapping_by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE coupon_code = %s',
			wc_format_coupon_code( $code )
		) );
	}

	public static function for_partner( $partner_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE partner_id = %d ORDER BY created_at ASC',
			absint( $partner_id )
		) );
	}

	public static function set_active( $mapping_id, $active ) {
		global $wpdb;
		return false !== $wpdb->update(
			PP_Schema::table( 'coupons' ),
			array( 'active' => $active ? 1 : 0, 'auto_paused' => 0 ),
			array( 'id' => absint( $mapping_id ) )
		);
	}

	/**
	 * Partner paused/archived: deactivate ALL their active coupons — mapping
	 * AND the WC coupon itself (draft), so the code stops giving discounts,
	 * not just commissions. auto_paused marks which ones to restore later;
	 * manually deactivated coupons are untouched and stay off after resume.
	 */
	public static function pause_partner_coupons( $partner_id ) {
		global $wpdb;
		$rows = self::for_partner( $partner_id );
		$n    = 0;
		foreach ( $rows as $c ) {
			if ( (int) $c->active !== 1 ) continue;
			$wpdb->update( PP_Schema::table( 'coupons' ),
				array( 'active' => 0, 'auto_paused' => 1 ),
				array( 'id' => $c->id )
			);
			if ( $c->wc_coupon_id && get_post( $c->wc_coupon_id ) ) {
				wp_update_post( array( 'ID' => (int) $c->wc_coupon_id, 'post_status' => 'draft' ) );
				self::flush_wc_code_cache( $c->coupon_code );
			}
			$n++;
		}
		return $n;
	}

	/**
	 * WC помни code → coupon ID в object cache (група 'coupons'). По-старите
	 * WC версии не го чистят при смяна на post_status през wp_update_post, и с
	 * persistent cache (Redis/Memcached) draft-нат купон продължава да се прилага.
	 */
	public static function flush_wc_code_cache( $code ) {
		if ( ! class_exists( 'WC_Cache_Helper' ) ) return;
		$prefix = WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_';
		foreach ( array_unique( array( (string) $code, wc_format_coupon_code( $code ), strtoupper( (string) $code ) ) ) as $k ) {
			wp_cache_delete( $prefix . $k, 'coupons' );
		}
	}

	/** Partner back to active: restore only the coupons WE paused. */
	public static function resume_partner_coupons( $partner_id ) {
		global $wpdb;
		$rows = self::for_partner( $partner_id );
		$n    = 0;
		foreach ( $rows as $c ) {
			if ( (int) $c->auto_paused !== 1 ) continue;
			$wpdb->update( PP_Schema::table( 'coupons' ),
				array( 'active' => 1, 'auto_paused' => 0 ),
				array( 'id' => $c->id )
			);
			if ( $c->wc_coupon_id && get_post( $c->wc_coupon_id ) ) {
				wp_update_post( array( 'ID' => (int) $c->wc_coupon_id, 'post_status' => 'publish' ) );
				self::flush_wc_code_cache( $c->coupon_code );
			}
			$n++;
		}
		return $n;
	}

	/**
	 * Resolve an order's coupon codes to a single (partner, coupon) attribution.
	 * individual_use should guarantee at most one mapped coupon; if data drift
	 * ever produces two, first active mapping wins and the anomaly is noted.
	 *
	 * @return array|null { partner_id, coupon_code, anomaly }
	 */
	public static function attribute_order( WC_Order $order ) {
		$codes = $order->get_coupon_codes();
		if ( empty( $codes ) ) return null;

		$hits = array();
		foreach ( $codes as $code ) {
			$map = self::get_mapping_by_code( $code );
			if ( $map && (int) $map->active === 1 ) $hits[] = $map;
		}
		if ( empty( $hits ) ) return null;

		return array(
			'partner_id'  => (int) $hits[0]->partner_id,
			'coupon_code' => $hits[0]->coupon_code,
			'anomaly'     => count( $hits ) > 1 ? 'multiple mapped coupons on order' : '',
		);
	}
}

/**
 * Runtime: partner links pre-apply the partner coupon.
 * Same single attribution path as typing the code — no cookies, no visit log.
 *
 * Link rules, explicit (№9 v3, след production тестове):
 *  - a ref is valid 48h from the click;
 *  - a newer ref click overwrites an older one (last click wins);
 *  - the ref REPLACES only a different PARTNER code; non-partner promos
 *    (FunnelKit cart thresholds) СЕ стакват с него —
 *    individual_use е изключен by design, gate-ът пази само
 *    партньорски-срещу-партньорски.
 *
 * №7: pretty links proofnutrition.eu/p/CODE (302 → /?ref=CODE) survive apps and
 * consent tools that strip query params; clicks are counted as daily
 * aggregates only — no visits table.
 * №8: for logged-in customers the last ref survives cross-device via user
 * meta (7-day TTL). Guests cannot cross devices without cookies — nobody
 * genuinely solves that, so we don't pretend to.
 */
class PP_Coupons_Runtime {

	const SESSION_KEY = 'proof_partner_ref';     // array{code,t} (legacy: plain string)
	const REF_TTL     = 48 * HOUR_IN_SECONDS;   // №9
	const USER_META   = '_proof_partner_ref';    // №8
	const USER_TTL    = 7 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'vanity_redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'selftest' ), 0 );
		add_action( 'template_redirect', array( $this, 'legacy_portal_redirect' ), 2 );

		add_action( 'woocommerce_add_to_cart', array( $this, 'apply_pending_ref' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'apply_pending_ref' ), 5 );

		// Cache-proof път: кешът може да сервира замразен HTML, но JS-ът в
		// browser-а чете ?ref= и стреля wc-ajax (frontend-нативна сесия,
		// същият канал като add-to-cart). admin-ajax остава като fallback.
		add_action( 'wc_ajax_proof_partner_ref', array( $this, 'ajax_ref' ) );
		add_action( 'wp_ajax_proof_partner_ref', array( $this, 'ajax_ref' ) );
		add_action( 'wp_ajax_nopriv_proof_partner_ref', array( $this, 'ajax_ref' ) );

		add_action( 'wp_footer', array( $this, 'print_flash' ), 5 );
		add_action( 'wp_footer', array( $this, 'footer_js' ), 6 );
	}

	/* ------------------------------------------------------------------ */
	/* Flash toasts — рендират се на ВСЯКА страница през wp_footer,        */
	/* защото WC notices не се показват извън WC страниците (Elementor     */
	/* начална = черна дупка за съобщения).                                */
	/* ------------------------------------------------------------------ */

	const FLASH = 'proof_partner_flash';

	public static function flash( $msg, $type = 'ok', $admin_only = false ) {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) return;
		$q   = (array) WC()->session->get( self::FLASH, array() );
		$q[] = array( 'm' => $msg, 't' => $type, 'a' => $admin_only ? 1 : 0 );
		WC()->session->set( self::FLASH, array_slice( $q, -4 ) );
	}

	public function print_flash() {
		if ( proof_partner_safe_mode() ) return;
		if ( is_admin() || ! function_exists( 'WC' ) || null === WC()->session ) return;
		$q = (array) WC()->session->get( self::FLASH, array() );
		if ( empty( $q ) ) return;
		WC()->session->__unset( self::FLASH );

		$is_admin_user = current_user_can( 'manage_woocommerce' );
		$out = '';
		foreach ( $q as $f ) {
			if ( ! empty( $f['a'] ) && ! $is_admin_user ) continue;
			$bg  = 'err' === $f['t'] ? '#d64545' : ( 'info' === $f['t'] ? '#f8a41d' : '#3bb273' );
			$out .= '<div style="background:' . $bg . ';color:#fff;font:700 14px/1.45 Nunito,-apple-system,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(560px,92vw);pointer-events:auto">'
				. wp_kses( $f['m'], array( 'strong' => array(), 'code' => array() ) ) . '</div>';
		}
		if ( '' === $out ) return;

		echo '<div id="proof-partner-flash" style="position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:999999;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none">' . $out . '</div>';
		echo '<script>setTimeout(function(){var e=document.getElementById("proof-partner-flash");if(e){e.style.transition="opacity .5s";e.style.opacity="0";setTimeout(function(){e.remove()},600);}},7000);</script>';
	}

	/* ---------------------- №7 vanity links --------------------------- */

	public function rewrite() {
		add_rewrite_rule( '^p/([A-Za-z0-9\-_]+)/?$', 'index.php?proof_partner_ref=$matches[1]', 'top' );
		add_rewrite_rule( '^join/([A-Za-z0-9\-_]+)/?$', 'index.php?proof_partner_join=$matches[1]', 'top' );
		// Legacy АffWP формати (/hi/КОД …): старите био линкове живеят вечно.
		foreach ( preg_split( '/[\s,]+/', (string) ( proof_partner_settings()['legacy_link_prefixes'] ?? '' ) ) as $pre ) {
			$pre = sanitize_key( $pre );
			if ( '' === $pre || 'p' === $pre ) continue;
			add_rewrite_rule( '^' . $pre . '/([A-Za-z0-9\-_]+)/?$', 'index.php?proof_partner_ref=$matches[1]', 'top' );
		}
	}

	/** Стар AffWP портал (напр. /affiliate-area/) → новият /partner. 301 завинаги. */
	public function legacy_portal_redirect() {
		$slug = trim( (string) ( proof_partner_settings()['legacy_portal_slug'] ?? '' ), "/ \t" );
		if ( '' === $slug ) return;
		$path = trim( (string) wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ), '/' );
		if ( $path === $slug || 0 === strpos( $path . '/', $slug . '/' ) ) {
			wp_safe_redirect( home_url( '/partner' ), 301 );
			exit;
		}
	}

	public function query_vars( $vars ) {
		$vars[] = 'proof_partner_ref';
		$vars[] = 'proof_partner_join';
		return $vars;
	}

	public function vanity_redirect() {
		$join = get_query_var( 'proof_partner_join' );
		if ( $join ) {
			header( 'X-LiteSpeed-Cache-Control: public, max-age=3600' );
			wp_safe_redirect( add_query_arg( 'join', rawurlencode( sanitize_text_field( $join ) ), home_url( '/partner' ) ), 302 );
			exit;
		}
		$code = get_query_var( 'proof_partner_ref' );
		if ( ! $code ) return;
		// Redirect-ът е детерминистичен (код → същият URL) → кешируем.
		// Целият vanity path става: кеширан redirect + кеширана страница.
		header( 'X-LiteSpeed-Cache-Control: public, max-age=3600' );
		wp_safe_redirect( add_query_arg( 'ref', rawurlencode( sanitize_text_field( $code ) ), home_url( '/' ) ), 302 );
		exit;
	}

	/* ---------------------- capture ----------------------------------- */

	/* ------------------------------------------------------------------ */
	/* Ядро: регистрация на ref — общо за server path и AJAX path          */
	/* ------------------------------------------------------------------ */

	/** @return array{ok:bool, applied?:bool, message?:string, debug?:string} */
	public function register_ref( $raw_code ) {
		$code = wc_format_coupon_code( sanitize_text_field( (string) $raw_code ) );
		if ( '' === $code ) return array( 'ok' => false, 'debug' => 'Празен ref код.' );

		$map = PP_Coupons::get_mapping_by_code( $code );
		if ( ! $map ) {
			return array( 'ok' => false, 'debug' => sprintf( 'Кодът <code>%s</code> НЕ е регистриран в Proof Partner — създай го от партньорския детайл или мини импорта.', strtoupper( $code ) ) );
		}
		if ( (int) $map->active !== 1 ) {
			return array( 'ok' => false, 'debug' => sprintf( 'Кодът <code>%s</code> е спрян в плъгина.', strtoupper( $code ) ) );
		}
		$partner = PP_Partners::get( $map->partner_id );
		if ( ! $partner || 'active' !== $partner->status ) {
			return array( 'ok' => false, 'debug' => sprintf( 'Партньорът на <code>%s</code> е със статус "%s" — линкът работи само за активни.', strtoupper( $code ), $partner ? $partner->status : 'липсва' ) );
		}
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return array( 'ok' => false, 'debug' => 'WooCommerce сесията липсва в този контекст.' );
		}

		if ( ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );

		$prev = $this->session_ref();
		if ( $prev && $prev !== $code ) {
			PP_Attribution::trail( sprintf( 'Нов линк %s презаписа %s (last click wins).', strtoupper( $code ), strtoupper( $prev ) ) );
		}
		WC()->session->set( self::SESSION_KEY, array( 'code' => $code, 't' => time() ) );

		// Клик броене с dedupe (server path + AJAX path не бива да броят двойно).
		$ck = 'proof_ref_counted_' . $code;
		if ( ! WC()->session->get( $ck ) ) {
			PP_Coupons::count_click( $code );
			proof_partner_feed_push( 'click', sprintf( 'Клик на %s (линк)', strtoupper( $code ) ) );
			WC()->session->set( $ck, time() );
			PP_Attribution::trail( sprintf( 'Партньорски линк уловен (?ref=%s).', strtoupper( $code ) ) );
		}

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META, array( 'code' => $code, 't' => time() ) );
		}

		$this->apply_pending_ref();
		$applied = function_exists( 'WC' ) && WC()->cart && WC()->cart->has_discount( $code );
		$first   = esc_html( current( explode( ' ', $partner->display_name ) ) );

		return array(
			'ok'      => true,
			'applied' => $applied,
			'message' => $applied
				? sprintf( 'Пазаруваш с кода на <strong>%s</strong> — <code>%s</code> е активен ✓', $first, strtoupper( $code ) )
				: sprintf( 'Отстъпката на <strong>%s</strong> те чака — добави продукт и кодът <code>%s</code> се прилага сам ✓', $first, strtoupper( $code ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Server path (некеширани заявки)                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * ИЗВЕДЕН ОТ УПОТРЕБА (v3.9.2): server-side捕 улавянето принуждаваше
	 * no-cache на цялата страница → всеки партньорски клик = пълен PHP
	 * render (секунди) вместо кеш hit (милисекунди). JS shim-ът + wc-ajax
	 * вършат същата работа върху КЕШИРАНА страница. Методът стои само за
	 * съвместимост, ако някой го викне външно.
	 */
	public function capture_ref() {
		if ( proof_partner_safe_mode() ) return;
		if ( empty( $_GET['ref'] ) || is_admin() ) return;
		if ( ! function_exists( 'WC' ) || null === WC()->session ) return;

		$r = $this->register_ref( wp_unslash( $_GET['ref'] ) );

		if ( ! $r['ok'] ) {
			if ( current_user_can( 'manage_woocommerce' ) && WC()->session ) {
				if ( ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );
				self::flash( '[дебъг, виждаш го само ти] ' . $r['debug'], 'err', true );
			}
			return;
		}
		if ( empty( $r['applied'] ) ) {
			self::flash( $r['message'], 'info' ); // изчаква добавяне в количката
		}
		// При applied: apply_pending_ref вече е пуснал зеления flash.
	}

	/* ------------------------------------------------------------------ */
	/* AJAX path — работи и през най-агресивния page cache                 */
	/* ------------------------------------------------------------------ */

	public function ajax_ref() {
		nocache_headers();
		if ( proof_partner_safe_mode() ) wp_send_json( array( 'ok' => false, 'safe_mode' => true ) );

		// Rate limit: публичен endpoint без auth → 30 заявки/мин на IP стигат
		// за всеки легитимен сценарий и убиват flood/скрейп опитите.
		$key = 'ap_ref_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 30 ) wp_send_json( array( 'ok' => false ) );
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		$r = $this->register_ref( wp_unslash( $_POST['code'] ?? '' ) );

		// Toast-ът се показва веднага от JS — чистим queue-то, за да не се
		// дублира на следващия page load.
		if ( function_exists( 'WC' ) && WC()->session ) WC()->session->__unset( self::FLASH );

		$payload = array( 'ok' => (bool) $r['ok'], 'applied' => ! empty( $r['applied'] ) );
		if ( ! empty( $r['message'] ) ) $payload['message'] = $r['message'];
		if ( ! $r['ok'] && current_user_can( 'manage_woocommerce' ) ) $payload['debug'] = '[дебъг, виждаш го само ти] ' . $r['debug'];

		// Дебъг на състоянието — отговаря на въпроса "с ЧИЯ количка говорим".
		if ( current_user_can( 'manage_woocommerce' ) && function_exists( 'WC' ) && WC()->cart ) {
			$items   = count( WC()->cart->get_cart() );
			$coupons = WC()->cart->get_applied_coupons();
			$split   = ( 0 === $items && ! empty( $_COOKIE['woocommerce_items_in_cart'] ) )
				? ' · ⚠ browser-ът носи items-in-cart cookie, а тази количка е ПРАЗНА → сесиен split (кеш/домейн/www)'
				: '';
			$payload['debug_state'] = sprintf(
				'[дебъг] сървърът вижда: %d арт. · купони: [%s] · session cookie: %s%s',
				$items,
				implode( ', ', array_map( 'strtoupper', $coupons ) ) ?: '—',
				! empty( $_COOKIE ) && WC()->session && WC()->session->has_session() ? 'да' : 'НЕ',
				$split
			);
		}

		wp_send_json( $payload );
	}

	/** Мини JS shim на всяка front страница: чете ?ref=, стреля AJAX, показва toast. */
	public function footer_js() {
		if ( is_admin() || proof_partner_safe_mode() ) return;
		$ajax = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
<script>
(function () {
	var m = location.search.match(/[?&]ref=([^&#]+)/);
	if (!m) return;
	var code = decodeURIComponent(m[1]);
	var fd = new FormData();
	fd.append('action', 'proof_partner_ref');
	fd.append('code', code);

	function toast(msg, bg) {
		var el = document.createElement('div');
		el.style.cssText = 'position:relative;background:' + bg + ';color:#fff;font:700 14px/1.45 Nunito,-apple-system,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(560px,92vw);margin-top:8px';
		el.innerHTML = msg;
		var box = document.getElementById('proof-partner-jsflash');
		if (!box) {
			box = document.createElement('div');
			box.id = 'proof-partner-jsflash';
			box.style.cssText = 'position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:999999;display:flex;flex-direction:column;align-items:center';
			document.body.appendChild(box);
		}
		box.appendChild(el);
		setTimeout(function () { el.style.transition = 'opacity .5s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 600); }, 9000);
	}

	function handle(d) {
		if (d.message || d.debug) toast(d.message || d.debug, d.ok ? (d.applied ? '#3bb273' : '#f8a41d') : '#d64545');
		if (d.debug_state) toast(d.debug_state, '#43302a');
		if (d.ok && window.history && history.replaceState) {
			var u = new URL(location.href); u.searchParams.delete('ref'); history.replaceState(null, '', u.toString());
		}
		if (d.applied && window.jQuery) { jQuery(document.body).trigger('wc_fragment_refresh'); }
	}

	// Първо wc-ajax (frontend-нативна сесия, relative URL → винаги same-origin
	// със страницата, никакъв www/без-www капан). Fallback: admin-ajax.
	fetch('/?wc-ajax=proof_partner_ref', { method: 'POST', body: fd, credentials: 'same-origin' })
		.then(function (r) { if (!r.ok) throw 0; return r.json(); })
		.then(handle)
		.catch(function () {
			fetch('<?php echo $ajax; ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(handle)
				.catch(function () {});
		});
})();
</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Селфтест: proofnutrition.eu/?proof_partner_selftest=КОД (само за админ)        */
	/* ------------------------------------------------------------------ */

	public function selftest() {
		if ( ! isset( $_GET['proof_partner_selftest'] ) ) return;
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		nocache_headers();

		$code = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_GET['proof_partner_selftest'] ) ) );
		$L    = array();
		$L[]  = 'Proof Partner selftest · v' . PROOF_PARTNER_VERSION . ' · ' . current_time( 'mysql' );
		$L[]  = 'PHP стигнат: ДА (тази страница е жив отговор, не кеш)';
		$L[]  = 'LiteSpeed Cache плъгин: ' . ( defined( 'LSCWP_V' ) ? 'ДА (v' . LSCWP_V . ')' : 'не е засечен' );
		$L[]  = 'WC session: ' . ( function_exists( 'WC' ) && WC()->session ? 'OK' : 'ЛИПСВА' );
		$L[]  = 'WC cart: ' . ( function_exists( 'WC' ) && WC()->cart ? count( WC()->cart->get_cart() ) . ' артикула, купони: [' . implode( ', ', WC()->cart->get_applied_coupons() ) . ']' : 'ЛИПСВА' );
		$L[]  = 'Бял списък (комбинират се с партньорски): [' . strtoupper( implode( ', ', PP_Coupons::allowed_with_partner_list() ) ) . ']';
		$L[]  = '';

		if ( '' !== $code ) {
			$map = PP_Coupons::get_mapping_by_code( $code );
			$L[] = 'Код "' . strtoupper( $code ) . '":';
			$L[] = '  mapping в плъгина: ' . ( $map ? 'ДА (partner #' . $map->partner_id . ', active=' . $map->active . ')' : 'НЕ — това е причината линкът да не прави нищо' );
			if ( $map ) {
				$p   = PP_Partners::get( $map->partner_id );
				$L[] = '  партньор: ' . ( $p ? $p->display_name . ' (статус: ' . $p->status . ')' : 'ЛИПСВА' );
				$wc_id = function_exists( 'wc_get_coupon_id_by_code' ) ? wc_get_coupon_id_by_code( $code ) : 0;
				$L[]   = '  WC купон: ' . ( $wc_id ? '#' . $wc_id : 'ЛИПСВА' );
				if ( $wc_id ) {
					$c   = new WC_Coupon( $wc_id );
					$L[] = '  individual_use: ' . ( $c->get_individual_use() ? 'ВКЛЮЧЕН (би блокирал стакинг с промо кодовете от белия списък!)' : 'изключен ✓' );
					$L[] = '  мин. сума: ' . ( $c->get_minimum_amount() ?: '—' ) . ' · валиден до: ' . ( $c->get_date_expires() ? $c->get_date_expires()->date( 'Y-m-d' ) : '—' ) . ' · usage: ' . $c->get_usage_count() . '/' . ( $c->get_usage_limit() ?: '∞' );
				}
			}
			$L[] = '';
			$L[] = 'Жив опит за регистрация на ref:';
			$r   = $this->register_ref( $code );
			$L[] = '  → ' . ( $r['ok'] ? ( 'OK, applied=' . ( empty( $r['applied'] ) ? 'не (количката е празна?)' : 'ДА' ) ) : 'ОТКАЗ: ' . wp_strip_all_tags( $r['debug'] ) );
		} else {
			$L[] = 'Подай код: ?proof_partner_selftest=IVANPROOF';
		}

		$L[] = '';
		$L[] = 'AJAX endpoint: ' . admin_url( 'admin-ajax.php' ) . ' (action=proof_partner_ref) — този път не се кешира никога.';

		wp_die( '<pre style="font:13px/1.6 monospace;padding:20px">' . esc_html( implode( "\n", $L ) ) . '</pre>', 'Proof Partner selftest', array( 'response' => 200 ) );
	}

	/** Session ref code with TTL enforcement; handles legacy string format. */
	private function session_ref() {
		$v = WC()->session ? WC()->session->get( self::SESSION_KEY ) : null;
		if ( ! $v ) return '';
		if ( is_string( $v ) ) return wc_format_coupon_code( $v ); // legacy, no TTL info — honor it
		$code = wc_format_coupon_code( (string) ( $v['code'] ?? '' ) );
		$t    = (int) ( $v['t'] ?? 0 );
		if ( '' === $code ) return '';
		if ( $t && time() - $t > self::REF_TTL ) {
			WC()->session->__unset( self::SESSION_KEY );
			PP_Attribution::trail( sprintf( 'Ref код %s изтече (48ч от клика).', strtoupper( $code ) ) );
			return '';
		}
		return $code;
	}

	public function apply_pending_ref() {
		if ( proof_partner_safe_mode() ) return;
		if ( ! function_exists( 'WC' ) || null === WC()->session || null === WC()->cart ) return;

		$code = $this->session_ref();

		// №8: no session ref → try the logged-in user's persisted ref.
		if ( '' === $code && is_user_logged_in() ) {
			$um = get_user_meta( get_current_user_id(), self::USER_META, true );
			if ( is_array( $um ) && ! empty( $um['code'] ) && time() - (int) ( $um['t'] ?? 0 ) <= self::USER_TTL ) {
				$code = wc_format_coupon_code( $um['code'] );
				$map  = PP_Coupons::get_mapping_by_code( $code );
				if ( $map && (int) $map->active === 1 ) {
					WC()->session->set( self::SESSION_KEY, array( 'code' => $code, 't' => time() ) );
					PP_Attribution::trail( sprintf( 'Ref код %s възстановен от профила (друго устройство).', strtoupper( $code ) ) );
				} else {
					delete_user_meta( get_current_user_id(), self::USER_META );
					$code = '';
				}
			}
		}

		if ( '' === $code || WC()->cart->is_empty() ) return;

		if ( WC()->cart->has_discount( $code ) ) {
			WC()->session->__unset( self::SESSION_KEY );
			return;
		}

		// Политика v4: ref кодът съжителства с белия списък (промо кодовете и
		// избраните от админа); замества друг партньорски код (last click
		// wins) и помита чуждите кодове извън списъка.
		$removed = array();
		foreach ( WC()->cart->get_applied_coupons() as $ac ) {
			$acn = wc_format_coupon_code( $ac );
			if ( $acn === $code ) continue;
			if ( PP_Coupons::get_mapping_by_code( $acn ) || ! PP_Coupons::is_allowed_with_partner( $acn ) ) {
				WC()->cart->remove_coupon( $ac );
				$removed[] = strtoupper( $ac );
			}
		}
		if ( $removed ) {
			PP_Attribution::trail( sprintf( 'Ref код %s замени/премахна: %s (белият списък оцелява).', strtoupper( $code ), implode( ', ', $removed ) ) );
		}

		WC()->session->set( PP_Attribution::SESSION_LINK, $code ); // source = link, before apply
		$applied = WC()->cart->apply_coupon( $code );
		WC()->session->__unset( self::SESSION_KEY );

		if ( $applied && WC()->cart->has_discount( $code ) ) {
			$map = PP_Coupons::get_mapping_by_code( $code );
			$p   = $map ? PP_Partners::get( $map->partner_id ) : null;
			if ( $p ) self::flash( sprintf( 'Пазаруваш с кода на <strong>%s</strong> — <code>%s</code> е активен ✓', esc_html( current( explode( ' ', $p->display_name ) ) ), strtoupper( $code ) ) );
			if ( is_user_logged_in() ) delete_user_meta( get_current_user_id(), self::USER_META );
		} elseif ( current_user_can( 'manage_woocommerce' ) ) {
			// WC отказа купона (мин. сума, usage limit, изтекъл срок…) — причината за админа.
			$why = array();
			foreach ( (array) wc_get_notices( 'error' ) as $n ) { $why[] = is_array( $n ) ? wp_strip_all_tags( $n['notice'] ?? '' ) : wp_strip_all_tags( (string) $n ); }
			self::flash( sprintf( '[дебъг] WooCommerce отказа <code>%s</code>: %s', strtoupper( $code ), $why ? implode( ' · ', array_filter( $why ) ) : 'без посочена причина — провери ограниченията на купона (мин. сума, срок, usage limit)' ), 'err', true );
		}
	}
}
