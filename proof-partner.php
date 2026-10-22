<?php
/**
 * Plugin Name: Proof Partner
 * Description: Партньорска (affiliate) система за proofnutrition.eu. Купон при checkout = комисиона; per-partner ставки; правила за EPACK пакетите (фиксирана сума или %, с/без отстъпка за клиента); комисиона върху Sublium подновявания (никога / първите N / докато е активен); импорт на съществуващи купони (Advanced Coupons); lock при checkout, holding период, clawback, нощна равносметка, fullscreen табло, портал /partner.
 * Version: 2.0.3
 * GitHub Plugin URI: proclaudecopilot/proof-partner
 * Author: Proof Nutrition
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Proof Partner</strong> изисква PHP 7.4+ (тук: ' . esc_html( PHP_VERSION ) . '). Плъгинът е спрян защитно.</p></div>';
	} );
	return;
}
if ( defined( 'PROOF_PARTNER_VERSION' ) ) {
	// Второ копие на плъгина (стара папка + нова). Излизаме тихо, сайтът живее.
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Proof Partner:</strong> открити са ДВЕ копия на плъгина. Това копие се самоизключи. Изтрий старата папка в wp-content/plugins (остави само една proof-partner).</p></div>';
	} );
	return;
}
define( 'PROOF_PARTNER_VERSION', '2.0.3' );
define( 'PROOF_PARTNER_FILE', __FILE__ );
define( 'PROOF_PARTNER_DIR', plugin_dir_path( __FILE__ ) );

// ЩИТ: гръм при зареждане НЕ събаря сайта — плъгинът се самоизключва
// функционално, записва точната грешка и я показва като admin notice.
if ( ! function_exists( 'proof_partner_log_shield' ) ) {
function proof_partner_log_shield( $where, $e ) {
	$log   = (array) get_option( 'proof_partner_fatal', array() );
	$log[] = array( 't' => gmdate( 'Y-m-d H:i:s' ), 'where' => $where, 'msg' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine() );
	update_option( 'proof_partner_fatal', array_slice( $log, -5 ), false );
	// Веднага при теб: Telegram (1/час на място, за да не залива)
	if ( function_exists( 'proof_partner_telegram' ) && ! get_transient( 'proof_partner_shield_tg_' . md5( $where ) ) ) {
		set_transient( 'proof_partner_shield_tg_' . md5( $where ), 1, HOUR_IN_SECONDS );
		proof_partner_telegram( '🛑 Proof Partner улови грешка [' . $where . ']: ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	}
}
}
try {
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-schema.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-partners.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-coupons.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-attribution.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-engine.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-admin.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-payouts.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-dashboard.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-reconcile.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-diagnostics.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-rest.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-app.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-portal.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-game.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-game-view.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-proof.php';
	require_once PROOF_PARTNER_DIR . 'includes/class-pp-offers.php';
} catch ( \Throwable $proof_partner_boot_error ) {
	proof_partner_log_shield( 'boot', $proof_partner_boot_error );
	add_action( 'admin_notices', function () use ( $proof_partner_boot_error ) {
		echo '<div class="notice notice-error"><p><strong>Proof Partner е спрян защитно.</strong> Грешка: '
			. esc_html( $proof_partner_boot_error->getMessage() ) . ' в '
			. esc_html( $proof_partner_boot_error->getFile() . ':' . $proof_partner_boot_error->getLine() )
			. '. Сайтът работи; прати този ред на разработчика.</p></div>';
	} );
	return; // спираме САМО плъгина, не сайта
}


/* ------------------------------------------------------------------ */
/* Cron / rewrite registration (shared by activation + upgrade guard)  */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'proof_partner_register_crons' ) ) {
function proof_partner_register_crons() {
	if ( ! wp_next_scheduled( 'proof_partner_promote_pending' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'proof_partner_promote_pending' );
	}
	if ( ! wp_next_scheduled( 'proof_partner_reconcile' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 03:30' ), 'daily', 'proof_partner_reconcile' );
	}
}
}

if ( ! function_exists( 'proof_partner_register_rewrite' ) ) {
function proof_partner_register_rewrite() {
	add_rewrite_rule( '^p/([A-Za-z0-9\-_]+)/?$', 'index.php?proof_partner_ref=$matches[1]', 'top' );
	add_rewrite_rule( '^partner/?$', 'index.php?proof_partner_portal=1', 'top' );
}
}

/* ------------------------------------------------------------------ */
/* Activation / deactivation                                           */
/* ------------------------------------------------------------------ */

register_activation_hook( __FILE__, function () {
	try {
	PP_Schema::install();
	update_option( 'proof_partner_version', PROOF_PARTNER_VERSION );

	// Default settings on first activation only.
	if ( false === get_option( 'proof_partner_settings', false ) ) {
		update_option( 'proof_partner_settings', array(
			'trigger_status'        => 'completed',
			'holding_days'          => 7,
			'default_rate'          => 10.0,
			'excluded_products'     => '',
			'default_coupon_type'   => 'percent',
			'default_coupon_amount' => 5,
			'block_self_referrals'  => 1,
			'velocity_min_weekly'   => 10,
			'velocity_multiplier'   => 5,
			'include_vat'           => 1,
			'allowed_with_partner'  => '',
			'safe_mode'             => 0,
			'refund_alert_pct'      => 25,
			'portal_auto_approve'   => 1,
			'kit_story'             => 'Открих {brand} и не спирам 💚 С кода {code} взимаш {discount} — или директно през линка ми: {link}',
			'kit_post'              => 'Моята {brand} рутина, честно: поръчвам от месеци и разликата се усеща. Кодът {code} ти дава {discount}, а линкът {link} го прилага сам. Питай ме в коментарите 👇',
			'kit_campaign'          => '',
			'tiers_enabled'         => 0,
			'tiers'                 => "Бронз|0|10\nСребро|300|12\nЗлато|800|15",
			'brand_name'            => '',
			'brand_company'         => '',
			'brand_company_id'      => '',
			'brand_company_address' => '',
			'brand_accent'          => '#2BB6EA',
			'brand_logo_url'        => '',
			'brand_accent2'         => '#16303F',
			'brand_code_suffix'     => 'PROOF',
		'brand_accent2'         => '#16303F',
		'brand_code_suffix'     => 'PROOF',
		) );
	}

	proof_partner_register_crons();

	// Vanity links /p/CODE — register the rule, then flush once.
	proof_partner_register_rewrite();
	flush_rewrite_rules();
	} catch ( \Throwable $e ) { proof_partner_log_shield( 'activation', $e ); }
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'proof_partner_promote_pending' );
	wp_clear_scheduled_hook( 'proof_partner_reconcile' );
	flush_rewrite_rules();
	// Tables are intentionally NOT dropped.
} );

/* HPOS compatibility */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/* Schema upgrade guard */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'proof_partner_version' ) !== PROOF_PARTNER_VERSION ) {
		// Нова версия на файловете: изгони СТАРИТЕ байткодове от OPcache.
		// (LiteSpeed + FTP замяна без рестарт = смес от стар и нов код = фатали.)
		if ( function_exists( 'opcache_invalidate' ) ) {
			foreach ( (array) glob( PROOF_PARTNER_DIR . 'includes/*.php' ) as $pp_f ) @opcache_invalidate( $pp_f, true );
			@opcache_invalidate( __FILE__, true );
		}
		PP_Schema::install();
		update_option( 'proof_partner_version', PROOF_PARTNER_VERSION );

		// Versioned migrations — всяка тече точно веднъж, независимо от версии.
		$done = (array) get_option( 'proof_partner_migrations', array() );
		$mig  = function ( $key, $cb ) use ( &$done ) {
			if ( in_array( $key, $done, true ) ) return;
			$cb();
			$done[] = $key;
			update_option( 'proof_partner_migrations', $done, false );
		};
		$GLOBALS['proof_partner_mig'] = $mig;

		$mig( 'fix_literal_newlines_email', function () {
			$s = get_option( 'proof_partner_settings', array() );
			if ( isset( $s['migration_email'] ) && false !== strpos( $s['migration_email'], '\\n' ) ) {
				$s['migration_email'] = str_replace( array( '\\n\\n', '\\n' ), array( "\n\n", "\n" ), $s['migration_email'] );
				update_option( 'proof_partner_settings', $s );
			}
		} );

		$mig( 'purge_wrong_company_default', function () {
			$s = get_option( 'proof_partner_settings', array() );
			$dirty = false;
			if ( isset( $s['brand_company'] ) && 'ЛОГАДОР' === $s['brand_company'] ) { $s['brand_company'] = ''; $dirty = true; }
			if ( $dirty ) update_option( 'proof_partner_settings', $s );
		} );

		/* Proof 1.0.1: нищо „ansa" в настройките — суфикс/префикси/името на бранда/текстовете */
		$mig( 'proof_scrub_ansa_brand', function () {
			$s = get_option( 'proof_partner_settings', array() ); if ( ! is_array( $s ) ) $s = array();
			$dirty = false;
			if ( false !== stripos( (string) ( $s['brand_code_suffix'] ?? '' ), 'ansa' ) ) { $s['brand_code_suffix'] = 'PROOF'; $dirty = true; }
			foreach ( array( 'brand_name', 'brand_company', 'allowed_with_partner' ) as $k ) {
				$v = (string) ( $s[ $k ] ?? '' );
				if ( false !== stripos( $v, 'ansa' ) || false !== stripos( $v, 'logador' ) || false !== mb_stripos( $v, 'логадор' ) ) { $s[ $k ] = ''; $dirty = true; }
			}
			if ( 'hi' === trim( (string) ( $s['legacy_link_prefixes'] ?? '' ) ) ) { $s['legacy_link_prefixes'] = ''; $dirty = true; }
			foreach ( array( 'kit_story', 'kit_post', 'kit_campaign', 'migration_email' ) as $k ) {
				if ( false !== stripos( (string) ( $s[ $k ] ?? '' ), 'ansa' ) ) { unset( $s[ $k ] ); $dirty = true; } /* пада на Proof default-а */
			}
			if ( $dirty ) update_option( 'proof_partner_settings', $s );
		} );
		$mig( 'strip_wc_prefix_trigger', function () {
			$s = get_option( 'proof_partner_settings', array() );
			if ( ! empty( $s['trigger_status'] ) && 0 === strpos( $s['trigger_status'], 'wc-' ) ) {
				$s['trigger_status'] = substr( $s['trigger_status'], 3 );
				update_option( 'proof_partner_settings', $s );
			}
		} );
		// Датите на комисионите = датата на ПОРЪЧКАТА (еднократно лечение).
		$mig( 'commission_dates_order_based', function () {
			global $wpdb;
			$c = $wpdb->prefix . 'proof_partner_commissions';
			if ( get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes' ) {
				$wpdb->query( "UPDATE {$c} l JOIN {$wpdb->prefix}wc_orders o ON o.id = l.order_id
					SET l.created_at = COALESCE(o.date_created_gmt, l.created_at)
					WHERE l.type = 'commission' AND l.order_id > 0" );
			} else {
				$wpdb->query( "UPDATE {$c} l JOIN {$wpdb->posts} p ON p.ID = l.order_id
					SET l.created_at = p.post_date
					WHERE l.type = 'commission' AND l.order_id > 0" );
			}
		} );

		// Rewrite rules may be new in this version — flush lazily on next init.
		add_action( 'init', function () { proof_partner_register_rewrite(); flush_rewrite_rules(); }, 99 );

		// One-time: convert legacy BGN lifetime strings to EUR (пост-еврозона: BGN никъде).
		$mig( 'bgn_lifetime_to_eur', function () {
		global $wpdb;
		$t    = PP_Schema::table( 'partners' );
		$rows = $wpdb->get_results( "SELECT partner_id, lifetime_imported FROM {$t} WHERE lifetime_imported LIKE '%BGN%'" );
		foreach ( (array) $rows as $r ) {
			if ( preg_match( '/earned\s+([\d.]+)\s+BGN,\s+paid\s+([\d.]+)\s+BGN/', $r->lifetime_imported, $m ) ) {
				$earned = (float) $m[1] / 1.95583;
				$paid   = (float) $m[2] / 1.95583;
				$new    = ( $earned > 0.004 || $paid > 0.004 )
					? sprintf( 'AffWP история: изкарани %.2f € / изплатени %.2f €', $earned, $paid )
					: '';
				$wpdb->update( $t, array( 'lifetime_imported' => $new ), array( 'partner_id' => $r->partner_id ) );
			}
		}
		} );

		// Policy v3 (2.8.0): individual_use OFF на всички партньорски кодове —
		// стакват се с FunnelKit промота от белия списък; партньорската
		// ексклузивност я пази server gate-ът, не WC.
		add_action( 'init', function () {
			global $wpdb;
			if ( ! class_exists( 'WC_Coupon' ) ) return;
			$ids = $wpdb->get_col( 'SELECT wc_coupon_id FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE wc_coupon_id > 0' );
			foreach ( array_map( 'intval', (array) $ids ) as $wc_id ) {
				if ( ! get_post( $wc_id ) ) continue;
				$c = new WC_Coupon( $wc_id );
				if ( $c->get_individual_use() ) {
					$c->set_individual_use( false );
					$c->save();
				}
			}
		}, 20 );
	}
	proof_partner_register_crons();
} );

/* Boot */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) return;
	$GLOBALS['proof_partner_attribution'] = new PP_Attribution();
	PP_Engine::instance();
	new PP_Reconcile();
	new PP_Coupons_Runtime();
	new PP_Dashboard();
	new PP_Portal();
	new PP_Rest();
	new PP_Proof(); new PP_Offers();
	if ( is_admin() ) {
		new PP_Admin();
		new PP_Payouts();
		new PP_App();
	}
}, 20 );

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'proof_partner_settings' ) ) {
function proof_partner_settings() {
	$defaults = array(
		'trigger_status'        => 'completed',
		'won_statuses'          => array(),
		'holding_days'          => 7,
		'default_rate'          => 10.0,
		'excluded_products'     => '',
		'default_coupon_type'   => 'percent',
		'default_coupon_amount' => 5,
		'block_self_referrals'  => 1,
		'velocity_min_weekly'   => 10,
		'velocity_multiplier'   => 5,
		'include_vat'           => 1,
		'allowed_with_partner'  => '',
		'safe_mode'             => 0,
		'refund_alert_pct'      => 25,
		'portal_auto_approve'   => 1,
		'kit_story'             => 'Открих {brand} и не спирам 💚 С кода {code} взимаш {discount} — или директно през линка ми: {link}',
		'kit_post'              => 'Моята {brand} рутина, честно: поръчвам от месеци и разликата се усеща. Кодът {code} ти дава {discount}, а линкът {link} го прилага сам. Питай ме в коментарите 👇',
		'kit_campaign'          => '',
		'tiers_enabled'         => 0,
		'tiers'                 => "Бронз|0|10\nСребро|300|12\nЗлато|800|15",
		'brand_name'            => '',
		'brand_company'         => '',
		'brand_company_id'      => '',
		'brand_company_address' => '',
		'brand_accent'          => '#2BB6EA',
		'brand_logo_url'        => '',
		'brand_accent2'         => '#16303F',
		'brand_code_suffix'     => 'PROOF',
		'tg_token'              => '',
		'tg_chat'               => '',
		'kit_campaign_from'     => '',
		'kit_campaign_to'       => '',
		'statement_offset'      => 142,
		'email_on_payout'       => 1,
		'email_on_welcome'      => 1,
		'legacy_link_prefixes'  => '',
		/* Proof: EPACK пакетите + Sublium подновяванията (class-pp-proof.php) */
		'pack_rules'            => array(),      /* 'pack:essentials' => ['mode'=>'fixed'|'pct','value'=>5,'discount'=>0]; Кутиите = 'pack:combo' */
		'renewal_mode'          => 'none',       /* none | first_n | forever */
		'renewal_n'             => 3,
		'renewal_rate'          => '',           /* '' = същите правила като първата поръчка; число = % върху подновяването */
		'fixed_msg_behind'      => 'Хей, {name}! През {month} продажбите с твоя код донесоха {commission} комисиона (по {rate}%), а ти платихме фактура за {invoice}. {wish} Поздрави, екипът на {brand}',
		'fixed_msg_ahead'       => 'Хей, {name}! През {month} продажбите с твоя код донесоха {commission} комисиона (по {rate}%), а фактурата ти беше {invoice} — изкара повече, отколкото ти платихме. Така се прави! Поздрави, екипът на {brand}',
		'fixed_wish_mid'        => 'Надяваме се до края на месеца да имаш повече късмет!',
		'fixed_wish_end'        => 'Надяваме се другия месец да имаш повече късмет!',
		'fixed_wish_next'       => 'Надяваме се през текущия месец да имаш повече късмет!',
		'legacy_portal_slug'    => '',
		'migration_email'       => 'Здравей, {name}!

Партньорската програма на {brand} се премести на нова платформа — по-бърза, с табло на живо, статистики и месечни справки.

Влизаш тук: {portal}
Email: същият, с който си регистриран/а
Парола: същата стара парола. Ако си я забравил/а — „Забравена парола\" на същата страница.

Твоят код и линкове са си СЪЩИТЕ и продължават да работят — нищо не губиш, нищо не преправяш.

До скоро,
{brand}',
	);
	$s = get_option( 'proof_partner_settings', array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), $defaults );
}
}

/**
 * Каноничният trigger статус — ВИНАГИ без 'wc-' префикс.
 * WooCommerce съхранява 'wc-delivered', но $order->get_status() връща
 * 'delivered' и hook-овете са woocommerce_order_status_delivered.
 * Едно място на истината, за да не се повтори разминаването.
 */
if ( ! function_exists( 'proof_partner_trigger_status' ) ) {
function proof_partner_trigger_status() {
	return preg_replace( '/^wc-/', '', sanitize_key( proof_partner_settings()['trigger_status'] ) );
}
}

/** Статуси, при които поръчката е „взета" (носи пари). */
if ( ! function_exists( 'proof_partner_won_statuses' ) ) {
function proof_partner_won_statuses() {
	/* Proof 1.9.6: списъкът се избира в „Пакети · Подновявания · Статуси" от регистрираните Woo статуси.
	 * Празен = старото поведение (тригер + completed). */
	$st = proof_partner_settings()['won_statuses'] ?? array();
	if ( is_array( $st ) ) { $st = array_values( array_filter( array_map( function ( $x ) { return preg_replace( '/^wc-/', '', sanitize_key( $x ) ); }, $st ) ) ); }
	if ( ! empty( $st ) ) return array_values( array_unique( $st ) );
	return array_values( array_unique( array( proof_partner_trigger_status(), 'completed' ) ) );
}
}

/** Нива: "Име|мин.30д.EUR|ставка" по ред. Връща подредени низходящо по праг. */
if ( ! function_exists( 'proof_partner_tiers' ) ) {
function proof_partner_tiers() {
	$out = array();
	foreach ( preg_split( '/[\r\n]+/', (string) ( proof_partner_settings()['tiers'] ?? '' ) ) as $line ) {
		$p = array_map( 'trim', explode( '|', $line ) );
		if ( count( $p ) < 3 || '' === $p[0] ) continue;
		$out[] = array( 'name' => sanitize_text_field( $p[0] ), 'min' => (float) $p[1], 'rate' => (float) $p[2] );
	}
	usort( $out, function ( $a, $b ) { return $b['min'] <=> $a['min']; } );
	return $out;
}
}

/** Нивото за дадена 30-дневна сума + прогрес към следващото. */
if ( ! function_exists( 'proof_partner_tier_for' ) ) {
function proof_partner_tier_for( $sum30 ) {
	$tiers = proof_partner_tiers();
	if ( empty( $tiers ) ) return null;
	$current = end( $tiers ); // най-ниското
	$next    = null;
	foreach ( $tiers as $i => $t ) {
		if ( $sum30 >= $t['min'] ) { $current = $t; $next = $tiers[ $i - 1 ] ?? null; break; }
	}
	return array( 'tier' => $current, 'next' => $next, 'sum30' => round( (float) $sum30, 2 ) );
}
}

/** Safe Mode: спира всички интервенции в количката; счетоводството остава живо. */
if ( ! function_exists( 'proof_partner_safe_mode' ) ) {
function proof_partner_safe_mode() {
	return ! empty( proof_partner_settings()['safe_mode'] );
}
}

/** Името на бранда: настройката, иначе името на сайта. НИКЪДЕ hardcoded. */
if ( ! function_exists( 'proof_partner_brand_name' ) ) {
function proof_partner_brand_name() {
	$n = trim( (string) ( proof_partner_settings()['brand_name'] ?? '' ) );
	return '' !== $n ? $n : get_bloginfo( 'name' );
}
}

/** Смес на hex цвят с бяло (pct>0) или черно (pct<0). */
if ( ! function_exists( 'proof_partner_shade' ) ) {
function proof_partner_shade( $hex, $pct ) {
	$hex = ltrim( (string) $hex, '#' );
	if ( 3 === strlen( $hex ) ) $hex = preg_replace( '/(.)/', '$1$1', $hex );
	if ( 6 !== strlen( $hex ) ) return '#' . $hex;
	$mixR = $pct >= 0 ? 255 : 0; $p = abs( $pct );
	$out = '#';
	foreach ( str_split( $hex, 2 ) as $ch ) {
		$c    = hexdec( $ch );
		$out .= str_pad( dechex( (int) round( $c + ( $mixR - $c ) * $p ) ), 2, '0', STR_PAD_LEFT );
	}
	return $out;
}
}

/** Цялата палитра, изведена от двата бранд цвята — за портал и админ. */
if ( ! function_exists( 'proof_partner_palette' ) ) {
function proof_partner_palette() {
	$s  = proof_partner_settings();
	$a  = sanitize_hex_color( $s['brand_accent'] ) ?: '#2BB6EA';
	$a2 = sanitize_hex_color( $s['brand_accent2'] ) ?: '#f8a41d';
	return array(
		'--coral'      => $a,
		'--coral-deep' => proof_partner_shade( $a, -0.14 ),
		'--coral-soft' => proof_partner_shade( $a, 0.78 ),
		'--blush'      => proof_partner_shade( $a, 0.88 ),
		'--amber'      => $a2,
		'--amber-soft' => proof_partner_shade( $a2, 0.8 ),
	);
}
}

if ( ! function_exists( 'proof_partner_palette_css' ) ) {
function proof_partner_palette_css() {
	$css = '';
	foreach ( proof_partner_palette() as $k => $v ) $css .= $k . ':' . $v . ';';
	return $css;
}
}

/**
 * Статусите на N поръчки с ЕДНА заявка (HPOS или legacy), без wc_get_order
 * в цикъл. Връща map order_id => статус без 'wc-' префикс.
 * Това е разликата между 30-секунден timeout и 50ms.
 */
if ( ! function_exists( 'proof_partner_order_statuses' ) ) {
function proof_partner_order_statuses( array $ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) return array();
	$in   = implode( ',', $ids );
	$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$rows = $hpos
		? $wpdb->get_results( "SELECT id AS oid, status FROM {$wpdb->prefix}wc_orders WHERE id IN ({$in})" )
		: $wpdb->get_results( "SELECT ID AS oid, post_status AS status FROM {$wpdb->posts} WHERE ID IN ({$in})" );
	$map = array();
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r->oid ] = preg_replace( '/^wc-/', '', (string) $r->status );
	}
	return $map;
}
}

if ( ! function_exists( 'proof_partner_status_is_won' ) ) {
function proof_partner_status_is_won( $status ) {
	return in_array( (string) $status, proof_partner_won_statuses(), true );
}

if ( ! function_exists( 'proof_partner_status_is_transitional' ) ) {
	/**
	 * Преходен статус = поръчката ПЪТУВА към доставка (или обработка).
	 * Комисионата ѝ чака легитимно. ЕДИНСТВЕНОТО място за този списък:
	 * watchdog-ът, Сверката и самопоправката четат оттук (одит F07).
	 */
	function proof_partner_status_is_transitional( $status ) {
		$status = preg_replace( '/^wc-/', '', (string) $status );
		if ( in_array( $status, array( 'pending', 'processing', 'on-hold', 'shipped', 'out-for-delivery' ), true ) ) return true;
		/* Proof 1.9.6: custom Woo статуси (напр. „в куриер"), които не са спечелени и не са отказ/връщане, са преходни */
		$dead = array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft', 'draft' );
		if ( in_array( $status, $dead, true ) || proof_partner_status_is_won( $status ) ) return false;
		return function_exists( 'wc_get_order_statuses' ) && array_key_exists( 'wc-' . $status, wc_get_order_statuses() );
	}
}
}

/** Провалени писма: последните 10 с причина — видими в Диагностика. */
add_action( 'wp_mail_failed', function ( $err ) {
	$log   = (array) get_option( 'proof_partner_mail_errors', array() );
	$log[] = array( 't' => current_time( 'mysql' ), 'msg' => is_wp_error( $err ) ? $err->get_error_message() : 'unknown' );
	update_option( 'proof_partner_mail_errors', array_slice( $log, -10 ), false );
} );

/** Telegram известие (ако е конфигуриран бот). Тихо при липса/грешка. */
if ( ! function_exists( 'proof_partner_telegram' ) ) {
function proof_partner_telegram( $msg ) {
	$s = proof_partner_settings();
	if ( empty( $s['tg_token'] ) || empty( $s['tg_chat'] ) ) return false;
	$r = wp_remote_post( 'https://api.telegram.org/bot' . rawurlencode( $s['tg_token'] ) . '/sendMessage', array(
		'timeout' => 5,
		'body'    => array( 'chat_id' => $s['tg_chat'], 'text' => '[' . proof_partner_brand_name() . '] ' . $msg ),
	) );
	return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
}
}

/** Изпраща едно анонс писмо; брои в статистиката. */
if ( ! function_exists( 'proof_partner_send_announce_mail' ) ) {
function proof_partner_send_announce_mail( $email, $first_name, $body, $subject ) {
	$brand  = proof_partner_brand_name();
	$portal = home_url( '/partner' );
	$accent = sanitize_hex_color( proof_partner_settings()['brand_accent'] ) ?: '#f47a49';
	$text   = strtr( $body, array( '{name}' => $first_name, '{brand}' => $brand, '{portal}' => '' ) );
	$text   = trim( preg_replace( '/\n{3,}/', "\n\n", str_replace( 'Влизаш тук: ', '', $text ) ) );

	// HTML: без чупене на дълги URL-и от plain-text wrap, с четим бутон.
	$html  = '<div style="font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#3a2f2b;max-width:560px;margin:0 auto;padding:8px 4px">';
	$html .= '<h2 style="color:' . esc_attr( $accent ) . ';font-size:19px;margin:0 0 14px">' . esc_html( $brand ) . ' — партньорска програма</h2>';
	$html .= '<p style="margin:0 0 16px">' . nl2br( esc_html( $text ) ) . '</p>';
	$html .= '<p style="margin:22px 0"><a href="' . esc_url( $portal ) . '" style="background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:999px;display:inline-block">Влез в портала →</a></p>';
	$html .= '<p style="font-size:12.5px;color:#8a7a74;margin:14px 0 0">Ако бутонът не работи, копирай адреса: ' . esc_html( $portal ) . '</p>';
	$html .= '</div>';

	$ok = wp_mail( $email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	$stats = (array) get_option( 'proof_partner_announce', array() );
	$stats[ $ok ? 'sent' : 'failed' ] = (int) ( $stats[ $ok ? 'sent' : 'failed' ] ?? 0 ) + 1;
	update_option( 'proof_partner_announce', $stats, false );
	return $ok;
}
}
add_action( 'proof_partner_send_announce', 'proof_partner_send_announce_mail', 10, 4 );

/** №9: жив фийд — ring buffer от последните 120 събития (без нова таблица). */
if ( ! function_exists( 'proof_partner_feed_push' ) ) {
function proof_partner_feed_push( $type, $msg ) {
	$feed   = (array) get_option( 'proof_partner_feed', array() );
	$feed[] = array( 't' => current_time( 'mysql' ), 'type' => sanitize_key( $type ), 'msg' => sanitize_text_field( $msg ) );
	update_option( 'proof_partner_feed', array_slice( $feed, -120 ), false );
	if ( in_array( $type, array( 'partner', 'commission', 'payout', 'tier', 'diag' ), true ) ) {
		proof_partner_telegram( $msg );
	}
}
}

if ( ! function_exists( 'proof_partner_excluded_product_ids' ) ) {
function proof_partner_excluded_product_ids() {
	$s   = proof_partner_settings();
	$ids = array_filter( array_map( 'absint', array_map( 'trim', explode( ',', (string) $s['excluded_products'] ) ) ) );
	return array_unique( $ids );
}
}

/* LOGADOR GitHub Updater — общ за всички плъгини на LOGADOR: копира се в wp-content/mu-plugins, ако липсва или е по-стар. */
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	$src = PROOF_PARTNER_DIR . 'includes/mu/logador-github-updater.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$dst = $dir . '/logador-github-updater.php';
	if ( ! is_file( $src ) ) return;
	$want = preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $src ), $m ) ? $m[1] : '0';
	$have = is_file( $dst ) && preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $dst ), $m2 ) ? $m2[1] : '0';
	if ( is_file( $dst ) && version_compare( $have, $want, '>=' ) ) return;
	if ( ! is_dir( $dir ) ) @wp_mkdir_p( $dir );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) || ! @copy( $src, $dst ) ) {
		add_action( 'admin_notices', function () use ( $src, $dir ) {
			echo '<div class="notice notice-warning"><p><b>Proof Partner:</b> не мога да запиша <code>logador-github-updater.php</code> в <code>' . esc_html( $dir ) . '</code> (права). Копирай го ръчно от <code>' . esc_html( $src ) . '</code>.</p></div>';
		} );
		return;
	}
	add_action( 'admin_notices', function () use ( $want ) {
		echo '<div class="notice notice-success is-dismissible"><p><b>LOGADOR GitHub Updater ' . esc_html( $want ) . '</b> е инсталиран. Token-ът се слага веднъж в <a href="' . esc_url( admin_url( 'options-general.php?page=logador-github' ) ) . '">Settings → GitHub ъпдейти</a>.</p></div>';
	} );
} );
