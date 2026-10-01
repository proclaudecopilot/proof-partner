<?php
/**
 * Proof Partner · Proof-специфичното (1.0.0)
 *
 * 1. EPACK/Combo купони: партньорските кодове минават през белия списък на EPACK
 *    (`pshrine_epack_allowed_coupons`) — иначе Shrine ги маха от количката.
 * 2. Правила за пакети (`pack_rules`): per пакет — 'fixed' (€ за поръчка) или 'pct' (% върху
 *    пакетната част), и дали клиентът получава отстъпка от кода (discount 0/1). Пакетът се
 *    разпознава по line item meta `_pshrine_ins_scope` = 'pack:{id}' (Shrine Insights) или по
 *    order meta `_pshrine_combo_snapshot` (Кутиите → ключ 'combo').
 * 3. Sublium подновявания (`renewal_mode`): none | first_n | forever. Подновяването няма купон при
 *    checkout → engine-ът пита `proof_partner_fallback_attribution`; тук намираме родителската
 *    поръчка (lock-а ѝ) и решаваме дали k-тото подновяване носи комисиона.
 * 4. Импорт на съществуващи WC купони (Advanced Coupons) като партньорски кодове — REST.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Proof {

	const RENEWAL_META = '_proof_partner_renewal'; /* върху renewal поръчката: ['sub'=>id,'parent'=>id,'k'=>n] */

	public function __construct() {
		add_filter( 'pshrine_epack_allowed_coupons', array( $this, 'allow_partner_codes' ) );
		/* 2.0: отстъпката за клиента и сметката по оферти са в PP_Offers (class-pp-offers.php); maybe_zero_discount/calc остават само за smoke тестовете */
		add_filter( 'proof_partner_fallback_attribution', array( $this, 'renewal_attribution' ), 10, 2 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'shipment_status_sync' ), 30, 3 ); /* служебните пратки следят статуса на поръчката */
	}

	/* ── настройки ── */
	public static function rules() {
		$r = proof_partner_settings()['pack_rules'] ?? array();
		return is_array( $r ) ? $r : array();
	}
	public static function rule_for( $scope ) {
		$r = self::rules();
		return isset( $r[ $scope ] ) && is_array( $r[ $scope ] ) ? $r[ $scope ] : null;
	}
	/* Кои пакети съществуват (за настройките): EPACK офертите + Кутиите */
	public static function pack_catalog() {
		$out = array();
		if ( class_exists( 'PShrine_Epack' ) && method_exists( 'PShrine_Epack', 'packs' ) ) {
			foreach ( (array) PShrine_Epack::packs() as $id => $c ) {
				$out[] = array( 'scope' => 'pack:' . $id, 'name' => (string) ( $c['name'] ?? $id ), 'archived' => ! empty( $c['archived'] ) );
			}
		}
		if ( class_exists( 'PShrine_Combo_Cart' ) ) $out[] = array( 'scope' => 'pack:combo', 'name' => 'Кутии (Протеин + Креатин)', 'archived' => false );
		return $out;
	}

	/* ── 1. EPACK бял списък ── */
	public function allow_partner_codes( $allowed ) {
		global $wpdb;
		$codes = $wpdb->get_col( 'SELECT coupon_code FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE active = 1' );
		foreach ( (array) $codes as $c ) $allowed[] = strtolower( $c );
		return array_values( array_unique( (array) $allowed ) );
	}

	/* ── 2. Отстъпка за клиента върху пакетни редове: само ако правилото го позволява ── */
	public function maybe_zero_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! is_array( $cart_item ) || ! $coupon instanceof WC_Coupon ) return $discount;
		if ( ! PP_Coupons::get_mapping_by_code( $coupon->get_code() ) ) return $discount;
		$scope = self::cart_item_scope( $cart_item );
		if ( '' === $scope ) return $discount;
		$rule = self::rule_for( $scope );
		if ( $rule && empty( $rule['discount'] ) ) return 0;
		return $discount;
	}
	public static function cart_item_scope( $ci ) {
		if ( ! empty( $ci['pshrine_epack'] ) ) { $p = sanitize_key( $ci['pshrine_epack_pack'] ?? '' ); return 'pack:' . ( $p ?: 'essentials' ); }
		return '';
	}

	/* ── пакетната част на поръчката: scope → base (по логиката на commission_base) ── */
	public static function order_scopes( WC_Order $order ) {
		$s = proof_partner_settings(); $include_vat = ! empty( $s['include_vat'] );
		$excluded = proof_partner_excluded_product_ids();
		$combo = (bool) $order->get_meta( '_pshrine_combo_snapshot' );
		$by = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pid = (int) $item->get_product_id(); $vid = (int) $item->get_variation_id();
			if ( in_array( $pid, $excluded, true ) || ( $vid && in_array( $vid, $excluded, true ) ) ) continue;
			$scope = (string) $item->get_meta( '_pshrine_ins_scope' );
			if ( '' === $scope && $combo ) $scope = 'pack:combo';
			if ( '' === $scope ) $scope = '_regular';
			$line_ex = (float) $item->get_total(); $refund_ex = (float) $order->get_total_refunded_for_item( $item_id );
			$net = $line_ex - $refund_ex; if ( $net <= 0 ) continue;
			if ( $include_vat ) { $ratio = $line_ex > 0 ? ( (float) $item->get_total_tax() ) / $line_ex : 0.0; $net *= ( 1 + $ratio ); }
			$by[ $scope ] = round( ( $by[ $scope ] ?? 0 ) + $net, 2 );
		}
		return $by;
	}

	/* ── 2. Сметката: пакетна част по правилото + останалото по ставката ── */
	public function calc( $calc, $order, $partner, $rate, $base, $attr ) {
		if ( ! $order instanceof WC_Order ) return $calc;
		$by = self::order_scopes( $order );
		$rules = self::rules();
		$has_rule = false;
		foreach ( $by as $scope => $b ) { if ( '_regular' !== $scope && isset( $rules[ $scope ] ) ) { $has_rule = true; break; } }
		if ( ! $has_rule ) return $calc;
		$amount = 0.0; $notes = array();
		foreach ( $by as $scope => $b ) {
			$rule = '_regular' !== $scope ? ( $rules[ $scope ] ?? null ) : null;
			if ( $rule && 'fixed' === ( $rule['mode'] ?? '' ) ) { $v = (float) ( $rule['value'] ?? 0 ); $amount += $v; $notes[] = $scope . ' фикс ' . number_format( $v, 2 ) . '€'; }
			elseif ( $rule && 'pct' === ( $rule['mode'] ?? '' ) ) { $v = (float) ( $rule['value'] ?? 0 ); $amount += $b * $v / 100; $notes[] = $scope . ' ' . rtrim( rtrim( number_format( $v, 2 ), '0' ), '.' ) . '% от ' . number_format( $b, 2 ); }
			else { $amount += $b * $rate / 100; if ( $b > 0 ) $notes[] = ( '_regular' === $scope ? 'останало' : $scope ) . ' ' . rtrim( rtrim( number_format( $rate, 2 ), '0' ), '.' ) . '% от ' . number_format( $b, 2 ); }
		}
		$amount = round( $amount, 2 );
		return array( 'amount' => $amount, 'rate' => $base > 0 ? round( $amount / $base * 100, 3 ) : $rate, 'note' => '[пакетно правило: ' . implode( ' + ', $notes ) . ']' );
	}

	/* ── 3. Sublium подновявания ── */
	public static function is_renewal( WC_Order $order ) {
		$sub = (int) $order->get_meta( '_sublium_subscription_id' );
		if ( ! $sub ) return 0;
		/* родителската поръчка също може да носи меtата — подновяване е само ако има ПО-РАННА поръчка със същия абонамент */
		return $sub;
	}
	/* Поръчките на абонамента, най-старата първа */
	public static function subscription_orders( $sub_id, $exclude_id = 0 ) {
		$ids = wc_get_orders( array( 'limit' => 50, 'orderby' => 'date', 'order' => 'ASC', 'return' => 'ids', 'meta_key' => '_sublium_subscription_id', 'meta_value' => (string) $sub_id, 'type' => 'shop_order' ) );
		return array_values( array_filter( array_map( 'intval', (array) $ids ), function ( $i ) use ( $exclude_id ) { return $i !== (int) $exclude_id; } ) );
	}
	public static function parent_lock( WC_Order $order, $sub_id ) {
		$lock = null; $parent = 0;
		foreach ( array( '_sublium_parent_order_id', '_sublium_original_order_id', '_parent_order_id' ) as $mk ) {
			$pid = (int) $order->get_meta( $mk );
			if ( $pid && ( $po = wc_get_order( $pid ) ) ) { $l = $po->get_meta( PP_Attribution::META_LOCK ); if ( is_array( $l ) && ! empty( $l['partner_id'] ) ) { return array( $pid, $l ); } }
		}
		$older = array();
		foreach ( self::subscription_orders( $sub_id, $order->get_id() ) as $oid ) {
			$po = wc_get_order( $oid ); if ( ! $po ) continue;
			if ( $po->get_date_created() && $order->get_date_created() && $po->get_date_created() > $order->get_date_created() ) continue;
			$older[] = $oid;
			$l = $po->get_meta( PP_Attribution::META_LOCK );
			if ( is_array( $l ) && ! empty( $l['partner_id'] ) && ! $lock ) { $lock = $l; $parent = $oid; }
		}
		if ( ! $older ) return array( 0, null ); /* няма по-стара поръчка → това е първата, не подновяване */
		return array( $parent, $lock );
	}
	public function renewal_attribution( $attr, $order ) {
		if ( $attr || ! $order instanceof WC_Order ) return $attr;
		$s = proof_partner_settings(); $mode = (string) ( $s['renewal_mode'] ?? 'none' );
		$sub = self::is_renewal( $order ); if ( ! $sub ) return $attr;
		list( $parent, $lock ) = self::parent_lock( $order, $sub );
		if ( ! $parent || ! $lock ) return $attr;
		/* 2.0: офертните правила на родителя могат да включат подновяване дори при глобално „никога" — PP_Offers::calc решава по редове */
		$offer_ren = false;
		if ( class_exists( 'PP_Offers' ) && ( $po = wc_get_order( $parent ) ) ) { $snap = $po->get_meta( PP_Offers::ORDER_RULES ); if ( is_array( $snap ) && ! empty( $snap['rules'] ) ) foreach ( $snap['rules'] as $fr ) { $rm = $fr['rule']['ren']['mode'] ?? 'inherit'; if ( in_array( $rm, array( 'first_n', 'forever' ), true ) ) { $offer_ren = true; break; } } }
		if ( 'none' === $mode && ! $offer_ren ) return $attr;
		$partner = PP_Partners::get( (int) $lock['partner_id'] );
		if ( ! $partner || 'active' !== $partner->status ) { PP_Attribution::olog( $order, 'Подновяване: партньорът на родителската поръчка не е активен — без комисиона.' ); return $attr; }
		/* k = поредност на подновяването: колко по-ранни подновявания на този абонамент вече са с комисиона */
		global $wpdb;
		$ids = self::subscription_orders( $sub, $order->get_id() );
		$k = 1;
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$k = 1 + (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . PP_Schema::table( 'commissions' ) . " WHERE type = 'commission' AND order_id IN ($in) AND order_id <> " . (int) $parent );
		}
		$n = max( 1, (int) ( $s['renewal_n'] ?? 3 ) );
		if ( 'first_n' === $mode && $k > $n && ! $offer_ren ) { PP_Attribution::olog( $order, sprintf( 'Подновяване №%d > лимит %d — без комисиона.', $k, $n ) ); $order->update_meta_data( self::RENEWAL_META, array( 'sub' => $sub, 'parent' => $parent, 'k' => $k, 'paid' => 0 ) ); $order->save(); return $attr; }
		$rr = trim( (string) ( $s['renewal_rate'] ?? '' ) );
		$rate = '' !== $rr && is_numeric( $rr ) ? (float) $rr : ( isset( $lock['rate'] ) && null !== $lock['rate'] ? (float) $lock['rate'] : null );
		$order->update_meta_data( self::RENEWAL_META, array( 'sub' => $sub, 'parent' => $parent, 'k' => $k, 'paid' => 1 ) ); $order->save();
		PP_Attribution::olog( $order, sprintf( 'Подновяване №%d на абонамент #%d (родител #%d) → %s.', $k, $sub, $parent, $partner->display_name ) );
		return array( 'partner_id' => (int) $lock['partner_id'], 'coupon_code' => (string) ( $lock['coupon_code'] ?? '' ), 'anomaly' => '', 'rate' => $rate, 'note' => sprintf( '[подновяване №%d · абонамент #%d · родител #%d]', $k, $sub, $parent ) , 'renewal' => array( 'k' => $k, 'parent' => $parent, 'sub' => $sub ) );
	}

	/* ── 4. REST: правила + импорт на купони ── */
	public function routes() {
		$p = array( 'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); } );
		register_rest_route( PP_Rest::NS, '/proof/settings', array( 'methods' => 'GET', 'callback' => array( $this, 'settings_get' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/settings', array( 'methods' => 'POST', 'callback' => array( $this, 'settings_save' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/import/coupons', array( 'methods' => 'GET', 'callback' => array( $this, 'import_list' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/import/adopt', array( 'methods' => 'POST', 'callback' => array( $this, 'import_adopt' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/coupons/suggest', array( 'methods' => 'GET', 'callback' => array( $this, 'coupons_suggest' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/invoices/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'invoices_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'invoices_add' ) ) + $p,
		) );
		register_rest_route( PP_Rest::NS, '/proof/invoices/(?P<id>\d+)/(?P<inv>\d+)', array(
			array( 'methods' => 'POST', 'callback' => array( $this, 'invoices_update' ) ) + $p,
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'invoices_delete' ) ) + $p,
		) );
		register_rest_route( PP_Rest::NS, '/proof/shipments/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'shipments_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'shipments_add' ) ) + $p,
		) );
		register_rest_route( PP_Rest::NS, '/proof/shipments/(?P<id>\d+)/preview', array( 'methods' => 'GET', 'callback' => array( $this, 'shipments_preview' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/shipments/(?P<id>\d+)/(?P<sid>\d+)', array( 'methods' => 'DELETE', 'callback' => array( $this, 'shipments_delete' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/roi/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'roi' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/history/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( $this, 'history_link' ) ) + $p );
	}
	public function settings_get() {
		$s = proof_partner_settings();
		return rest_ensure_response( array( 'packs' => self::pack_catalog(), 'pack_rules' => self::rules(), 'renewal_mode' => (string) $s['renewal_mode'], 'renewal_n' => (int) $s['renewal_n'], 'renewal_rate' => (string) $s['renewal_rate'], 'shrine' => class_exists( 'PShrine_Epack' ),
			'wc_statuses' => self::wc_statuses(), 'won_statuses' => proof_partner_won_statuses(), 'won_custom' => ! empty( $s['won_statuses'] ) ) );
	}
	/* Регистрираните Woo статуси (вкл. custom от плъгини), без вътрешните */
	public static function wc_statuses() {
		$out = array();
		if ( ! function_exists( 'wc_get_order_statuses' ) ) return $out;
		foreach ( wc_get_order_statuses() as $k => $label ) {
			$k = preg_replace( '/^wc-/', '', $k );
			if ( in_array( $k, array( 'checkout-draft', 'draft', 'trash' ), true ) ) continue;
			$out[] = array( 'key' => $k, 'label' => (string) $label, 'dead' => in_array( $k, array( 'cancelled', 'refunded', 'failed' ), true ) );
		}
		return $out;
	}
	public function settings_save( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$s = get_option( 'proof_partner_settings', array() ); if ( ! is_array( $s ) ) $s = array();
		if ( isset( $b['pack_rules'] ) && is_array( $b['pack_rules'] ) ) {
			$rules = array();
			foreach ( $b['pack_rules'] as $scope => $r ) {
				$scope = strtolower( sanitize_text_field( $scope ) );
				if ( ! preg_match( '/^pack:[a-z0-9_\-]{1,40}$/', $scope ) || ! is_array( $r ) ) continue;
				$mode = in_array( $r['mode'] ?? '', array( 'fixed', 'pct', 'off' ), true ) ? $r['mode'] : 'off';
				if ( 'off' === $mode ) continue; /* без правило = по ставката на партньора */
				$v = (float) str_replace( ',', '.', (string) ( $r['value'] ?? 0 ) );
				if ( 'pct' === $mode ) $v = max( 0, min( 100, $v ) ); else $v = max( 0, $v );
				$rules[ $scope ] = array( 'mode' => $mode, 'value' => round( $v, 2 ), 'discount' => empty( $r['discount'] ) ? 0 : 1 );
			}
			$s['pack_rules'] = $rules;
		}
		if ( isset( $b['renewal_mode'] ) ) $s['renewal_mode'] = in_array( $b['renewal_mode'], array( 'none', 'first_n', 'forever' ), true ) ? $b['renewal_mode'] : 'none';
		if ( isset( $b['renewal_n'] ) ) $s['renewal_n'] = max( 1, min( 120, (int) $b['renewal_n'] ) );
		if ( isset( $b['won_statuses'] ) && is_array( $b['won_statuses'] ) ) {
			$valid = wp_list_pluck( self::wc_statuses(), 'key' ); $sel = array();
			foreach ( $b['won_statuses'] as $x ) { $x = preg_replace( '/^wc-/', '', sanitize_key( (string) $x ) ); if ( in_array( $x, $valid, true ) && ! in_array( $x, array( 'cancelled', 'refunded', 'failed' ), true ) ) $sel[] = $x; }
			$s['won_statuses'] = array_values( array_unique( $sel ) );
			if ( $s['won_statuses'] ) $s['trigger_status'] = $s['won_statuses'][0]; /* старите места, които четат тригера, виждат първия избран */
		}
		if ( isset( $b['renewal_rate'] ) ) { $rr = trim( (string) $b['renewal_rate'] ); $s['renewal_rate'] = ( '' === $rr || ! is_numeric( $rr ) ) ? '' : (string) max( 0, min( 100, (float) $rr ) ); }
		update_option( 'proof_partner_settings', $s );
		return $this->settings_get();
	}
	/* Autocomplete за „Нов код": WC купоните по префикс (най-ползваните първи), с история на поръчките */
	public function coupons_suggest( WP_REST_Request $req ) {
		global $wpdb;
		$q = strtolower( trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) ) );
		if ( strlen( $q ) < 2 ) return rest_ensure_response( array() );
		$mapped = array_map( 'strtolower', (array) $wpdb->get_col( 'SELECT coupon_code FROM ' . PP_Schema::table( 'coupons' ) ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_status = 'publish' AND LOWER(post_title) LIKE %s ORDER BY post_title ASC LIMIT 40", $wpdb->esc_like( $q ) . '%' ) );
		$out = array();
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			$c = new WC_Coupon( $id ); $code = $c->get_code();
			$h = self::code_history( $code );
			$out[] = array( 'id' => $id, 'code' => $code, 'type' => $c->get_discount_type(), 'amount' => (float) $c->get_amount(), 'usage' => (int) $c->get_usage_count(),
				'mapped' => in_array( strtolower( $code ), $mapped, true ), 'orders' => $h['orders'], 'won' => $h['won'], 'revenue' => $h['revenue'], 'last' => $h['last'] );
		}
		usort( $out, function ( $a, $b ) { return $b['orders'] <=> $a['orders'] ?: $b['usage'] <=> $a['usage']; } );
		return rest_ensure_response( array_slice( $out, 0, 12 ) );
	}

	/* ── Служебни пратки (стока, дадена на партньора) ── */
	public static function shipment_snapshot( $order_id ) {
		$o = wc_get_order( (int) $order_id );
		if ( ! $o || 'shop_order' !== $o->get_type() ) return null;
		$items = array(); $qty = 0; $value = 0.0;
		foreach ( $o->get_items( 'line_item' ) as $it ) {
			$pid = (int) $it->get_product_id(); $vid = (int) $it->get_variation_id(); $q = (int) $it->get_quantity();
			$prod = $vid ? wc_get_product( $vid ) : wc_get_product( $pid );
			$reg = $prod ? (float) $prod->get_regular_price() : 0.0;
			if ( $reg <= 0 && $prod && $prod->is_type( 'variable' ) ) $reg = (float) $prod->get_variation_regular_price( 'min' );
			$line_paid = (float) $it->get_total() + (float) $it->get_total_tax();
			$items[] = array( 'name' => wp_strip_all_tags( $it->get_name() ), 'sku' => $prod ? (string) $prod->get_sku() : '', 'pid' => $pid, 'vid' => $vid, 'qty' => $q, 'unit_regular' => round( $reg, 2 ), 'line_regular' => round( $reg * $q, 2 ), 'line_paid' => round( $line_paid, 2 ) );
			$qty += $q; $value += $reg * $q;
		}
		$st = $o->get_status();
		return array( 'order_id' => $o->get_id(), 'order_status' => $st, 'counted' => in_array( $st, proof_partner_won_statuses(), true ) ? 1 : 0, /* САМО доставена (trigger статусът) / завършена — не processing */ 'items' => $items, 'qty' => $qty, 'value' => round( $value, 2 ),
			'order_date' => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : null, 'paid_total' => (float) $o->get_total(),
			'recipient' => trim( $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() ) ?: trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), 'city' => $o->get_shipping_city() ?: $o->get_billing_city(), 'edit_url' => get_edit_post_link( $o->get_id(), 'raw' ) );
	}
	public static function shipments_for( $pid ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'shipments' ) . ' WHERE partner_id = %d ORDER BY order_date DESC, shipment_id DESC', (int) $pid ) );
		return array_map( function ( $r ) {
			$items = json_decode( (string) $r->items, true ); if ( ! is_array( $items ) ) $items = array();
			return array( 'shipment_id' => (int) $r->shipment_id, 'order_id' => (int) $r->order_id, 'order_status' => (string) $r->order_status, 'counted' => (int) $r->counted, 'items' => $items, 'qty' => (int) $r->qty, 'value' => (float) $r->value, 'order_date' => (string) $r->order_date, 'note' => (string) $r->note, 'edit_url' => get_edit_post_link( (int) $r->order_id, 'raw' ) );
		}, $rows );
	}
	public static function shipments_totals( $pid ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, SUM(counted) delivered, SUM(CASE WHEN counted=1 THEN qty ELSE 0 END) qty, SUM(CASE WHEN counted=1 THEN value ELSE 0 END) value FROM ' . PP_Schema::table( 'shipments' ) . ' WHERE partner_id = %d', (int) $pid ) );
		return array( 'shipments' => (int) ( $r->n ?? 0 ), 'delivered' => (int) ( $r->delivered ?? 0 ), 'qty' => (int) ( $r->qty ?? 0 ), 'value' => round( (float) ( $r->value ?? 0 ), 2 ) );
	}
	public function shipments_get( WP_REST_Request $req ) { $pid = (int) $req['id']; return rest_ensure_response( array( 'shipments' => self::shipments_for( $pid ), 'totals' => self::shipments_totals( $pid ) ) ); }
	public function shipments_preview( WP_REST_Request $req ) {
		global $wpdb;
		$oid = (int) preg_replace( '/\D/', '', (string) $req->get_param( 'order' ) );
		$snap = $oid ? self::shipment_snapshot( $oid ) : null;
		if ( ! $snap ) return new WP_Error( 'pp_order', 'Няма поръчка №' . $oid . '.', array( 'status' => 404 ) );
		$own = $wpdb->get_row( $wpdb->prepare( 'SELECT partner_id FROM ' . PP_Schema::table( 'shipments' ) . ' WHERE order_id = %d', $oid ) );
		$snap['already'] = $own ? (int) $own->partner_id : 0;
		$snap['has_commission'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE order_id = %d AND type <> 'history'", $oid ) );
		return rest_ensure_response( $snap );
	}
	public function shipments_add( WP_REST_Request $req ) {
		global $wpdb;
		$pid = (int) $req['id']; if ( ! PP_Partners::get( $pid ) ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		$b = (array) $req->get_json_params();
		$oid = (int) preg_replace( '/\D/', '', (string) ( $b['order_id'] ?? '' ) );
		$snap = $oid ? self::shipment_snapshot( $oid ) : null;
		if ( ! $snap ) return new WP_Error( 'pp_order', 'Няма поръчка №' . $oid . '.', array( 'status' => 404 ) );
		$own = $wpdb->get_row( $wpdb->prepare( 'SELECT partner_id FROM ' . PP_Schema::table( 'shipments' ) . ' WHERE order_id = %d', $oid ) );
		if ( $own && (int) $own->partner_id !== $pid ) return new WP_Error( 'pp_dup', 'Поръчка №' . $oid . ' вече е записана като пратка към партньор #' . (int) $own->partner_id . '.', array( 'status' => 400 ) );
		$now = current_time( 'mysql' );
		$row = array( 'partner_id' => $pid, 'order_id' => $oid, 'order_status' => $snap['order_status'], 'counted' => $snap['counted'], 'items' => wp_json_encode( $snap['items'], JSON_UNESCAPED_UNICODE ), 'qty' => $snap['qty'], 'value' => $snap['value'], 'order_date' => $snap['order_date'], 'note' => sanitize_text_field( (string) ( $b['note'] ?? '' ) ), 'updated_at' => $now );
		if ( $own ) { $wpdb->update( PP_Schema::table( 'shipments' ), $row, array( 'order_id' => $oid ) ); }
		else { $row['created_at'] = $now; if ( false === $wpdb->insert( PP_Schema::table( 'shipments' ), $row ) ) return new WP_Error( 'pp_db', 'Записът отказа: ' . $wpdb->last_error, array( 'status' => 500 ) ); }
		if ( $o = wc_get_order( $oid ) ) { $o->update_meta_data( '_proof_partner_shipment', $pid ); $o->save(); $o->add_order_note( 'Proof Partner: служебна пратка към партньор #' . $pid . ' (' . $snap['qty'] . ' бр., ' . number_format( $snap['value'], 2 ) . ' € по каталог).' ); }
		if ( function_exists( 'proof_partner_feed_push' ) ) proof_partner_feed_push( 'order', sprintf( 'Служебна пратка #%d → партньор #%d: %d бр. (%s)', $oid, $pid, $snap['qty'], $snap['order_status'] ) );
		return rest_ensure_response( array( 'shipments' => self::shipments_for( $pid ), 'totals' => self::shipments_totals( $pid ) ) );
	}
	public function shipments_delete( WP_REST_Request $req ) {
		global $wpdb; $pid = (int) $req['id'];
		$sid = (int) $req['sid']; $r = $wpdb->get_row( $wpdb->prepare( 'SELECT order_id FROM ' . PP_Schema::table( 'shipments' ) . ' WHERE shipment_id = %d AND partner_id = %d', $sid, $pid ) );
		if ( $r ) { $wpdb->delete( PP_Schema::table( 'shipments' ), array( 'shipment_id' => $sid ) ); if ( $o = wc_get_order( (int) $r->order_id ) ) { $o->delete_meta_data( '_proof_partner_shipment' ); $o->save(); } }
		return rest_ensure_response( array( 'shipments' => self::shipments_for( $pid ), 'totals' => self::shipments_totals( $pid ) ) );
	}
	/* Статусът се следи: delivered/completed = „получено" (брои се), всичко друго = „по пътя"/„отменено" (не се брои) */
	public function shipment_status_sync( $order_id, $from, $to ) {
		global $wpdb;
		$t = PP_Schema::table( 'shipments' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT shipment_id FROM {$t} WHERE order_id = %d", (int) $order_id ) ) ) return;
		$snap = self::shipment_snapshot( (int) $order_id ); if ( ! $snap ) return;
		$wpdb->update( $t, array( 'order_status' => $snap['order_status'], 'counted' => $snap['counted'], 'items' => wp_json_encode( $snap['items'], JSON_UNESCAPED_UNICODE ), 'qty' => $snap['qty'], 'value' => $snap['value'], 'updated_at' => current_time( 'mysql' ) ), array( 'order_id' => (int) $order_id ) );
	}

	/* ── Фактури (фиксиран договор) + ROI ── */
	private static function inv_out( $r ) {
		$att = (int) $r->attachment_id;
		return array( 'invoice_id' => (int) $r->invoice_id, 'number' => (string) $r->number, 'issued_at' => (string) $r->issued_at, 'period_from' => (string) $r->period_from, 'period_to' => (string) $r->period_to,
			'amount' => (float) $r->amount, 'vat_amount' => (float) $r->vat_amount, 'currency' => (string) $r->currency, 'paid' => (int) $r->paid, 'note' => (string) $r->note,
			'attachment_id' => $att, 'file_url' => $att ? (string) wp_get_attachment_url( $att ) : '', 'file_name' => $att ? basename( (string) get_attached_file( $att ) ) : '' );
	}
	public static function invoices_for( $pid ) {
		global $wpdb;
		return array_map( array( __CLASS__, 'inv_out' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'invoices' ) . ' WHERE partner_id = %d ORDER BY issued_at DESC, invoice_id DESC', (int) $pid ) ) );
	}
	public function invoices_get( WP_REST_Request $req ) { return rest_ensure_response( array( 'invoices' => self::invoices_for( (int) $req['id'] ) ) ); }
	private static function inv_fields( $b ) {
		$d = function ( $v ) { $v = trim( (string) $v ); return $v && strtotime( $v ) ? date( 'Y-m-d', strtotime( $v ) ) : null; };
		$out = array();
		if ( isset( $b['number'] ) ) $out['number'] = sanitize_text_field( $b['number'] );
		foreach ( array( 'issued_at', 'period_from', 'period_to' ) as $k ) { if ( array_key_exists( $k, $b ) ) $out[ $k ] = $d( $b[ $k ] ); }
		if ( isset( $b['amount'] ) ) $out['amount'] = round( (float) str_replace( ',', '.', (string) $b['amount'] ), 2 );
		if ( isset( $b['vat_amount'] ) ) $out['vat_amount'] = round( (float) str_replace( ',', '.', (string) $b['vat_amount'] ), 2 );
		if ( isset( $b['paid'] ) ) $out['paid'] = empty( $b['paid'] ) || 'false' === $b['paid'] || '0' === $b['paid'] ? 0 : 1;
		if ( isset( $b['note'] ) ) $out['note'] = sanitize_text_field( $b['note'] );
		if ( isset( $b['attachment_id'] ) ) $out['attachment_id'] = (int) $b['attachment_id'];
		return $out;
	}
	/* multipart (файл + полета) или JSON */
	private static function inv_body( WP_REST_Request $req ) {
		$b = $req->get_json_params(); if ( ! is_array( $b ) || ! $b ) $b = (array) $req->get_body_params();
		$files = $req->get_file_params();
		if ( ! empty( $files['file'] ) && empty( $files['file']['error'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_handle_sideload( $files['file'], 0, 'Фактура партньор' );
			if ( ! is_wp_error( $att ) ) $b['attachment_id'] = (int) $att; else $b['_file_error'] = $att->get_error_message();
		}
		return $b;
	}
	public function invoices_add( WP_REST_Request $req ) {
		global $wpdb;
		$pid = (int) $req['id']; if ( ! PP_Partners::get( $pid ) ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		$b = self::inv_body( $req ); $f = self::inv_fields( $b );
		if ( empty( $f['amount'] ) || $f['amount'] <= 0 ) return new WP_Error( 'pp_inv', 'Сумата на фактурата е задължителна.', array( 'status' => 400 ) );
		if ( empty( $f['issued_at'] ) ) $f['issued_at'] = current_time( 'Y-m-d' );
		if ( empty( $f['period_from'] ) ) $f['period_from'] = date( 'Y-m-01', strtotime( $f['issued_at'] ) );
		if ( empty( $f['period_to'] ) ) $f['period_to'] = date( 'Y-m-t', strtotime( $f['period_from'] ) );
		$f += array( 'number' => '', 'vat_amount' => 0, 'paid' => 0, 'note' => '', 'attachment_id' => 0 );
		$f['partner_id'] = $pid; $f['currency'] = 'EUR'; $f['created_at'] = current_time( 'mysql' );
		if ( false === $wpdb->insert( PP_Schema::table( 'invoices' ), $f ) ) return new WP_Error( 'pp_db', 'Записът отказа: ' . $wpdb->last_error, array( 'status' => 500 ) );
		return rest_ensure_response( array( 'invoices' => self::invoices_for( $pid ), 'file_error' => (string) ( $b['_file_error'] ?? '' ) ) );
	}
	public function invoices_update( WP_REST_Request $req ) {
		global $wpdb;
		$pid = (int) $req['id']; $b = self::inv_body( $req ); $f = self::inv_fields( $b );
		if ( $f ) $wpdb->update( PP_Schema::table( 'invoices' ), $f, array( 'invoice_id' => (int) $req['inv'], 'partner_id' => $pid ) );
		return rest_ensure_response( array( 'invoices' => self::invoices_for( $pid ) ) );
	}
	public function invoices_delete( WP_REST_Request $req ) {
		global $wpdb; $pid = (int) $req['id'];
		$wpdb->delete( PP_Schema::table( 'invoices' ), array( 'invoice_id' => (int) $req['inv'], 'partner_id' => $pid ) );
		return rest_ensure_response( array( 'invoices' => self::invoices_for( $pid ) ) );
	}
	/* Сравнение за един месец (YYYY-MM): комисиона, която продажбите биха донесли, срещу фактурираното */
	public static function month_compare( $partner, $ym ) {
		global $wpdb;
		$t = PP_Schema::table( 'commissions' ); $pid = (int) $partner->partner_id;
		$s = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(base_amount),0) rev FROM {$t} WHERE partner_id = %d AND type = 'commission' AND status <> 'reversed' AND DATE_FORMAT(created_at,'%%Y-%%m') = %s", $pid, $ym ) );
		$inv = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . PP_Schema::table( 'invoices' ) . " WHERE partner_id = %d AND DATE_FORMAT(COALESCE(period_from, issued_at),'%%Y-%%m') = %s", $pid, $ym ) );
		$rate = (float) $partner->rate; $rev = (float) ( $s->rev ?? 0 ); $comm = round( $rev * $rate / 100, 2 );
		return array( 'ym' => $ym, 'orders' => (int) ( $s->n ?? 0 ), 'revenue' => round( $rev, 2 ), 'commission' => $comm, 'invoice' => round( $inv, 2 ), 'rate' => $rate, 'diff' => round( $inv - $comm, 2 ), 'behind' => $inv > $comm );
	}
	/* Текстът за портала. Референтен месец = ТЕКУЩИЯТ, ако има входирана фактура за него; иначе предходният
	 * (докато не се входира новата фактура). Пожеланието зависи от датата: до 27-мо „до края на месеца",
	 * 28+ „другия месец", а когато още четем предходния месец — „през текущия месец". Текстовете са per партньор
	 * (празно = глобалните от Маркетинг). $debug=true връща ['text','why','ym','wish'] за преглед в картата. */
	public static function fixed_message( $partner, $debug = false ) {
		$out = function ( $text, $why, $ym = '', $wish = '' ) use ( $debug ) { return $debug ? array( 'text' => $text, 'why' => $why, 'ym' => $ym, 'wish' => $wish ) : $text; };
		if ( ( $partner->pay_model ?? '' ) !== 'fixed' ) return $out( '', 'партньорът не е на фиксиран договор' );
		$ts = current_time( 'timestamp' ); $day = (int) date( 'j', $ts ); $cur = date( 'Y-m', $ts ); $prev = date( 'Y-m', strtotime( 'first day of last month', $ts ) );
		$c = self::month_compare( $partner, $cur ); $ym = $cur; $wish_key = $day >= 28 ? 'end' : 'mid';
		if ( $c['invoice'] <= 0 ) { $c = self::month_compare( $partner, $prev ); $ym = $prev; $wish_key = 'next'; }
		if ( $c['invoice'] <= 0 ) return $out( '', 'няма фактура нито за текущия, нито за предходния месец — без съобщение', $ym );
		$s = proof_partner_settings();
		$pick = function ( $own, $glob ) { $own = trim( (string) $own ); return '' !== $own ? $own : (string) $glob; };
		$tpl = $c['behind'] ? $pick( $partner->msg_behind ?? '', $s['fixed_msg_behind'] ?? '' ) : $pick( $partner->msg_ahead ?? '', $s['fixed_msg_ahead'] ?? '' );
		$wishes = array( 'mid' => $pick( $partner->msg_wish_mid ?? '', $s['fixed_wish_mid'] ?? '' ), 'end' => $pick( $partner->msg_wish_end ?? '', $s['fixed_wish_end'] ?? '' ), 'next' => $pick( $partner->msg_wish_next ?? '', $s['fixed_wish_next'] ?? '' ) );
		if ( '' === trim( $tpl ) ) return $out( '', 'шаблонът е празен', $ym );
		$months = array( 1 => 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември' );
		$first = trim( current( explode( ' ', trim( (string) $partner->display_name ) ) ) );
		$text = strtr( $tpl, array( '{name}' => $first, '{month}' => $months[ (int) substr( $ym, 5, 2 ) ] . ' ' . substr( $ym, 0, 4 ), '{commission}' => number_format( $c['commission'], 2 ) . ' €', '{invoice}' => number_format( $c['invoice'], 2 ) . ' €', '{revenue}' => number_format( $c['revenue'], 2 ) . ' €', '{orders}' => $c['orders'], '{rate}' => rtrim( rtrim( number_format( $c['rate'], 2 ), '0' ), '.' ), '{brand}' => proof_partner_brand_name(), '{wish}' => $wishes[ $wish_key ] ) );
		$text = preg_replace( '/\s{2,}/', ' ', $text );
		$why = ( $ym === $cur ? 'текущият месец (има фактура за него)' : 'предходният месец — за текущия още няма фактура' ) . ' · ' . ( $c['behind'] ? 'комисионата е под фактурата' : 'комисионата е над фактурата' ) . ' · пожелание: ' . ( 'mid' === $wish_key ? 'до края на месеца (' . $day . '-о число)' : ( 'end' === $wish_key ? 'другия месец (' . $day . '-о число)' : 'през текущия месец' ) );
		return $out( $text, $why, $ym, $wishes[ $wish_key ] );
	}

	/* ROI по месеци: продажбите с кодовете (оборот на спечелените поръчки от старта; реални + fixed редове) срещу фактурите (по period_from месец) */
	public function roi( WP_REST_Request $req ) {
		global $wpdb;
		$pid = (int) $req['id']; $p = PP_Partners::get( $pid ); if ( ! $p ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		$t = PP_Schema::table( 'commissions' );
		$sales = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') ym, COUNT(*) n, SUM(base_amount) rev, SUM(amount) comm FROM {$t} WHERE partner_id = %d AND type = 'commission' AND status <> 'reversed' GROUP BY ym", $pid ), OBJECT_K );
		$hist  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m') ym, COUNT(*) n, SUM(base_amount) rev FROM {$t} WHERE partner_id = %d AND type = 'history' AND note NOT LIKE '%%не е спечелена%%' GROUP BY ym", $pid ), OBJECT_K );
		$goods = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(order_date,'%%Y-%%m') ym, COUNT(*) n, SUM(qty) q, SUM(value) v FROM " . PP_Schema::table( 'shipments' ) . " WHERE partner_id = %d AND counted = 1 GROUP BY ym", $pid ), OBJECT_K );
		$inv = self::invoices_for( $pid ); $cost = array();
		foreach ( $inv as $i ) { $ym = substr( (string) ( $i['period_from'] ?: $i['issued_at'] ), 0, 7 ); if ( ! isset( $cost[ $ym ] ) ) $cost[ $ym ] = array( 'amount' => 0.0, 'n' => 0 ); $cost[ $ym ]['amount'] += $i['amount']; $cost[ $ym ]['n']++; }
		$months = array_unique( array_merge( array_keys( $sales ), array_keys( $hist ), array_keys( $cost ), array_keys( $goods ) ) ); rsort( $months );
		$rate = (float) $p->rate; $rows = array(); $tot = array( 'orders' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'would' => 0.0, 'hist_orders' => 0, 'hist_revenue' => 0.0, 'goods_qty' => 0, 'goods_value' => 0.0, 'commission_paid' => 0.0 );
		foreach ( $months as $ym ) {
			$s = $sales[ $ym ] ?? null; $h = $hist[ $ym ] ?? null; $c = $cost[ $ym ] ?? null;
			$rev = $s ? (float) $s->rev : 0.0; $n = $s ? (int) $s->n : 0; $cs = $c ? $c['amount'] : 0.0; $g = $goods[ $ym ] ?? null; $comm = $s ? (float) $s->comm : 0.0;
			$would = round( $rev * $rate / 100, 2 );
			$rows[] = array( 'ym' => $ym, 'orders' => $n, 'revenue' => round( $rev, 2 ), 'hist_orders' => $h ? (int) $h->n : 0, 'hist_revenue' => $h ? round( (float) $h->rev, 2 ) : 0.0,
				'cost' => round( $cs, 2 ), 'invoices' => $c ? $c['n'] : 0, 'would_commission' => $would, 'commission' => round( $comm, 2 ), 'goods_qty' => $g ? (int) $g->q : 0, 'goods_value' => $g ? round( (float) $g->v, 2 ) : 0.0,
				'cost_pct' => $rev > 0 ? round( $cs / $rev * 100, 1 ) : null, 'roi' => $cs > 0 ? round( $rev / $cs, 2 ) : null );
			$tot['orders'] += $n; $tot['revenue'] += $rev; $tot['cost'] += $cs; $tot['would'] += $would; $tot['hist_orders'] += $h ? (int) $h->n : 0; $tot['hist_revenue'] += $h ? (float) $h->rev : 0;
			$tot['goods_qty'] += $g ? (int) $g->q : 0; $tot['goods_value'] += $g ? (float) $g->v : 0; $tot['commission_paid'] += $comm;
		}
		$tot['revenue'] = round( $tot['revenue'], 2 ); $tot['cost'] = round( $tot['cost'], 2 ); $tot['would'] = round( $tot['would'], 2 ); $tot['hist_revenue'] = round( $tot['hist_revenue'], 2 );
		$tot['goods_value'] = round( $tot['goods_value'], 2 ); $tot['commission_paid'] = round( $tot['commission_paid'], 2 );
		$tot['cost_pct'] = $tot['revenue'] > 0 ? round( $tot['cost'] / $tot['revenue'] * 100, 1 ) : null; $tot['roi'] = $tot['cost'] > 0 ? round( $tot['revenue'] / $tot['cost'], 2 ) : null;
		$prev_ym = date( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) );
		return rest_ensure_response( array( 'pay_model' => (string) ( $p->pay_model ?? 'commission' ), 'rate' => $rate, 'tracked_from' => ! empty( $p->tracked_from ) ? substr( $p->tracked_from, 0, 10 ) : '', 'months' => $rows, 'totals' => $tot, 'invoices' => $inv,
			'prev_month' => self::month_compare( $p, $prev_ym ), 'this_month' => self::month_compare( $p, current_time( 'Y-m' ) ), 'portal_message' => self::fixed_message( $p ) ) );
	}

	/* „Свържи историята": за съществуващ партньор — сумира поръчките с кодовете му преди tracked_from → lifetime_imported */
	public function history_link( WP_REST_Request $req ) {
		$partner = PP_Partners::get( (int) $req['id'] ); if ( ! $partner ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		$b = (array) $req->get_json_params();
		$from = ! empty( $b['tracked_from'] ) && strtotime( $b['tracked_from'] ) ? date( 'Y-m-d', strtotime( $b['tracked_from'] ) ) : ( ! empty( $partner->tracked_from ) ? substr( $partner->tracked_from, 0, 10 ) : current_time( 'Y-m-d' ) );
		$tot = array( 'orders' => 0, 'won' => 0, 'revenue' => 0.0, 'first' => '', 'last' => '' ); $per = array();
		foreach ( PP_Coupons::for_partner( (int) $partner->partner_id ) as $c ) {
			$h = self::code_history( $c->coupon_code, $from ); $per[ $c->coupon_code ] = $h;
			$tot['orders'] += $h['orders']; $tot['won'] += $h['won']; $tot['revenue'] += $h['revenue'];
			if ( $h['first'] && ( '' === $tot['first'] || $h['first'] < $tot['first'] ) ) $tot['first'] = $h['first']; if ( $h['last'] > $tot['last'] ) $tot['last'] = $h['last'];
		}
		$tot['revenue'] = round( $tot['revenue'], 2 );
		PP_Partners::update( (int) $partner->partner_id, array( 'tracked_from' => $from, 'lifetime_imported' => self::history_label( $tot ) ) );
		$rows = self::write_history_rows( (int) $partner->partner_id, $from );
		$live = self::adopt_live_orders( (int) $partner->partner_id, $from );
		return rest_ensure_response( array( 'tracked_from' => $from, 'history' => $tot, 'per_code' => $per, 'label' => self::history_label( $tot ), 'rows' => $rows, 'live' => $live ) );
	}

	/* WC купоните, които още не са партньорски (Advanced Coupons ги пази като shop_coupon) */
	public function import_list( WP_REST_Request $req ) {
		global $wpdb;
		$mapped = array_map( 'strtolower', (array) $wpdb->get_col( 'SELECT coupon_code FROM ' . PP_Schema::table( 'coupons' ) ) );
		$q = strtolower( sanitize_text_field( (string) $req->get_param( 'q' ) ) );
		$posts = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC' ) );
		$out = array();
		foreach ( $posts as $p ) {
			$code = strtolower( $p->post_title );
			if ( in_array( $code, $mapped, true ) ) continue;
			if ( '' !== $q && false === strpos( $code . ' ' . strtolower( $p->post_excerpt ), $q ) ) continue;
			$c = new WC_Coupon( $p->ID );
			$exp = $c->get_date_expires();
			$out[] = array(
				'id' => $p->ID, 'code' => $p->post_title, 'type' => $c->get_discount_type(), 'amount' => (float) $c->get_amount(),
				'usage' => (int) $c->get_usage_count(), 'limit' => (int) $c->get_usage_limit(), 'expires' => $exp ? $exp->date( 'Y-m-d' ) : '',
				'expired' => $exp ? $exp->getTimestamp() < time() : false, 'desc' => (string) $p->post_excerpt,
				'emails' => array_values( (array) $c->get_email_restrictions() ), 'products' => count( (array) $c->get_product_ids() ),
				'acfw' => (bool) get_post_meta( $p->ID, '_acfw_bogo_deals', true ) || (bool) get_post_meta( $p->ID, '_acfw_cart_conditions', true ),
				'history' => ! empty( $req->get_param( 'history' ) ) ? self::code_history( $p->post_title ) : null,
			);
		}
		return rest_ensure_response( array( 'coupons' => $out, 'mapped' => count( $mapped ) ) );
	}
	/* Поръчките с даден купон — ЧИСТ SQL (HPOS или posts), без wc_get_order: 6000 поръчки = 3-4 заявки, не 6000 обекта.
	 * Връща [order_id => ['date'=>'Y-m-d','dt'=>'Y-m-d H:i:s','status'=>'completed','total'=>float,'currency'=>'EUR','products'=>'…']] */
	public static function orders_for_code( $code ) {
		global $wpdb;
		$code = strtolower( trim( (string) $code ) ); if ( '' === $code ) return array();
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'coupon' AND LOWER(order_item_name) = %s", $code ) ) );
		if ( ! $ids ) return array();
		$hpos = get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes';
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			if ( $hpos ) {
				$rows = $wpdb->get_results( "SELECT id, status, date_created_gmt AS d, total_amount AS total, currency FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND id IN ($in)" );
				foreach ( (array) $rows as $r ) {
					$local = get_date_from_gmt( (string) $r->d, 'Y-m-d H:i:s' );
					$out[ (int) $r->id ] = array( 'date' => substr( $local, 0, 10 ), 'dt' => $local, 'status' => preg_replace( '/^wc-/', '', (string) $r->status ), 'total' => (float) $r->total, 'currency' => (string) ( $r->currency ?: 'EUR' ), 'products' => '' );
				}
			} else {
				$rows = $wpdb->get_results( "SELECT p.ID AS id, p.post_status AS status, p.post_date AS d,
					(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_order_total' LIMIT 1) AS total,
					(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_order_currency' LIMIT 1) AS currency
					FROM {$wpdb->posts} p WHERE p.post_type = 'shop_order' AND p.ID IN ($in)" );
				foreach ( (array) $rows as $r ) {
					$out[ (int) $r->id ] = array( 'date' => substr( (string) $r->d, 0, 10 ), 'dt' => (string) $r->d, 'status' => preg_replace( '/^wc-/', '', (string) $r->status ), 'total' => (float) $r->total, 'currency' => (string) ( $r->currency ?: 'EUR' ), 'products' => '' );
				}
			}
			/* продуктите: име ×qty, групирано по поръчка */
			$items = $wpdb->get_results( "SELECT i.order_id, i.order_item_name AS n, (SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta m WHERE m.order_item_id = i.order_item_id AND m.meta_key = '_qty' LIMIT 1) AS q FROM {$wpdb->prefix}woocommerce_order_items i WHERE i.order_item_type = 'line_item' AND i.order_id IN ($in) ORDER BY i.order_item_id ASC" );
			$by = array();
			foreach ( (array) $items as $it ) { $q = (int) $it->q; $by[ (int) $it->order_id ][] = preg_replace( '/\s+/', ' ', trim( wp_strip_all_tags( (string) $it->n ) ) ) . ( $q > 1 ? ' ×' . $q : '' ); }
			foreach ( $by as $oid => $parts ) { if ( isset( $out[ $oid ] ) ) { $str = implode( ', ', $parts ); $out[ $oid ]['products'] = function_exists( 'mb_substr' ) ? mb_substr( $str, 0, 500 ) : substr( $str, 0, 500 ); } }
		}
		ksort( $out );
		return $out;
	}
	public static function won_statuses() { return array_values( array_unique( array_merge( proof_partner_won_statuses(), array( 'processing' ) ) ) ); }

	/* Историята на един код ПРЕДИ проследяването: брой спечелени поръчки + оборот (за lifetime_imported и за одита) */
	public static function code_history( $code, $before_date = '' ) {
		$out = array( 'orders' => 0, 'won' => 0, 'revenue' => 0.0, 'first' => '', 'last' => '' );
		$won = self::won_statuses();
		foreach ( self::orders_for_code( $code ) as $oid => $o ) {
			if ( $before_date && $o['date'] >= $before_date ) continue;
			$out['orders']++;
			if ( in_array( $o['status'], $won, true ) ) { $out['won']++; $out['revenue'] += $o['total']; }
			if ( '' === $out['first'] || $o['date'] < $out['first'] ) $out['first'] = $o['date']; if ( $o['date'] > $out['last'] ) $out['last'] = $o['date'];
		}
		$out['revenue'] = round( $out['revenue'], 2 );
		return $out;
	}
	/* Историята РЕД ПО РЕД: type='history', status='history', amount=0 — един ред на стара поръчка с кода.
	 * Пресъздава се изцяло при всяко свързване. Поръчка с реален ред (commission/reversal) не получава history ред.
	 * Ключ 'h<order>-<partner>'. Записва се на порции по 200 реда в един INSERT. */
	/* Поръчките С кода ОТ tracked_from нататък, които още нямат ред: минават през engine-а веднага (не чакат
	 * мрежата). Всички живи статуси — on-hold/processing стават „изчакваща", доставена/приключена → одобрява се
	 * след периода на задържане; отказана/върната/неуспешна не се пипат. Връща ['scanned','created','skipped']. */
	public static function adopt_live_orders( $partner_id, $from, $limit = 400 ) {
		global $wpdb;
		$partner_id = (int) $partner_id; $t = PP_Schema::table( 'commissions' );
		$out = array( 'scanned' => 0, 'created' => 0, 'skipped' => 0, 'more' => 0 );
		if ( ! class_exists( 'PP_Engine' ) || ! function_exists( 'wc_get_order' ) ) return $out;
		$engine = PP_Engine::instance();
		$dead = array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'draft' );
		$have = array_flip( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$t} WHERE partner_id = %d AND type = 'commission'", $partner_id ) ) ) );
		$t0 = microtime( true ); $todo = array();
		foreach ( PP_Coupons::for_partner( $partner_id ) as $c ) {
			foreach ( self::orders_for_code( $c->coupon_code ) as $oid => $o ) {
				if ( $from && $o['date'] < $from ) continue;
				if ( isset( $have[ $oid ] ) || in_array( $o['status'], $dead, true ) ) { $out['skipped']++; continue; }
				$todo[ $oid ] = 1;
			}
		}
		$todo = array_keys( $todo ); sort( $todo );
		foreach ( $todo as $i => $oid ) {
			if ( $i >= $limit || microtime( true ) - $t0 > 20 ) { $out['more'] = count( $todo ) - $i; break; }
			$out['scanned']++;
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND type = 'commission'", $oid ) );
			try { $engine->maybe_create_commission( (int) $oid ); } catch ( \Throwable $e ) { if ( function_exists( 'proof_partner_log_shield' ) ) proof_partner_log_shield( 'adopt_live', $e ); }
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE order_id = %d AND type = 'commission'", $oid ) ) > $before ) $out['created']++;
		}
		return $out;
	}

	public static function write_history_rows( $partner_id, $from ) {
		global $wpdb;
		$partner_id = (int) $partner_id; $t = PP_Schema::table( 'commissions' );
		$wpdb->delete( $t, array( 'partner_id' => $partner_id, 'type' => 'history' ) );
		$real = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$t} WHERE partner_id = %d AND type <> 'history'", $partner_id ) ) );
		$real = array_flip( $real );
		$n = 0; $ins = 0; $rev = 0.0; $seen = array(); $now = current_time( 'mysql' ); $won = self::won_statuses(); $values = array();
		$flush = function () use ( &$values, $wpdb, $t ) {
			if ( ! $values ) return;
			$wpdb->query( "INSERT IGNORE INTO {$t} (order_partner_key, order_id, partner_id, coupon_code, type, base_amount, rate_applied, amount, currency, status, products, note, created_at, updated_at) VALUES " . implode( ',', $values ) );
			$values = array();
		};
		foreach ( PP_Coupons::for_partner( $partner_id ) as $c ) {
			foreach ( self::orders_for_code( $c->coupon_code ) as $oid => $o ) {
				if ( isset( $seen[ $oid ] ) || isset( $real[ $oid ] ) ) continue; $seen[ $oid ] = 1;
				if ( $from && $o['date'] >= $from ) continue;
				$is_won = in_array( $o['status'], $won, true );
				$values[] = $wpdb->prepare( '(%s,%d,%d,%s,%s,%f,%d,%d,%s,%s,%s,%s,%s,%s)',
					'h' . $oid . '-' . $partner_id, $oid, $partner_id, $c->coupon_code, 'history', round( $o['total'], 2 ), 0, 0, $o['currency'], 'history',
					$o['products'], '[история преди старта · ' . $o['status'] . ( $is_won ? '' : ' · не е спечелена' ) . ']', $o['dt'], $now );
				$ins++;
				if ( $is_won ) { $n++; $rev += $o['total']; }
				if ( count( $values ) >= 200 ) $flush();
			}
		}
		$flush();
		return array( 'won' => $n, 'revenue' => round( $rev, 2 ), 'rows' => $ins );
	}
	public static function history_label( $h ) {
		return $h['won'] ? sprintf( 'История до старта: %d поръчки / %.0f €', $h['won'], $h['revenue'] ) : '';
	}

	/* Осиновяване: партньор (нов или по email съществуващ) + mapping на кода (кодът остава същият) */
	public function import_adopt( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$wc_id = (int) ( $b['wc_id'] ?? 0 ); $p = $wc_id ? get_post( $wc_id ) : null;
		if ( ! $p || 'shop_coupon' !== $p->post_type ) return new WP_Error( 'pp_coupon', 'Няма такъв купон.', array( 'status' => 400 ) );
		$email = sanitize_email( $b['email'] ?? '' ); $name = sanitize_text_field( $b['name'] ?? '' );
		$partner = null;
		if ( ! empty( $b['partner_id'] ) ) { $partner = PP_Partners::get( (int) $b['partner_id'] ); }
		elseif ( is_email( $email ) && ( $u = get_user_by( 'email', $email ) ) ) { $partner = PP_Partners::get_by_user( $u->ID ); }
		$from = ! empty( $b['tracked_from'] ) && strtotime( $b['tracked_from'] ) ? date( 'Y-m-d', strtotime( $b['tracked_from'] ) ) : current_time( 'Y-m-d' );
		if ( ! $partner ) {
			$rate = isset( $b['rate'] ) && '' !== $b['rate'] ? $b['rate'] : proof_partner_settings()['default_rate'];
			$pid = PP_Partners::create( array( 'email' => $email, 'display_name' => $name ?: $p->post_title, 'rate' => $rate, 'tracked_from' => $from ) );
			if ( is_wp_error( $pid ) ) return new WP_Error( 'pp_partner', $pid->get_error_message(), array( 'status' => 400 ) );
			$partner = PP_Partners::get( $pid );
		}
		$c = new WC_Coupon( $wc_id );
		$r = PP_Coupons::create_for_partner( (int) $partner->partner_id, $p->post_title, in_array( $c->get_discount_type(), array( 'percent', 'fixed_cart' ), true ) ? $c->get_discount_type() : 'percent', (float) $c->get_amount(), true );
		if ( is_wp_error( $r ) ) return new WP_Error( 'pp_map', $r->get_error_message(), array( 'status' => 400 ) );
		/* историята на кода преди старта → lifetime_imported (само информативно; engine-ът не създава комисиони преди tracked_from) */
		$h = self::code_history( $p->post_title, $from );
		$upd = array( 'tracked_from' => $from ); if ( $h['won'] ) $upd['lifetime_imported'] = self::history_label( $h );
		PP_Partners::update( (int) $partner->partner_id, $upd );
		self::write_history_rows( (int) $partner->partner_id, $from );
		$live = self::adopt_live_orders( (int) $partner->partner_id, $from );
		if ( function_exists( 'proof_partner_feed_push' ) ) proof_partner_feed_push( 'code', sprintf( 'Импорт: %s → %s (следи се от %s; история %d поръчки)', strtoupper( $p->post_title ), $partner->display_name, $from, $h['won'] ) );
		return rest_ensure_response( array( 'ok' => 1, 'partner_id' => (int) $partner->partner_id, 'partner' => $partner->display_name, 'mapping' => (int) $r, 'tracked_from' => $from, 'history' => $h ) );
	}
}
