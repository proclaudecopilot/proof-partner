<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Attribution correctness core:
 *
 * 1) GATE — server-side law: at most ONE partner coupon per cart/order,
 *    enforced in coupon validation regardless of individual_use flags or
 *    other coupon plugins' settings. Plus a post-apply safety net.
 *
 * 2) LOCK — attribution (partner + code + RATE) is resolved and frozen at
 *    checkout, so weeks-later Delivered triggers pay the partner who owned
 *    the code when the sale happened, at the rate promised then.
 *
 * 3) LOG — every attribution event (link captured, coupon applied/removed,
 *    lock, commission, block, reversal) leaves a timestamped trail on the
 *    order. Disputes are settled with evidence, not memory.
 */
class PP_Attribution {

	const META_LOCK     = '_proof_partner_attribution';
	const META_LOG      = '_proof_partner_log';
	const SESSION_TRAIL = 'proof_partner_trail';
	const SESSION_LINK  = 'proof_partner_link_applied';

	public function __construct() {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'gate' ), 10, 3 );
		add_action( 'woocommerce_applied_coupon', array( $this, 'on_applied' ), 5 );
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_removed' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'lock_order' ), 5 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'lock_order' ), 5 );
		add_action( 'add_meta_boxes', array( $this, 'metabox' ), 20 );

		// Политика v4 interop: ако купон от белия списък (напр. FunnelKit
		// промо код от белия списък) носи СОБСТВЕН individual_use флаг, WC по подразбиране
		// би изгонил партньорския код (и обратно). Тези два core филтъра
		// казват на WC: партньорски + бял списък живеят заедно.
		add_filter( 'woocommerce_apply_individual_use_coupon', array( $this, 'individual_use_keep' ), 10, 3 );
		add_filter( 'woocommerce_apply_with_individual_use_coupon', array( $this, 'individual_use_allow' ), 10, 4 );

		// №6: човешки съобщения вместо криптичните WC текстове.
		add_filter( 'woocommerce_coupon_message', array( $this, 'coupon_message' ), 10, 3 );
		add_action( 'woocommerce_before_cart', array( $this, 'active_code_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'active_code_notice' ), 6 );
	}

	/* ------------------------------------------------------------------ */
	/* individual_use interop (политика v4)                                 */
	/* ------------------------------------------------------------------ */

	/** Прилага се individual_use купон → кои вече приложени кодове ОЦЕЛЯВАТ. */
	public function individual_use_keep( $keep, $coupon, $applied ) {
		if ( proof_partner_safe_mode() ) return $keep;
		foreach ( (array) $applied as $ac ) {
			if ( PP_Coupons::is_allowed_with_partner( $ac ) ) $keep[] = $ac;
		}
		return array_unique( (array) $keep );
	}

	/** Има individual_use купон в количката → кои НОВИ кодове минават въпреки него. */
	public function individual_use_allow( $apply, $coupon, $iu_coupon, $applied ) {
		if ( $apply ) return $apply;
		if ( proof_partner_safe_mode() ) return $apply;
		return PP_Coupons::is_allowed_with_partner( $coupon->get_code() );
	}

	/* ------------------------------------------------------------------ */
	/* Branded coupon messaging                                             */
	/* ------------------------------------------------------------------ */

	private function discount_label( WC_Coupon $coupon ) {
		$amount = (float) $coupon->get_amount();
		if ( 'percent' === $coupon->get_discount_type() ) {
			return rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . '% отстъпка';
		}
		return rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) . ' ' . get_woocommerce_currency_symbol() . ' отстъпка';
	}

	private function partner_first_name( $partner ) {
		$parts = preg_split( '/\s+/', trim( (string) $partner->display_name ) );
		return $parts[0] ?: $partner->display_name;
	}

	/** "Coupon code applied successfully." → "Пазаруваш с кода на Йоана — 5% отстъпка ✓" */
	public function coupon_message( $msg, $msg_code, $coupon ) {
		if ( proof_partner_safe_mode() ) return $msg;
		$success = class_exists( 'WC_Coupon' ) && defined( 'WC_Coupon::WC_COUPON_SUCCESS' ) ? WC_Coupon::WC_COUPON_SUCCESS : 200;
		if ( (int) $msg_code !== (int) $success || ! ( $coupon instanceof WC_Coupon ) ) return $msg;

		$map = PP_Coupons::get_mapping_by_code( wc_format_coupon_code( $coupon->get_code() ) );
		if ( ! $map ) return $msg;

		$partner = PP_Partners::get( $map->partner_id );
		if ( ! $partner ) return $msg;

		return sprintf( 'Пазаруваш с кода на %s — %s ✓', $this->partner_first_name( $partner ), $this->discount_label( $coupon ) );
	}

	/** Quiet reminder on cart/checkout renders where no fresh success notice exists. */
	public function active_code_notice() {
		if ( proof_partner_safe_mode() ) return;
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) return;
		if ( function_exists( 'wc_notice_count' ) && wc_notice_count( 'success' ) > 0 ) return; // fresh apply already talks

		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			$map = PP_Coupons::get_mapping_by_code( wc_format_coupon_code( $code ) );
			if ( ! $map ) continue;
			$partner = PP_Partners::get( $map->partner_id );
			if ( ! $partner ) continue;

			$coupon = new WC_Coupon( $code );
			wc_print_notice( sprintf(
				'Активен партньорски код: <strong>%s</strong> (%s) — %s.',
				esc_html( strtoupper( $code ) ), esc_html( $this->partner_first_name( $partner ) ), esc_html( $this->discount_label( $coupon ) )
			), 'notice' );
			return; // gate guarantees at most one
		}
	}

	/* ------------------------------------------------------------------ */
	/* Trail / log                                                          */
	/* ------------------------------------------------------------------ */

	/** Session-level breadcrumb (pre-order). */
	public static function trail( $msg ) {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) return;
		$t = (array) WC()->session->get( self::SESSION_TRAIL, array() );
		$t[] = array( 't' => current_time( 'mysql' ), 'e' => $msg );
		WC()->session->set( self::SESSION_TRAIL, array_slice( $t, -20 ) );
	}

	/** Order-level attribution log (post-order). */
	public static function olog( $order, $msg ) {
		if ( ! ( $order instanceof WC_Order ) ) $order = wc_get_order( $order );
		if ( ! $order ) return;
		$log   = (array) $order->get_meta( self::META_LOG );
		$log[] = array( 't' => current_time( 'mysql' ), 'e' => $msg );
		$order->update_meta_data( self::META_LOG, array_slice( $log, -30 ) );
		$order->save();
	}

	/* ------------------------------------------------------------------ */
	/* 1) GATE                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Reject a partner coupon if a DIFFERENT partner coupon is already
	 * applied — server-side, unconditional. Throwing an Exception makes
	 * WooCommerce surface the message to the customer.
	 */
	public function gate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid ) return $valid;
		if ( proof_partner_safe_mode() ) return $valid; // №2 Safe Mode: никакви интервенции

		$code = wc_format_coupon_code( $coupon->get_code() );

		$applied = array();
		if ( $discounts && is_callable( array( $discounts, 'get_object' ) ) ) {
			$obj = $discounts->get_object();
			if ( $obj instanceof WC_Cart )  $applied = $obj->get_applied_coupons();
			if ( $obj instanceof WC_Order ) $applied = $obj->get_coupon_codes();
		}
		if ( empty( $applied ) && function_exists( 'WC' ) && null !== WC()->cart ) {
			$applied = WC()->cart->get_applied_coupons();
		}

		$partner_in_cart = '';
		foreach ( $applied as $ac ) {
			if ( wc_format_coupon_code( $ac ) !== $code && PP_Coupons::get_mapping_by_code( wc_format_coupon_code( $ac ) ) ) {
				$partner_in_cart = wc_format_coupon_code( $ac );
				break;
			}
		}

		if ( PP_Coupons::get_mapping_by_code( $code ) ) {
			// Партньорски код: блокира се само ДРУГ партньорски.
			if ( $partner_in_cart ) {
				throw new Exception( sprintf(
					'Само един партньорски код може да се използва на поръчка. Активен код: %s.',
					strtoupper( $partner_in_cart )
				) );
			}
			return $valid;
		}

		// Чужд код при активен партньорски: минава само белият списък.
		if ( $partner_in_cart && ! PP_Coupons::is_allowed_with_partner( $code ) ) {
			throw new Exception( sprintf(
				'Кодът %s не може да се комбинира с партньорска отстъпка (активен: %s).',
				strtoupper( $code ), strtoupper( $partner_in_cart )
			) );
		}
		return $valid;
	}

	/**
	 * Метачка след прилагане (политика v4):
	 * - втори партньорски код → маха се новият;
	 * - партньорски код влиза → всички чужди кодове ИЗВЪН белия списък се
	 *   премахват автоматично, с човешко съобщение и запис в trail-а.
	 */
	public function on_applied( $code ) {
		if ( proof_partner_safe_mode() ) return;
		$code = wc_format_coupon_code( $code );
		$map  = PP_Coupons::get_mapping_by_code( $code );

		$via_link = function_exists( 'WC' ) && WC()->session
			&& wc_format_coupon_code( (string) WC()->session->get( self::SESSION_LINK ) ) === $code;

		if ( $map ) {
			self::trail( sprintf( 'Партньорски код %s приложен %s.', strtoupper( $code ), $via_link ? 'чрез линк' : 'ръчно' ) );
		}

		if ( ! function_exists( 'WC' ) || null === WC()->cart ) return;

		$applied       = array_map( 'wc_format_coupon_code', WC()->cart->get_applied_coupons() );
		$partner_codes = array_values( array_filter( $applied, function ( $c ) { return (bool) PP_Coupons::get_mapping_by_code( $c ); } ) );

		// Два партньорски (race/bypass) → новият пада.
		if ( $map && count( $partner_codes ) > 1 ) {
			WC()->cart->remove_coupon( $code );
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( sprintf( 'Само един партньорски код на поръчка. Активен остава: %s.', strtoupper( $partner_codes[0] ) ), 'error' );
			}
			self::trail( sprintf( 'Втори партньорски код %s отстранен автоматично.', strtoupper( $code ) ) );
			return;
		}

		// Активен партньорски → метем чуждите извън белия списък.
		if ( ! empty( $partner_codes ) ) {
			foreach ( $applied as $ac ) {
				if ( PP_Coupons::get_mapping_by_code( $ac ) ) continue;
				if ( PP_Coupons::is_allowed_with_partner( $ac ) ) continue;
				WC()->cart->remove_coupon( $ac );
				self::trail( sprintf( 'Код %s премахнат — не се комбинира с партньорска отстъпка.', strtoupper( $ac ) ) );
				if ( class_exists( 'PP_Coupons_Runtime' ) ) {
					PP_Coupons_Runtime::flash( sprintf( 'Кодът <code>%s</code> не се комбинира с партньорска отстъпка и беше премахнат.', strtoupper( $ac ) ), 'info' );
				}
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( sprintf( 'Кодът %s не се комбинира с партньорска отстъпка и беше премахнат.', strtoupper( $ac ) ), 'notice' );
				}
			}
		}
	}

	public function on_removed( $code ) {
		$code = wc_format_coupon_code( $code );
		if ( PP_Coupons::get_mapping_by_code( $code ) ) {
			self::trail( sprintf( 'Партньорски код %s премахнат от количката.', strtoupper( $code ) ) );
			if ( function_exists( 'WC' ) && WC()->session
				&& wc_format_coupon_code( (string) WC()->session->get( self::SESSION_LINK ) ) === $code ) {
				WC()->session->__unset( self::SESSION_LINK );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* 2) LOCK                                                              */
	/* ------------------------------------------------------------------ */

	public function lock_order( $order ) {
		if ( is_numeric( $order ) ) $order = wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) return;
		if ( ! ( $order instanceof WC_Order ) ) $order = wc_get_order( $order );
		if ( ! $order ) return;
		if ( $order->get_meta( self::META_LOCK ) ) return; // never overwrite a lock

		// Flush the session breadcrumb into the order log.
		$trail = array();
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			$trail = (array) WC()->session->get( self::SESSION_TRAIL, array() );
			WC()->session->__unset( self::SESSION_TRAIL );
		}

		$attr = PP_Coupons::attribute_order( $order );
		if ( ! $attr && empty( $trail ) ) return; // nothing partner-related touched this order

		$log = (array) $order->get_meta( self::META_LOG );
		$log = array_merge( $log, $trail );

		if ( $attr ) {
			$partner  = PP_Partners::get( $attr['partner_id'] );
			$via_link = function_exists( 'WC' ) && WC()->session
				&& wc_format_coupon_code( (string) WC()->session->get( self::SESSION_LINK ) ) === wc_format_coupon_code( $attr['coupon_code'] );

			$lock = array(
				'partner_id'  => (int) $attr['partner_id'],
				'coupon_code' => $attr['coupon_code'],
				'partner'     => $partner ? $partner->display_name : ( '#' . $attr['partner_id'] ),
				'rate'        => $partner ? (float) $partner->rate : null,
				'source'      => $via_link ? 'link' : 'code',
				'locked_at'   => current_time( 'mysql' ),
			);
			$order->update_meta_data( self::META_LOCK, $lock );
			$order->update_meta_data( '_proof_partner_id', (int) $attr['partner_id'] ); // flat, за outcome заявки
			proof_partner_feed_push( 'order', sprintf( 'Поръчка #%d с код %s (%s)', $order->get_id(), strtoupper( $attr['coupon_code'] ), 'link' === ( $attr['source'] ?? '' ) ? 'линк' : 'код' ) );
			$log[] = array( 't' => current_time( 'mysql' ), 'e' => sprintf(
				'Атрибуция заключена: %s → %s (%s%%, източник: %s).',
				strtoupper( $attr['coupon_code'] ), $lock['partner'],
				null !== $lock['rate'] ? rtrim( rtrim( number_format( $lock['rate'], 3 ), '0' ), '.' ) : '?',
				'link' === $lock['source'] ? 'линк' : 'код'
			) );
		} else {
			$log[] = array( 't' => current_time( 'mysql' ), 'e' => 'Поръчка без партньорски код при checkout (имаше партньорска активност в сесията — виж по-горе).' );
		}

		$order->update_meta_data( self::META_LOG, array_slice( $log, -30 ) );
		$order->save();

		// №8: ref consumed by a real order — stop restoring it on other devices.
		$cid = (int) $order->get_customer_id();
		if ( $cid ) delete_user_meta( $cid, PP_Coupons_Runtime::USER_META );

		if ( function_exists( 'WC' ) && null !== WC()->session ) WC()->session->__unset( self::SESSION_LINK );
	}

	/* ------------------------------------------------------------------ */
	/* 3) Evidence metabox on the order screen                              */
	/* ------------------------------------------------------------------ */

	public function metabox() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) $screens[] = wc_get_page_screen_id( 'shop-order' ); // HPOS
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'proof-partner-attribution', 'Proof Partner — Атрибуция', array( $this, 'render_metabox' ), $screen, 'side', 'default' );
		}
	}

	public function render_metabox( $object ) {
		$order = ( $object instanceof WC_Order ) ? $object : wc_get_order( is_object( $object ) ? $object->ID : $object );
		if ( ! $order ) { echo '—'; return; }

		$lock = $order->get_meta( self::META_LOCK );
		$log  = (array) $order->get_meta( self::META_LOG );

		if ( is_array( $lock ) && ! empty( $lock['coupon_code'] ) ) {
			printf(
				'<p><strong>%s</strong> → %s<br><small>%s%% · източник: %s · %s</small></p>',
				esc_html( strtoupper( $lock['coupon_code'] ) ),
				esc_html( $lock['partner'] ?? '' ),
				esc_html( isset( $lock['rate'] ) ? rtrim( rtrim( number_format( (float) $lock['rate'], 3 ), '0' ), '.' ) : '?' ),
				esc_html( ( $lock['source'] ?? '' ) === 'link' ? 'линк' : 'код' ),
				esc_html( $lock['locked_at'] ?? '' )
			);
		} else {
			echo '<p><em>Няма заключена партньорска атрибуция.</em></p>';
		}

		if ( ! empty( $log ) ) {
			echo '<hr style="margin:8px 0"><ol style="margin:0 0 0 16px;padding:0;font-size:11.5px;line-height:1.5">';
			foreach ( $log as $l ) {
				printf( '<li><span style="color:#888">%s</span> — %s</li>', esc_html( $l['t'] ?? '' ), esc_html( $l['e'] ?? '' ) );
			}
			echo '</ol>';
		}
	}
}
