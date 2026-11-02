<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Proof 2.1: Вход за мениджъра — /partner/<slug> (напр. /partner/mgr-proof).
 * Пренесено от ANSA Partner 5.13–5.17 (AP_Manager), без еднократния бонус за нов партньор.
 *
 * МНОГО профили. Достъпът се дава/маха от wp-admin (Proof Partner → Достъп до портала), не от
 * приложението. Всички влизат САМО на един и същ адрес /partner/<slug> със собствено
 * потребителско име и парола (wp-login и wp-admin са затворени за чистите портални профили).
 * Вид достъп: „потребител“ (вижда книгата, без бонус) или „мениджър“ (трупа бонус — PP_Bonus: % от нетния оборот).
 *
 * Плъгинът поддържа WordPress потребители с роля `proof_partner_manager`, която има
 * само способността `proof_partner_manage` (+ read / view_admin_dashboard, за да не ги
 * рита WooCommerce от wp-admin). Същата способност се дава автоматично на всеки с
 * `manage_woocommerce`, така че администраторите работят както досега.
 *
 * Мениджърът влиза на /partner/<slug> с username + парола и вижда приложението
 * (Партньорска книга) на същия адрес — без wp-login и без wp-admin. Сесията е
 * стандартният WP auth cookie, затова REST (nonce) и одитът (кой е платил/писал
 * бележка) работят без промяна.
 */
class PP_Manager {

	const ROLE    = 'proof_partner_manager';
	const CAP     = 'proof_partner_manage';
	const NONCE   = 'proof_partner_portal'; // същият като на портала (PP_Portal::NONCE) — ajax `proof_pp_nonce` го освежава покрай кеша
	const QV      = 'proof_partner_mgr';
	const META_LAST_LOGIN = 'proof_partner_manager_last_login';
	const META_ENABLED    = 'proof_partner_manager_enabled'; // '0' = профилът е спрян (липса = активен)
	const META_TYPE       = 'proof_partner_portal_type';     // 'manager' (трупа бонус) | 'user' (без бонус)
	const META_CREATED    = 'proof_partner_portal_created';  // '1' = WP потребителят е създаден от плъгина (махането го трие)
	const MAX_FAILS = 5;
	const LOCK_SECONDS = 900;

	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'wp', array( $this, 'nocache_early' ), 0 );
		add_action( 'template_redirect', array( $this, 'render' ), 0 );
		add_filter( 'user_has_cap', array( $this, 'grant_cap' ), 10, 4 );
		add_filter( 'authenticate', array( $this, 'block_disabled' ), 30, 1 );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar' ), 20 );
		add_action( 'admin_init', array( $this, 'no_wp_admin' ), 1 ); // чистите портални профили нямат работа в wp-admin
		// Свеж nonce за формата за вход (страницата може да дойде от кеш) — admin-ajax не се кешира.
		add_action( 'wp_ajax_nopriv_proof_pp_nonce', array( $this, 'ajax_nonce' ) );
		add_action( 'wp_ajax_proof_pp_nonce', array( $this, 'ajax_nonce' ) );
	}

	public function ajax_nonce() {
		nocache_headers();
		wp_send_json( array( 'nonce' => wp_create_nonce( self::NONCE ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Роля и способност                                                     */
	/* ------------------------------------------------------------------ */

	/** Правилото е САМО за конфигурирания slug — другите /partner/… адреси (порталът на партньорите) не се пипат. */
	public static function register_rewrite() {
		$slug = self::slug();
		if ( '' === $slug ) return;
		add_rewrite_rule( '^partner/' . preg_quote( $slug, '/' ) . '/?$', 'index.php?' . self::QV . '=' . $slug, 'top' );
	}

	public function init() {
		self::register_rewrite();
		self::ensure_role();
	}

	public function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Ролята съществува и носи точно нужните способности (идемпотентно, без запис при всяко зареждане). */
	public static function ensure_role() {
		$caps = array( 'read' => true, self::CAP => true, 'view_admin_dashboard' => true );
		$role = get_role( self::ROLE );
		if ( ! $role ) { add_role( self::ROLE, 'Афилиейт мениджър', $caps ); return; }
		foreach ( $caps as $c => $_ ) if ( ! $role->has_cap( $c ) ) $role->add_cap( $c );
	}

	/** Всеки с manage_woocommerce има и proof_partner_manage — админите работят както досега. */
	public function grant_cap( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['manage_woocommerce'] ) && empty( $allcaps[ self::CAP ] ) ) $allcaps[ self::CAP ] = true;
		return $allcaps;
	}

	/**
	 * wp-login: спрян профил / спрян вход не влиза; чист портален профил (само нашата роля) не влиза
	 * през wp-login изобщо: достъпът е само през формата на портала (handle_login не минава през този filter).
	 */
	public function block_disabled( $user ) {
		if ( ! $user instanceof WP_User ) return $user;
		if ( ! self::is_manager( $user->ID ) ) return $user;
		if ( ! self::enabled() || ! self::user_enabled( $user->ID ) ) return new WP_Error( 'pp_mgr_disabled', 'Достъпът на този профил е спрян.' );
		if ( self::is_portal_only( $user->ID ) ) {
			$url = self::url();
			return new WP_Error( 'pp_mgr_portal', 'Този профил влиза само през портала' . ( $url ? ': <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' : '.' ) );
		}
		return $user;
	}

	/** Чист портален профил = единствената му роля е нашата (не е редактор/админ, на когото е даден достъп). */
	public static function is_portal_only( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return false;
		$u = get_user_by( 'id', $user_id );
		return $u instanceof WP_User && array( self::ROLE ) === array_values( (array) $u->roles );
	}

	/** На мениджъра не му трябва WP лентата по сайта. */
	public function hide_admin_bar( $show ) {
		return self::is_manager() ? false : $show;
	}

	/** Чист портален профил в wp-admin → към портала (ajax/cron/REST не се пипат). */
	public function no_wp_admin() {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
		if ( ! is_user_logged_in() || ! self::is_portal_only() ) return;
		wp_safe_redirect( self::url() ?: home_url( '/' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Настройки / потребители                                              */
	/* ------------------------------------------------------------------ */

	public static function slug() {
		return self::sanitize_slug( (string) ( proof_partner_settings()['manager_slug'] ?? '' ) );
	}

	public static function sanitize_slug( $slug ) {
		$slug = preg_replace( '/[^A-Za-z0-9\-_]/', '', trim( (string) $slug, "/ \t" ) );
		return strlen( $slug ) >= 3 && strlen( $slug ) <= 40 ? $slug : '';
	}

	/** Глобален ключ: адресът за вход работи ли изобщо. */
	public static function enabled() {
		return ! empty( proof_partner_settings()['manager_enabled'] );
	}

	/** Всички портални профили (WP потребители с ролята), подредени по име. */
	public static function users() {
		$users = get_users( array( 'role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => 200 ) );
		return array_values( array_filter( (array) $users, function ( $u ) { return $u instanceof WP_User; } ) );
	}

	/** Мениджър = потребител с нашата роля (не администратор). */
	public static function is_manager( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return false;
		$u = get_user_by( 'id', $user_id );
		return $u instanceof WP_User && in_array( self::ROLE, (array) $u->roles, true );
	}

	/** Профилът е активен (липсващо мета = активен). */
	public static function user_enabled( $user_id ) {
		return '0' !== (string) get_user_meta( (int) $user_id, self::META_ENABLED, true );
	}

	/** Пълният адрес за вход, '' ако няма slug. */
	public static function url() {
		$slug = self::slug();
		return $slug ? home_url( '/partner/' . $slug ) : '';
	}

	/** Какво вижда приложението в Настройки (REST GET /settings) — само адресът и броят профили; профилите са в wp-admin. */
	public static function status() {
		$users = self::users();
		return array(
			'manager_slug'     => self::slug(),
			'manager_enabled'  => self::enabled(),
			'manager_url'      => self::url(),
			'managers_url'     => admin_url( 'admin.php?page=proof-partner-managers' ),
			'managers_count'   => count( $users ),
			'managers_active'  => count( array_filter( $users, function ( $u ) { return self::user_enabled( $u->ID ); } ) ),
		);
	}

	/** Профил за списъка в wp-admin / REST. */
	public static function profile( WP_User $u ) {
		return array(
			'id'            => (int) $u->ID,
			'login'         => $u->user_login,
			'name'          => $u->display_name ?: $u->user_login,
			'email'         => (string) $u->user_email,
			'enabled'       => self::user_enabled( $u->ID ),
			'type'          => self::type( $u->ID ),
			'created'       => '1' === (string) get_user_meta( $u->ID, self::META_CREATED, true ),
			'portal_only'   => self::is_portal_only( $u->ID ),
			'last_login'    => (string) get_user_meta( $u->ID, self::META_LAST_LOGIN, true ),
			'bonus'         => class_exists( 'PP_Bonus' ) ? PP_Bonus::terms( $u->ID ) : array(),
		);
	}

	/** Вид достъп — 'manager' трупа бонус, 'user' не. Липсващо мета = мениджър. */
	public static function type( $user_id ) {
		return 'user' === (string) get_user_meta( (int) $user_id, self::META_TYPE, true ) ? 'user' : 'manager';
	}

	/**
	 * Адресът за вход (slug + глобален ключ). Връща WP_Error при проблем.
	 */
	public static function save_address( array $b ) {
		$s = get_option( 'proof_partner_settings', array() );
		if ( ! is_array( $s ) ) $s = array();

		if ( array_key_exists( 'manager_slug', $b ) ) {
			$raw  = trim( (string) $b['manager_slug'], "/ \t" );
			$slug = self::sanitize_slug( $raw );
			if ( '' !== $raw && '' === $slug ) return new WP_Error( 'pp_mgr_slug', 'Slug-ът за входа е 3–40 знака: латински букви, цифри, - и _ (напр. mgr-proof).' );
			if ( '' !== $slug && in_array( strtolower( $slug ), array( 'login', 'logout', 'admin', 'register', 'statement', 'statements' ), true ) ) return new WP_Error( 'pp_mgr_slug', 'Този slug е запазен — избери друг.' );
			if ( ( $s['manager_slug'] ?? '' ) !== $slug ) {
				$s['manager_slug'] = $slug;
				// Новото правило влиза веднага: регистрира се с новия slug (init вече мина със стария) и чак тогава се flush-ва.
				add_action( 'shutdown', function () { PP_Manager::register_rewrite(); flush_rewrite_rules(); } );
			}
		}
		if ( array_key_exists( 'manager_enabled', $b ) ) $s['manager_enabled'] = empty( $b['manager_enabled'] ) ? 0 : 1;
		update_option( 'proof_partner_settings', $s );
		return true;
	}

	/**
	 * Създава / обновява един портален профил (wp-admin → Достъп до портала).
	 * $b: id (0 = нов), existing (login/имейл на съществуващ WP потребител, за да му се ДАДЕ достъп),
	 *     login, name, email, pass (празно = без промяна), enabled, type ('manager'|'user'), bonus (условия).
	 * Връща ID-то на потребителя или WP_Error.
	 */
	public static function save_profile( array $b ) {
		$id    = absint( $b['id'] ?? 0 );
		$user  = $id ? get_user_by( 'id', $id ) : null;
		if ( $id && ( ! $user || ! in_array( self::ROLE, (array) $user->roles, true ) ) ) return new WP_Error( 'pp_mgr_404', 'Няма такъв портален профил.' );
		$type  = 'user' === (string) ( $b['type'] ?? 'manager' ) ? 'user' : 'manager';

		/* --- достъп на СЪЩЕСТВУВАЩ WordPress потребител (assign) --- */
		$existing = trim( (string) ( $b['existing'] ?? '' ) );
		if ( ! $user && '' !== $existing ) {
			$eu = is_email( $existing ) ? get_user_by( 'email', $existing ) : get_user_by( 'login', $existing );
			if ( ! $eu ) return new WP_Error( 'pp_mgr_404', 'Няма WordPress потребител „' . $existing . '“ (потребителско име или имейл).' );
			if ( user_can( $eu, 'manage_woocommerce' ) ) return new WP_Error( 'pp_mgr_admin', '„' . $eu->user_login . '“ е администратор — той и без това има достъп до книгата.' );
			if ( in_array( self::ROLE, (array) $eu->roles, true ) ) return new WP_Error( 'pp_mgr_dup', '„' . $eu->user_login . '“ вече има достъп — редактирай го от списъка.' );
			$eu->add_role( self::ROLE ); // другите му роли остават (може да влиза и през wp-login, както досега)
			update_user_meta( $eu->ID, self::META_CREATED, '0' );
			update_user_meta( $eu->ID, self::META_TYPE, $type );
			update_user_meta( $eu->ID, self::META_ENABLED, empty( $b['enabled'] ) && array_key_exists( 'enabled', $b ) ? '0' : '1' );
			if ( class_exists( 'PP_Bonus' ) ) PP_Bonus::save_terms( $eu->ID, (array) ( $b['bonus'] ?? array() ) );
			proof_partner_feed_push( 'diag', '🔑 Даден е достъп до портала на WP потребител ' . $eu->user_login . ' (' . ( 'manager' === $type ? 'мениджър' : 'потребител' ) . ')' );
			return (int) $eu->ID;
		}

		$login = sanitize_user( trim( (string) ( $b['login'] ?? '' ) ), true );
		$name  = sanitize_text_field( (string) ( $b['name'] ?? '' ) );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$pass  = (string) ( $b['pass'] ?? '' );
		if ( '' === $login || ! validate_username( $login ) ) return new WP_Error( 'pp_mgr_user', 'Невалидно потребителско име (латински букви, цифри, . - _ @).' );
		if ( '' !== $email && ! is_email( $email ) ) return new WP_Error( 'pp_mgr_email', 'Невалиден имейл.' );
		if ( '' !== $pass && strlen( $pass ) < 10 ) return new WP_Error( 'pp_mgr_pass', 'Паролата трябва да е поне 10 знака.' );
		if ( ! $user && '' === $pass ) return new WP_Error( 'pp_mgr_pass', 'Задай парола за новия профил (поне 10 знака).' );

		$other = get_user_by( 'login', $login );
		if ( $other && ( ! $user || (int) $other->ID !== (int) $user->ID ) ) return new WP_Error( 'pp_mgr_user', 'Потребител „' . $login . '“ вече съществува в WordPress — избери друго име или му дай достъп като съществуващ потребител.' );
		if ( '' !== $email ) {
			$oe = get_user_by( 'email', $email );
			if ( $oe && ( ! $user || (int) $oe->ID !== (int) $user->ID ) ) return new WP_Error( 'pp_mgr_email', 'Имейлът „' . $email . '“ вече е на друг WordPress потребител.' );
		}
		if ( '' === $name ) $name = $login;

		if ( ! $user ) {
			$uid = wp_insert_user( array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $email,
				'role'         => self::ROLE,
				'display_name' => $name,
				'first_name'   => $name,
				'show_admin_bar_front' => 'false',
			) );
			if ( is_wp_error( $uid ) ) return new WP_Error( 'pp_mgr_user', 'WordPress отказа да създаде потребителя: ' . $uid->get_error_message() );
			$user = get_user_by( 'id', (int) $uid );
			update_user_meta( $user->ID, self::META_CREATED, '1' );
			$pass = ''; // вече е записана
			proof_partner_feed_push( 'diag', '🔑 Създаден е портален профил ' . $login . ' (' . ( 'manager' === $type ? 'мениджър' : 'потребител' ) . ')' );
		} else {
			if ( $user->user_login !== $login ) {
				global $wpdb;
				$wpdb->update( $wpdb->users, array( 'user_login' => $login, 'user_nicename' => sanitize_title( $login ) ), array( 'ID' => (int) $user->ID ) );
				clean_user_cache( $user->ID );
			}
			wp_update_user( array( 'ID' => (int) $user->ID, 'display_name' => $name, 'first_name' => $name, 'user_email' => $email ) );
			$user = get_user_by( 'id', (int) $user->ID );
			if ( '' !== $pass ) {
				wp_set_password( $pass, (int) $user->ID );
				if ( get_current_user_id() !== (int) $user->ID ) {
					// Старите сесии падат — новата парола важи веднага навсякъде.
					$st = WP_Session_Tokens::get_instance( (int) $user->ID );
					if ( $st ) $st->destroy_all();
				}
				proof_partner_feed_push( 'diag', '🔑 Паролата на портален профил ' . $login . ' е сменена' );
			}
		}
		// Създаден от плъгина = само нашата роля; даден на съществуващ WP потребител = нашата роля в добавка.
		if ( '1' === (string) get_user_meta( $user->ID, self::META_CREATED, true ) ) {
			if ( ! in_array( self::ROLE, (array) $user->roles, true ) || count( (array) $user->roles ) > 1 ) $user->set_role( self::ROLE );
		} elseif ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}
		update_user_meta( $user->ID, self::META_TYPE, $type );

		if ( array_key_exists( 'enabled', $b ) ) {
			$on = ! empty( $b['enabled'] );
			update_user_meta( $user->ID, self::META_ENABLED, $on ? '1' : '0' );
			if ( ! $on && get_current_user_id() !== (int) $user->ID ) {
				$st = WP_Session_Tokens::get_instance( (int) $user->ID );
				if ( $st ) $st->destroy_all();
			}
		}
		if ( array_key_exists( 'bonus', $b ) && class_exists( 'PP_Bonus' ) ) PP_Bonus::save_terms( $user->ID, (array) $b['bonus'] );

		return (int) $user->ID;
	}

	/**
	 * Маха достъпа. Профил, създаден от плъгина → WP потребителят се трие;
	 * съществуващ WP потребител → само нашата роля пада, той си остава. Историята на бонус изплащанията остава.
	 */
	public static function delete_profile( $user_id ) {
		$u = get_user_by( 'id', absint( $user_id ) );
		if ( ! $u || ! in_array( self::ROLE, (array) $u->roles, true ) ) return new WP_Error( 'pp_mgr_404', 'Няма такъв портален профил.' );
		if ( (int) $u->ID === get_current_user_id() ) return new WP_Error( 'pp_mgr_self', 'Не можеш да махнеш профила, с който си влязъл.' );
		if ( '1' === (string) get_user_meta( $u->ID, self::META_CREATED, true ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( ! wp_delete_user( (int) $u->ID ) ) return new WP_Error( 'pp_mgr_del', 'WordPress не изтри потребителя.' );
			proof_partner_feed_push( 'diag', '🗑 Изтрит е портален профил (' . $u->user_login . ')' );
		} else {
			$u->remove_role( self::ROLE );
			delete_user_meta( $u->ID, self::META_TYPE );
			delete_user_meta( $u->ID, self::META_ENABLED );
			$st = WP_Session_Tokens::get_instance( (int) $u->ID );
			if ( $st ) $st->destroy_all();
			proof_partner_feed_push( 'diag', '🗑 Махнат е достъпът до портала на WP потребител ' . $u->user_login );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Роут: /partner/<slug>                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Slug-ът от адреса. Първо query var-ът от rewrite правилото; ако него го няма (правилата не са
	 * flush-нати, object cache държи стар `rewrite_rules`, друг плъгин ги е презаписал), се чете самият
	 * път на заявката — /partner/<slug> се разпознава САМО ако е точно конфигурираният slug, така че
	 * другите /partner/… адреси (порталът на партньорите) остават на WordPress.
	 */
	private function requested_slug() {
		$qv = self::sanitize_slug( (string) get_query_var( self::QV, '' ) );
		if ( '' !== $qv ) return $qv;
		$slug = self::slug();
		if ( '' === $slug ) return '';
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) $path = substr( $path, strlen( $home ) + 1 );
		return 0 === strcasecmp( $path, 'partner/' . $slug ) ? $slug : '';
	}

	/** Страницата никога не се кешира — флаговете още на `wp`, преди буфера на кеш плъгините. */
	public function nocache_early() {
		if ( '' === $this->requested_slug() ) return;
		if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
		if ( ! defined( 'DONOTCACHEDB' ) ) define( 'DONOTCACHEDB', true );
		do_action( 'litespeed_control_set_nocache', 'proof partner manager login' );
		nocache_headers();
	}

	public function render() {
		$req = $this->requested_slug();
		if ( '' === $req ) return;

		$slug = self::slug();
		// Грешен slug, непопълнен или спрян вход → обикновено 404, без да издаваме, че адресът съществува.
		if ( '' === $slug || ! hash_equals( $slug, $req ) || ! self::enabled() ) {
			$this->not_found();
		}

		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		nocache_headers();

		if ( isset( $_GET['logout'] ) ) {
			// nonce-ът се приема и под `amp;_wpnonce` (линк с &amp;), за да не остане някой „заключен вътре“.
			$nonce = (string) ( $_GET['_wpnonce'] ?? ( $_GET['amp;_wpnonce'] ?? '' ) );
			if ( is_user_logged_in() && wp_verify_nonce( $nonce, 'proof_partner_mgr_logout' ) ) {
				wp_logout();
				wp_safe_redirect( add_query_arg( 'out', '1', self::url() ) );
				exit;
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		$error = '';
		$notice = isset( $_GET['out'] ) && ! is_user_logged_in() ? 'Излезе успешно.' : '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'login' === ( $_POST['pp_mgr_action'] ?? '' ) ) {
			$r = $this->handle_login();
			if ( is_wp_error( $r ) ) $error = $r->get_error_message();
			else { wp_safe_redirect( self::url() ); exit; }
		}

		// Спрян профил, който още държи cookie → вън.
		if ( is_user_logged_in() && self::is_manager() && ! self::user_enabled( get_current_user_id() ) ) {
			wp_logout();
			$error = 'Достъпът на този профил е спрян.';
		}

		if ( is_user_logged_in() && current_user_can( self::CAP ) ) {
			status_header( 200 );
			// Без wp_nonce_url: той връща &amp; (esc_html), а app.js escape-ва href-а още веднъж → параметърът става `amp;_wpnonce`.
			PP_App::render_front( array( 'logoutUrl' => add_query_arg( array( 'logout' => 1, '_wpnonce' => wp_create_nonce( 'proof_partner_mgr_logout' ) ), self::url() ) ) );
			exit;
		}

		status_header( 200 );
		$this->login_page( $error, $notice );
		exit;
	}

	private function not_found() {
		global $wp_query;
		if ( $wp_query ) $wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		$tpl = get_404_template();
		if ( $tpl ) include $tpl;
		exit;
	}

	private function ip_key() {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return 'proof_mgr_fail_' . md5( $ip . '|' . wp_salt( 'auth' ) );
	}

	private function handle_login() {
		if ( ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), self::NONCE ) ) {
			return new WP_Error( 'pp_nonce', 'Сесията изтече — опитай отново.' );
		}
		$fails = (int) get_transient( $this->ip_key() );
		if ( $fails >= self::MAX_FAILS ) {
			return new WP_Error( 'pp_locked', 'Твърде много грешни опити. Опитай пак след 15 минути.' );
		}

		$login = sanitize_user( trim( (string) ( $_POST['username'] ?? '' ) ), true );
		$pass  = (string) ( $_POST['password'] ?? '' );
		// Приема се САМО потребител с мениджърската роля (не е втори вход за админите).
		$user  = '' !== $login ? get_user_by( 'login', $login ) : null;
		if ( $user instanceof WP_User && ! in_array( self::ROLE, (array) $user->roles, true ) ) $user = null;
		$ok    = $user && '' !== $pass && wp_check_password( $pass, $user->user_pass, $user->ID );

		if ( ! $ok ) {
			set_transient( $this->ip_key(), $fails + 1, self::LOCK_SECONDS );
			if ( $fails + 1 >= self::MAX_FAILS ) {
				proof_partner_feed_push( 'diag', '🔒 Входът за мениджъра е заключен за 15 мин след ' . self::MAX_FAILS . ' грешни опита (IP ' . sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) . ')' );
			}
			return new WP_Error( 'pp_login', 'Грешно потребителско име или парола.' );
		}
		if ( ! self::user_enabled( $user->ID ) ) {
			return new WP_Error( 'pp_disabled', 'Достъпът на този профил е спрян.' );
		}
		if ( ! user_can( $user, self::CAP ) ) {
			return new WP_Error( 'pp_cap', 'Профилът няма достъп до Партньорската книга — провери ролята му.' );
		}

		delete_transient( $this->ip_key() );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		update_user_meta( $user->ID, self::META_LAST_LOGIN, current_time( 'mysql' ) );
		proof_partner_feed_push( 'diag', '🔑 Мениджърът влезе в Партньорската книга (' . $user->user_login . ')' );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Форма за вход — същият стил като портала (portal.css)               */
	/* ------------------------------------------------------------------ */

	private function login_page( $error, $notice = '' ) {
		$assets = plugin_dir_url( PROOF_PARTNER_FILE ) . 'assets/';
		$BS     = proof_partner_settings();
		$brand  = proof_partner_brand_name();
		$logo   = (string) ( $BS['brand_logo_url'] ?? '' );
		$other  = is_user_logged_in() ? wp_get_current_user() : null; // влязъл като партньор/друг без достъп
		?><!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="generator" content="proof-partner <?php echo esc_attr( PROOF_PARTNER_VERSION ); ?>">
<title><?php echo esc_html( $brand ); ?> — Вход за мениджъра</title>
<link rel="stylesheet" href="<?php echo esc_url( $assets . 'portal.css?v=' . PROOF_PARTNER_VERSION ); ?>">
<style>:root, .pp-wrap { <?php echo esc_html( proof_partner_palette_css() ); ?> }</style>
</head>
<body class="app-portal">
<div class="pp-wrap">
	<header class="pp-head">
		<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" width="94" height="40" style="object-fit:contain"><?php else : ?><strong style="font-size:20px"><?php echo esc_html( $brand ); ?></strong><?php endif; ?>
		<span class="pp-sub">Партньорска книга · вход за мениджъра</span>
	</header>
	<?php if ( $error ) : ?><div class="pp-msg err"><?php echo esc_html( $error ); ?></div><?php endif; ?>
	<?php if ( $notice ) : ?><div class="pp-msg ok"><?php echo esc_html( $notice ); ?></div><?php endif; ?>
	<?php if ( $other ) : ?><div class="pp-msg wait">В момента си влязъл/а като <strong><?php echo esc_html( $other->display_name ?: $other->user_login ); ?></strong>, а този профил няма достъп до Партньорската книга. Влез с данните на мениджъра — те ще заменят текущата сесия.</div><?php endif; ?>
	<section class="pp-card pp-auth">
		<form method="post"><?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pp_mgr_action" value="login">
			<label>Потребителско име<input type="text" name="username" required autocomplete="username" autocapitalize="off" spellcheck="false" autofocus></label>
			<label>Парола<input type="password" name="password" required autocomplete="current-password"></label>
			<button class="pp-btn">Влез</button>
			<p class="pp-hint">Данните за вход ги дава администраторът.</p>
		</form>
	</section>
	<footer class="pp-foot"><?php echo esc_html( $brand ); ?> · <a href="<?php echo esc_url( home_url( '/' ) ); ?>">към сайта</a></footer>
</div>
<script>
(function () {
	// Свеж nonce през admin-ajax (не се кешира) — формата работи и от кеширано копие на страницата.
	var AJAX = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
	var fd = new FormData(); fd.append('action', 'proof_pp_nonce');
	fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
		if (!d || !d.nonce) return;
		document.querySelectorAll('input[name="_wpnonce"]').forEach(function (i) { i.value = d.nonce; });
	}).catch(function () {});
})();
</script>
</body>
</html><?php
	}
}
