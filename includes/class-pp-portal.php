<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Partner portal v3: proofnutrition.eu/partner — цялостно приложение, не форма.
 *
 * - Регистрация: AJAX, полеви грешки под самите полета, НИЩО не се
 *   презарежда и нищо не се губи. Авто-одобрение (настройка).
 * - Онбординг: стъпка "Избери своя код" — предложен автоматично от
 *   латинското име, редактируем, с ЖИВА проверка за свободен код и
 *   preview на линка. Създава се веднага, линкът е готов за споделяне.
 * - Табло: 5 секции като админ приложението — Табло / Кодове / Комисиони /
 *   Изплащания / Настройки.
 */
class PP_Portal {

	const NONCE = 'proof_partner_portal';

	public function __construct() {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ), 0 );

		add_action( 'wp_ajax_nopriv_proof_pp_lostpw', array( $this, 'ajax_lostpw' ) );
		add_action( 'wp_ajax_proof_pp_lostpw', array( $this, 'ajax_lostpw' ) );
		add_action( 'wp_ajax_nopriv_proof_pp_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_proof_pp_register', array( $this, 'ajax_register' ) );
		add_action( 'wp_ajax_proof_pp_check_code', array( $this, 'ajax_check_code' ) );
		add_action( 'wp_ajax_proof_pp_create_code', array( $this, 'ajax_create_code' ) );
	}

	public function rewrite() {
		add_rewrite_rule( '^partner/?$', 'index.php?proof_partner_portal=1', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'proof_partner_portal';
		return $vars;
	}

	/* ------------------------------------------------------------------ */
	/* Официална БГ транслитерация                                          */
	/* ------------------------------------------------------------------ */

	public static function latin( $name ) {
		$s = mb_strtolower( trim( (string) $name ) );
		$s = preg_replace( '/ия(?=\s|$)/u', 'ia', $s );
		$map = array(
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh',
			'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
			'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f',
			'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a',
			'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
		);
		$s = strtr( $s, $map );
		$s = preg_replace( '/[^a-z\s\-]/', '', $s );
		return trim( ucwords( $s, " -" ) );
	}

	public static function suggest_code( $latin_name ) {
		$first = strtoupper( preg_replace( '/[^A-Za-z]/', '', current( explode( ' ', trim( $latin_name ) ) ) ) );
		if ( '' === $first ) $first = 'PARTNER';
		$suffix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( proof_partner_settings()['brand_code_suffix'] ?? 'PROOF' ) ) ) ?: 'PROOF';
		return substr( $first, 0, 20 - strlen( $suffix ) ) . $suffix;
	}

	/* ------------------------------------------------------------------ */
	/* Забравена парола — изцяло в портала                                  */
	/* ------------------------------------------------------------------ */

	private static $lostpw_active = false;

	public function ajax_lostpw() {
		nocache_headers();
		$email = sanitize_email( $_POST['email'] ?? '' );
		// Винаги един и същ отговор — без user enumeration.
		$generic = array( 'ok' => true, 'msg' => 'Ако с този email има партньорски профил, изпратихме линк за нова парола. Провери и папка Спам.' );

		$user = $email ? get_user_by( 'email', $email ) : false;
		if ( $user && PP_Partners::get_by_user( $user->ID ) ) {
			$ip = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			if ( ! get_transient( 'ap_lostpw_' . $ip ) ) {
				self::$lostpw_active = true;
				add_filter( 'retrieve_password_message', array( $this, 'lostpw_message' ), 10, 4 );
				add_filter( 'retrieve_password_title', function () { return proof_partner_brand_name() . ' Партньори — нова парола'; } );
				retrieve_password( $user->user_login );
				remove_filter( 'retrieve_password_message', array( $this, 'lostpw_message' ), 10 );
				self::$lostpw_active = false;
				set_transient( 'ap_lostpw_' . $ip, 1, 3 * MINUTE_IN_SECONDS );
			}
		}
		wp_send_json( $generic );
	}

	/** Линкът в имейла сочи към портала, не към wp-login. */
	public function lostpw_message( $message, $key, $user_login, $user_data ) {
		if ( ! self::$lostpw_active ) return $message;
		$url = home_url( '/partner?rp=1&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user_login ) );
		return "Здравей!

Получихме заявка за нова парола на партньорския ти профил в {$brand}.

Отвори линка и си избери нова парола:
{$url}

Ако не си бил ти — игнорирай този имейл, паролата ти остава непроменена.

{$brand} Партньори";
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: регистрация с полеви грешки + авто-одобрение                   */
	/* ------------------------------------------------------------------ */

	public function ajax_register() {
		nocache_headers();
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Сесията изтече — презареди страницата и опитай пак.' ) );
		}
		if ( ! empty( $_POST['ap_hp'] ) ) wp_send_json( array( 'ok' => false, 'error' => 'Нещо се обърка.' ) );

		$ip = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( get_transient( 'ap_portal_reg_' . $ip ) ) {
			wp_send_json( array( 'ok' => false, 'error' => 'Вече има регистрация от този адрес — изчакай няколко минути.' ) );
		}

		$name     = sanitize_text_field( $_POST['name'] ?? '' );
		$latin    = sanitize_text_field( $_POST['latin_name'] ?? '' );
		$email    = sanitize_email( $_POST['email'] ?? '' );
		$pass     = (string) ( $_POST['password'] ?? '' );
		$pass2    = (string) ( $_POST['password2'] ?? '' );
		$phone    = sanitize_text_field( $_POST['phone'] ?? '' );
		$iban_raw = (string) ( $_POST['iban'] ?? '' );
		$channels = array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['channels'] ?? array() ) ) );

		// Полеви грешки — клиентът ги показва под точното поле, нищо не се губи.
		$errors = array();
		if ( '' === $name )              $errors['name'] = 'Въведи име и фамилия.';
		if ( ! is_email( $email ) )      $errors['email'] = 'Невалиден email.';
		elseif ( email_exists( $email ) ) $errors['email'] = 'С този email вече има профил — използвай „Вход".';
		if ( strlen( $pass ) < 8 )       $errors['password'] = 'Минимум 8 знака.';
		if ( $pass !== $pass2 )          $errors['password2'] = 'Паролите не съвпадат.';
		if ( empty( $channels ) )        $errors['channels'] = 'Посочи поне един канал.';
		$iban = PP_Partners::validate_iban( $iban_raw );
		if ( is_wp_error( $iban ) )      { $errors['iban'] = $iban->get_error_message(); $iban = ''; }
		if ( $errors ) wp_send_json( array( 'ok' => false, 'errors' => $errors ) );

		if ( '' === $latin ) $latin = self::latin( $name );

		$login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( username_exists( $login ) ) $login .= '_' . wp_generate_password( 4, false, false );
		$user_id = wp_create_user( $login, $pass, $email );
		if ( is_wp_error( $user_id ) ) wp_send_json( array( 'ok' => false, 'error' => $user_id->get_error_message() ) );
		wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );

		$auto   = ! empty( proof_partner_settings()['portal_auto_approve'] );
		$status = $auto ? 'active' : 'applicant';

		$partner_id = PP_Partners::create( array(
			'display_name' => $name,
			'email'        => $email,
			'rate'         => proof_partner_settings()['default_rate'],
			'status'       => $status,
			'legal_name'   => $latin,
			'iban'         => $iban,
			'phone'        => $phone,
			'channels'     => implode( ', ', $channels ),
			'notes'        => 'Регистрация от портала' . ( $auto ? ' (авто-активен)' : '' ) . '.',
		) );
		if ( is_wp_error( $partner_id ) ) wp_send_json( array( 'ok' => false, 'error' => $partner_id->get_error_message() ) );

		$join = strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) ( $_POST['join'] ?? '' ) ) );
		if ( $join && class_exists( 'PP_Game' ) ) {
			global $wpdb;
			$ref_pid = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT partner_id FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE coupon_code = %s AND active = 1',
				wc_format_coupon_code( $join ) ) );
			if ( $ref_pid && $ref_pid !== (int) $partner_id ) {
				PP_Partners::update( (int) $partner_id, array( 'referred_by' => $ref_pid ) );
				PP_Game::on_referred_registered( (int) $partner_id, $ref_pid );
			}
		}
		proof_partner_feed_push( 'partner', sprintf( 'Нов партньор: %s%s', $name, $auto ? ' (авто-активен)' : '' ) );
		$alerts   = (array) get_option( 'proof_partner_alerts', array() );
		$alerts[] = array(
			'partner_id' => (int) $partner_id,
			'time'       => current_time( 'mysql' ),
			'message'    => sprintf( '%s: %s (%s), канали: %s.', $auto ? 'НОВ ПАРТНЬОР (авто-активен)' : 'НОВА КАНДИДАТУРА', $name, $email, implode( ', ', $channels ) ),
		);
		update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
		set_transient( 'ap_portal_reg_' . $ip, 1, 10 * MINUTE_IN_SECONDS );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_send_json( array(
			'ok'        => true,
			'auto'      => $auto,
			'first'     => current( explode( ' ', $name ) ),
			'suggested' => self::suggest_code( $latin ),
			'nonce'     => wp_create_nonce( self::NONCE ), // свеж nonce за логнатия потребител
		) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: жива проверка + self-service създаване на код                  */
	/* ------------------------------------------------------------------ */

	private function code_verdict( $code ) {
		$code = wc_format_coupon_code( sanitize_text_field( $code ) );
		if ( strlen( $code ) < 4 || strlen( $code ) > 20 ) return array( false, '4–20 знака.' );
		if ( ! preg_match( '/^[a-z0-9]+$/', $code ) )       return array( false, 'Само букви и цифри, без интервали.' );
		if ( PP_Coupons::get_mapping_by_code( $code ) )      return array( false, 'Кодът е зает.' );
		$norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', $code ) );
		global $wpdb;
		foreach ( (array) $wpdb->get_col( 'SELECT coupon_code FROM ' . PP_Schema::table( 'coupons' ) ) as $e ) {
			if ( strtolower( preg_replace( '/[^a-z0-9]/i', '', $e ) ) === $norm ) return array( false, 'Кодът е зает.' );
		}
		if ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) ) return array( false, 'Кодът вече съществува в магазина — избери друг.' );
		if ( PP_Coupons::is_allowed_with_partner( $code ) ) return array( false, 'Този код е запазен.' );
		return array( true, 'Свободен ✓' );
	}

	public function ajax_check_code() {
		nocache_headers();
		if ( ! is_user_logged_in() ) wp_send_json( array( 'ok' => false ) );
		list( $free, $msg ) = $this->code_verdict( $_POST['code'] ?? '' );
		wp_send_json( array( 'ok' => true, 'free' => $free, 'msg' => $msg ) );
	}

	public function ajax_create_code() {
		nocache_headers();
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE ) ) wp_send_json( array( 'ok' => false, 'error' => 'Сесията изтече — презареди.' ) );
		if ( ! is_user_logged_in() ) wp_send_json( array( 'ok' => false, 'error' => 'Влез в профила си.' ) );

		$partner = PP_Partners::get_by_user( get_current_user_id() );
		if ( ! $partner || 'active' !== $partner->status ) wp_send_json( array( 'ok' => false, 'error' => 'Профилът още не е активен.' ) );

		$active = array_filter( PP_Coupons::for_partner( $partner->partner_id ), function ( $c ) { return (int) $c->active === 1; } );
		if ( ! empty( $active ) ) wp_send_json( array( 'ok' => false, 'error' => 'Вече имаш активен код. За допълнителен — пиши ни.' ) );

		$code = wc_format_coupon_code( sanitize_text_field( $_POST['code'] ?? '' ) );
		list( $free, $msg ) = $this->code_verdict( $code );
		if ( ! $free ) wp_send_json( array( 'ok' => false, 'error' => $msg ) );

		$s   = proof_partner_settings();
		$res = PP_Coupons::create_for_partner( $partner->partner_id, $code, sanitize_key( $s['default_coupon_type'] ), $s['default_coupon_amount'], false );
		if ( is_wp_error( $res ) ) wp_send_json( array( 'ok' => false, 'error' => $res->get_error_message() ) );

		proof_partner_feed_push( 'code', sprintf( 'Код създаден: %s → %s', $partner->display_name, strtoupper( $code ) ) );
		$alerts   = (array) get_option( 'proof_partner_alerts', array() );
		$alerts[] = array( 'partner_id' => (int) $partner->partner_id, 'time' => current_time( 'mysql' ),
			'message' => sprintf( 'КОД СЪЗДАДЕН от партньора: %s → %s.', $partner->display_name, strtoupper( $code ) ) );
		update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );

		// F3: добре дошъл — кодът, линковете и първото готово story в пощата.
		if ( ! empty( proof_partner_settings()['email_on_welcome'] ) ) {
			$u = wp_get_current_user();
			if ( $u->user_email ) {
				$brand  = proof_partner_brand_name();
				$accent = sanitize_hex_color( proof_partner_settings()['brand_accent'] ) ?: '#2BB6EA';
				$vanity = home_url( '/p/' . rawurlencode( strtoupper( $code ) ) );
				$story  = $this->kit_text( 'kit_story', $partner, strtoupper( $code ), $vanity, '' );
				$html   = '<div style="font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#3a2f2b;max-width:560px;margin:0 auto;padding:8px 4px">'
					. '<h2 style="color:' . esc_attr( $accent ) . ';font-size:19px;margin:0 0 14px">Добре дошъл/дошла в ' . esc_html( $brand ) . ' Партньори 🚀</h2>'
					. '<p>Твоят код: <strong style="font-size:17px">' . esc_html( strtoupper( $code ) ) . '</strong><br>Твоят линк: <a href="' . esc_url( $vanity ) . '">' . esc_html( $vanity ) . '</a> (прилага кода автоматично)</p>'
					. ( $story ? '<p style="background:#faf3ef;border-radius:12px;padding:12px 14px"><strong>Готово за първото story:</strong><br>' . nl2br( esc_html( $story ) ) . '</p>' : '' )
					. '<p style="margin:22px 0"><a href="' . esc_url( home_url( '/partner' ) ) . '" style="background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:999px;display:inline-block">Към таблото ти →</a></p></div>';
				wp_mail( $u->user_email, $brand . ' — твоят партньорски код е готов', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			}
		}

		wp_send_json( array(
			'ok'     => true,
			'code'   => strtoupper( $code ),
			'ref'    => home_url( '/?ref=' . rawurlencode( $code ) ),
			'vanity' => home_url( '/p/' . rawurlencode( strtoupper( $code ) ) ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Router                                                               */
	/* ------------------------------------------------------------------ */

	public function render() {
		if ( ! get_query_var( 'proof_partner_portal' ) ) return;

		if ( isset( $_GET['statement'] ) ) {
			$this->statement( (int) $_GET['statement'] );
			exit;
		}
		if ( isset( $_GET['statements'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$this->statements_batch( sanitize_text_field( wp_unslash( $_GET['statements'] ) ) );
			exit;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
		do_action( 'litespeed_control_set_nocache', 'proof partner portal' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );

		$error = ''; $notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['ap_portal_action'] ) ) {
			$result = $this->handle_post();
			if ( is_wp_error( $result ) )   $error  = $result->get_error_message();
			elseif ( is_string( $result ) ) $notice = $result;
		}

		status_header( 200 );
		nocache_headers();
		$this->page( $error, $notice );
		exit;
	}

	private function handle_post() {
		if ( isset( $_GET['view_as'] ) ) return new WP_Error( 'ap_viewas', 'Админ режимът е само за гледане.' );
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE ) ) {
			return new WP_Error( 'ap_nonce', 'Сесията изтече — опитай отново.' );
		}
		$action = sanitize_key( $_POST['ap_portal_action'] );

		if ( 'login' === $action ) {
			$email = sanitize_email( $_POST['email'] ?? '' );
			$user  = get_user_by( 'email', $email );
			$signed = wp_signon( array(
				'user_login'    => $user ? $user->user_login : $email,
				'user_password' => (string) ( $_POST['password'] ?? '' ),
				'remember'      => true,
			), is_ssl() );
			if ( is_wp_error( $signed ) ) return new WP_Error( 'ap_login', 'Грешен email или парола.' );
			wp_safe_redirect( home_url( '/partner' ) );
			exit;
		}

		if ( 'profile' === $action ) {
			if ( ! is_user_logged_in() ) return new WP_Error( 'ap_auth', 'Влез в профила си.' );
			$partner = PP_Partners::get_by_user( get_current_user_id() );
			if ( ! $partner ) return new WP_Error( 'ap_auth', 'Профилът не е партньорски.' );
			$channels = array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['channels'] ?? array() ) ) );
			$res = PP_Partners::update( $partner->partner_id, array(
				'legal_name' => $_POST['latin_name'] ?? '',
				'iban'       => $_POST['iban'] ?? '',
				'phone'      => $_POST['phone'] ?? '',
				'channels'   => implode( ', ', $channels ),
				'public_name'=> sanitize_text_field( $_POST['public_name'] ?? '' ),
			) );
			return is_wp_error( $res ) ? $res : 'Данните са запазени.';
		}

		if ( 'password' === $action ) {
			if ( ! is_user_logged_in() ) return new WP_Error( 'ap_auth', 'Влез в профила си.' );
			$user = wp_get_current_user();
			if ( ! wp_check_password( (string) ( $_POST['current_password'] ?? '' ), $user->user_pass, $user->ID ) ) return new WP_Error( 'ap_pw', 'Текущата парола е грешна.' );
			$new = (string) ( $_POST['new_password'] ?? '' );
			if ( strlen( $new ) < 8 ) return new WP_Error( 'ap_pw', 'Новата парола трябва да е поне 8 знака.' );
			if ( $new !== (string) ( $_POST['new_password2'] ?? '' ) ) return new WP_Error( 'ap_pw', 'Двете нови пароли не съвпадат.' );
			wp_set_password( $new, $user->ID );
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
			return 'Паролата е сменена.';
		}

		if ( 'rp_set' === $action ) {
			$login = sanitize_user( wp_unslash( $_POST['rp_login'] ?? '' ), true );
			$key   = sanitize_text_field( $_POST['rp_key'] ?? '' );
			$user  = check_password_reset_key( $key, $login );
			if ( is_wp_error( $user ) ) return new WP_Error( 'ap_rp', 'Линкът е невалиден или изтекъл — заяви нов от „Забравена парола".' );
			$new = (string) ( $_POST['new_password'] ?? '' );
			if ( strlen( $new ) < 8 ) return new WP_Error( 'ap_rp', 'Паролата трябва да е поне 8 знака.' );
			if ( $new !== (string) ( $_POST['new_password2'] ?? '' ) ) return new WP_Error( 'ap_rp', 'Двете пароли не съвпадат.' );
			reset_password( $user, $new );
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
			wp_safe_redirect( home_url( '/partner' ) );
			exit;
		}

		if ( 'logout' === $action ) {
			wp_logout();
			wp_safe_redirect( home_url( '/partner' ) );
			exit;
		}

		return new WP_Error( 'ap_unknown', 'Непознато действие.' );
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                 */
	/* ------------------------------------------------------------------ */

	private function channels_inputs( $values ) {
		if ( ! is_array( $values ) ) $values = explode( ',', (string) $values );
		$values = array_pad( array_values( array_filter( array_map( 'trim', $values ) ) ), 1, '' );
		$html   = '<div class="pp-channels">';
		foreach ( $values as $v ) {
			$html .= '<input type="text" name="channels[]" value="' . esc_attr( $v ) . '" placeholder="@профил / линк">';
		}
		$html .= '</div><button type="button" class="pp-btn ghost sm pp-add-channel">+ още канал</button>';
		return $html;
	}

	/** Данни за таблото на партньора. */
	private function partner_data( $partner ) {
		global $wpdb;
		$pid = (int) $partner->partner_id;

		$d = array( 'totals' => PP_Partners::totals( $pid ) );

		$d['month_sum'] = (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND status != 'reversed' AND created_at >= %s",
			$pid, gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) )
		) );

		$d['months'] = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS ym, SUM(amount) AS total
			 FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND status != 'reversed' AND created_at >= %s
			 GROUP BY ym ORDER BY ym",
			$pid, gmdate( 'Y-m-01', strtotime( '-5 months', current_time( 'timestamp' ) ) )
		) );

		$d['orders_30'] = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND created_at >= %s",
			$pid, gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS )
		) );

		$d['coupons'] = array_values( array_filter( PP_Coupons::for_partner( $pid ), function ( $c ) { return (int) $c->active === 1; } ) );

		$d['clicks_30'] = 0; $day_clicks = array();
		$click_opt = get_option( PP_Coupons::CLICKS_OPT, array() );
		$cut30     = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		foreach ( $d['coupons'] as $c ) {
			$code = wc_format_coupon_code( $c->coupon_code );
			foreach ( (array) $click_opt as $day => $codes ) {
				if ( $day < $cut30 || empty( $codes[ $code ] ) ) continue;
				$d['clicks_30'] += (int) $codes[ $code ];
				$wd = (int) gmdate( 'N', strtotime( $day ) );
				$day_clicks[ $wd ] = ( $day_clicks[ $wd ] ?? 0 ) + (int) $codes[ $code ];
			}
		}
		$d['conversion'] = $d['clicks_30'] ? round( 100 * $d['orders_30'] / $d['clicks_30'], 1 ) : null;
		$d['best_day']   = '';
		if ( $day_clicks ) {
			arsort( $day_clicks );
			$names = array( 1 => 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота', 'неделя' );
			$d['best_day'] = $names[ array_key_first( $day_clicks ) ];
		}

		$prod_rows = $wpdb->get_col( $wpdb->prepare(
			'SELECT products FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND products != '' AND created_at >= %s LIMIT 300",
			$pid, gmdate( 'Y-m-d', current_time( 'timestamp' ) - 90 * DAY_IN_SECONDS )
		) );
		$pc = array();
		foreach ( $prod_rows as $pr ) {
			foreach ( explode( ',', $pr ) as $item ) {
				$n = trim( preg_replace( '/\s*[-–]\s*\d+\s*(кути|бро|x|×).*/ui', '', $item ) );
				if ( mb_strlen( $n ) >= 4 ) $pc[ $n ] = ( $pc[ $n ] ?? 0 ) + 1;
			}
		}
		arsort( $pc );
		$d['top_products'] = array_slice( array_keys( $pc ), 0, 3 );

		$d['ledger'] = $wpdb->get_results( $wpdb->prepare(
			'SELECT created_at, order_id, amount, currency, status, type, products FROM ' . PP_Schema::table( 'commissions' ) . '
			 WHERE partner_id = %d ORDER BY created_at DESC LIMIT 50', $pid ) );

		$d['payouts'] = $wpdb->get_results( $wpdb->prepare(
			'SELECT payout_id, created_at, amount, currency, reference, note FROM ' . PP_Schema::table( 'payouts' ) . '
			 WHERE partner_id = %d ORDER BY payout_id DESC LIMIT 24', $pid ) );

		// №4: ниво + прогрес (rolling 30 дни, същата сметка като нощния cron)
		$sum30 = (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND created_at >= %s",
			$pid, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS )
		) );
		$d['tier'] = ! empty( proof_partner_settings()['tiers_enabled'] ) ? proof_partner_tier_for( $sum30 ) : null;

		// Личен рекорд: най-добрият календарен месец
		$best = $wpdb->get_row( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS ym, SUM(amount) AS total
			 FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND status != 'reversed' GROUP BY ym ORDER BY total DESC LIMIT 1", $pid ) );
		$d['best_month'] = $best ? array( 'ym' => $best->ym, 'total' => (float) $best->total ) : null;

		// Отстъпка на първия код (за кита)
		$d['kit_pct'] = '';
		if ( ! empty( $d['coupons'] ) && function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$wc_id = wc_get_coupon_id_by_code( wc_format_coupon_code( $d['coupons'][0]->coupon_code ) );
			if ( $wc_id ) {
				$c = new WC_Coupon( $wc_id );
				$amt = rtrim( rtrim( number_format( (float) $c->get_amount(), 2 ), '0' ), '.' );
				$d['kit_pct'] = 'percent' === $c->get_discount_type() ? $amt . '%' : $amt . ' €';
			}
		}
		return $d;
	}

	private function status_bg( $s, $type = '' ) {
		if ( 'reversal' === $type ) return 'Корекция';
		$map = array( 'pending' => 'Изчаква', 'approved' => 'За изплащане', 'paid' => 'Изплатена', 'reversed' => 'Сторнирана' );
		return $map[ $s ] ?? $s;
	}

	/** Шаблон от настройките с плейсхолдъри {code} {discount} {link} {name}. */
	private function kit_text( $key, $partner, $code, $link, $discount ) {
		$st  = proof_partner_settings();
		$tpl = trim( (string) ( $st[ $key ] ?? '' ) );
		if ( '' === $tpl ) return '';
		if ( 'kit_campaign' === $key ) {
			$today = current_time( 'Y-m-d' );
			if ( ! empty( $st['kit_campaign_from'] ) && $today < $st['kit_campaign_from'] ) return '';
			if ( ! empty( $st['kit_campaign_to'] )   && $today > $st['kit_campaign_to'] )   return '';
		}
		return strtr( $tpl, array(
			'{code}'     => $code,
			'{link}'     => $link,
			'{discount}' => $discount ?: 'отстъпка',
			'{name}'     => current( explode( ' ', $partner->display_name ) ),
			'{brand}'    => proof_partner_brand_name(),
		) );
	}

	private function page( $error, $notice ) {
		$assets  = plugin_dir_url( PROOF_PARTNER_FILE ) . 'assets/';
		// Админ view-as: гледаш портала през очите на партньора, БЕЗ подмяна
		// на сесията ти. Формите са заключени — само гледаш.
		$view_as = 0;
		if ( isset( $_GET['view_as'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$view_as = absint( $_GET['view_as'] );
		}
		$BS      = proof_partner_settings();
		$brand   = proof_partner_brand_name();
		$logo    = $BS['brand_logo_url'] ?: '';
		$accent  = sanitize_hex_color( $BS['brand_accent'] ) ?: '#2BB6EA';
		$partner = $view_as ? PP_Partners::get( $view_as ) : ( is_user_logged_in() ? PP_Partners::get_by_user( get_current_user_id() ) : null );
		$needs_code = ! $view_as && $partner && 'active' === $partner->status
			&& ! array_filter( PP_Coupons::for_partner( $partner->partner_id ), function ( $c ) { return (int) $c->active === 1; } );
		$data = ( $partner && in_array( $partner->status, array( 'active', 'paused' ), true ) ) ? $this->partner_data( $partner ) : null;
		?><!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="generator" content="proof-partner <?php echo esc_attr( PROOF_PARTNER_VERSION ); ?>">
<title><?php echo esc_html( $brand ); ?> — Партньорски портал</title>
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'portal.css?v=' . PROOF_PARTNER_VERSION ); ?>">
<style>:root, .pp-wrap { <?php echo esc_html( proof_partner_palette_css() ); ?> }</style>
</head>
<body class="app-portal">
<div class="pp-wrap<?php echo $data ? ' wide' : ''; ?>">
	<header class="pp-head">
		<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" width="94" height="40" style="object-fit:contain"><?php else : ?><b style="font:900 18px Montserrat,sans-serif;color:#16303F;letter-spacing:.02em"><?php echo esc_html( $brand ); ?></b><?php endif; ?>
		<span class="pp-sub">Партньорски портал</span>
		<?php if ( is_user_logged_in() ) : ?>
			<form method="post" class="pp-logout"><?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ap_portal_action" value="logout">
				<button class="pp-btn ghost sm">Изход</button>
			</form>
		<?php endif; ?>
	</header>

	<?php if ( $view_as && $partner ) : ?><div class="pp-msg wait" style="border:2px dashed #d64545">👁 АДМИН РЕЖИМ: гледаш портала като <strong><?php echo esc_html( $partner->display_name ); ?></strong>. Формите са заключени. <a href="<?php echo esc_url( admin_url( 'admin.php?page=proof-partner#/partners/' . (int) $partner->partner_id ) ); ?>">← обратно в админа</a></div><?php endif; ?>
	<?php if ( $view_as && ! $partner ) : ?><div class="pp-msg err">Партньор с това ID липсва.</div><?php endif; ?>
	<?php if ( $error ) : ?><div class="pp-msg err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<?php if ( $notice ) : ?><div class="pp-msg ok"><?php echo esc_html( $notice ); ?></div><?php endif; ?>

<?php if ( $partner && $needs_code ) : /* ---------- ОНБОРДИНГ: избери своя код ---------- */
	$suggested = self::suggest_code( $partner->legal_name ?: self::latin( $partner->display_name ) ); ?>

	<section class="pp-card pp-auth" id="pp-codestep">
		<h2>Избери своя промо код 🎉</h2>
		<p class="pp-hint">Предложихме ти един — можеш да го запазиш или да напишеш свой. Кодът дава отстъпка на клиентите ти и носи комисиона на теб.</p>
		<label>Твоят код
			<input type="text" id="pp-code-input" value="<?php echo esc_attr( $suggested ); ?>" maxlength="20" autocomplete="off" style="text-transform:uppercase;font-size:18px;letter-spacing:.04em">
		</label>
		<span class="pp-iban-hint" id="pp-code-hint"></span>
		<p class="pp-hint" style="margin:6px 0 14px">Линкът ти ще бъде: <code id="pp-code-preview"><?php echo esc_html( home_url( '/p/' ) ); ?><span><?php echo esc_html( $suggested ); ?></span></code></p>
		<button class="pp-btn" id="pp-code-create">Създай кода ми</button>
	</section>

	<section class="pp-card pp-welcome" id="pp-codedone" style="display:none">
		<h2>Готово! 🚀</h2>
		<div class="pp-codebox">
			<span class="lbl">Твоят код</span>
			<code id="pp-done-code"></code>
			<button type="button" class="pp-copy" data-copy-el="pp-done-code">Копирай</button>
		</div>
		<p><strong>Линк за споделяне:</strong> <code id="pp-done-link"></code> <button type="button" class="pp-copy" data-copy-el="pp-done-link">Копирай</button></p>
		<ul class="pp-list">
			<li>Сложи линка в bio-то си — той прилага кода автоматично.</li>
			<li>В stories споменавай кода — клиентът го въвежда при поръчка.</li>
			<li>Всяка продажба и изплащане ги виждаш тук, на живо.</li>
		</ul>
		<button class="pp-btn" onclick="location.reload()">Влез в таблото</button>
	</section>

<?php elseif ( $partner && $data ) : /* ---------- ПРИЛОЖЕНИЕТО: 5 секции ---------- */ ?>

	<?php if ( 'paused' === $partner->status ) : ?><div class="pp-msg wait">Профилът е на пауза — кодът ти временно не работи. Пиши ни при въпроси.</div><?php endif; ?>

	<nav class="pp-nav">
		<button class="pp-navbtn on" data-pane="home">Табло</button>
		<button class="pp-navbtn" data-pane="codes">Кодове</button>
		<button class="pp-navbtn" data-pane="comm">Комисиони</button>
		<?php if ( class_exists( 'PP_Game' ) && ( PP_Game::active() || PP_Game::has_final() ) ) : ?><button class="pp-navbtn" data-pane="game">🏆 Играта</button><?php endif; ?>
		<button class="pp-navbtn" data-pane="pay">Изплащания</button>
		<?php $pp_ship = class_exists( 'PP_Proof' ) ? PP_Proof::shipments_for( $partner->partner_id ) : array(); if ( $pp_ship ) : ?><button class="pp-navbtn" data-pane="goods">📦 Продукти</button><?php endif; ?>
		<button class="pp-navbtn" data-pane="set">Настройки</button>
	</nav>

	<?php
	$campaign = '';
	if ( ! empty( $data['coupons'] ) ) {
		$campaign = $this->kit_text( 'kit_campaign', $partner, strtoupper( $data['coupons'][0]->coupon_code ), home_url( '/p/' . rawurlencode( strtoupper( $data['coupons'][0]->coupon_code ) ) ), $data['kit_pct'] );
	}
	if ( $campaign ) : ?>
	<div class="pp-campaign">🎉 <strong>Кампания:</strong> <span id="pp-camp"><?php echo esc_html( $campaign ); ?></span> <button type="button" class="pp-copy" data-copy-el="pp-camp">Копирай</button></div>
	<?php endif; ?>

	<div class="pp-pane" data-pane="home">
		<?php $pp_fx = class_exists( 'PP_Proof' ) ? PP_Proof::fixed_message( $partner ) : ''; if ( $pp_fx ) : ?>
		<div class="pp-card pp-fxmsg"><p style="margin:0;font-size:15px;line-height:1.6"><?php echo esc_html( $pp_fx ); ?></p></div>
		<?php endif; ?>
		<div class="pp-kpis">
			<div class="pp-kpi">Този месец<b><?php echo esc_html( number_format( $data['month_sum'], 2 ) ); ?> €</b></div>
			<div class="pp-kpi">Изчакващи<b><?php echo esc_html( number_format( $data['totals']['pending'], 2 ) ); ?> €</b></div>
			<div class="pp-kpi hot">За изплащане<b><?php echo esc_html( number_format( $data['totals']['approved'], 2 ) ); ?> €</b></div>
			<div class="pp-kpi">Изплатени<b><?php echo esc_html( number_format( $data['totals']['paid'], 2 ) ); ?> €</b></div>
		</div>
		<?php if ( ! empty( $data['tier'] ) ) : $T = $data['tier']; ?>
		<div class="pp-card pp-tier">
			<h2>🏆 Твоето ниво: <?php echo esc_html( $T['tier']['name'] ); ?> · <?php echo esc_html( rtrim( rtrim( number_format( (float) $T['tier']['rate'], 3 ), '0' ), '.' ) ); ?>% комисиона</h2>
			<?php if ( $T['next'] ) : $need = max( 0, $T['next']['min'] - $T['sum30'] ); $pct = min( 100, round( 100 * $T['sum30'] / max( 0.01, $T['next']['min'] ) ) ); ?>
			<div class="pp-progress"><b style="width:<?php echo (int) $pct; ?>%"></b></div>
			<p class="pp-hint" style="margin-top:6px">Още <strong><?php echo esc_html( number_format( $need, 2 ) ); ?> €</strong> продажби за 30 дни до <strong><?php echo esc_html( $T['next']['name'] ); ?> (<?php echo esc_html( rtrim( rtrim( number_format( (float) $T['next']['rate'], 3 ), '0' ), '.' ) ); ?>%)</strong>. Текущо: <?php echo esc_html( number_format( $T['sum30'], 2 ) ); ?> €.</p>
			<?php else : ?>
			<p class="pp-hint" style="margin-top:4px">Най-високото ниво — държиш короната 👑 (<?php echo esc_html( number_format( $T['sum30'], 2 ) ); ?> € за 30 дни)</p>
			<?php endif; ?>
			<?php if ( ! empty( $data['best_month'] ) ) : ?><p class="pp-hint">Личен рекорд: <strong><?php echo esc_html( number_format( $data['best_month']['total'], 2 ) ); ?> €</strong> през <?php echo esc_html( mysql2date( 'F Y', $data['best_month']['ym'] . '-01' ) ); ?><?php if ( $data['month_sum'] > $data['best_month']['total'] * 0.8 && $data['month_sum'] < $data['best_month']['total'] ) echo ' — този месец си на крачка да го счупиш!'; ?></p><?php endif; ?>
		</div>
		<?php endif; ?>
		<div class="pp-card">
			<h2>Последни 6 месеца</h2>
			<?php if ( empty( $data['months'] ) ) : ?><p class="pp-hint">Още няма комисиони — сподели линка си и започваме.</p>
			<?php else : $max = 0.01; foreach ( $data['months'] as $m ) $max = max( $max, (float) $m->total ); ?>
			<div class="pp-bars">
				<?php foreach ( $data['months'] as $m ) : $h = max( 6, round( 90 * (float) $m->total / $max ) ); ?>
				<div class="pp-bar"><i><?php echo esc_html( number_format( (float) $m->total, 0 ) ); ?>€</i><b style="height:<?php echo (int) $h; ?>px"></b><u><?php echo esc_html( mysql2date( 'M', $m->ym . '-01' ) ); ?></u></div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<div class="pp-statline">
				<span>Кликове 30д: <strong><?php echo (int) $data['clicks_30']; ?></strong></span>
				<span>Поръчки 30д: <strong><?php echo (int) $data['orders_30']; ?></strong></span>
				<span>Конверсия: <strong><?php echo null === $data['conversion'] ? '—' : esc_html( $data['conversion'] . '%' ); ?></strong></span>
			</div>
			<?php if ( $data['best_day'] || $data['top_products'] ) : ?>
			<p class="pp-hint" style="margin-top:8px">
				<?php if ( $data['best_day'] ) : ?>💡 Най-много кликове събираш в <strong><?php echo esc_html( $data['best_day'] ); ?></strong> — публикувай тогава.<?php endif; ?>
				<?php if ( $data['top_products'] ) : ?> Най-купувани през твоя код: <strong><?php echo esc_html( implode( ', ', $data['top_products'] ) ); ?></strong>.<?php endif; ?>
			</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="pp-pane hidden" data-pane="codes">
		<?php foreach ( $data['coupons'] as $c ) :
			$code   = strtoupper( $c->coupon_code );
			$ref    = home_url( '/?ref=' . rawurlencode( $c->coupon_code ) );
			$vanity = home_url( '/p/' . rawurlencode( $code ) );
			$c30    = PP_Coupons::clicks_30d( $c->coupon_code ); ?>
		<div class="pp-card">
			<div class="pp-codebox" style="margin-top:0">
				<span class="lbl">Твоят код · <?php echo (int) $c30; ?> кликa за 30 дни</span>
				<code id="cc-<?php echo (int) $c->id; ?>"><?php echo esc_html( $code ); ?></code>
				<button type="button" class="pp-copy" data-copy-el="cc-<?php echo (int) $c->id; ?>">Копирай</button>
			</div>
			<p><strong>Кратък линк:</strong> <code id="cv-<?php echo (int) $c->id; ?>"><?php echo esc_html( $vanity ); ?></code> <button type="button" class="pp-copy" data-copy-el="cv-<?php echo (int) $c->id; ?>">Копирай</button><br>
			<span class="pp-hint">Линкът прилага кода автоматично — идеален за bio и swipe-up.</span></p>
		</div>
		<?php endforeach; ?>
		<?php if ( ! empty( $data['coupons'] ) ) :
			$code   = strtoupper( $data['coupons'][0]->coupon_code );
			$vanity = home_url( '/p/' . rawurlencode( $code ) );
			$story  = $this->kit_text( 'kit_story', $partner, $code, $vanity, $data['kit_pct'] );
			$post   = $this->kit_text( 'kit_post', $partner, $code, $vanity, $data['kit_pct'] ); ?>
		<div class="pp-card">
			<h2>Готово за споделяне</h2>
			<p><strong>Story:</strong> <span id="kit-s"><?php echo esc_html( $story ); ?></span> <button type="button" class="pp-copy" data-copy-el="kit-s">Копирай</button></p>
			<p><strong>Пост:</strong> <span id="kit-p"><?php echo esc_html( $post ); ?></span> <button type="button" class="pp-copy" data-copy-el="kit-p">Копирай</button></p>
		</div>
		<?php endif; ?>
	</div>

	<div class="pp-pane hidden" data-pane="comm">
		<div class="pp-card">
			<h2>Комисиони — последни 50</h2>
			<?php if ( empty( $data['ledger'] ) ) : ?><p class="pp-hint">Още няма движения.</p>
			<?php else : ?>
			<table><thead><tr><th>Дата</th><th>Поръчка</th><th>Продукти</th><th style="text-align:right">Сума</th><th>Статус</th></tr></thead><tbody>
			<?php foreach ( $data['ledger'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></td>
					<td><?php echo $r->order_id ? '#' . (int) $r->order_id : '—'; ?></td>
					<td class="pp-trim"><?php echo esc_html( mb_substr( (string) $r->products, 0, 60 ) ); ?></td>
					<td style="text-align:right;<?php echo (float) $r->amount < 0 ? 'color:#d64545' : ''; ?>"><?php echo esc_html( number_format( (float) $r->amount, 2 ) . ' ' . $r->currency ); ?></td>
					<td><?php echo esc_html( $this->status_bg( $r->status, $r->type ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="pp-hint">„Изчаква" става „За изплащане" след кратък период на задържане (защита при връщания). Плащаме в началото на всеки месец за предходния.</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( class_exists( 'PP_Game' ) && ( PP_Game::active() || PP_Game::has_final() ) ) : ?>
	<div class="pp-pane hidden" data-pane="game">
		<?php try { PP_Game_View::pane( $partner, $data ); } catch ( \Throwable $e ) { proof_partner_log_shield( 'game_pane', $e ); echo '<div class="pp-card"><p class="pp-hint">Екранът на играта е временно недостъпен.</p></div>'; } ?>
	</div>
	<?php endif; ?>

	<div class="pp-pane hidden" data-pane="pay">
		<div class="pp-card">
			<h2>Изплащания</h2>
			<p class="pp-hint">Плащаме в началото на всеки месец за предходния. Текущо „За изплащане": <strong><?php echo esc_html( number_format( $data['totals']['approved'], 2 ) ); ?> €</strong>.</p>
			<?php if ( empty( $data['payouts'] ) ) : ?><p class="pp-hint">Още няма изплащания.</p>
			<?php else : ?>
			<table><thead><tr><th>Дата</th><th style="text-align:right">Сума</th><th>Период</th><th></th></tr></thead><tbody>
			<?php foreach ( $data['payouts'] as $r ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></td>
					<td style="text-align:right"><strong><?php echo esc_html( number_format( (float) $r->amount, 2 ) . ' ' . $r->currency ); ?></strong></td>
					<td class="pp-trim"><?php echo esc_html( str_replace( 'Период: ', '', (string) $r->note ) ?: $r->reference ); ?></td>
					<td><a href="<?php echo esc_url( home_url( '/partner?statement=' . (int) $r->payout_id ) ); ?>" target="_blank" rel="noopener">Справка ↗</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="pp-hint">„Справка" е месечният ти отчет за печат/PDF — за твоето счетоводство.</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $pp_ship ) ) : $pp_st = PP_Proof::shipments_totals( $partner->partner_id ); ?>
	<div class="pp-pane hidden" data-pane="goods">
		<div class="pp-card">
			<h2>📦 Продукти, изпратени към теб</h2>
			<p class="pp-hint">Получени: <strong><?php echo (int) $pp_st['delivered']; ?></strong> пратки · <strong><?php echo (int) $pp_st['qty']; ?></strong> бр. · на стойност <strong><?php echo esc_html( number_format( $pp_st['value'], 2 ) ); ?> €</strong> по каталожни цени. Пратка се брои за получена при статус „доставена/завършена".</p>
			<?php foreach ( $pp_ship as $sh ) : ?>
			<div style="border-top:1px solid var(--line);padding:12px 0">
				<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><strong>Пратка #<?php echo (int) $sh['order_id']; ?></strong><span class="pp-hint"><?php echo esc_html( $sh['order_date'] ? mysql2date( 'd.m.Y', $sh['order_date'] ) : '' ); ?></span><span class="pp-chip <?php echo $sh['counted'] ? 'ok' : 'wait'; ?>"><?php echo $sh['counted'] ? '✓ получена' : ( in_array( $sh['order_status'], array( 'cancelled', 'refunded', 'failed' ), true ) ? '✕ отменена' : '⏳ по пътя' ); ?></span><span style="flex:1"></span><strong><?php echo (int) $sh['qty']; ?> бр. · <?php echo esc_html( number_format( $sh['value'], 2 ) ); ?> €</strong></div>
				<ul style="margin:6px 0 0 18px;padding:0;font-size:13px"><?php foreach ( $sh['items'] as $it ) : ?><li><?php echo esc_html( $it['name'] ); ?> × <?php echo (int) $it['qty']; ?></li><?php endforeach; ?></ul>
				<?php if ( $sh['note'] ) : ?><p class="pp-hint" style="margin:4px 0 0"><?php echo esc_html( $sh['note'] ); ?></p><?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="pp-pane hidden" data-pane="set">
		<?php if ( $view_as ) : ?><p class="pp-hint">👁 Данните се виждат, формите са заключени в админ режим.</p><?php endif; ?>
		<div class="pp-card">
			<h2>Моите данни</h2>
			<form method="post"<?php echo $view_as ? ' style="pointer-events:none;opacity:.65"' : ''; ?>><?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ap_portal_action" value="profile">
				<label>Имена на латиница (за изплащанията)<input type="text" name="latin_name" value="<?php echo esc_attr( $partner->legal_name ?? '' ); ?>"></label>
				<label>Име в класацията на Играта (по избор — псевдоним)<input type="text" name="public_name" value="<?php echo esc_attr( $partner->public_name ?? '' ); ?>" placeholder="напр. Мая К."></label>
				<label>IBAN за възнагражденията<input type="text" name="iban" value="<?php echo esc_attr( $partner->iban ?? '' ); ?>" placeholder="BG.."></label>
				<label>Телефон<input type="text" name="phone" value="<?php echo esc_attr( $partner->phone ?? '' ); ?>"></label>
				<label>Канали</label>
				<?php echo $this->channels_inputs( $partner->channels ?? '' ); ?>
				<button class="pp-btn" style="margin-top:14px">Запази данните</button>
			</form>
		</div>
		<div class="pp-card"<?php echo $view_as ? ' style="display:none"' : ''; ?>>
			<h2>Смяна на парола</h2>
			<form method="post"><?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ap_portal_action" value="password">
				<label>Текуща парола<input type="password" name="current_password" required autocomplete="current-password"></label>
				<label>Нова парола (мин. 8 знака)<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
				<label>Повтори новата<input type="password" name="new_password2" required minlength="8" autocomplete="new-password"></label>
				<button class="pp-btn">Смени паролата</button>
			</form>
		</div>
	</div>

<?php elseif ( $partner && 'applicant' === $partner->status ) : ?>

	<div class="pp-msg wait">Кандидатурата ти чака одобрение. Щом активираме профила, тук избираш кода си и започваш.</div>

<?php elseif ( $partner && 'archived' === $partner->status ) : ?>

	<div class="pp-msg err">Профилът е архивиран. Свържи се с нас при въпроси.</div>

<?php elseif ( is_user_logged_in() ) : $cu = wp_get_current_user(); ?>

	<section class="pp-card pp-auth">
		<h2>Този профил не е партньорски</h2>
		<p class="pp-hint">Влязъл/ла си като <strong><?php echo esc_html( $cu->user_email ); ?></strong> — този email няма партньорски профил. Възможно е партньорската ти регистрация да е с ДРУГ email.</p>
		<?php if ( current_user_can( 'manage_woocommerce' ) ) : ?>
			<p class="pp-hint" style="background:#fdf3e3;border-radius:12px;padding:10px 14px">👑 Това е <strong>администраторски</strong> профил. Порталът е за партньори — за да видиш нечие табло, ползвай „Виж портала като него" от <a href="<?php echo esc_url( admin_url( 'admin.php?page=proof-partner' ) ); ?>">админ панела</a>.</p>
		<?php endif; ?>
		<form method="post" style="display:inline"><?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="ap_portal_action" value="logout">
			<button class="pp-btn">Излез и влез с партньорския email</button>
		</form>
		<p class="pp-hint" style="margin-top:12px">Нямаш партньорски профил? След изхода избери „Регистрация" — отнема 2 минути. Или ни пиши и ще те свържем с този email.</p>
	</section>

<?php elseif ( ! is_user_logged_in() && isset( $_GET['rp'] ) ) :
	$rp_login = sanitize_user( wp_unslash( $_GET['login'] ?? '' ), true );
	$rp_key   = sanitize_text_field( $_GET['key'] ?? '' );
	$rp_user  = check_password_reset_key( $rp_key, $rp_login ); ?>

	<section class="pp-card pp-auth">
		<?php if ( is_wp_error( $rp_user ) ) : ?>
			<h2>Линкът е изтекъл</h2>
			<p class="pp-hint">Заяви нов от „Забравена парола" на <a href="<?php echo esc_url( home_url( '/partner' ) ); ?>">страницата за вход</a>.</p>
		<?php else : ?>
			<h2>Избери нова парола</h2>
			<form method="post"><?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ap_portal_action" value="rp_set">
				<input type="hidden" name="rp_login" value="<?php echo esc_attr( $rp_login ); ?>">
				<input type="hidden" name="rp_key" value="<?php echo esc_attr( $rp_key ); ?>">
				<label>Нова парола (мин. 8 знака)<input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
				<label>Повтори я<input type="password" name="new_password2" required minlength="8" autocomplete="new-password"></label>
				<button class="pp-btn">Запази и влез</button>
			</form>
		<?php endif; ?>
	</section>

<?php else : /* ---------- ВХОД / РЕГИСТРАЦИЯ ---------- */ ?>

	<section class="pp-card pp-auth">
		<div class="pp-tabs" role="tablist">
			<button type="button" class="pp-tab on" data-pane="login">Вход</button>
			<button type="button" class="pp-tab" data-pane="register">Регистрация</button>
		</div>

		<div class="pp-pane" data-pane="login">
			<form method="post"><?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="ap_portal_action" value="login">
				<label>Email<input type="email" name="email" required autocomplete="email"></label>
				<label>Парола<input type="password" name="password" required autocomplete="current-password"></label>
				<button class="pp-btn">Влез</button>
				<p class="pp-hint"><a href="#" id="pp-lost-toggle">Забравена парола?</a></p>
			</form>
			<div id="pp-lost" style="display:none">
				<label>Email на профила<input type="email" id="pp-lost-email" autocomplete="email"></label>
				<button type="button" class="pp-btn" id="pp-lost-send">Изпрати линк за нова парола</button>
				<p class="pp-ferr" id="pp-lost-msg" style="min-height:16px"></p>
			</div>
		</div>

		<div class="pp-pane hidden" data-pane="register">
			<p class="pp-hint">Личен промо код и линк, комисиона от всяка продажба, табло с всичко на едно място.</p>
			<div id="pp-reg" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
				<input type="text" name="ap_hp" tabindex="-1" autocomplete="new-password" class="pp-hp" aria-hidden="true">
				<p class="pp-hint" style="font-size:12px">С регистрацията се съгласяваш името ти (или псевдоним от Настройки) да се показва в класациите на програмата.</p>
				<input type="hidden" data-f="join" id="pp-join" value="<?php echo esc_attr( strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) ( $_GET['join'] ?? '' ) ) ) ); ?>">
				<?php if ( ! empty( $_GET['join'] ) ) : ?>
				<div class="pp-hint" style="background:#e2f2e9;color:#2b7550;font-weight:800;border-radius:12px;padding:10px 14px;margin-bottom:10px">🤝 Идваш с покана от партньор — регистрирай се и стартираш с пълна подкрепа.</div>
				<script>document.addEventListener('DOMContentLoaded',function(){var b=document.querySelector('.pp-tab[data-pane="register"]');if(b)b.click();});</script>
				<?php endif; ?>
				<label>Име и фамилия<input type="text" data-f="name" id="pp-name" placeholder="Калина Петрова"><span class="pp-ferr" data-e="name"></span></label>
				<label>На латиница (за изплащанията — попълва се само̀)
					<input type="text" data-f="latin_name" id="pp-latin" placeholder="Kalina Petrova"><span class="pp-ferr" data-e="latin_name"></span></label>
				<label>Email<input type="email" data-f="email" autocomplete="email"><span class="pp-ferr" data-e="email"></span></label>
				<label>Парола (мин. 8 знака)<input type="password" data-f="password" id="pp-pass" autocomplete="new-password"><span class="pp-ferr" data-e="password"></span></label>
				<label>Повтори паролата<input type="password" data-f="password2" id="pp-pass2" autocomplete="new-password"><span class="pp-ferr" data-e="password2"></span></label>
				<label>Канали (Instagram / TikTok / YouTube / сайт)</label>
				<?php echo $this->channels_inputs( '' ); ?>
				<span class="pp-ferr" data-e="channels"></span>
				<label style="margin-top:13px">Телефон (по избор)<input type="text" data-f="phone"></label>
				<label>IBAN (по избор — може и по-късно от Настройки)
					<input type="text" data-f="iban" id="pp-iban" placeholder="BG.."><span class="pp-iban-hint" id="pp-iban-hint"></span><span class="pp-ferr" data-e="iban"></span></label>
				<button class="pp-btn" id="pp-reg-btn">Регистрирай ме</button>
				<p class="pp-ferr" data-e="_global" style="margin-top:10px"></p>
			</div>
		</div>
	</section>

<?php endif; ?>

	<footer class="pp-foot"><?php echo esc_html( $brand ); ?> · <a href="<?php echo esc_url( home_url( '/' ) ); ?>">към магазина</a></footer>
</div>

<script>
(function () {
	var AJAX = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

	/* Табове (вход/регистрация) + навигация (приложението) */
	document.querySelectorAll('.pp-tabs .pp-tab, .pp-nav .pp-navbtn').forEach(function (t) {
		t.addEventListener('click', function () {
			var group = t.closest('.pp-tabs, .pp-nav');
			group.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === t); });
			var scope = group.classList.contains('pp-nav') ? document : group.parentElement;
			scope.querySelectorAll(':scope > .pp-pane, .pp-wrap > .pp-pane').forEach(function (p) {
				p.classList.toggle('hidden', p.dataset.pane !== t.dataset.pane);
			});
		});
	});

	/* Копирай бутони */
	document.querySelectorAll('.pp-copy').forEach(function (b) {
		b.addEventListener('click', function () {
			var el = document.getElementById(b.dataset.copyEl);
			if (!el) return;
			navigator.clipboard.writeText(el.textContent.trim()).then(function () {
				var t = b.textContent; b.textContent = 'Копирано ✓';
				setTimeout(function () { b.textContent = t; }, 1800);
			});
		});
	});

	/* + още канал */
	document.querySelectorAll('.pp-add-channel').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var box = btn.previousElementSibling;
			if (box.querySelectorAll('input').length >= 4) return;
			var i = document.createElement('input');
			i.type = 'text'; i.name = 'channels[]'; i.placeholder = '@профил / линк';
			box.appendChild(i);
		});
	});

	/* Транслитерация на живо */
	var MAP = { 'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'h','ц':'ts','ч':'ch','ш':'sh','щ':'sht','ъ':'a','ь':'y','ю':'yu','я':'ya' };
	function latin(s) {
		s = (s || '').toLowerCase().replace(/ия(?=\s|$)/g, 'ia');
		var out = '';
		for (var i = 0; i < s.length; i++) out += (MAP[s[i]] !== undefined) ? MAP[s[i]] : s[i];
		out = out.replace(/[^a-z\s\-]/g, '');
		return out.replace(/(^|\s|\-)([a-z])/g, function (m, p, c) { return p + c.toUpperCase(); }).trim();
	}
	var nameEl = document.getElementById('pp-name'), latEl = document.getElementById('pp-latin'), touched = false;
	if (nameEl && latEl) {
		latEl.addEventListener('input', function () { touched = latEl.value !== ''; });
		nameEl.addEventListener('input', function () { if (!touched) latEl.value = latin(nameEl.value); });
	}

	/* IBAN mod-97 на живо */
	function ibanValid(v) {
		v = (v || '').toUpperCase().replace(/\s+/g, '');
		if (v === '') return null;
		if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/.test(v)) return false;
		var r = v.slice(4) + v.slice(0, 4), num = '';
		for (var i = 0; i < r.length; i++) { var c = r.charCodeAt(i); num += (c >= 65) ? String(c - 55) : r[i]; }
		var rem = 0;
		for (var j = 0; j < num.length; j += 7) rem = parseInt(String(rem) + num.substr(j, 7), 10) % 97;
		return rem === 1;
	}
	var ib = document.getElementById('pp-iban'), ih = document.getElementById('pp-iban-hint');
	if (ib && ih) ib.addEventListener('input', function () {
		var ok = ibanValid(ib.value);
		ih.textContent = ok === null ? '' : (ok ? '✓ Валиден IBAN' : '✗ Невалиден IBAN — провери цифрите');
		ih.className = 'pp-iban-hint' + (ok === null ? '' : (ok ? ' good' : ' bad'));
	});

	/* ---------- Забравена парола: в портала, без redirect ---------- */
	var lt = document.getElementById('pp-lost-toggle');
	if (lt) {
		lt.addEventListener('click', function (e) {
			e.preventDefault();
			var box = document.getElementById('pp-lost');
			box.style.display = box.style.display === 'none' ? '' : 'none';
		});
		document.getElementById('pp-lost-send').addEventListener('click', function () {
			var b = this, msg = document.getElementById('pp-lost-msg');
			b.disabled = true;
			var fd = new FormData();
			fd.append('action', 'proof_pp_lostpw');
			fd.append('email', document.getElementById('pp-lost-email').value);
			fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) { b.disabled = false; msg.textContent = d.msg || ''; msg.style.color = 'var(--good)'; })
				.catch(function () { b.disabled = false; msg.textContent = 'Мрежова грешка — опитай пак.'; });
		});
	}

	/* ---------- AJAX регистрация: НИЩО не се ресетва ---------- */
	var reg = document.getElementById('pp-reg');
	if (reg) {
		var btn = document.getElementById('pp-reg-btn');
		btn.addEventListener('click', function () {
			reg.querySelectorAll('.pp-ferr').forEach(function (e) { e.textContent = ''; });
			var fd = new FormData();
			fd.append('action', 'proof_pp_register');
			fd.append('_wpnonce', reg.dataset.nonce);
			fd.append('ap_hp', reg.querySelector('[name=ap_hp]').value);
			reg.querySelectorAll('[data-f]').forEach(function (i) { fd.append(i.dataset.f, i.value); });
			reg.querySelectorAll('[name="channels[]"]').forEach(function (i) { if (i.value.trim()) fd.append('channels[]', i.value); });

			// Клиентски проверки — преди изобщо да пращаме
			var errs = {};
			var p1 = document.getElementById('pp-pass').value, p2 = document.getElementById('pp-pass2').value;
			if (p1.length < 8) errs.password = 'Минимум 8 знака.';
			if (p1 !== p2)     errs.password2 = 'Паролите не съвпадат.';
			if (ib && ibanValid(ib.value) === false) errs.iban = 'Невалиден IBAN.';
			if (Object.keys(errs).length) { showErrs(errs); return; }

			btn.disabled = true; btn.textContent = 'Изпращане…';
			fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					btn.disabled = false; btn.textContent = 'Регистрирай ме';
					if (!d.ok) { showErrs(d.errors || { _global: d.error || 'Грешка — опитай отново.' }); return; }
					if (d.auto) { location.reload(); } // → онбординг: избери своя код
					else {
						reg.innerHTML = '<div class="pp-msg ok">Кандидатурата е приета, ' + d.first + '! Ще я прегледаме и активираме съвсем скоро — тогава тук избираш кода си.</div>';
					}
				})
				.catch(function () { btn.disabled = false; btn.textContent = 'Регистрирай ме'; showErrs({ _global: 'Мрежова грешка — данните ти са си тук, опитай пак.' }); });
		});
		function showErrs(errs) {
			Object.keys(errs).forEach(function (k) {
				var el = reg.querySelector('[data-e="' + k + '"]');
				if (el) el.textContent = errs[k];
			});
			var first = reg.querySelector('.pp-ferr:not(:empty)');
			if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	/* ---------- Онбординг: избери своя код ---------- */
	var ci = document.getElementById('pp-code-input');
	if (ci) {
		var hint = document.getElementById('pp-code-hint'),
			prev = document.querySelector('#pp-code-preview span'),
			cbtn = document.getElementById('pp-code-create'),
			nonce = '<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>',
			timer = null, free = null;
		function check() {
			var v = ci.value.trim().toUpperCase();
			if (prev) prev.textContent = v;
			clearTimeout(timer);
			timer = setTimeout(function () {
				var fd = new FormData();
				fd.append('action', 'proof_pp_check_code'); fd.append('code', v);
				fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						free = !!d.free;
						hint.textContent = d.msg || '';
						hint.className = 'pp-iban-hint ' + (d.free ? 'good' : 'bad');
						cbtn.disabled = !d.free;
					});
			}, 350);
		}
		ci.addEventListener('input', check);
		check();
		cbtn.addEventListener('click', function () {
			cbtn.disabled = true; cbtn.textContent = 'Създаване…';
			var fd = new FormData();
			fd.append('action', 'proof_pp_create_code'); fd.append('_wpnonce', nonce); fd.append('code', ci.value.trim());
			fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (!d.ok) { cbtn.disabled = false; cbtn.textContent = 'Създай кода ми'; hint.textContent = d.error; hint.className = 'pp-iban-hint bad'; return; }
					document.getElementById('pp-codestep').style.display = 'none';
					document.getElementById('pp-done-code').textContent = d.code;
					document.getElementById('pp-done-link').textContent = d.vanity;
					document.getElementById('pp-codedone').style.display = '';
					document.querySelectorAll('#pp-codedone .pp-copy').forEach(function (b) {
						b.addEventListener('click', function () {
							var el = document.getElementById(b.dataset.copyEl);
							navigator.clipboard.writeText(el.textContent.trim()).then(function () { b.textContent = 'Копирано ✓'; });
						});
					});
				})
				.catch(function () { cbtn.disabled = false; cbtn.textContent = 'Създай кода ми'; });
		});
	}
})();
</script>
</body>
</html><?php
	}

	/* ------------------------------------------------------------------ */
	/* №14: печатна справка за изплащане                                    */
	/* ------------------------------------------------------------------ */

	/** Всички справки от партида на една страница — един Ctrl+P за целия месец. */
	private function statements_batch( $reference ) {
		global $wpdb;
		nocache_headers();
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT payout_id FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE reference = %s ORDER BY payout_id', $reference
		) );
		if ( empty( $ids ) ) wp_die( 'Партидата липсва.', '', array( 'response' => 404 ) );
		echo '<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Справки — ' . esc_html( $reference ) . '</title>';
		echo '<style>.ap-stmt{page-break-after:always} @media print{.noprint{display:none}} body{margin:0}</style></head><body>';
		echo '<button class="noprint" onclick="window.print()" style="position:fixed;top:14px;right:14px;padding:10px 22px;border:0;border-radius:8px;background:#f47a49;color:#fff;font-weight:700;cursor:pointer;z-index:9">Печат на всички (' . count( $ids ) . ')</button>';
		foreach ( $ids as $pid ) {
			ob_start();
			$this->statement( (int) $pid );
			$html = ob_get_clean();
			if ( preg_match( '/<body>(.*)<\/body>/s', $html, $m ) ) {
				$inner = preg_replace( '/<button class="noprint".*?<\/button>/s', '', $m[1] );
				echo '<div class="ap-stmt" style="font:14px/1.55 \'Segoe UI\',Arial,sans-serif;color:#222;max-width:820px;margin:30px auto;padding:0 24px">' . $inner . '</div>';
			}
		}
		echo '</body></html>';
	}

	private function statement( $payout_id ) {
		global $wpdb;
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );

		$payout = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE payout_id = %d', $payout_id ) );
		if ( ! $payout ) wp_die( 'Справката липсва.', '', array( 'response' => 404 ) );

		$partner = PP_Partners::get( $payout->partner_id );
		if ( ! $partner ) wp_die( 'Партньорът липсва.', '', array( 'response' => 404 ) );

		$is_admin = current_user_can( 'manage_woocommerce' );
		$is_own   = is_user_logged_in() && (int) $partner->user_id === get_current_user_id();
		if ( ! $is_admin && ! $is_own ) wp_die( 'Нямаш достъп до тази справка.', '', array( 'response' => 403 ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE payout_id = %d ORDER BY created_at ASC', $payout_id
		) );
		$gross    = round( (float) $payout->amount, 2 );
		$withhold = round( $gross * 0.10, 2 );
		// Документният № на справката е с офсет (счетоводството води своя
		// номерация на сметките за изплатени суми — това тук е за партньора).
		$doc_no   = (int) $payout->payout_id + (int) ( proof_partner_settings()['statement_offset'] ?? 0 );
		?><!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<title>Справка изплащане №<?php echo (int) $doc_no; ?></title>
<style>
	body{font:14px/1.55 "Segoe UI",Arial,sans-serif;color:#222;max-width:820px;margin:30px auto;padding:0 24px}
	h1{font-size:20px} h2{font-size:15px;margin:22px 0 8px}
	table{width:100%;border-collapse:collapse;margin:10px 0 18px} th,td{border:1px solid #ccc;padding:7px 9px;text-align:left;font-size:13px}
	th{background:#f4f4f2} .num{text-align:right} .tot td{font-weight:700;background:#faf7f5}
	.grid{display:flex;gap:30px} .grid>div{flex:1}
	.sig{display:flex;gap:60px;margin-top:46px} .sig>div{flex:1;border-top:1px solid #555;padding-top:6px;font-size:12.5px}
	.muted{color:#777;font-size:12px}
	@media print{.noprint{display:none}}
</style></head><body>
<button class="noprint" onclick="window.print()" style="float:right;padding:8px 18px;border:0;border-radius:8px;background:#f47a49;color:#fff;font-weight:700;cursor:pointer">Печат / PDF</button>
<h1>Справка за изплатено възнаграждение №<?php echo (int) $doc_no; ?></h1>
<p class="muted">Партида: <?php echo esc_html( $payout->reference ); ?> · Дата: <?php echo esc_html( mysql2date( 'd.m.Y', $payout->created_at ) ); ?><?php if ( $payout->note ) echo ' · ' . esc_html( $payout->note ); ?></p>
<div class="grid">
	<div><h2>Платец</h2><?php
		$bs    = proof_partner_settings();
		$lines = array_filter( array(
			trim( (string) $bs['brand_company'] ),
			proof_partner_brand_name(),
			'' !== trim( (string) $bs['brand_company_id'] ) ? 'ЕИК: ' . trim( (string) $bs['brand_company_id'] ) : '',
			trim( (string) $bs['brand_company_address'] ),
			wp_parse_url( home_url(), PHP_URL_HOST ),
		) );
		echo implode( '<br>', array_map( 'esc_html', array_unique( $lines ) ) );
	?></div>
	<div><h2>Получател</h2><?php echo esc_html( trim( ( $partner->legal_name ?: $partner->display_name ) ) ); ?><br>
	<?php if ( ! empty( $partner->iban ) ) echo 'IBAN: ' . esc_html( $partner->iban ) . '<br>'; ?>
	<?php $u = get_user_by( 'id', $partner->user_id ); if ( $u ) echo esc_html( $u->user_email ); ?></div>
</div>
<h2>Включени комисиони (<?php echo count( $rows ); ?>)</h2>
<table><thead><tr><th>Дата</th><th>Поръчка</th><th>Описание</th><th class="num">Сума</th></tr></thead><tbody>
<?php foreach ( $rows as $r ) : ?>
	<tr>
		<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></td>
		<td><?php echo $r->order_id ? '#' . (int) $r->order_id : '—'; ?></td>
		<td><?php echo esc_html( mb_substr( (string) ( $r->products ?: $r->note ), 0, 90 ) ); ?></td>
		<td class="num"><?php echo esc_html( number_format( (float) $r->amount, 2 ) ); ?></td>
	</tr>
<?php endforeach; ?>
	<tr class="tot"><td colspan="3">Общо изплатено (бруто)</td><td class="num"><?php echo esc_html( number_format( $gross, 2 ) . ' ' . $payout->currency ); ?></td></tr>
</tbody></table>
<h2>Данъчна информация (ориентировъчна)</h2>
<table>
	<tr><td>Брутна сума</td><td class="num"><?php echo esc_html( number_format( $gross, 2 ) ); ?> <?php echo esc_html( $payout->currency ); ?></td></tr>
	<tr><td>Авансов данък 10% (ЗДДФЛ) — ако е приложим</td><td class="num"><?php echo esc_html( number_format( $withhold, 2 ) ); ?> <?php echo esc_html( $payout->currency ); ?></td></tr>
	<tr><td>Нето след удръжка</td><td class="num"><?php echo esc_html( number_format( $gross - $withhold, 2 ) ); ?> <?php echo esc_html( $payout->currency ); ?></td></tr>
</table>
<p class="muted">Редът „Авансов данък" е информативен — приложимостта и признатите разходи се определят от счетоводител според статута на получателя.</p>
<div class="sig"><div>Платец: подпис</div><div>Получател: подпис</div></div>
</body></html><?php
	}
}
