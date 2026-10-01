<?php
/**
 * Proof Partner · Правила по оферти (2.0.0)
 *
 * Един код, различни условия по оферта: комисиона за партньора (по ставката / % / фиксирана за бр. / без),
 * отстъпка за клиента върху редовете на офертата (по купона / % / фиксирана за бр. / без) и подновявания
 * (наследи глобалното / никога / първите N / винаги; като първата поръчка / % / фиксирана).
 * Правило = (оферта, важи за: any|onetime|sub). Резолюция: лично(вид) → лично(any) → глобално(вид) → глобално(any).
 *
 * ЗАМРАЗЯВАНЕ (най-важното): при checkout всеки ред получава meta ITEM_SCOPE/ITEM_KIND, а поръчката —
 * снимка на резолвираните правила (ORDER_RULES). Комисионата (седмици по-късно, при доставка) се смята от
 * снимката — промяна на правило, спиране на оферта или изключване на Shrine не преоценява стари поръчки.
 * Подновяванията (Sublium, без наша meta на редовете) четат снимката на РОДИТЕЛСКАТА поръчка.
 *
 * Разбивката (по оферта, вид, база, правило, сума) е в таблица `commission_items` — една комисиона на поръчка
 * (изплащане/сторно/партиди не се пипат), разбивката е за четене и филтриране.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Offers {

	const ITEM_SCOPE  = '_proof_offer_scope';
	const ITEM_KIND   = '_proof_offer_kind';
	const ORDER_RULES = '_proof_partner_offer_rules';
	const DEAD_SCOPES = array( '_regular' );

	public function __construct() {
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'discount' ), 50, 5 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'snapshot' ), 8 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'snapshot' ), 8 );
		add_filter( 'proof_partner_commission_calc', array( $this, 'calc' ), 20, 6 );
		add_action( 'proof_partner_commission_row', array( $this, 'write_items' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/* ═══════════ 1. Правила: форма, валидиране, съхранение ═══════════ */

	public static function empty_rule() {
		return array( 'comm' => array( 'mode' => 'rate', 'value' => 0 ), 'disc' => array( 'mode' => 'coupon', 'value' => 0 ), 'ren' => array( 'mode' => 'inherit', 'n' => 3, 'cmode' => 'same', 'value' => 0 ) );
	}
	public static function sanitize_rule( $r ) {
		$d = self::empty_rule(); if ( ! is_array( $r ) ) return $d;
		$num = function ( $v ) { return round( max( 0, (float) str_replace( ',', '.', (string) $v ) ), 2 ); };
		$c = (array) ( $r['comm'] ?? array() ); $dd = (array) ( $r['disc'] ?? array() ); $n = (array) ( $r['ren'] ?? array() );
		$d['comm']['mode'] = in_array( $c['mode'] ?? '', array( 'rate', 'pct', 'fixed', 'none' ), true ) ? $c['mode'] : 'rate';
		$d['comm']['value'] = 'pct' === $d['comm']['mode'] ? min( 100, $num( $c['value'] ?? 0 ) ) : $num( $c['value'] ?? 0 );
		$d['disc']['mode'] = in_array( $dd['mode'] ?? '', array( 'coupon', 'pct', 'fixed', 'none' ), true ) ? $dd['mode'] : 'coupon';
		$d['disc']['value'] = 'pct' === $d['disc']['mode'] ? min( 100, $num( $dd['value'] ?? 0 ) ) : $num( $dd['value'] ?? 0 );
		$d['ren']['mode'] = in_array( $n['mode'] ?? '', array( 'inherit', 'none', 'first_n', 'forever' ), true ) ? $n['mode'] : 'inherit';
		$d['ren']['n'] = max( 1, min( 120, (int) ( $n['n'] ?? 3 ) ) );
		$d['ren']['cmode'] = in_array( $n['cmode'] ?? '', array( 'same', 'pct', 'fixed' ), true ) ? $n['cmode'] : 'same';
		$d['ren']['value'] = 'pct' === $d['ren']['cmode'] ? min( 100, $num( $n['value'] ?? 0 ) ) : $num( $n['value'] ?? 0 );
		return $d;
	}
	public static function valid_scope( $s ) { return (bool) preg_match( '/^(pack|prod|cat|man):[a-z0-9_\-]{1,60}$/', (string) $s ); }
	public static function valid_applies( $a ) { return in_array( $a, array( 'any', 'onetime', 'sub' ), true ); }

	/* Глобални: settings['offer_rules'][scope][applies] = rule; ръчни оферти: settings['offer_manual'][scope] = ['label','type','ids'] */
	public static function global_rules() {
		$st = proof_partner_settings(); $r = $st['offer_rules'] ?? array();
		if ( ! is_array( $r ) ) $r = array();
		if ( ! $r && empty( $st['offer_rules_set'] ) ) { /* еднократна миграция на старите pack_rules (fixed/pct + discount 0/1) → any */
			$old = proof_partner_settings()['pack_rules'] ?? array(); $r = array();
			foreach ( (array) $old as $scope => $o ) {
				if ( ! is_array( $o ) || ! self::valid_scope( $scope ) ) continue;
				$rule = self::empty_rule();
				$rule['comm'] = array( 'mode' => in_array( $o['mode'] ?? '', array( 'fixed', 'pct' ), true ) ? $o['mode'] : 'rate', 'value' => (float) ( $o['value'] ?? 0 ) );
				$rule['disc'] = array( 'mode' => empty( $o['discount'] ) ? 'none' : 'coupon', 'value' => 0 );
				$r[ $scope ] = array( 'any' => $rule );
			}
		}
		return $r;
	}
	public static function manual_offers() {
		$m = proof_partner_settings()['offer_manual'] ?? array();
		return is_array( $m ) ? $m : array();
	}
	public static function partner_rules( $pid ) {
		global $wpdb; $out = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT scope, applies, rule FROM ' . PP_Schema::table( 'offer_rules' ) . ' WHERE partner_id = %d', (int) $pid ) );
		foreach ( (array) $rows as $row ) { $j = json_decode( (string) $row->rule, true ); if ( is_array( $j ) ) $out[ $row->scope ][ $row->applies ] = self::sanitize_rule( $j ); }
		return $out;
	}
	public static function partner_counts() {
		global $wpdb; $out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT scope, COUNT(DISTINCT partner_id) n FROM ' . PP_Schema::table( 'offer_rules' ) . ' GROUP BY scope' ) as $r ) $out[ $r->scope ] = (int) $r->n;
		return $out;
	}

	/** Резолюция: лично(вид) → лично(any) → глобално(вид) → глобално(any). Връща ['rule'=>…, 'source'=>'partner'|'global'] или null */
	public static function resolve( $scope, $kind, $pid, $partner_rules = null, $global = null ) {
		if ( '' === $scope || in_array( $scope, self::DEAD_SCOPES, true ) ) return null;
		$kind = 'sub' === $kind ? 'sub' : 'onetime';
		if ( null === $partner_rules ) $partner_rules = $pid ? self::partner_rules( $pid ) : array();
		if ( null === $global ) $global = self::global_rules();
		foreach ( array( array( $partner_rules, 'partner' ), array( $global, 'global' ) ) as $pair ) {
			list( $set, $src ) = $pair;
			if ( isset( $set[ $scope ][ $kind ] ) ) return array( 'rule' => self::sanitize_rule( $set[ $scope ][ $kind ] ), 'source' => $src, 'applies' => $kind );
			if ( isset( $set[ $scope ]['any'] ) ) return array( 'rule' => self::sanitize_rule( $set[ $scope ]['any'] ), 'source' => $src, 'applies' => 'any' );
		}
		return null;
	}

	/* ═══════════ 2. Каталог на офертите (Shrine + ръчни + „спрени, но с правило") ═══════════ */
	public static function catalog() {
		$out = array(); $seen = array();
		if ( class_exists( 'PShrine_Epack' ) && method_exists( 'PShrine_Epack', 'packs' ) ) {
			foreach ( (array) PShrine_Epack::packs() as $id => $c ) {
				$scope = 'pack:' . sanitize_key( $id ); $seen[ $scope ] = 1;
				$out[ $scope ] = array( 'scope' => $scope, 'label' => (string) ( $c['name'] ?? $id ), 'source' => 'Shrine EPACK', 'retired' => ! empty( $c['archived'] ), 'manual' => false, 'sub_capable' => self::pack_has_plan( $c ) );
			}
		}
		if ( class_exists( 'PShrine_Combo_Cart' ) ) { $seen['pack:combo'] = 1; $out['pack:combo'] = array( 'scope' => 'pack:combo', 'label' => 'Кутии (Протеин + Креатин)', 'source' => 'Shrine Combo', 'retired' => false, 'manual' => false, 'sub_capable' => true ); }
		foreach ( self::manual_offers() as $scope => $m ) {
			if ( ! self::valid_scope( $scope ) ) continue; $seen[ $scope ] = 1;
			$out[ $scope ] = array( 'scope' => $scope, 'label' => (string) ( $m['label'] ?? $scope ), 'source' => 'ръчно · ' . ( 'cat' === ( $m['type'] ?? '' ) ? 'категория' : 'продукт' ), 'retired' => ! empty( $m['retired'] ), 'manual' => true, 'match' => array( 'type' => (string) ( $m['type'] ?? 'product' ), 'ids' => array_values( array_map( 'intval', (array) ( $m['ids'] ?? array() ) ) ) ), 'sub_capable' => true );
		}
		/* правила за оферти, които вече ги няма никъде (спрени в Shrine и изтрити) — пазят се за историята */
		foreach ( self::global_rules() as $scope => $x ) {
			if ( isset( $seen[ $scope ] ) ) continue;
			$out[ $scope ] = array( 'scope' => $scope, 'label' => self::scope_label( $scope ), 'source' => 'спряна оферта', 'retired' => true, 'manual' => false, 'sub_capable' => true );
		}
		return array_values( $out );
	}
	protected static function pack_has_plan( $c ) {
		$j = wp_json_encode( $c ); return is_string( $j ) && false !== stripos( $j, 'sublium' );
	}
	public static function scope_label( $scope ) {
		foreach ( self::catalog_cache() as $c ) if ( $c['scope'] === $scope ) return $c['label'];
		$m = self::manual_offers(); if ( isset( $m[ $scope ]['label'] ) ) return (string) $m[ $scope ]['label'];
		return ucfirst( (string) substr( strrchr( 'x' . $scope, ':' ), 1 ) ) ?: $scope;
	}
	protected static $cat_cache = null;
	protected static function catalog_cache() { if ( null === self::$cat_cache ) { self::$cat_cache = array(); if ( class_exists( 'PShrine_Epack' ) && method_exists( 'PShrine_Epack', 'packs' ) ) foreach ( (array) PShrine_Epack::packs() as $id => $c ) self::$cat_cache[] = array( 'scope' => 'pack:' . sanitize_key( $id ), 'label' => (string) ( $c['name'] ?? $id ) ); self::$cat_cache[] = array( 'scope' => 'pack:combo', 'label' => 'Кутии (Протеин + Креатин)' ); } return self::$cat_cache; }

	/* ═══════════ 3. Количка: оферта + вид на реда, отстъпка за клиента ═══════════ */

	/** Ръчните оферти: продукт/категория → scope (първото съвпадение; продукт бие категория) */
	public static function manual_scope_for_product( $pid, $vid = 0 ) {
		$m = self::manual_offers(); if ( ! $m ) return '';
		$pid = (int) $pid; $vid = (int) $vid; $cats = null;
		foreach ( $m as $scope => $o ) { if ( ! empty( $o['retired'] ) || 'product' !== ( $o['type'] ?? 'product' ) ) continue; $ids = array_map( 'intval', (array) ( $o['ids'] ?? array() ) ); if ( in_array( $pid, $ids, true ) || ( $vid && in_array( $vid, $ids, true ) ) ) return (string) $scope; }
		foreach ( $m as $scope => $o ) { if ( ! empty( $o['retired'] ) || 'cat' !== ( $o['type'] ?? '' ) ) continue; if ( null === $cats ) $cats = function_exists( 'wc_get_product_term_ids' ) ? array_map( 'intval', (array) wc_get_product_term_ids( $pid, 'product_cat' ) ) : array(); if ( array_intersect( $cats, array_map( 'intval', (array) ( $o['ids'] ?? array() ) ) ) ) return (string) $scope; }
		return '';
	}
	public static function cart_item_scope( $ci ) {
		if ( ! is_array( $ci ) ) return '';
		if ( ! empty( $ci['pshrine_epack'] ) ) { $p = sanitize_key( $ci['pshrine_epack_pack'] ?? '' ); return 'pack:' . ( $p ?: 'essentials' ); }
		if ( ! empty( $ci['pshrine_combo'] ) || ! empty( $ci['pshrine_combo_line'] ) ) return 'pack:combo';
		return self::manual_scope_for_product( (int) ( $ci['product_id'] ?? 0 ), (int) ( $ci['variation_id'] ?? 0 ) );
	}
	/** Абонамент: Shrine/Sublium слага `sublium_wcs_plan` (id на план) на реда в количката. Филтър за други случаи. */
	public static function cart_item_kind( $ci ) {
		$is = false;
		if ( is_array( $ci ) ) {
			if ( ! empty( $ci['sublium_wcs_plan'] ) ) $is = true;
			else foreach ( $ci as $k => $v ) { if ( is_string( $k ) && 0 === strpos( $k, 'sublium' ) && ! empty( $v ) && ! is_array( $v ) ) { $is = true; break; } }
		}
		$is = (bool) apply_filters( 'proof_partner_item_is_subscription', $is, $ci );
		return $is ? 'sub' : 'onetime';
	}
	/** Партньорът на партньорския купон в количката (или null) */
	protected static function cart_partner_id( $coupon ) {
		$map = $coupon instanceof WC_Coupon ? PP_Coupons::get_mapping_by_code( $coupon->get_code() ) : null;
		return $map && (int) $map->active === 1 ? (int) $map->partner_id : 0;
	}
	public function discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( ! is_array( $cart_item ) || ! $coupon instanceof WC_Coupon ) return $discount;
		$pid = self::cart_partner_id( $coupon ); if ( ! $pid ) return $discount;
		$scope = self::cart_item_scope( $cart_item ); if ( '' === $scope ) return $discount;
		$res = self::resolve( $scope, self::cart_item_kind( $cart_item ), $pid ); if ( ! $res ) return $discount;
		$d = $res['rule']['disc']; $qty = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		/* fixed_cart купон: Woo разпределя сумата пропорционално по редовете — различна стойност по ред не е надеждна → само „по купона" или „без" */
		$fixed_cart = 'fixed_cart' === $coupon->get_discount_type();
		switch ( $d['mode'] ) {
			case 'none':  return 0;
			case 'pct':   return $fixed_cart ? $discount : round( (float) $discounting_amount * (float) $d['value'] / 100, wc_get_price_decimals() );
			case 'fixed': return $fixed_cart ? $discount : min( (float) $discounting_amount, round( (float) $d['value'] * ( $single ? 1 : $qty ), wc_get_price_decimals() ) );
			default:      return $discount;
		}
	}
	/** Замразяване 1: всеки ред на поръчката носи офертата и вида си */
	public function stamp_item( $item, $cart_item_key, $values, $order ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) return;
		$scope = self::cart_item_scope( (array) $values ); if ( '' === $scope ) return;
		$item->add_meta_data( self::ITEM_SCOPE, $scope, true );
		$item->add_meta_data( self::ITEM_KIND, self::cart_item_kind( (array) $values ), true );
	}
	/** Замразяване 2: снимка на резолвираните правила за партньора на поръчката */
	public function snapshot( $order ) {
		if ( is_numeric( $order ) ) $order = wc_get_order( $order );
		if ( ! $order instanceof WC_Order || $order->get_meta( self::ORDER_RULES ) ) return;
		$attr = PP_Coupons::attribute_order( $order ); if ( ! $attr ) return;
		$pid = (int) $attr['partner_id']; $pr = self::partner_rules( $pid ); $gl = self::global_rules(); $snap = array();
		foreach ( self::order_lines( $order, false ) as $ln ) {
			if ( '_regular' === $ln['scope'] ) continue; $key = $ln['scope'] . '|' . $ln['kind']; if ( isset( $snap[ $key ] ) ) continue;
			$res = self::resolve( $ln['scope'], $ln['kind'], $pid, $pr, $gl );
			$snap[ $key ] = $res ? array( 'scope' => $ln['scope'], 'kind' => $ln['kind'], 'rule' => $res['rule'], 'source' => $res['source'], 'label' => self::scope_label( $ln['scope'] ) ) : array( 'scope' => $ln['scope'], 'kind' => $ln['kind'], 'rule' => null, 'source' => 'default', 'label' => self::scope_label( $ln['scope'] ) );
		}
		$order->update_meta_data( self::ORDER_RULES, array( 'v' => 1, 'partner_id' => $pid, 'at' => current_time( 'mysql' ), 'rules' => $snap ) );
		$order->save();
	}

	/* ═══════════ 4. Поръчка: редове → (оферта, вид, база) ═══════════ */

	/** [ {item_id, product_id, scope, kind, base, qty} ] — базата по логиката на commission_base (ДДС настройка, изключени, връщания) */
	public static function order_lines( WC_Order $order, $with_base = true ) {
		$s = proof_partner_settings(); $include_vat = ! empty( $s['include_vat'] ); $excluded = proof_partner_excluded_product_ids();
		$combo = (bool) $order->get_meta( '_pshrine_combo_snapshot' ); $out = array();
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pid = (int) $item->get_product_id(); $vid = (int) $item->get_variation_id();
			if ( in_array( $pid, $excluded, true ) || ( $vid && in_array( $vid, $excluded, true ) ) ) continue;
			$scope = (string) $item->get_meta( self::ITEM_SCOPE );
			if ( '' === $scope ) $scope = (string) $item->get_meta( '_pshrine_ins_scope' );
			if ( '' === $scope && $combo ) $scope = 'pack:combo';
			if ( '' === $scope ) $scope = self::manual_scope_for_product( $pid, $vid );
			if ( '' === $scope ) $scope = '_regular';
			$kind = (string) $item->get_meta( self::ITEM_KIND );
			if ( '' === $kind ) { $kind = 'onetime'; foreach ( (array) $item->get_meta_data() as $md ) { $k = is_object( $md ) && isset( $md->key ) ? (string) $md->key : ''; if ( '' !== $k && false !== stripos( $k, 'sublium' ) && false !== stripos( $k, 'plan' ) ) { $kind = 'sub'; break; } } }
			$base = 0.0;
			if ( $with_base ) {
				$line_ex = (float) $item->get_total(); $refund_ex = (float) $order->get_total_refunded_for_item( $item_id );
				$net = $line_ex - $refund_ex; if ( $net <= 0 ) continue;
				if ( $include_vat ) { $ratio = $line_ex > 0 ? ( (float) $item->get_total_tax() ) / $line_ex : 0.0; $net *= ( 1 + $ratio ); }
				$base = round( $net, 2 );
			}
			$out[] = array( 'item_id' => (int) $item_id, 'product_id' => $pid, 'variation_id' => $vid, 'scope' => $scope, 'kind' => $kind, 'base' => $base, 'qty' => max( 1, (int) $item->get_quantity() ) );
		}
		return $out;
	}

	/* ═══════════ 5. Сметката ═══════════ */

	protected static function comm_amount( $mode, $value, $base, $qty, $rate ) {
		switch ( $mode ) {
			case 'none':  return 0.0;
			case 'pct':   return round( $base * (float) $value / 100, 2 );
			case 'fixed': return round( (float) $value * $qty, 2 );
			default:      return round( $base * (float) $rate / 100, 2 );
		}
	}
	protected static function fmt( $v ) { return rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' ); }

	/**
	 * Заменя старата PP_Proof::calc. Връща ['amount','rate','note','items'].
	 * Обикновена поръчка: снимката в поръчката (замразено) → иначе живите правила (стари поръчки, backfill).
	 * Подновяване ($attr['renewal'] от PP_Proof::renewal_attribution): снимката на родителя + секцията „подновявания".
	 */
	public function calc( $calc, $order, $partner, $rate, $base, $attr ) {
		if ( ! $order instanceof WC_Order ) return $calc;
		$pid = (int) $partner->partner_id; $items = array(); $amount = 0.0; $notes = array();
		$renewal = is_array( $attr ) && ! empty( $attr['renewal'] ) ? $attr['renewal'] : null;
		$lines = self::order_lines( $order, true ); if ( ! $lines ) return $calc;

		if ( $renewal ) {
			$k = (int) ( $renewal['k'] ?? 1 ); $parent = ! empty( $renewal['parent'] ) ? wc_get_order( (int) $renewal['parent'] ) : null;
			$snap = $parent ? $parent->get_meta( self::ORDER_RULES ) : null; $snap = is_array( $snap ) && ! empty( $snap['rules'] ) ? $snap['rules'] : array();
			$pmap = array(); if ( $parent ) foreach ( self::order_lines( $parent, false ) as $pl ) { $pmap[ $pl['product_id'] ] = $pl; if ( $pl['variation_id'] ) $pmap[ 'v' . $pl['variation_id'] ] = $pl; }
			$s = proof_partner_settings(); $gmode = (string) ( $s['renewal_mode'] ?? 'none' ); $gn = max( 1, (int) ( $s['renewal_n'] ?? 3 ) );
			foreach ( $lines as $ln ) {
				$pl = $pmap[ 'v' . $ln['variation_id'] ] ?? ( $pmap[ $ln['product_id'] ] ?? null );
				$scope = $pl ? $pl['scope'] : $ln['scope']; $label = self::scope_label( $scope );
				$fr = $snap[ $scope . '|sub' ] ?? ( $snap[ $scope . '|onetime' ] ?? null );
				$rule = $fr && ! empty( $fr['rule'] ) ? self::sanitize_rule( $fr['rule'] ) : null;
				$rmode = $rule ? $rule['ren']['mode'] : 'inherit';
				if ( 'inherit' === $rmode ) {
					if ( 'none' === $gmode || ( 'first_n' === $gmode && $k > $gn ) ) { $a = 0.0; $lbl = 'подновяване №' . $k . ' · глобално: без'; $mode = 'none'; $val = 0; }
					else { $a = round( $ln['base'] * $rate / 100, 2 ); $lbl = 'подновяване №' . $k . ' · ' . self::fmt( $rate ) . '% от ' . number_format( $ln['base'], 2 ); $mode = 'rate'; $val = $rate; }
				} elseif ( 'none' === $rmode || ( 'first_n' === $rmode && $k > (int) $rule['ren']['n'] ) ) { $a = 0.0; $lbl = 'подновяване №' . $k . ' · правило: без'; $mode = 'none'; $val = 0; }
				else {
					$cm = $rule['ren']['cmode']; $cv = (float) $rule['ren']['value'];
					if ( 'same' === $cm ) { $mode = $rule['comm']['mode']; $val = (float) $rule['comm']['value']; }
					else { $mode = $cm; $val = $cv; }
					$a = self::comm_amount( $mode, $val, $ln['base'], $ln['qty'], $rate );
					$lbl = 'подновяване №' . $k . ' · ' . self::rule_text( $mode, $val, $ln['base'], $ln['qty'], $rate );
				}
				$items[] = array( 'scope' => $scope, 'label' => $label, 'kind' => 'renewal', 'renewal_n' => $k, 'base' => $ln['base'], 'qty' => $ln['qty'], 'mode' => $mode, 'value' => $val, 'amount' => $a, 'source' => $fr ? ( $fr['source'] ?? 'global' ) : 'default', 'text' => $lbl );
				$amount += $a; $notes[] = $label . ': ' . $lbl;
			}
		} else {
			$snapm = $order->get_meta( self::ORDER_RULES ); $snap = is_array( $snapm ) && ! empty( $snapm['rules'] ) ? $snapm['rules'] : null; $frozen = null !== $snap;
			$pr = $frozen ? null : self::partner_rules( $pid ); $gl = $frozen ? null : self::global_rules(); $any_rule = false;
			foreach ( $lines as $ln ) {
				$scope = $ln['scope']; $kind = $ln['kind'];
				if ( '_regular' === $scope ) { $a = round( $ln['base'] * $rate / 100, 2 ); $items[] = array( 'scope' => '_regular', 'label' => 'извън оферти', 'kind' => $kind, 'renewal_n' => 0, 'base' => $ln['base'], 'qty' => $ln['qty'], 'mode' => 'rate', 'value' => $rate, 'amount' => $a, 'source' => 'default', 'text' => self::fmt( $rate ) . '% от ' . number_format( $ln['base'], 2 ) ); $amount += $a; continue; }
				if ( $frozen ) { $fr = $snap[ $scope . '|' . $kind ] ?? ( $snap[ $scope . '|onetime' ] ?? ( $snap[ $scope . '|sub' ] ?? null ) ); $rule = $fr && ! empty( $fr['rule'] ) ? self::sanitize_rule( $fr['rule'] ) : null; $src = $fr ? ( $fr['source'] ?? 'default' ) : 'default'; }
				else { $res = self::resolve( $scope, $kind, $pid, $pr, $gl ); $rule = $res ? $res['rule'] : null; $src = $res ? $res['source'] . '·live' : 'default'; }
				if ( $rule ) { $any_rule = true; $mode = $rule['comm']['mode']; $val = (float) $rule['comm']['value']; }
				else { $mode = 'rate'; $val = $rate; }
				$a = self::comm_amount( $mode, $val, $ln['base'], $ln['qty'], $rate );
				$txt = self::rule_text( $mode, $val, $ln['base'], $ln['qty'], $rate );
				$items[] = array( 'scope' => $scope, 'label' => self::scope_label( $scope ), 'kind' => $kind, 'renewal_n' => 0, 'base' => $ln['base'], 'qty' => $ln['qty'], 'mode' => $mode, 'value' => $val, 'amount' => $a, 'source' => $src, 'text' => $txt );
				$amount += $a; $notes[] = self::scope_label( $scope ) . ( 'sub' === $kind ? ' 🔁' : '' ) . ': ' . $txt;
			}
			if ( ! $any_rule && ! $notes ) return $calc; /* нищо офертно — старата проста сметка */
			if ( ! $frozen && $notes ) $notes[] = 'без замразено правило';
		}
		$amount = round( $amount, 2 );
		return array( 'amount' => $amount, 'rate' => $base > 0 ? round( $amount / $base * 100, 3 ) : $rate, 'note' => '[оферти: ' . implode( ' + ', $notes ) . ']', 'items' => $items );
	}
	public static function rule_text( $mode, $val, $base, $qty, $rate ) {
		switch ( $mode ) {
			case 'none':  return 'без комисиона';
			case 'pct':   return self::fmt( $val ) . '% от ' . number_format( $base, 2 ) . ' = ' . number_format( $base * $val / 100, 2 );
			case 'fixed': return 'фикс ' . number_format( $val, 2 ) . ( $qty > 1 ? ' × ' . $qty : '' ) . ' = ' . number_format( $val * $qty, 2 );
			default:      return self::fmt( $rate ) . '% от ' . number_format( $base, 2 ) . ' = ' . number_format( $base * $rate / 100, 2 );
		}
	}

	/* ═══════════ 6. Разбивката в базата ═══════════ */
	public function write_items( $commission_id, $order, $partner, $calc ) {
		global $wpdb; if ( ! is_array( $calc ) || empty( $calc['items'] ) ) return;
		$t = PP_Schema::table( 'commission_items' ); $now = current_time( 'mysql' );
		$wpdb->delete( $t, array( 'commission_id' => (int) $commission_id ) );
		$d = $order->get_date_created(); $when = $d ? $d->date( 'Y-m-d H:i:s' ) : $now;
		foreach ( $calc['items'] as $it ) {
			$wpdb->insert( $t, array( 'commission_id' => (int) $commission_id, 'order_id' => (int) $order->get_id(), 'partner_id' => (int) $partner->partner_id,
				'scope' => substr( (string) $it['scope'], 0, 80 ), 'label' => substr( (string) $it['label'], 0, 120 ), 'kind' => substr( (string) $it['kind'], 0, 12 ), 'renewal_n' => (int) $it['renewal_n'],
				'base_amount' => (float) $it['base'], 'qty' => (int) $it['qty'], 'mode' => substr( (string) $it['mode'], 0, 12 ), 'value' => (float) $it['value'], 'amount' => (float) $it['amount'],
				'source' => substr( (string) $it['source'], 0, 20 ), 'text' => substr( (string) $it['text'], 0, 255 ), 'order_date' => $when, 'created_at' => $now ) );
		}
	}

	/* ═══════════ 7. REST ═══════════ */
	public function routes() {
		$p = array( 'permission_callback' => function () { return current_user_can( 'manage_woocommerce' ); } );
		register_rest_route( PP_Rest::NS, '/proof/offers', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'r_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'r_save' ) ) + $p,
		) );
		register_rest_route( PP_Rest::NS, '/proof/offers/partner/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'r_partner_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'r_partner_save' ) ) + $p,
		) );
		register_rest_route( PP_Rest::NS, '/proof/offers/ledger', array( 'methods' => 'GET', 'callback' => array( $this, 'r_ledger' ) ) + $p );
		register_rest_route( PP_Rest::NS, '/proof/offers/products', array( 'methods' => 'GET', 'callback' => array( $this, 'r_products' ) ) + $p );
	}
	public function r_get() {
		$s = proof_partner_settings();
		return rest_ensure_response( array( 'catalog' => self::catalog(), 'rules' => self::global_rules(), 'partner_counts' => self::partner_counts(), 'shrine' => class_exists( 'PShrine_Epack' ),
			'renewal_global' => array( 'mode' => (string) ( $s['renewal_mode'] ?? 'none' ), 'n' => (int) ( $s['renewal_n'] ?? 3 ), 'rate' => (string) ( $s['renewal_rate'] ?? '' ) ) ) );
	}
	public function r_save( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params(); $s = get_option( 'proof_partner_settings', array() ); if ( ! is_array( $s ) ) $s = array();
		if ( isset( $b['rules'] ) && is_array( $b['rules'] ) ) {
			$out = array();
			foreach ( $b['rules'] as $scope => $kinds ) { if ( ! self::valid_scope( $scope ) || ! is_array( $kinds ) ) continue; foreach ( $kinds as $ap => $r ) { if ( self::valid_applies( $ap ) && is_array( $r ) ) $out[ $scope ][ $ap ] = self::sanitize_rule( $r ); } }
			$s['offer_rules'] = $out; $s['offer_rules_set'] = 1;
		}
		if ( isset( $b['manual'] ) && is_array( $b['manual'] ) ) {
			$m = array();
			foreach ( $b['manual'] as $scope => $o ) { if ( ! is_array( $o ) ) continue; $scope = (string) $scope; if ( 0 !== strpos( $scope, 'man:' ) ) $scope = 'man:' . sanitize_title( substr( $scope, 0, 40 ) ); if ( ! self::valid_scope( $scope ) ) continue;
				$m[ $scope ] = array( 'label' => sanitize_text_field( (string) ( $o['label'] ?? $scope ) ), 'type' => 'cat' === ( $o['type'] ?? '' ) ? 'cat' : 'product', 'ids' => array_values( array_filter( array_map( 'intval', (array) ( $o['ids'] ?? array() ) ) ) ), 'retired' => empty( $o['retired'] ) ? 0 : 1 ); }
			$s['offer_manual'] = $m;
		}
		update_option( 'proof_partner_settings', $s );
		return $this->r_get();
	}
	public function r_partner_get( WP_REST_Request $req ) {
		$pid = (int) $req['id']; $p = PP_Partners::get( $pid ); if ( ! $p ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		return rest_ensure_response( array( 'partner_id' => $pid, 'rate' => (float) $p->rate, 'catalog' => array_values( array_filter( self::catalog(), function ( $c ) { return empty( $c['retired'] ); } ) ), 'global' => self::global_rules(), 'rules' => self::partner_rules( $pid ) ) );
	}
	public function r_partner_save( WP_REST_Request $req ) {
		global $wpdb; $pid = (int) $req['id']; if ( ! PP_Partners::get( $pid ) ) return new WP_Error( 'pp_partner', 'Няма такъв партньор.', array( 'status' => 404 ) );
		$b = (array) $req->get_json_params(); $t = PP_Schema::table( 'offer_rules' ); $now = current_time( 'mysql' );
		if ( isset( $b['rules'] ) && is_array( $b['rules'] ) ) {
			$wpdb->delete( $t, array( 'partner_id' => $pid ) );
			foreach ( $b['rules'] as $scope => $kinds ) { if ( ! self::valid_scope( $scope ) || ! is_array( $kinds ) ) continue; foreach ( $kinds as $ap => $r ) { if ( ! self::valid_applies( $ap ) || ! is_array( $r ) ) continue;
				$wpdb->insert( $t, array( 'partner_id' => $pid, 'scope' => $scope, 'applies' => $ap, 'rule' => wp_json_encode( self::sanitize_rule( $r ) ), 'updated_at' => $now ) ); } }
		}
		return $this->r_partner_get( $req );
	}
	/** Разбивката: страници по 30 комисиони, филтър по оферта/партньор; плочки-суми по оферта (won статуси + pending, без сторно) */
	public function r_ledger( WP_REST_Request $req ) {
		global $wpdb; $t = PP_Schema::table( 'commission_items' ); $c = PP_Schema::table( 'commissions' );
		$scope = sanitize_text_field( (string) $req->get_param( 'scope' ) ); $pid = (int) $req->get_param( 'partner_id' ); $page = max( 1, (int) $req->get_param( 'page' ) ); $per = 30;
		$w = array( "c.status <> 'reversed'" ); if ( $scope ) $w[] = $wpdb->prepare( 'i.scope = %s', $scope ); if ( $pid ) $w[] = $wpdb->prepare( 'i.partner_id = %d', $pid );
		$where = implode( ' AND ', $w );
		$tot = $wpdb->get_results( "SELECT i.scope, MAX(i.label) label, COUNT(DISTINCT i.order_id) orders, SUM(i.amount) amount, SUM(i.base_amount) base, SUM(i.kind='sub') subs, SUM(i.kind='renewal') renewals FROM {$t} i JOIN {$c} c ON c.commission_id = i.commission_id WHERE {$where} GROUP BY i.scope ORDER BY amount DESC" );
		$ids = $wpdb->get_col( "SELECT DISTINCT i.commission_id FROM {$t} i JOIN {$c} c ON c.commission_id = i.commission_id WHERE {$where} ORDER BY i.order_date DESC, i.commission_id DESC LIMIT " . ( ( $page - 1 ) * $per ) . ", {$per}" );
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT i.commission_id) FROM {$t} i JOIN {$c} c ON c.commission_id = i.commission_id WHERE {$where}" );
		$rows = array();
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) ); $names = array();
			foreach ( (array) $wpdb->get_results( 'SELECT partner_id, display_name FROM ' . PP_Schema::table( 'partners' ) ) as $n ) $names[ (int) $n->partner_id ] = $n->display_name;
			$cm = array(); foreach ( (array) $wpdb->get_results( "SELECT commission_id, order_id, partner_id, coupon_code, amount, status, created_at, currency FROM {$c} WHERE commission_id IN ({$in})" ) as $r ) $cm[ (int) $r->commission_id ] = $r;
			$its = array(); foreach ( (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE commission_id IN ({$in}) ORDER BY item_id ASC" ) as $i ) $its[ (int) $i->commission_id ][] = array( 'scope' => $i->scope, 'label' => $i->label, 'kind' => $i->kind, 'renewal_n' => (int) $i->renewal_n, 'base' => (float) $i->base_amount, 'amount' => (float) $i->amount, 'source' => $i->source, 'text' => $i->text );
			foreach ( $ids as $id ) { $r = $cm[ (int) $id ] ?? null; if ( ! $r ) continue; $rows[] = array( 'commission_id' => (int) $id, 'order_id' => (int) $r->order_id, 'partner_id' => (int) $r->partner_id, 'partner' => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ), 'coupon_code' => $r->coupon_code, 'amount' => (float) $r->amount, 'status' => $r->status, 'created_at' => $r->created_at, 'currency' => $r->currency, 'items' => $its[ (int) $id ] ?? array() ); }
		}
		return rest_ensure_response( array( 'totals' => array_map( function ( $x ) { return array( 'scope' => $x->scope, 'label' => '_regular' === $x->scope ? 'извън оферти' : ( $x->label ?: self::scope_label( $x->scope ) ), 'orders' => (int) $x->orders, 'amount' => round( (float) $x->amount, 2 ), 'base' => round( (float) $x->base, 2 ), 'subs' => (int) $x->subs, 'renewals' => (int) $x->renewals ); }, (array) $tot ), 'rows' => $rows, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per ) ), 'total' => $total ) );
	}
	/** Търсачка за ръчни оферти: продукти и категории по име */
	public function r_products( WP_REST_Request $req ) {
		$q = sanitize_text_field( (string) $req->get_param( 'q' ) ); $out = array( 'products' => array(), 'cats' => array() );
		if ( strlen( $q ) < 2 ) return rest_ensure_response( $out );
		foreach ( (array) get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 's' => $q, 'posts_per_page' => 15, 'fields' => 'ids' ) ) as $id ) $out['products'][] = array( 'id' => (int) $id, 'name' => get_the_title( $id ) );
		foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'name__like' => $q, 'number' => 15 ) ) as $tm ) if ( is_object( $tm ) ) $out['cats'][] = array( 'id' => (int) $tm->term_id, 'name' => $tm->name );
		return rest_ensure_response( $out );
	}
}
