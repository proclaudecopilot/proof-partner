<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Partners {

	const STATUSES = array( 'applicant', 'active', 'paused', 'archived' );

	/** ISO 13616 mod-97 IBAN check. Empty passes (field is optional). */
	public static function validate_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( '' === $iban ) return '';
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', $iban ) ) {
			return new WP_Error( 'ap_iban', 'Невалиден IBAN формат.' );
		}
		$rearr = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$num   = '';
		foreach ( str_split( $rearr ) as $ch ) {
			$num .= ctype_alpha( $ch ) ? (string) ( ord( $ch ) - 55 ) : $ch;
		}
		// bcmod-free mod 97 on big number.
		$rem = 0;
		foreach ( str_split( $num, 7 ) as $chunk ) {
			$rem = (int) ( ( $rem . $chunk ) % 97 );
		}
		if ( 1 !== $rem ) return new WP_Error( 'ap_iban', 'IBAN не минава контролната проверка (mod-97) — провери за разменени цифри.' );
		return $iban;
	}

	/** Auto-derive BIC for common BG banks from IBAN bank code (chars 5–8). Wise derives it anyway for SEPA. */
	public static function bic_from_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( 0 !== strpos( $iban, 'BG' ) || strlen( $iban ) < 8 ) return '';
		$map = array(
			'UNCR' => 'UNCRBGSF', // УниКредит Булбанк
			'STSA' => 'STSABGSF', // Банка ДСК
			'UBBS' => 'UBBSBGSF', // ОББ
			'BPBI' => 'BPBIBGSF', // Пощенска банка
			'FINV' => 'FINVBGSF', // ПИБ
			'CECB' => 'CECBBGSF', // ЦКБ
			'IORT' => 'IORTBGSF', // Инвестбанк
			'BUIN' => 'BUINBGSF', // Алианц Банк
			'TCZB' => 'TCZBBGSF', // Ти Би Ай Банк
			'ISBK' => 'ISBKBGSF', // Ишбанк
		);
		return $map[ substr( $iban, 4, 4 ) ] ?? '';
	}

	/**
	 * Create a partner. Links an existing WP user by email, or creates one.
	 *
	 * @param array $args { display_name, email, rate, payment_method, payment_details, notes }
	 * @return int|WP_Error partner_id
	 */
	public static function create( $args ) {
		global $wpdb;

		$email = sanitize_email( $args['email'] ?? '' );
		$name  = sanitize_text_field( $args['display_name'] ?? '' );
		$rate  = self::sanitize_rate( $args['rate'] ?? proof_partner_settings()['default_rate'] );

		if ( ! is_email( $email ) ) return new WP_Error( 'ap_email', 'Invalid email.' );
		if ( '' === $name )         return new WP_Error( 'ap_name', 'Display name is required.' );
		if ( is_wp_error( $rate ) ) return $rate;

		$iban = self::validate_iban( $args['iban'] ?? '' );
		if ( is_wp_error( $iban ) ) return $iban;
		$bic = strtoupper( sanitize_text_field( $args['bic'] ?? '' ) );
		if ( '' === $bic && $iban ) $bic = self::bic_from_iban( $iban );

		$status = sanitize_key( $args['status'] ?? 'active' );
		if ( ! in_array( $status, self::STATUSES, true ) ) $status = 'active';

		// Link or create WP user.
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$user_id = (int) $user->ID;
			// One partner per user.
			$exists = self::get_by_user( $user_id );
			if ( $exists ) return new WP_Error( 'ap_dup', sprintf( 'User %s is already partner #%d (%s).', $email, $exists->partner_id, $exists->display_name ) );
		} else {
			$login = sanitize_user( current( explode( '@', $email ) ), true );
			if ( username_exists( $login ) ) $login .= '_' . wp_generate_password( 4, false, false );
			$user_id = wp_create_user( $login, wp_generate_password( 20 ), $email );
			if ( is_wp_error( $user_id ) ) return $user_id;
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );
			// NOTE: no email is sent by design (the portal sends the welcome email when enabled).
			// Partner can use "Lost password" to set credentials when the dashboard ships.
		}

		$now = current_time( 'mysql' );
		$ok  = $wpdb->insert( PP_Schema::table( 'partners' ), array(
			'user_id'         => $user_id,
			'display_name'    => $name,
			'status'          => $status,
			'rate'            => $rate,
			'payment_method'  => sanitize_text_field( $args['payment_method'] ?? 'bank' ),
			'payment_details' => sanitize_textarea_field( $args['payment_details'] ?? '' ),
			'legal_name'      => sanitize_text_field( $args['legal_name'] ?? '' ),
			'iban'            => $iban,
			'bic'             => $bic,
			'phone'           => sanitize_text_field( $args['phone'] ?? '' ),
			'channels'        => sanitize_text_field( $args['channels'] ?? '' ),
			'notes'           => sanitize_textarea_field( $args['notes'] ?? '' ),
			'tracked_from'    => ! empty( $args['tracked_from'] ) && strtotime( $args['tracked_from'] ) ? date( 'Y-m-d 00:00:00', strtotime( $args['tracked_from'] ) ) : current_time( 'Y-m-d 00:00:00' ), /* Proof: по подразбиране от ДНЕС — старите продажби са история */
			'created_at'      => $now,
			'updated_at'      => $now,
		) );

		if ( ! $ok ) return new WP_Error( 'ap_db', 'DB insert failed: ' . $wpdb->last_error );
		return (int) $wpdb->insert_id;
	}

	public static function update( $partner_id, $fields ) {
		global $wpdb;
		$allowed = array( 'display_name', 'status', 'rate', 'payment_method', 'payment_details', 'legal_name', 'iban', 'bic', 'phone', 'channels', 'notes', 'referred_by', 'public_name', 'tracked_from', 'lifetime_imported', 'pay_model', 'company_name', 'company_id', 'company_vat', 'company_address', 'company_mol', 'msg_behind', 'msg_ahead', 'msg_wish_mid', 'msg_wish_end', 'msg_wish_next' );
		$data    = array();

		foreach ( $fields as $k => $v ) {
			if ( ! in_array( $k, $allowed, true ) ) continue;
			if ( 'tracked_from' === $k ) { $v = trim( (string) $v ); $t = $v ? strtotime( $v ) : false; $data['tracked_from'] = $t ? date( 'Y-m-d 00:00:00', $t ) : null; continue; }
			if ( 'pay_model' === $k ) { $data['pay_model'] = in_array( $v, array( 'commission', 'fixed' ), true ) ? $v : 'commission'; continue; }
			if ( 'lifetime_imported' === $k ) { $data['lifetime_imported'] = function_exists( 'mb_substr' ) ? mb_substr( sanitize_text_field( $v ), 0, 50 ) : substr( sanitize_text_field( $v ), 0, 50 ); continue; }
			if ( 'rate' === $k ) {
				$v = self::sanitize_rate( $v );
				if ( is_wp_error( $v ) ) return $v;
			} elseif ( 'status' === $k ) {
				if ( ! in_array( $v, self::STATUSES, true ) ) return new WP_Error( 'ap_status', 'Invalid status.' );
			} elseif ( 'iban' === $k ) {
				$v = self::validate_iban( $v );
				if ( is_wp_error( $v ) ) return $v;
			} elseif ( 'bic' === $k ) {
				$v = strtoupper( sanitize_text_field( $v ) );
			} elseif ( in_array( $k, array( 'payment_details', 'notes' ), true ) ) {
				$v = sanitize_textarea_field( $v );
			} else {
				$v = sanitize_text_field( $v );
			}
			$data[ $k ] = $v;
		}
		if ( empty( $data ) ) return true;

		// BIC auto-fill when IBAN present and BIC left empty.
		if ( ! empty( $data['iban'] ) && ( ! isset( $data['bic'] ) || '' === $data['bic'] ) ) {
			$auto = self::bic_from_iban( $data['iban'] );
			if ( $auto ) $data['bic'] = $auto;
		}

		$old = self::get( $partner_id );
		if ( ! $old ) return new WP_Error( 'ap_partner', 'Partner not found.' );

		$data['updated_at'] = current_time( 'mysql' );
		$res = $wpdb->update( PP_Schema::table( 'partners' ), $data, array( 'partner_id' => absint( $partner_id ) ) );
		if ( false === $res && false !== stripos( (string) $wpdb->last_error, 'unknown column' ) ) {
			/* колоната липсва (ъпдейт без активация) → добавяме я и опитваме пак */
			PP_Schema::install();
			$res = $wpdb->update( PP_Schema::table( 'partners' ), $data, array( 'partner_id' => absint( $partner_id ) ) );
		}
		if ( false === $res ) return new WP_Error( 'ap_db', 'Записът в базата отказа: ' . $wpdb->last_error );

		// Status transition → coupon lifecycle. A paused partner's code must
		// stop giving discounts, not just commissions.
		if ( isset( $data['status'] ) && $data['status'] !== $old->status ) {
			if ( in_array( $data['status'], array( 'paused', 'archived' ), true ) && 'active' === $old->status ) {
				PP_Coupons::pause_partner_coupons( $partner_id );
			} elseif ( 'active' === $data['status'] && in_array( $old->status, array( 'paused', 'archived' ), true ) ) {
				PP_Coupons::resume_partner_coupons( $partner_id );
			}
		}
		return true;
	}

	public static function sanitize_rate( $rate ) {
		$rate = (float) str_replace( ',', '.', (string) $rate );
		if ( $rate < 0 || $rate > 100 ) return new WP_Error( 'ap_rate', 'Rate must be between 0 and 100.' );
		return round( $rate, 3 );
	}

	public static function get( $partner_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'partners' ) . ' WHERE partner_id = %d', absint( $partner_id )
		) );
	}

	public static function get_by_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'partners' ) . ' WHERE user_id = %d', absint( $user_id )
		) );
	}

	public static function all( $status = '' ) {
		global $wpdb;
		$t = PP_Schema::table( 'partners' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY display_name ASC", $status ) );
		}
		return $wpdb->get_results( "SELECT * FROM {$t} ORDER BY FIELD(status,'applicant','active','paused','archived'), display_name ASC" );
	}

	/**
	 * Ledger totals per partner: pending / approved (payable) / paid / reversed.
	 * Reversal rows (negative amounts, status approved) net into 'approved'.
	 */
	public static function totals( $partner_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT status, SUM(amount) AS total FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type <> 'history' GROUP BY status", absint( $partner_id )
		), OBJECT_K );

		return array(
			'pending'  => isset( $rows['pending'] )  ? round( (float) $rows['pending']->total, 2 )  : 0.0,
			'approved' => isset( $rows['approved'] ) ? round( (float) $rows['approved']->total, 2 ) : 0.0,
			'paid'     => isset( $rows['paid'] )     ? round( (float) $rows['paid']->total, 2 )     : 0.0,
			'reversed' => isset( $rows['reversed'] ) ? round( (float) $rows['reversed']->total, 2 ) : 0.0,
		);
	}
}
