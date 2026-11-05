<?php
/* php tests/sub-policy-test.php — абонаментната политика (2.2.0): глобални/лични стойности, резолюция (изрично
 * правило бие политиката; извън оферти = политиката само за абонамент), отстъпката за клиента, снимката при
 * checkout, сметката за първа поръчка и подновяване (№k, режим, лично %), разпознаване на Sublium подновяване
 * (_sublium_wcs_* + таблицата) и атрибуцията му. Без WordPress — стъбове. */
define( 'ABSPATH', __DIR__ . '/' );
$SETTINGS = []; $PARTNERS = []; $ORDERS = []; $LOGS = [];
function proof_partner_settings() { global $SETTINGS; return array_merge( [ 'default_rate' => 10, 'include_vat' => 0, 'renewal_mode' => 'forever', 'renewal_n' => 3, 'renewal_rate' => '', 'sub_first_rate' => 10, 'sub_renewal_rate' => 5, 'sub_customer_discount' => 'none', 'offer_rules' => [], 'offer_rules_set' => 1, 'offer_manual' => [] ], $SETTINGS ); }
function proof_partner_excluded_product_ids() { return []; }
function add_filter() {} function add_action() {} function register_rest_route() {} function apply_filters( $h, $v ) { return $v; }
function wc_get_price_decimals() { return 2; } function current_time( $t ) { return '2026-11-05 12:00:00'; } function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); } function sanitize_text_field( $s ) { return trim( (string) $s ); } function wc_format_coupon_code( $c ) { return strtolower( trim( (string) $c ) ); }
function wc_get_order( $id ) { global $ORDERS; return $ORDERS[ (int) $id ] ?? null; } function esc_sql( $s ) { return $s; }
class WC_Coupon { public $code; public $type; function __construct( $c, $t = 'percent' ) { $this->code = $c; $this->type = $t; } function get_code() { return $this->code; } function get_discount_type() { return $this->type; } }
class FakeItem { public $m = []; public $pid; public $vid; public $qty; public $total; function __construct( $pid, $total, $meta = [], $qty = 1, $vid = 0 ) { $this->pid = $pid; $this->total = $total; $this->m = $meta; $this->qty = $qty; $this->vid = $vid; }
	function get_product_id() { return $this->pid; } function get_variation_id() { return $this->vid; } function get_quantity() { return $this->qty; } function get_total() { return $this->total; } function get_total_tax() { return 0; }
	function get_meta( $k ) { return $this->m[ $k ] ?? ''; } function get_meta_data() { $o = []; foreach ( $this->m as $k => $v ) $o[] = (object) [ 'key' => $k, 'value' => $v ]; return $o; } function add_meta_data( $k, $v ) { $this->m[ $k ] = $v; } }
class WC_Order { public $id; public $items = []; public $meta = []; public $parent = 0; public $date; public $coupons = []; public $status = 'completed';
	function __construct( $id, $items = [], $meta = [], $parent = 0, $date = '2026-11-01' ) { $this->id = $id; $this->items = $items; $this->meta = $meta; $this->parent = $parent; $this->date = $date; }
	function get_id() { return $this->id; } function get_items( $t = '' ) { return $this->items; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() {}
	function get_total_refunded_for_item( $i ) { return 0; } function get_parent_id() { return $this->parent; } function get_date_created() { return new DateTime( $this->date ); } function get_coupon_codes() { return $this->coupons; } function get_status() { return $this->status; } }
class PP_Schema { public static function table( $n ) { return 'wp_proof_partner_' . $n; } }
class PP_Partners { public static function get( $id ) { global $PARTNERS; return $PARTNERS[ (int) $id ] ?? null; } }
class PP_Coupons { public static $map = [ 'proof10' => 5 ]; public static function get_mapping_by_code( $c ) { $c = strtolower( $c ); return isset( self::$map[ $c ] ) ? (object) [ 'active' => 1, 'partner_id' => self::$map[ $c ], 'coupon_code' => $c ] : null; }
	public static function attribute_order( $o ) { foreach ( $o->get_coupon_codes() as $c ) { $m = self::get_mapping_by_code( $c ); if ( $m ) return [ 'partner_id' => $m->partner_id, 'coupon_code' => $c, 'anomaly' => '' ]; } return null; } }
class PP_Attribution { const META_LOCK = '_proof_partner_attribution'; const SESSION_LINK = 'x'; public static function olog( $o, $t ) { global $LOGS; $LOGS[] = $t; } }
class PP_Manager { const CAP = 'x'; } class PP_Rest { const NS = 'x'; }
/* $wpdb стъб: таблицата на Sublium + книгата (брой по-ранни подновявания с комисиона) */
class FakeWpdb { public $prefix = 'wp_'; public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts'; public $sub_parent = []; public $sub_orders = []; public $paid = [];
	function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%[ds]/', is_numeric( $v ) ? (string) $v : "'" . $v . "'", $q, 1 ); } return $q; }
	function get_var( $q ) { if ( false !== strpos( $q, 'SHOW TABLES' ) ) return false !== strpos( $q, 'sublium' ) ? 'wp_sublium_wcs_subscriptions' : ''; if ( preg_match( '/parent_order_id FROM .* WHERE id = (\d+)/', $q, $m ) ) return $this->sub_parent[ (int) $m[1] ] ?? 0; if ( false !== strpos( $q, 'COUNT(*)' ) && preg_match( '/IN \(([\d,]+)\) AND order_id <> (\d+)/', $q, $m ) ) { $n = 0; foreach ( explode( ',', $m[1] ) as $i ) if ( (int) $i !== (int) $m[2] && in_array( (int) $i, $this->paid, true ) ) $n++; return $n; } return 0; }
	function get_col( $q ) { if ( preg_match( "/meta_value = '?(\d+)'?/", $q, $m ) && false !== strpos( $q, 'postmeta' ) ) return $this->sub_orders[ (int) $m[1] ] ?? []; return []; }
	function get_results() { return []; } function get_row() { return null; } function insert() { return 1; } function delete() {} }
$wpdb = new FakeWpdb();

require __DIR__ . '/../includes/class-pp-offers.php';
require __DIR__ . '/../includes/class-pp-proof.php';
$OF = new PP_Offers(); $PR = new PP_Proof();
$T = 0; $F = 0; function ok( $c, $m ) { global $T, $F; $T++; if ( $c ) echo "  ✓ $m\n"; else { $F++; echo "  ✗ $m\n"; } }
$PARTNERS[5] = (object) [ 'partner_id' => 5, 'rate' => 15, 'status' => 'active', 'display_name' => 'Иван', 'sub_first_rate' => null, 'sub_renewal_rate' => null, 'sub_discount' => '' ];
$PARTNERS[6] = (object) [ 'partner_id' => 6, 'rate' => 12, 'status' => 'active', 'display_name' => 'Мария', 'sub_first_rate' => '12.5', 'sub_renewal_rate' => null, 'sub_discount' => 'coupon' ];
$P5 = $PARTNERS[5]; $P6 = $PARTNERS[6];

echo "1. Политиката: глобална и лична\n";
$g = PP_Offers::global_policy(); ok( 10.0 === $g['first'] && 5.0 === $g['renewal'] && 'none' === $g['disc'] && 'forever' === $g['mode'], 'глобално: първа 10 % · подновявания 5 % винаги · клиент без бонус' );
$p = PP_Offers::sub_policy( 6, $P6 ); ok( 12.5 === $p['first'] && 5.0 === $p['renewal'] && 'coupon' === $p['disc'] && 'policy·partner' === $p['source'] && [ 'first', 'disc' ] === $p['own'], 'лично: първа 12.5 % (своя), подновяване 5 % (глобално), клиент по купона' );
ok( 'policy' === PP_Offers::sub_policy( 5, $P5 )['source'], 'партньор без лични стойности → source policy' );
$r = PP_Offers::policy_rule( 6, $P6 ); ok( 'pct' === $r['comm']['mode'] && 12.5 === $r['comm']['value'] && 'coupon' === $r['disc']['mode'] && 'forever' === $r['ren']['mode'] && 'pct' === $r['ren']['cmode'] && 5.0 === $r['ren']['value'], 'policy_rule = обикновено правило (замразява се като всяко друго)' );
$SETTINGS = [ 'renewal_mode' => 'first_n', 'renewal_n' => 2, 'sub_renewal_rate' => '7,5' ]; $g = PP_Offers::global_policy(); ok( 'first_n' === $g['mode'] && 2 === $g['n'] && 7.5 === $g['renewal'], 'режим първите N + запетая в процента' ); $SETTINGS = []; PP_Offers::flush_policy_cache();

echo "2. Резолюция\n";
$res = PP_Offers::resolve( 'pack:essentials', 'sub', 5, [], [] ); ok( $res && 'policy' === $res['source'] && 10.0 === $res['rule']['comm']['value'], 'абонамент без правило → политиката' );
ok( null === PP_Offers::resolve( 'pack:essentials', 'onetime', 5, [], [] ), 'еднократна без правило → по ставката (null)' );
$res = PP_Offers::resolve( '_regular', 'sub', 5, [], [] ); ok( $res && 'policy' === $res['source'], 'абонамент ИЗВЪН оферти → политиката' );
ok( null === PP_Offers::resolve( '_regular', 'onetime', 5, [], [] ), 'еднократна извън оферти → null' );
$own = [ 'pack:essentials' => [ 'sub' => [ 'comm' => [ 'mode' => 'fixed', 'value' => 3 ], 'disc' => [ 'mode' => 'coupon' ], 'ren' => [ 'mode' => 'none' ] ] ] ];
$res = PP_Offers::resolve( 'pack:essentials', 'sub', 5, $own, [] ); ok( 'partner' === $res['source'] && 'fixed' === $res['rule']['comm']['mode'], 'изрично лично правило бие политиката' );
$gl = [ 'pack:essentials' => [ 'any' => [ 'comm' => [ 'mode' => 'pct', 'value' => 20 ] ] ] ];
$res = PP_Offers::resolve( 'pack:essentials', 'sub', 5, [], $gl ); ok( 'global' === $res['source'] && 'any' === $res['applies'], 'глобално „1× и 🔁" бие политиката' );

echo "3. Отстъпката за клиента (woocommerce_coupon_get_discount_amount)\n";
$cp = new WC_Coupon( 'PROOF10' );
ok( 0 === $OF->discount( 4.0, 40.0, [ 'product_id' => 1, 'sublium_wcs_plan' => 2, 'quantity' => 1 ], false, $cp ), 'абонаментен ред (извън оферти) → 0 отстъпка (без бонус)' );
ok( 4.0 === $OF->discount( 4.0, 40.0, [ 'product_id' => 1, 'quantity' => 1 ], false, $cp ), 'еднократен ред → отстъпката по купона остава' );
ok( 0 === $OF->discount( 4.0, 40.0, [ 'product_id' => 1, 'pshrine_epack' => 1, 'pshrine_epack_pack' => 'essentials', 'sublium_wcs_plan' => 2, 'quantity' => 1 ], false, $cp ), 'EPACK абонаментен ред → 0' );
PP_Coupons::$map['maria'] = 6; $cp6 = new WC_Coupon( 'MARIA' );
ok( 4.0 === $OF->discount( 4.0, 40.0, [ 'product_id' => 1, 'sublium_wcs_plan' => 2, 'quantity' => 1 ], false, $cp6 ), 'партньор с „по купона" → клиентът получава отстъпката и на абонамента' );
ok( 4.0 === $OF->discount( 4.0, 40.0, [ 'product_id' => 1, 'sublium_wcs_plan' => 2, 'quantity' => 1 ], false, new WC_Coupon( 'OTHER' ) ), 'чужд купон → не пипаме' );

echo "4. Първа поръчка: снимка + сметка\n";
$o1 = new WC_Order( 100, [ new FakeItem( 11, 100, [ '_proof_offer_scope' => 'pack:essentials', '_proof_offer_kind' => 'sub', '_sublium_wcs_plan' => 2 ] ), new FakeItem( 12, 50, [ '_proof_offer_scope' => 'prod:12', '_proof_offer_kind' => 'onetime' ] ), new FakeItem( 13, 30, [ '_proof_offer_scope' => '_regular', '_proof_offer_kind' => 'sub', '_sublium_wcs_plan' => 2 ] ), new FakeItem( 14, 20, [] ) ] );
$o1->coupons = [ 'proof10' ]; $o1->meta['_sublium_wcs_subscription_id'] = 152;
$OF->snapshot( $o1 ); $snap = $o1->get_meta( PP_Offers::ORDER_RULES );
ok( isset( $snap['rules']['pack:essentials|sub'] ) && 'policy' === $snap['rules']['pack:essentials|sub']['source'] && isset( $snap['rules']['_regular|sub'] ) && ! isset( $snap['rules']['_regular|onetime'] ), 'снимката носи политиката за абонаментните редове (и извън оферти), не за еднократните извън оферти' );
$c = $OF->calc( null, $o1, $P5, 15.0, 200.0, [ 'partner_id' => 5 ] );
ok( 10.0 + 7.5 + 3.0 + 3.0 === $c['amount'], 'сметка: 100×10 % (политика) + 50×15 % (prod без правило → ставката) + 30×10 % (извън оферти, абонамент) + 20×15 % = ' . $c['amount'] );
$by = []; foreach ( $c['items'] as $it ) $by[ $it['scope'] . '|' . $it['kind'] ] = $it;
ok( 'policy' === $by['pack:essentials|sub']['source'] && 'policy' === $by['_regular|sub']['source'] && 'default' === $by['_regular|onetime']['source'], 'източници по редове' );
$P6o = new WC_Order( 101, [ new FakeItem( 11, 100, [ '_proof_offer_scope' => 'pack:essentials', '_proof_offer_kind' => 'sub', '_sublium_wcs_plan' => 2 ] ) ] ); $P6o->coupons = [ 'maria' ]; $OF->snapshot( $P6o );
$c = $OF->calc( null, $P6o, $P6, 12.0, 100.0, [ 'partner_id' => 6 ] ); ok( 12.5 === $c['amount'] && 'policy·partner' === $c['items'][0]['source'], 'лична първа ставка 12.5 % → 12.50' );
$old = new WC_Order( 90, [ new FakeItem( 11, 100, [ '_proof_offer_scope' => 'pack:essentials', '_proof_offer_kind' => 'sub' ] ) ], [ PP_Offers::ORDER_RULES => [ 'v' => 1, 'rules' => [ 'pack:essentials|sub' => [ 'scope' => 'pack:essentials', 'kind' => 'sub', 'rule' => null, 'source' => 'default', 'label' => 'E' ] ] ] ] );
$c = $OF->calc( null, $old, $P5, 15.0, 100.0, [ 'partner_id' => 5 ] ); ok( 15.0 === $c['amount'], 'стара поръчка (снимка преди 2.2 без правило) → остава по ставката (замразено)' );

echo "5. Подновяване\n";
$ORDERS[100] = $o1;
$ren = new WC_Order( 200, [ new FakeItem( 11, 100, [ '_sublium_wcs_plan' => 2 ] ), new FakeItem( 13, 30, [ '_sublium_wcs_plan' => 2 ] ) ], [ '_sublium_wcs_subscription_renewal' => 'yes', '_sublium_wcs_subscription_id' => 152 ], 100, '2027-01-04' );
$c = $OF->calc( null, $ren, $P5, 5.0, 130.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 1, 'parent' => 100, 'sub' => 152 ] ] );
ok( 6.5 === $c['amount'] && 'renewal' === $c['items'][0]['kind'] && 1 === $c['items'][0]['renewal_n'], 'подновяване №1: (100+30)×5 % = 6.50, редовете се мапват към офертите на родителя' );
$P5->sub_renewal_rate = '8'; PP_Offers::flush_policy_cache();
$c = $OF->calc( null, $ren, $P5, 5.0, 130.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 3, 'parent' => 100, 'sub' => 152 ] ] ); ok( 10.4 === $c['amount'], 'лична ставка за подновяване 8 % → 10.40 (живите условия на партньора, не снимката)' );
$SETTINGS = [ 'renewal_mode' => 'first_n', 'renewal_n' => 2 ]; PP_Offers::flush_policy_cache();
$c = $OF->calc( null, $ren, $P5, 5.0, 130.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 3, 'parent' => 100, 'sub' => 152 ] ] ); ok( 0.0 === $c['amount'] && false !== strpos( $c['items'][0]['text'], 'политика: без' ), 'първите 2 → №3 е без комисиона' );
$c = $OF->calc( null, $ren, $P5, 5.0, 130.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 2, 'parent' => 100, 'sub' => 152 ] ] ); ok( 10.4 === $c['amount'], '№2 ≤ 2 → плаща' );
$SETTINGS = []; $P5->sub_renewal_rate = null; PP_Offers::flush_policy_cache();
$ORDERS[90] = $old; $ren_old = new WC_Order( 201, [ new FakeItem( 11, 100, [ '_sublium_wcs_plan' => 2 ] ) ], [ '_sublium_wcs_subscription_renewal' => 'yes', '_sublium_wcs_subscription_id' => 77 ], 90 );
$c = $OF->calc( null, $ren_old, $P5, 5.0, 100.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 1, 'parent' => 90, 'sub' => 77 ] ] ); ok( 5.0 === $c['amount'] && 'policy·live' === $c['items'][0]['source'], 'родител без замразено правило → живата политика (5 %)' );
$own_parent = new WC_Order( 95, [ new FakeItem( 11, 100, [ '_proof_offer_scope' => 'pack:essentials', '_proof_offer_kind' => 'sub' ] ) ], [ PP_Offers::ORDER_RULES => [ 'v' => 1, 'rules' => [ 'pack:essentials|sub' => [ 'scope' => 'pack:essentials', 'kind' => 'sub', 'rule' => [ 'comm' => [ 'mode' => 'fixed', 'value' => 3 ], 'disc' => [ 'mode' => 'none' ], 'ren' => [ 'mode' => 'forever', 'cmode' => 'same' ] ], 'source' => 'partner', 'label' => 'E' ] ] ] ] ); $ORDERS[95] = $own_parent;
$ren2 = new WC_Order( 202, [ new FakeItem( 11, 100, [ '_sublium_wcs_plan' => 2 ] ) ], [ '_sublium_wcs_subscription_renewal' => 'yes', '_sublium_wcs_subscription_id' => 78 ], 95 );
$c = $OF->calc( null, $ren2, $P5, 5.0, 100.0, [ 'partner_id' => 5, 'renewal' => [ 'k' => 1, 'parent' => 95, 'sub' => 78 ] ] ); ok( 3.0 === $c['amount'] && 'partner' === $c['items'][0]['source'], 'изрично замразено правило (фикс 3 € · винаги · както 1-вата) бие политиката' );

echo "6. Sublium подновяване: разпознаване и атрибуция\n";
$o1->meta[ PP_Attribution::META_LOCK ] = [ 'partner_id' => 5, 'coupon_code' => 'proof10', 'rate' => 15 ];
$wpdb->sub_parent[152] = 100; $wpdb->sub_orders[152] = [ 100, 200 ];
ok( 0 === PP_Proof::is_renewal( $o1 ), 'родителската поръчка (носи sub id, таблицата сочи нея) НЕ е подновяване' );
ok( 152 === PP_Proof::is_renewal( $ren ), 'подновяването (_sublium_wcs_subscription_renewal=yes) е подновяване' );
$noflag = new WC_Order( 203, [], [ '_sublium_wcs_subscription_id' => 152 ], 0, '2027-03-04' ); ok( 152 === PP_Proof::is_renewal( $noflag ), 'без флаг, но таблицата сочи друг родител → подновяване' );
list( $parent, $lock ) = PP_Proof::parent_lock( $ren, 152 ); ok( 100 === $parent && 5 === (int) $lock['partner_id'], 'родителят и lock-ът му през parent_id / таблицата' );
ok( [ 100, 200 ] === PP_Proof::subscription_orders( 152 ) && [ 100 ] === PP_Proof::subscription_orders( 152, 200 ), 'поръчките на абонамента (по _sublium_wcs_subscription_id)' );
$attr = $PR->renewal_attribution( null, $ren );
ok( is_array( $attr ) && 5 === $attr['partner_id'] && 1 === $attr['renewal']['k'] && 100 === $attr['renewal']['parent'] && 5.0 === $attr['rate'], 'атрибуция: партньор 5, подновяване №1, ставка 5 % (политика)' );
$attr = $PR->renewal_attribution( [ 'partner_id' => 5, 'coupon_code' => 'proof10', 'anomaly' => '' ], $ren );
ok( is_array( $attr ) && ! empty( $attr['renewal'] ), 'Sublium е копирал купона върху подновяването → пак е подновяване, не първа поръчка' );
$wpdb->paid = [ 200 ]; $ren3 = new WC_Order( 204, [], [ '_sublium_wcs_subscription_renewal' => 'yes', '_sublium_wcs_subscription_id' => 152 ], 100, '2027-03-04' ); $wpdb->sub_orders[152] = [ 100, 200, 204 ];
$attr = $PR->renewal_attribution( null, $ren3 ); ok( 2 === $attr['renewal']['k'], '№k = 1 + по-ранните подновявания с комисиона (200) → №2' );
$SETTINGS = [ 'renewal_mode' => 'first_n', 'renewal_n' => 1 ]; PP_Offers::flush_policy_cache();
ok( null === $PR->renewal_attribution( null, $ren3 ), 'първите 1 → №2 без атрибуция' );
$SETTINGS = [ 'renewal_mode' => 'none' ]; PP_Offers::flush_policy_cache(); ok( null === $PR->renewal_attribution( null, $ren ), 'режим „никакви" → без' );
$SETTINGS = []; PP_Offers::flush_policy_cache();
ok( null === $PR->renewal_attribution( null, $o1 ), 'първата поръчка не минава през подновяване (атрибуцията ѝ е от lock-а)' );
$first_cp = new WC_Order( 300, [], [ '_sublium_wcs_subscription_id' => 160 ], 0 ); $first_cp->coupons = [ 'proof10' ]; $wpdb->sub_parent[160] = 300;
$a = [ 'partner_id' => 5, 'coupon_code' => 'proof10', 'anomaly' => '' ]; ok( $a === $PR->renewal_attribution( $a, $first_cp ), 'първа поръчка с купон (sub id, таблицата сочи нея) → атрибуцията по купон остава непроменена' );
$PARTNERS[5]->status = 'paused'; ok( null === $PR->renewal_attribution( null, $ren ), 'спрян партньор → подновяването не плаща' ); $PARTNERS[5]->status = 'active';

echo "\n$T проверки, $F грешки\n"; exit( $F ? 1 : 0 );
