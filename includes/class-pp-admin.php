<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Admin {

	const CAP   = PP_Manager::CAP;
	const NONCE = 'proof_partner_admin';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
		add_action( 'admin_notices', array( $this, 'velocity_notices' ) );
	}

	public function velocity_notices() {
		if ( ! current_user_can( self::CAP ) ) return;
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'proof-partner' ) ) return;

		$alerts = get_option( 'proof_partner_alerts', array() );
		if ( empty( $alerts ) || ! is_array( $alerts ) ) return;

		$clear = $this->action_url( array( 'page' => 'proof-partner-classic', 'ap_action' => 'clear_alerts' ) );
		echo '<div class="notice notice-warning"><p><strong>Partner — velocity alerts:</strong></p><ul style="margin-left:1.4em;list-style:disc">';
		foreach ( array_reverse( $alerts ) as $a ) {
			echo '<li>[' . esc_html( $a['time'] ) . '] ' . esc_html( $a['message'] ) . '</li>';
		}
		echo '</ul><p><a class="button" href="' . esc_url( $clear ) . '">Clear alerts</a></p></div>';
	}

	public function menu() {
		$menu_label = proof_partner_brand_name() . ' Partner';
		add_menu_page( $menu_label, $menu_label, self::CAP, 'proof-partner', array( 'PP_App', 'render' ), 'dashicons-groups', 56 );
		add_submenu_page( 'proof-partner', 'Partners App', 'App', self::CAP, 'proof-partner', array( 'PP_App', 'render' ) );
		add_submenu_page( 'proof-partner', 'Partners (classic)', 'Partners (classic)', self::CAP, 'proof-partner-classic', array( $this, 'page_partners' ) );
		add_submenu_page( 'proof-partner', 'Add Partner', 'Add Partner', self::CAP, 'proof-partner-add', array( $this, 'page_add' ) );
		add_submenu_page( 'proof-partner', 'Commissions', 'Commissions', self::CAP, 'proof-partner-commissions', array( $this, 'page_commissions' ) );
		add_submenu_page( 'proof-partner', 'Settings', 'Settings', self::CAP, 'proof-partner-settings', array( $this, 'page_settings' ) );
		// Proof 2.1: достъпът до портала (мениджъри и потребители) се дава/маха ТУК (wp-admin), не от приложението — само администратор (manage_woocommerce).
		add_submenu_page( 'proof-partner', 'Достъп до портала', '🔑 Достъп до портала', 'manage_woocommerce', 'proof-partner-managers', array( $this, 'page_managers' ) );
	}

	private function redirect( $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* POST / actions                                                      */
	/* ------------------------------------------------------------------ */

	public function handle_post() {
		if ( empty( $_REQUEST['ap_action'] ) ) return;
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Insufficient permissions.' );
		check_admin_referer( self::NONCE );

		$action = sanitize_key( $_REQUEST['ap_action'] );

		// Proof 2.1: порталните профили — само администратор.
		if ( 0 === strpos( $action, 'mgr_' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
			$this->handle_managers( $action );
			return;
		}

		switch ( $action ) {

			case 'clear_alerts':
				delete_option( 'proof_partner_alerts' );
				$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_msg' => 'alerts_cleared' ) );
				break;

			case 'add_partner':
				$result = PP_Partners::create( array(
					'display_name'    => $_POST['display_name'] ?? '',
					'email'           => $_POST['email'] ?? '',
					'rate'            => $_POST['rate'] ?? 15,
					'payment_method'  => $_POST['payment_method'] ?? 'bank',
					'payment_details' => $_POST['payment_details'] ?? '',
					'notes'           => $_POST['notes'] ?? '',
				) );
				if ( is_wp_error( $result ) ) {
					$this->redirect( array( 'page' => 'proof-partner-add', 'ap_error' => rawurlencode( $result->get_error_message() ) ) );
				}
				$partner_id = $result;

				// Optional coupon in the same submit.
				$code = trim( (string) ( $_POST['coupon_code'] ?? '' ) );
				if ( '' !== $code ) {
					$c = PP_Coupons::create_for_partner(
						$partner_id,
						$code,
						sanitize_key( $_POST['coupon_type'] ?? 'percent' ),
						$_POST['coupon_amount'] ?? 5
					);
					if ( is_wp_error( $c ) ) {
						$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $partner_id, 'ap_error' => rawurlencode( 'Partner created, coupon failed: ' . $c->get_error_message() ) ) );
					}
				}
				$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $partner_id, 'ap_msg' => 'created' ) );
				break;

			case 'update_partner':
				$pid = absint( $_POST['partner_id'] ?? 0 );
				$res = PP_Partners::update( $pid, array(
					'display_name'    => $_POST['display_name'] ?? '',
					'status'          => $_POST['status'] ?? 'active',
					'rate'            => $_POST['rate'] ?? 15,
					'payment_method'  => $_POST['payment_method'] ?? 'bank',
					'payment_details' => $_POST['payment_details'] ?? '',
					'notes'           => $_POST['notes'] ?? '',
				) );
				if ( is_wp_error( $res ) ) {
					$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $pid, 'ap_error' => rawurlencode( $res->get_error_message() ) ) );
				}
				$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $pid, 'ap_msg' => 'updated' ) );
				break;

			case 'add_coupon':
				$pid = absint( $_POST['partner_id'] ?? 0 );
				$c   = PP_Coupons::create_for_partner( $pid, $_POST['coupon_code'] ?? '', sanitize_key( $_POST['coupon_type'] ?? 'percent' ), $_POST['coupon_amount'] ?? 5 );
				if ( is_wp_error( $c ) ) {
					$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $pid, 'ap_error' => rawurlencode( $c->get_error_message() ) ) );
				}
				$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $pid, 'ap_msg' => 'coupon_added' ) );
				break;

			case 'toggle_coupon':
				$pid = absint( $_GET['partner_id'] ?? 0 );
				PP_Coupons::set_active( absint( $_GET['mapping_id'] ?? 0 ), absint( $_GET['to'] ?? 0 ) );
				$this->redirect( array( 'page' => 'proof-partner-classic', 'ap_view' => $pid, 'ap_msg' => 'coupon_toggled' ) );
				break;

			case 'commission_status':
				global $wpdb;
				$cid = absint( $_GET['commission_id'] ?? 0 );
				$to  = sanitize_key( $_GET['to'] ?? '' );
				if ( in_array( $to, array( 'approved', 'reversed', 'pending' ), true ) && $cid ) {
					$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE commission_id = %d', $cid ) );
					if ( $row && 'paid' !== $row->status ) { // paid rows are immutable; use reversal rows
						$wpdb->update( PP_Schema::table( 'commissions' ),
							array( 'status' => $to, 'note' => trim( $row->note . ' | manual: ' . $to ), 'updated_at' => current_time( 'mysql' ) ),
							array( 'commission_id' => $cid )
						);
					}
				}
				$this->redirect( array( 'page' => 'proof-partner-commissions', 'ap_msg' => 'status_changed' ) );
				break;

			case 'save_settings':
				$s = array(
					'trigger_status'        => preg_replace( '/^wc-/', '', sanitize_key( $_POST['trigger_status'] ?? 'completed' ) ),
					'holding_days'          => absint( $_POST['holding_days'] ?? 7 ),
					'default_rate'          => (float) str_replace( ',', '.', (string) ( $_POST['default_rate'] ?? 15 ) ),
					'excluded_products'     => sanitize_text_field( $_POST['excluded_products'] ?? '' ),
					'default_coupon_type'   => sanitize_key( $_POST['default_coupon_type'] ?? 'percent' ),
					'default_coupon_amount' => (float) str_replace( ',', '.', (string) ( $_POST['default_coupon_amount'] ?? 5 ) ),
					'block_self_referrals'  => empty( $_POST['block_self_referrals'] ) ? 0 : 1,
					'velocity_min_weekly'   => absint( $_POST['velocity_min_weekly'] ?? 10 ),
					'velocity_multiplier'   => max( 2, (float) str_replace( ',', '.', (string) ( $_POST['velocity_multiplier'] ?? 5 ) ) ),
					'include_vat'           => empty( $_POST['include_vat'] ) ? 0 : 1,
					'allowed_with_partner'  => sanitize_textarea_field( $_POST['allowed_with_partner'] ?? '' ),
					'safe_mode'             => empty( $_POST['safe_mode'] ) ? 0 : 1,
					'refund_alert_pct'      => min( 90, max( 5, (int) ( $_POST['refund_alert_pct'] ?? 25 ) ) ),
					'portal_auto_approve'   => empty( $_POST['portal_auto_approve'] ) ? 0 : 1,
					'kit_story'             => sanitize_textarea_field( $_POST['kit_story'] ?? '' ),
					'kit_post'              => sanitize_textarea_field( $_POST['kit_post'] ?? '' ),
					'kit_campaign'          => sanitize_textarea_field( $_POST['kit_campaign'] ?? '' ),
				);
				// Одит F12: формата носи САМО своите ключове — merge, не замяна,
				// иначе изтриваме brand/Telegram/tiers/email и всичко от SPA настройките.
				$s = array_merge( (array) get_option( 'proof_partner_settings', array() ), $s );
				update_option( 'proof_partner_settings', $s );
				$this->redirect( array( 'page' => 'proof-partner-settings', 'ap_msg' => 'settings_saved' ) );
				break;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Proof 2.1: Достъп до портала (мениджъри / потребители) — wp-admin    */
	/* ------------------------------------------------------------------ */

	private function handle_managers( $action ) {
		$back = array( 'page' => 'proof-partner-managers' );
		$fail = function ( $e ) use ( $back ) { $this->redirect( $back + array( 'ap_error' => rawurlencode( is_wp_error( $e ) ? $e->get_error_message() : (string) $e ) ) ); };

		if ( 'mgr_address' === $action ) {
			$r = PP_Manager::save_address( array( 'manager_slug' => wp_unslash( $_POST['manager_slug'] ?? '' ), 'manager_enabled' => ! empty( $_POST['manager_enabled'] ) ) );
			if ( is_wp_error( $r ) ) $fail( $r );
			$this->redirect( $back + array( 'ap_msg' => 'mgr_address' ) );
		}
		if ( 'mgr_save' === $action ) {
			$bonus = (array) ( $_POST['bonus'] ?? array() );
			$existing = 'existing' === ( $_POST['mgr_mode'] ?? '' ) ? sanitize_text_field( wp_unslash( $_POST['mgr_existing'] ?? '' ) ) : '';
			if ( 'existing' === ( $_POST['mgr_mode'] ?? '' ) && '' === $existing ) $fail( 'Напиши потребителското име или имейла на WordPress потребителя.' );
			$r = PP_Manager::save_profile( array(
				'id'       => absint( $_POST['mgr_id'] ?? 0 ),
				'existing' => $existing,
				'login'    => wp_unslash( $_POST['mgr_login'] ?? '' ),
				'name'     => wp_unslash( $_POST['mgr_name'] ?? '' ),
				'email'    => wp_unslash( $_POST['mgr_email'] ?? '' ),
				'pass'     => (string) wp_unslash( $_POST['mgr_pass'] ?? '' ),
				'enabled'  => ! empty( $_POST['mgr_enabled'] ),
				'type'     => 'user' === ( $_POST['mgr_type'] ?? '' ) ? 'user' : 'manager',
				'bonus'    => array(
					'from' => sanitize_text_field( wp_unslash( $bonus['from'] ?? '' ) ),
					'pct'  => sanitize_text_field( wp_unslash( $bonus['pct'] ?? '' ) ),
				),
			) );
			if ( is_wp_error( $r ) ) $fail( $r );
			$this->redirect( $back + array( 'ap_msg' => absint( $_POST['mgr_id'] ?? 0 ) ? 'mgr_saved' : 'mgr_created' ) );
		}
		if ( 'mgr_toggle' === $action ) {
			$uid = absint( $_GET['mgr'] ?? 0 );
			if ( ! PP_Manager::is_manager( $uid ) ) $fail( 'Няма такъв портален профил.' );
			$on = ! PP_Manager::user_enabled( $uid );
			update_user_meta( $uid, PP_Manager::META_ENABLED, $on ? '1' : '0' );
			if ( ! $on ) { $st = WP_Session_Tokens::get_instance( $uid ); if ( $st ) $st->destroy_all(); }
			$this->redirect( $back + array( 'ap_msg' => $on ? 'mgr_on' : 'mgr_off' ) );
		}
		if ( 'mgr_delete' === $action ) {
			$r = PP_Manager::delete_profile( absint( $_GET['mgr'] ?? 0 ) );
			if ( is_wp_error( $r ) ) $fail( $r );
			$this->redirect( $back + array( 'ap_msg' => 'mgr_deleted' ) );
		}
		if ( 'mgr_seed_hide' === $action ) {
			delete_option( PP_Manager::OPT_SEED );
			$this->redirect( $back + array( 'ap_msg' => 'mgr_seed_hidden' ) );
		}
		$fail( 'Непознато действие.' );
	}

	public function page_managers() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
		$st     = PP_Manager::status();
		$users  = PP_Manager::users();
		$edit   = absint( $_GET['mgr_edit'] ?? 0 );
		$editU  = $edit ? get_user_by( 'id', $edit ) : null;
		if ( $editU && ! in_array( PP_Manager::ROLE, (array) $editU->roles, true ) ) $editU = null;
		$ep     = $editU ? PP_Manager::profile( $editU ) : null;
		$bonus  = $ep ? $ep['bonus'] : PP_Bonus::defaults();
		$type   = $ep ? $ep['type'] : 'manager';
		$appUrl = admin_url( 'admin.php?page=proof-partner#/bonus' );
		$home   = home_url( '/partner/' );
		$fmt    = function ( $v, $dec = 2 ) { return rtrim( rtrim( number_format( (float) $v, $dec, '.', '' ), '0' ), '.' ); };

		echo '<div class="wrap"><h1>🔑 Достъп до портала</h1>';
		$this->notices();
		/* 2.1.1: началната парола на готовия профил — генерирана на сайта при ъпдейта, показва се докато не бъде сменена или скрита */
		$seed = get_option( PP_Manager::OPT_SEED );
		if ( is_array( $seed ) && ! empty( $seed['pass'] ) && get_user_by( 'id', (int) ( $seed['uid'] ?? 0 ) ) ) {
			echo '<div class="notice notice-info" style="padding:12px 16px"><p style="margin:0 0 6px"><strong>Готов профил за вход на мениджъра</strong> (създаден при ъпдейта на ' . esc_html( (string) ( $seed['t'] ?? '' ) ) . '). Прати му данните и го накарай да си смени паролата — или я смени ти от „Редакция“.</p>'
				. '<p style="margin:0 0 6px">Адрес: ' . ( $st['manager_url'] ? '<a href="' . esc_url( $st['manager_url'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $st['manager_url'] ) . '</strong></a>' : '<em>(попълни адреса по-долу)</em>' ) . '<br>Потребителско име: <code>' . esc_html( (string) $seed['login'] ) . '</code><br>Начална парола: <code>' . esc_html( (string) $seed['pass'] ) . '</code></p>'
				. '<p style="margin:0"><a class="button button-small" href="' . esc_url( $this->action_url( array( 'page' => 'proof-partner-managers', 'ap_action' => 'mgr_seed_hide' ) ) ) . '">Записах си я — скрий</a> <span class="description">При смяна на паролата се скрива сама.</span></p></div>';
		}
		echo '<p class="description" style="max-width:860px">Тук даваш и махаш достъп до Партньорската книга. Профилите влизат <strong>само</strong> през формата на портала (адресът по-долу) — не през wp-login и не в wp-admin. Вид достъп: <strong>Мениджър</strong> трупа бонус (процент от нетния оборот — виж условията), <strong>Потребител</strong> вижда книгата без бонус. Администраторите на WordPress имат достъп и без това.</p>';

		/* --- Адрес за вход --- */
		echo '<h2 style="margin-top:1.4em">Адрес за вход</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="mgr_address">';
		echo '<table class="form-table" style="max-width:860px">';
		echo '<tr><th>Адрес</th><td><code>' . esc_html( $home ) . '</code><input type="text" name="manager_slug" value="' . esc_attr( $st['manager_slug'] ) . '" placeholder="mgr-proof" class="regular-text" style="width:200px" autocomplete="off"> <span class="description">3–40 знака: латински букви, цифри, - и _. Един адрес за всички профили (порталът на партньорите на /partner не се пипа).</span>'
			. ( $st['manager_url'] ? '<br><a href="' . esc_url( $st['manager_url'] ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $st['manager_url'] ) . '</strong></a>' : '<br><span class="description">Попълни адрес — линкът ще се появи тук.</span>' ) . '</td></tr>';
		echo '<tr><th>Входът е активен</th><td><label><input type="checkbox" name="manager_enabled" value="1"' . checked( $st['manager_enabled'], true, false ) . '> Изключен = адресът дава 404 и никой портален профил не влиза.</label></td></tr>';
		echo '</table><p><button class="button button-primary">Запази адреса</button></p></form>';

		/* --- Списък --- */
		echo '<h2 style="margin-top:1.4em">Профили с достъп (' . count( $users ) . ')</h2>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Име</th><th>Потребител</th><th>Имейл</th><th>Вид</th><th>Статус</th><th>Последен вход</th><th>Бонус</th><th></th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$p   = PP_Manager::profile( $u );
			$b   = $p['bonus'];
			$bt  = 'manager' === $p['type']
				? sprintf( '%s%% от нетния оборот от %s', $fmt( $b['pct'], 3 ), esc_html( date_i18n( 'd.m.Y', strtotime( $b['from'] ) ) ) )
				: '<span class="description">—</span>';
			$toggle = $this->action_url( array( 'page' => 'proof-partner-managers', 'ap_action' => 'mgr_toggle', 'mgr' => (int) $u->ID ) );
			$del    = $this->action_url( array( 'page' => 'proof-partner-managers', 'ap_action' => 'mgr_delete', 'mgr' => (int) $u->ID ) );
			$editL  = admin_url( 'admin.php?page=proof-partner-managers&mgr_edit=' . (int) $u->ID . '#mgr-form' );
			printf(
				'<tr><td><strong>%s</strong>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="white-space:nowrap"><a class="button button-small" href="%s">Редакция</a> <a class="button button-small" href="%s">%s</a> %s<a class="button button-small button-link-delete" href="%s" onclick="return confirm(\'%s\')">Махни достъпа</a></td></tr>',
				esc_html( $p['name'] ), $p['created'] ? '' : ' <span class="description">(WP потребител)</span>', esc_html( $p['login'] ), esc_html( $p['email'] ?: '—' ),
				'manager' === $p['type'] ? '<strong>Мениджър</strong>' : 'Потребител',
				$p['enabled'] ? '<span style="color:#1f7a4d;font-weight:700">активен</span>' : '<span style="color:#b32d2e;font-weight:700">спрян</span>',
				esc_html( $p['last_login'] ?: 'още не е влизал' ),
				$bt,
				esc_url( $editL ), esc_url( $toggle ), $p['enabled'] ? 'Спри' : 'Пусни',
				'manager' === $p['type'] ? '<a class="button button-small" href="' . esc_url( $appUrl . '?manager=' . (int) $u->ID ) . '" target="_blank" rel="noopener">💶 Табло бонус</a> ' : '',
				esc_url( $del ), esc_js( $p['created'] ? 'Махаш достъпа на ' . $p['login'] . '? WordPress потребителят ще бъде изтрит (историята на бонус изплащанията остава).' : 'Махаш достъпа до портала на ' . $p['login'] . '? WordPress потребителят остава, пада само ролята.' )
			);
		}
		if ( empty( $users ) ) echo '<tr><td colspan="8">Още никой няма достъп — дай първия от формата по-долу.</td></tr>';
		echo '</tbody></table>';

		/* --- Нов / редакция --- */
		echo '<h2 id="mgr-form" style="margin-top:1.6em">' . ( $ep ? 'Редакция: ' . esc_html( $ep['name'] ) . ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=proof-partner-managers' ) ) . '">+ нов достъп</a>' : 'Дай достъп' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" autocomplete="off" id="mgr-form-el">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="mgr_save"><input type="hidden" name="mgr_id" value="' . ( $ep ? (int) $ep['id'] : 0 ) . '">';
		echo '<table class="form-table" style="max-width:860px">';
		if ( ! $ep ) {
			echo '<tr><th>Кой</th><td><label style="margin-right:18px"><input type="radio" name="mgr_mode" value="new" checked onchange="document.querySelectorAll(\'.mgr-new\').forEach(function(r){r.style.display=\'\'});document.getElementById(\'mgr-existing-row\').style.display=\'none\'"> Нов профил (създава се WordPress потребител само за портала)</label><label><input type="radio" name="mgr_mode" value="existing" onchange="document.querySelectorAll(\'.mgr-new\').forEach(function(r){r.style.display=\'none\'});document.getElementById(\'mgr-existing-row\').style.display=\'\'"> Съществуващ WordPress потребител</label></td></tr>';
			echo '<tr id="mgr-existing-row" style="display:none"><th>WordPress потребител</th><td><input type="text" name="mgr_existing" class="regular-text" placeholder="потребителско име или имейл" autocomplete="off"> <span class="description">получава достъп до портала в добавка към ролята си; махането му връща предишното състояние</span></td></tr>';
		}
		echo '<tr class="mgr-new"><th>Потребителско име</th><td><input type="text" name="mgr_login" value="' . esc_attr( $ep ? $ep['login'] : '' ) . '" class="regular-text" autocomplete="off" placeholder="manager"> <span class="description">латински букви, цифри, . - _ @</span></td></tr>';
		echo '<tr class="mgr-new"><th>Име (как се показва)</th><td><input type="text" name="mgr_name" value="' . esc_attr( $ep ? $ep['name'] : '' ) . '" class="regular-text" placeholder="Име"></td></tr>';
		echo '<tr class="mgr-new"><th>Имейл (по избор)</th><td><input type="email" name="mgr_email" value="' . esc_attr( $ep ? $ep['email'] : '' ) . '" class="regular-text"></td></tr>';
		echo '<tr class="mgr-new"><th>' . ( $ep ? 'Нова парола' : 'Парола' ) . '</th><td><input type="password" name="mgr_pass" value="" class="regular-text" autocomplete="new-password"' . ( $ep ? ' placeholder="празно = без промяна"' : ' placeholder="поне 10 знака"' ) . '> <span class="description">поне 10 знака' . ( $ep ? '; смяната затваря отворените му сесии' : '' ) . '</span></td></tr>';
		echo '<tr><th>Вид достъп</th><td><label style="margin-right:18px"><input type="radio" name="mgr_type" value="manager"' . checked( 'manager', $type, false ) . ' onchange="document.getElementById(\'mgr-bonus\').style.display=\'\'"> <strong>Мениджър</strong> — трупа бонус</label><label><input type="radio" name="mgr_type" value="user"' . checked( 'user', $type, false ) . ' onchange="document.getElementById(\'mgr-bonus\').style.display=\'none\'"> <strong>Потребител</strong> — вижда книгата, без бонус</label></td></tr>';
		echo '<tr><th>Профилът е активен</th><td><label><input type="checkbox" name="mgr_enabled" value="1"' . checked( $ep ? $ep['enabled'] : true, true, false ) . '> спрян профил не влиза никъде</label></td></tr>';
		echo '</table>';

		echo '<div id="mgr-bonus"' . ( 'manager' === $type ? '' : ' style="display:none"' ) . '><h3 style="margin:1em 0 0">💶 Бонус за мениджъра</h3><p class="description">Процент от нетния оборот на цялата програма от началната дата нататък, без край. Мениджърът вижда таблото си в приложението (💶 Моят бонус); изплащането „до дата X по банка“ го отбелязва администратор пак там (💶 Бонус мениджър).</p>';
		echo '<table class="form-table" style="max-width:860px">';
		echo '<tr><th>Процентът — от дата</th><td><input type="date" name="bonus[from]" value="' . esc_attr( $bonus['from'] ) . '"> <span class="description">процентът е за всички партньорски поръчки от тази дата (по датата на поръчката)</span></td></tr>';
		echo '<tr><th>% от нетния оборот</th><td><input type="number" step="0.001" min="0" max="100" name="bonus[pct]" value="' . esc_attr( $bonus['pct'] ) . '" style="width:90px"> % <span class="description">от <strong>всички</strong> реализирани (доставени) партньорски поръчки; оборот = стойността на продуктите след отстъпката на клиента (без доставка, нето от връщания) <strong>минус комисионата на партньора</strong>. Фиксираните договори по фактура не влизат.</span></td></tr>';
		echo '</table></div>';
		echo '<p><button class="button button-primary">' . ( $ep ? 'Запази' : 'Дай достъп' ) . '</button></p></form>';

		echo '<hr><p class="description">След 5 грешни опита входът се заключва за 15 минути (известие във фийда/Telegram). Таблото на бонуса и отбелязването на изплащанията: <a href="' . esc_url( $appUrl ) . '">Партньорска книга → 💶 Бонус мениджър</a>.</p>';
		echo '</div>';
	}

	private function notices() {
		if ( ! empty( $_GET['ap_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $_GET['ap_error'] ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['ap_msg'] ) ) {
			$k = sanitize_key( $_GET['ap_msg'] );
			$nice = array( 'mgr_address' => '✅ Адресът за вход е запазен.', 'mgr_created' => '✅ Достъпът е даден — прати адреса на портала, потребителското име и паролата.', 'mgr_saved' => '✅ Профилът е запазен.', 'mgr_on' => '✅ Профилът е пуснат.', 'mgr_off' => '⏸ Профилът е спрян (сесиите му са затворени).', 'mgr_deleted' => '🗑 Достъпът е махнат.', 'mgr_seed_hidden' => '✅ Началната парола е скрита.' );
			echo '<div class="notice notice-success is-dismissible"><p>' . ( isset( $nice[ $k ] ) ? esc_html( $nice[ $k ] ) : esc_html( ucwords( str_replace( '_', ' ', $k ) ) ) . '.' ) . '</p></div>';
		}
	}

	private function action_url( $args ) {
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), self::NONCE );
	}

	/* ------------------------------------------------------------------ */
	/* Partners list + detail                                              */
	/* ------------------------------------------------------------------ */

	public function page_partners() {
		if ( ! empty( $_GET['ap_view'] ) ) {
			$this->partner_detail( absint( $_GET['ap_view'] ) );
			return;
		}

		$partners = PP_Partners::all();
		echo '<div class="wrap"><h1>Partners <a href="' . esc_url( admin_url( 'admin.php?page=proof-partner-add' ) ) . '" class="page-title-action">Add Partner</a></h1>';
		$this->notices();

		echo '<table class="widefat striped"><thead><tr>
			<th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Rate</th>
			<th>Coupons</th><th>Pending</th><th>Payable</th><th>Paid</th><th></th>
		</tr></thead><tbody>';

		foreach ( $partners as $p ) {
			$user    = get_user_by( 'id', $p->user_id );
			$totals  = PP_Partners::totals( $p->partner_id );
			$coupons = PP_Coupons::for_partner( $p->partner_id );
			$codes   = implode( ', ', array_map( function ( $c ) {
				return $c->active ? esc_html( $c->coupon_code ) : '<s>' . esc_html( $c->coupon_code ) . '</s>';
			}, $coupons ) );

			printf(
				'<tr><td>%d</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s%%</td><td>%s</td><td>%s</td><td><strong>%s</strong></td><td>%s</td><td><a class="button button-small" href="%s">View</a></td></tr>',
				$p->partner_id,
				esc_html( $p->display_name ),
				esc_html( $user ? $user->user_email : '—' ),
				esc_html( $p->status ),
				esc_html( rtrim( rtrim( number_format( (float) $p->rate, 3 ), '0' ), '.' ) ),
				$codes ?: '—',
				esc_html( number_format( $totals['pending'], 2 ) ),
				esc_html( number_format( $totals['approved'], 2 ) ),
				esc_html( number_format( $totals['paid'], 2 ) ),
				esc_url( admin_url( 'admin.php?page=proof-partner-classic&ap_view=' . $p->partner_id ) )
			);
		}
		if ( empty( $partners ) ) echo '<tr><td colspan="10">No partners yet.</td></tr>';
		echo '</tbody></table></div>';
	}

	private function partner_detail( $partner_id ) {
		global $wpdb;
		$p = PP_Partners::get( $partner_id );
		if ( ! $p ) { echo '<div class="wrap"><h1>Partner not found</h1></div>'; return; }

		$user    = get_user_by( 'id', $p->user_id );
		$totals  = PP_Partners::totals( $p->partner_id );
		$coupons = PP_Coupons::for_partner( $p->partner_id );
		$s       = proof_partner_settings();
		$ledger  = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE partner_id = %d ORDER BY created_at DESC LIMIT 100',
			$partner_id
		) );

		echo '<div class="wrap"><h1>' . esc_html( $p->display_name ) . ' <small>(#' . (int) $p->partner_id . ')</small></h1>';
		$this->notices();

		echo '<p><strong>Pending:</strong> ' . esc_html( number_format( $totals['pending'], 2 ) )
			. ' &nbsp; <strong>Payable:</strong> ' . esc_html( number_format( $totals['approved'], 2 ) )
			. ' &nbsp; <strong>Paid:</strong> ' . esc_html( number_format( $totals['paid'], 2 ) )
			. ' &nbsp; <strong>Reversed:</strong> ' . esc_html( number_format( $totals['reversed'], 2 ) ) . '</p>';

		/* Edit form */
		echo '<h2>Details</h2><form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="update_partner"><input type="hidden" name="partner_id" value="' . (int) $p->partner_id . '">';
		echo '<table class="form-table">';
		echo '<tr><th>Display name</th><td><input type="text" name="display_name" class="regular-text" value="' . esc_attr( $p->display_name ) . '" required></td></tr>';
		echo '<tr><th>Email (login)</th><td>' . esc_html( $user ? $user->user_email : '—' ) . '</td></tr>';
		echo '<tr><th>Status</th><td><select name="status">';
		foreach ( array( 'applicant', 'active', 'paused', 'archived' ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $p->status, $st, false ) . '>' . esc_html( ucfirst( $st ) ) . '</option>';
		}
		echo '</select> <span class="description">paused/archived = no new commissions; ledger untouched</span></td></tr>';
		echo '<tr><th>Rate (%)</th><td><input type="number" step="0.001" min="0" max="100" name="rate" value="' . esc_attr( (float) $p->rate ) . '"> <span class="description">of product value ex-shipping (VAT per Settings); applies to new commissions only</span></td></tr>';
		echo '<tr><th>Payment method</th><td><input type="text" name="payment_method" value="' . esc_attr( $p->payment_method ) . '"></td></tr>';
		echo '<tr><th>Payment details</th><td><textarea name="payment_details" class="large-text" rows="2">' . esc_textarea( (string) $p->payment_details ) . '</textarea></td></tr>';
		echo '<tr><th>Notes</th><td><textarea name="notes" class="large-text" rows="2">' . esc_textarea( (string) $p->notes ) . '</textarea></td></tr>';
		echo '</table><p><button class="button button-primary">Save</button></p></form>';

		/* Coupons */
		echo '<h2>Coupons</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>Code</th><th>WC coupon</th><th>Active</th><th>Ref link</th><th></th></tr></thead><tbody>';
		foreach ( $coupons as $c ) {
			$toggle = $this->action_url( array(
				'page' => 'proof-partner-classic', 'ap_action' => 'toggle_coupon',
				'mapping_id' => $c->id, 'partner_id' => $p->partner_id, 'to' => $c->active ? 0 : 1,
			) );
			printf(
				'<tr><td><code>%s</code></td><td><a href="%s">#%d</a></td><td>%s</td><td><code>%s</code></td><td><a class="button button-small" href="%s">%s</a></td></tr>',
				esc_html( $c->coupon_code ),
				esc_url( get_edit_post_link( $c->wc_coupon_id ) ),
				(int) $c->wc_coupon_id,
				$c->active ? 'Yes' : 'No',
				esc_html( home_url( '/?ref=' . rawurlencode( $c->coupon_code ) ) ),
				esc_url( $toggle ),
				$c->active ? 'Deactivate' : 'Activate'
			);
		}
		if ( empty( $coupons ) ) echo '<tr><td colspan="5">No coupons mapped.</td></tr>';
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-top:10px">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="add_coupon"><input type="hidden" name="partner_id" value="' . (int) $p->partner_id . '">';
		echo 'Code: <input type="text" name="coupon_code" required> ';
		echo 'Type: <select name="coupon_type"><option value="percent"' . selected( $s['default_coupon_type'], 'percent', false ) . '>percent</option><option value="fixed_cart"' . selected( $s['default_coupon_type'], 'fixed_cart', false ) . '>fixed cart</option></select> ';
		echo 'Amount: <input type="number" step="0.01" min="0" name="coupon_amount" value="' . esc_attr( $s['default_coupon_amount'] ) . '" style="width:90px"> ';
		echo '<button class="button">Add coupon</button> <span class="description">existing WC coupon codes are adopted and forced to individual-use</span></form>';

		/* Ledger */
		echo '<h2 style="margin-top:2em">Ledger (last 100)</h2>';
		$this->ledger_table( $ledger, false );
		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Add partner                                                         */
	/* ------------------------------------------------------------------ */

	public function page_add() {
		$s = proof_partner_settings();
		echo '<div class="wrap"><h1>Add Partner</h1>';
		$this->notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="add_partner">';
		echo '<table class="form-table">';
		echo '<tr><th>Display name *</th><td><input type="text" name="display_name" class="regular-text" required placeholder="e.g. Joana Dimitrova"></td></tr>';
		echo '<tr><th>Email *</th><td><input type="email" name="email" class="regular-text" required> <span class="description">existing WP user with this email is linked; otherwise a user is created silently (no email sent)</span></td></tr>';
		echo '<tr><th>Rate (%) *</th><td><input type="number" step="0.001" min="0" max="100" name="rate" value="' . esc_attr( $s['default_rate'] ) . '" required> <span class="description">of product value ex-shipping (VAT per Settings)</span></td></tr>';
		echo '<tr><th>Payment method</th><td><input type="text" name="payment_method" value="bank"></td></tr>';
		echo '<tr><th>Payment details</th><td><textarea name="payment_details" class="large-text" rows="2" placeholder="IBAN, holder name"></textarea></td></tr>';
		echo '<tr><th>Notes</th><td><textarea name="notes" class="large-text" rows="2"></textarea></td></tr>';
		echo '</table>';

		echo '<h2>Coupon (optional, created in the same step)</h2><table class="form-table">';
		echo '<tr><th>Coupon code</th><td><input type="text" name="coupon_code" class="regular-text" placeholder="e.g. JOANAPROOF"> <span class="description">leave empty to add later; existing codes (AffWP migration) are adopted</span></td></tr>';
		echo '<tr><th>Discount type</th><td><select name="coupon_type"><option value="percent"' . selected( $s['default_coupon_type'], 'percent', false ) . '>percent</option><option value="fixed_cart"' . selected( $s['default_coupon_type'], 'fixed_cart', false ) . '>fixed cart</option></select></td></tr>';
		echo '<tr><th>Discount amount</th><td><input type="number" step="0.01" min="0" name="coupon_amount" value="' . esc_attr( $s['default_coupon_amount'] ) . '"></td></tr>';
		echo '</table>';

		echo '<p><button class="button button-primary button-hero">Create partner</button></p></form></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Commissions                                                         */
	/* ------------------------------------------------------------------ */

	public function page_commissions() {
		global $wpdb;
		$t = PP_Schema::table( 'commissions' );

		$status  = isset( $_GET['ap_status'] ) ? sanitize_key( $_GET['ap_status'] ) : '';
		$partner = isset( $_GET['ap_partner'] ) ? absint( $_GET['ap_partner'] ) : 0;

		$where = array( '1=1' );
		$args  = array();
		if ( $status )  { $where[] = 'status = %s';     $args[] = $status; }
		if ( $partner ) { $where[] = 'partner_id = %d'; $args[] = $partner; }

		$sql = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		echo '<div class="wrap"><h1>Commissions</h1>';
		$this->notices();

		echo '<form method="get" style="margin-bottom:12px"><input type="hidden" name="page" value="proof-partner-commissions">';
		echo 'Status: <select name="ap_status"><option value="">All</option>';
		foreach ( array( 'pending', 'approved', 'paid', 'reversed' ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $status, $st, false ) . '>' . esc_html( ucfirst( $st ) ) . '</option>';
		}
		echo '</select> Partner: <select name="ap_partner"><option value="0">All</option>';
		foreach ( PP_Partners::all() as $p ) {
			echo '<option value="' . (int) $p->partner_id . '"' . selected( $partner, (int) $p->partner_id, false ) . '>' . esc_html( $p->display_name ) . '</option>';
		}
		echo '</select> <button class="button">Filter</button></form>';

		$this->ledger_table( $rows, true );
		echo '</div>';
	}

	private function ledger_table( $rows, $show_partner ) {
		$partners = array();
		foreach ( PP_Partners::all() as $p ) $partners[ $p->partner_id ] = $p->display_name;

		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th>';
		if ( $show_partner ) echo '<th>Partner</th>';
		echo '<th>Order</th><th>Coupon</th><th>Type</th><th>Base</th><th>Rate</th><th>Amount</th><th>Status</th><th>Note</th><th>Actions</th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$actions = array();
			if ( 'paid' !== $r->status ) {
				if ( 'pending' === $r->status )  $actions[] = '<a href="' . esc_url( $this->action_url( array( 'page' => 'proof-partner-commissions', 'ap_action' => 'commission_status', 'commission_id' => $r->commission_id, 'to' => 'approved' ) ) ) . '">Approve</a>';
				if ( 'reversed' !== $r->status ) $actions[] = '<a href="' . esc_url( $this->action_url( array( 'page' => 'proof-partner-commissions', 'ap_action' => 'commission_status', 'commission_id' => $r->commission_id, 'to' => 'reversed' ) ) ) . '" style="color:#b32d2e">Reverse</a>';
				if ( 'reversed' === $r->status ) $actions[] = '<a href="' . esc_url( $this->action_url( array( 'page' => 'proof-partner-commissions', 'ap_action' => 'commission_status', 'commission_id' => $r->commission_id, 'to' => 'pending' ) ) ) . '">Reinstate</a>';
			}

			echo '<tr><td>' . (int) $r->commission_id . '</td><td>' . esc_html( $r->created_at ) . '</td>';
			if ( $show_partner ) echo '<td>' . esc_html( $partners[ $r->partner_id ] ?? ( '#' . $r->partner_id ) ) . '</td>';

			$order_link = get_admin_url( null, 'post.php?post=' . (int) $r->order_id . '&action=edit' );
			if ( function_exists( 'wc_get_order' ) ) {
				$o = wc_get_order( $r->order_id );
				if ( $o ) $order_link = $o->get_edit_order_url();
			}

			printf(
				'<td><a href="%s">#%d</a></td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s%%</td><td><strong>%s %s</strong></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( $order_link ), (int) $r->order_id,
				esc_html( $r->coupon_code ),
				esc_html( $r->type ),
				esc_html( number_format( (float) $r->base_amount, 2 ) ),
				esc_html( rtrim( rtrim( number_format( (float) $r->rate_applied, 3 ), '0' ), '.' ) ),
				esc_html( number_format( (float) $r->amount, 2 ) ), esc_html( $r->currency ),
				esc_html( $r->status ),
				esc_html( $r->note ),
				$actions ? implode( ' | ', $actions ) : '—'
			);
		}
		if ( empty( $rows ) ) echo '<tr><td colspan="12">No entries.</td></tr>';
		echo '</tbody></table>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function page_settings() {
		$s = proof_partner_settings();
		echo '<div class="wrap"><h1>Proof Partner — Settings</h1>';
		$this->notices();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="ap_action" value="save_settings">';
		echo '<table class="form-table">';

		echo '<tr><th>Commission trigger status</th><td><select name="trigger_status">';
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array( 'wc-completed' => 'Completed' );
		foreach ( $statuses as $key => $label ) {
			$k = preg_replace( '/^wc-/', '', $key );
			if ( in_array( $k, array( 'pending', 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) continue; // nonsensical triggers
			$plain = preg_replace( '/^wc-/', '', sanitize_key( $k ) );
			echo '<option value="' . esc_attr( $plain ) . '"' . selected( preg_replace( '/^wc-/', '', sanitize_key( $s['trigger_status'] ) ), $plain, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <span class="description">custom statuses (e.g. Delivered) are listed automatically; commission fires when an order ENTERS this status</span></td></tr>';

		echo '<tr><th>Holding period (days)</th><td><input type="number" min="0" name="holding_days" value="' . esc_attr( $s['holding_days'] ) . '"> <span class="description">pending → payable after N days; refunds inside the window reverse cleanly</span></td></tr>';
		echo '<tr><th style="color:#d64545">⛑ Safe Mode</th><td><label><input type="checkbox" name="safe_mode" value="1"' . checked( ! empty( $s['safe_mode'] ), true, false ) . '> <strong>Спри всички интервенции в количката</strong> (gate, автоприлагане на ref, метачка, съобщения). Счетоводството — комисиони при Delivered, изплащания, сверка — остава живо. Ползвай при съмнение за конфликт след WC/FunnelKit ъпдейт.</label></td></tr>';
		echo '<tr><th>Портал: текст за story</th><td><textarea name="kit_story" rows="2" style="width:520px">' . esc_textarea( $s['kit_story'] ) . '</textarea><br><span class="description">Плейсхолдъри: <code>{code}</code> <code>{discount}</code> <code>{link}</code> <code>{name}</code></span></td></tr>';
		echo '<tr><th>Портал: текст за пост</th><td><textarea name="kit_post" rows="3" style="width:520px">' . esc_textarea( $s['kit_post'] ) . '</textarea></td></tr>';
		echo '<tr><th>🎉 Активна кампания</th><td><textarea name="kit_campaign" rows="2" style="width:520px" placeholder="Напр.: До неделя: {code} дава двойна отстъпка на Daily Greens — пусни story днес!">' . esc_textarea( $s['kit_campaign'] ) . '</textarea><br><span class="description">Празно = няма кампания. Попълнено = банер в портала на ВСИЧКИ партньори (същите плейсхолдъри).</span></td></tr>';
		echo '<tr><th>Портал: авто-одобрение</th><td><label><input type="checkbox" name="portal_auto_approve" value="1"' . checked( ! empty( $s['portal_auto_approve'] ), true, false ) . '> Новорегистрираните партньори стават <strong>активни веднага</strong> и сами си създават код. Изключено = чакат ръчно одобрение.</label></td></tr>';
		echo '<tr><th>Праг за сигнал: невзети поръчки</th><td><input type="number" min="5" max="90" name="refund_alert_pct" value="' . esc_attr( $s['refund_alert_pct'] ) . '" style="width:70px"> % <span class="description">сигнал, когато делът на невзетите (refunded/cancelled/failed) поръчки на партньор надхвърли прага (мин. 5 поръчки, 90 дни).</span></td></tr>';
		echo '<tr><th>Кодове, съвместими с партньорски</th><td><textarea name="allowed_with_partner" rows="3" style="width:340px">' . esc_textarea( $s['allowed_with_partner'] ) . '</textarea><br><span class="description">По един код на ред. Тези промо кодове СЕ комбинират с партньорски код. Всички други се премахват автоматично при активен партньорски код. Поддържа wildcard: <code>promo*</code>.</span></td></tr>';
		echo '<tr><th>Commission base includes VAT</th><td><label><input type="checkbox" name="include_vat" value="1"' . checked( ! empty( $s['include_vat'] ), true, false ) . '> Rate applies to product value INCLUDING VAT (shipping always excluded)</label></td></tr>';
		echo '<tr><th>Default rate (%)</th><td><input type="number" step="0.001" min="0" max="100" name="default_rate" value="' . esc_attr( $s['default_rate'] ) . '"> <span class="description">prefills the Add Partner form only — actual rate always lives on the partner</span></td></tr>';
		echo '<tr><th>Excluded product IDs</th><td><input type="text" name="excluded_products" class="regular-text" value="' . esc_attr( $s['excluded_products'] ) . '" placeholder="e.g. 15526"> <span class="description">comma-separated product/variation IDs earning zero commission (promo vouchers etc.)</span></td></tr>';
		echo '<tr><th>Default coupon type</th><td><select name="default_coupon_type"><option value="percent"' . selected( $s['default_coupon_type'], 'percent', false ) . '>percent</option><option value="fixed_cart"' . selected( $s['default_coupon_type'], 'fixed_cart', false ) . '>fixed cart</option></select></td></tr>';
		echo '<tr><th>Default coupon amount</th><td><input type="number" step="0.01" min="0" name="default_coupon_amount" value="' . esc_attr( $s['default_coupon_amount'] ) . '"></td></tr>';

		echo '<tr><th colspan="2"><h2 style="margin-bottom:0">Fraud guard</h2></th></tr>';
		echo '<tr><th>Block self-referrals</th><td><label><input type="checkbox" name="block_self_referrals" value="1"' . checked( ! empty( $s['block_self_referrals'] ), true, false ) . '> Partner buying with their own code gets the discount but no commission</label></td></tr>';
		echo '<tr><th>Velocity alert: min orders / week</th><td><input type="number" min="1" name="velocity_min_weekly" value="' . esc_attr( $s['velocity_min_weekly'] ) . '"> <span class="description">absolute floor before any alert fires (avoids noise on small partners)</span></td></tr>';
		echo '<tr><th>Velocity alert: multiplier</th><td><input type="number" step="0.1" min="2" name="velocity_multiplier" value="' . esc_attr( $s['velocity_multiplier'] ) . '"> <span class="description">alert when weekly volume ≥ N× the 8-week baseline — classic coupon-leak signature. Alerts only, never blocks.</span></td></tr>';
		echo '</table><p><button class="button button-primary">Save settings</button></p></form>';

		echo '<hr><p class="description">Commission base = product line totals after discounts, excluding shipping, excluding excluded products, net of refunds; VAT included or excluded per the setting above. Attribution = mapped coupon on the order. Rate changes affect future commissions only (rate is stored per ledger row).</p>';
		echo '</div>';
	}
}
