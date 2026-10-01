<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST API for the fullscreen Partners app (and any future hub).
 * Namespace: proof-partner/v1. Auth: logged-in admin with manage_woocommerce
 * + standard WP REST nonce.
 */
class PP_Rest {

	const NS = 'proof-partner/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	public function routes() {
		$p = array( 'permission_callback' => array( $this, 'permission' ) );

		register_rest_route( self::NS, '/overview',      array( 'methods' => 'GET',  'callback' => array( $this, 'overview' ) ) + $p );
		register_rest_route( self::NS, '/partners',      array(
			array( 'methods' => 'GET',  'callback' => array( $this, 'partners_list' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'partners_create' ) ) + $p,
		) );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)', array(
			array( 'methods' => 'GET',  'callback' => array( $this, 'partners_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'partners_update' ) ) + $p,
		) );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/coupons', array( 'methods' => 'POST', 'callback' => array( $this, 'coupons_add' ) ) + $p );
		register_rest_route( self::NS, '/coupons/(?P<id>\d+)/toggle',   array( 'methods' => 'POST', 'callback' => array( $this, 'coupons_toggle' ) ) + $p );
		register_rest_route( self::NS, '/commissions',   array( 'methods' => 'GET',  'callback' => array( $this, 'commissions_list' ) ) + $p );
		register_rest_route( self::NS, '/commissions/(?P<id>\d+)/status', array( 'methods' => 'POST', 'callback' => array( $this, 'commissions_status' ) ) + $p );
		register_rest_route( self::NS, '/payables',      array( 'methods' => 'GET',  'callback' => array( $this, 'payables' ) ) + $p );
		register_rest_route( self::NS, '/pay',           array( 'methods' => 'POST', 'callback' => array( $this, 'pay' ) ) + $p );
		register_rest_route( self::NS, '/payouts',       array( 'methods' => 'GET',  'callback' => array( $this, 'payouts_list' ) ) + $p );
		register_rest_route( self::NS, '/payouts/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'payouts_get' ) ) + $p );
		register_rest_route( self::NS, '/payouts-csv',   array( 'methods' => 'GET',  'callback' => array( $this, 'payouts_csv' ) ) + $p );
		register_rest_route( self::NS, '/alerts/clear',  array( 'methods' => 'POST', 'callback' => array( $this, 'alerts_clear' ) ) + $p );
		register_rest_route( self::NS, '/reconciliation', array( 'methods' => 'GET',  'callback' => array( $this, 'recon_get' ) ) + $p );
		register_rest_route( self::NS, '/reconciliation/run', array( 'methods' => 'POST', 'callback' => array( $this, 'recon_run' ) ) + $p );
		register_rest_route( self::NS, '/reconciliation/fix', array( 'methods' => 'POST', 'callback' => array( $this, 'recon_fix' ) ) + $p );
		register_rest_route( self::NS, '/diagnostics/run', array( 'methods' => 'POST', 'callback' => array( $this, 'diagnostics_run' ) ) + $p );
		register_rest_route( self::NS, '/order-info/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( $this, 'order_info' ) ) + $p );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/outcomes', array( 'methods' => 'GET', 'callback' => array( $this, 'partner_outcomes' ) ) + $p );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/adjustment', array( 'methods' => 'POST', 'callback' => array( $this, 'partner_adjustment' ) ) + $p );
		register_rest_route( self::NS, '/export', array( 'methods' => 'GET', 'callback' => array( $this, 'full_export' ) ) + $p );
		register_rest_route( self::NS, '/marketing', array( 'methods' => 'GET',  'callback' => array( $this, 'marketing_get' ) ) + $p );
		register_rest_route( self::NS, '/marketing', array( 'methods' => 'POST', 'callback' => array( $this, 'marketing_save' ) ) + $p );
		register_rest_route( self::NS, '/trigger', array( 'methods' => 'POST', 'callback' => array( $this, 'trigger_save' ) ) + $p );
		register_rest_route( self::NS, '/feed', array( 'methods' => 'GET', 'callback' => array( $this, 'feed' ) ) + $p );
		register_rest_route( self::NS, '/tiers-preview', array( 'methods' => 'POST', 'callback' => array( $this, 'tiers_preview' ) ) + $p );
		register_rest_route( self::NS, '/ranking', array( 'methods' => 'GET', 'callback' => array( $this, 'ranking' ) ) + $p );
		register_rest_route( self::NS, '/partners/bulk', array( 'methods' => 'POST', 'callback' => array( $this, 'partners_bulk' ) ) + $p );
		register_rest_route( self::NS, '/telegram-test', array( 'methods' => 'POST', 'callback' => array( $this, 'telegram_test' ) ) + $p );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/password', array( 'methods' => 'POST', 'callback' => array( $this, 'partner_password' ) ) + $p );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/reset-link', array( 'methods' => 'POST', 'callback' => array( $this, 'partner_reset_link' ) ) + $p );
		register_rest_route( self::NS, '/partners/(?P<id>\d+)/note', array( 'methods' => 'POST', 'callback' => array( $this, 'partner_note' ) ) + $p );
		register_rest_route( self::NS, '/migration/announce', array( 'methods' => 'POST', 'callback' => array( $this, 'migration_announce' ) ) + $p );
		register_rest_route( self::NS, '/migration/status', array( 'methods' => 'GET', 'callback' => array( $this, 'migration_status' ) ) + $p );
		register_rest_route( self::NS, '/annual', array( 'methods' => 'GET', 'callback' => array( $this, 'annual_report' ) ) + $p );
		register_rest_route( self::NS, '/game/config', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'game_config_get' ) ) + $p,
			array( 'methods' => 'POST', 'callback' => array( $this, 'game_config_save' ) ) + $p,
		) );
		register_rest_route( self::NS, '/game/content', array( 'methods' => 'GET', 'callback' => array( $this, 'game_content_list' ) ) + $p );
		register_rest_route( self::NS, '/game/content/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( $this, 'game_content_moderate' ) ) + $p );
		register_rest_route( self::NS, '/game/draw', array( 'methods' => 'POST', 'callback' => array( $this, 'game_draw' ) ) + $p );
		register_rest_route( self::NS, '/game/standings', array( 'methods' => 'GET', 'callback' => array( $this, 'game_standings' ) ) + $p );
		register_rest_route( self::NS, '/game/preview', array( 'methods' => 'POST', 'callback' => array( $this, 'game_preview' ) ) + $p );
		register_rest_route( self::NS, '/game/finalize', array( 'methods' => 'POST', 'callback' => array( $this, 'game_finalize' ) ) + $p );
		register_rest_route( self::NS, '/payouts/notify', array( 'methods' => 'POST', 'callback' => array( $this, 'payouts_notify' ) ) + $p );
		register_rest_route( self::NS, '/sweep', array( 'methods' => 'POST', 'callback' => array( $this, 'sweep_now' ) ) + $p );
		register_rest_route( self::NS, '/sweep-status', array( 'methods' => 'GET', 'callback' => array( $this, 'sweep_status' ) ) + $p );
	}

	public function sweep_status() {
		return rest_ensure_response( array(
			'sweep'    => get_option( 'proof_partner_sweep', array() ),
			'backfill' => get_option( 'proof_partner_backfill', array() ),
		) );
	}

	public function sweep_now() {
		$r = PP_Engine::sweep( true );
		return rest_ensure_response( $r ?: array( 'scanned' => 0, 'missing' => 0, 'created' => 0 ) );
	}

	/** Жив преглед: рендерира порталния екран с ПОДАДЕНИТЕ (незапазени) стойности. */
	public function game_preview( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		PP_Game::$preview_override = array_merge( $b, array( 'enabled' => 1 ) );
		// Богат изглед: партньорът с най-много точки; иначе първият активен.
		$pm = PP_Game::points_map();
		arsort( $pm );
		$partner = null;
		foreach ( array_keys( $pm ) as $pid ) { $pp = PP_Partners::get( $pid ); if ( $pp && 'active' === $pp->status ) { $partner = $pp; break; } }
		if ( ! $partner ) foreach ( PP_Partners::all() as $pp ) { if ( 'active' === $pp->status ) { $partner = $pp; break; } }
		if ( ! $partner ) return new WP_Error( 'ap_preview', 'Няма активен партньор за преглед — добави поне един.' );
		global $wpdb;
		$coupons = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE partner_id = %d', $partner->partner_id ) );
		ob_start();
		try { PP_Game_View::pane( $partner, array( 'coupons' => $coupons ) ); }
		catch ( \Throwable $e ) { ob_end_clean(); PP_Game::$preview_override = null; return new WP_Error( 'ap_preview', 'Прегледът гръмна: ' . $e->getMessage() ); }
		$html = ob_get_clean();
		PP_Game::$preview_override = null;
		$css = plugin_dir_url( PROOF_PARTNER_DIR . 'proof-partner.php' ) . 'assets/portal.css?v=' . PROOF_PARTNER_VERSION;
		return rest_ensure_response( array(
			'html' => '<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
				. '<link rel="stylesheet" href="' . esc_url( $css ) . '">'
				. '<style>body{margin:0;padding:16px;background:#fdf0e8;font-family:Nunito,-apple-system,sans-serif}.pp-pane{display:block!important}</style></head>'
				. '<body><div class="pp-pane">' . $html . '</div></body></html>',
			'as' => $partner->display_name,
		) );
	}

	public function game_config_get() { return rest_ensure_response( PP_Game::config() ); }

	public function game_config_save( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$c = PP_Game::config();
		$ints  = array( 'enabled', 'grace_days', 'pts_sale', 'pts_content', 'pts_referral', 'content_cap', 'streak_weeks', 'streak_bonus', 'overtake_notify', 'digest_enabled', 'share_enabled' );
		$texts = array( 'from', 'to', 'levels', 'hero_kick', 'hero_title', 'hero_sub', 'hero_chips', 'win_line',
			'prize_p1', 'prize_p2', 'prize_p3', 'prize_content', 'prize_content_t', 'prize_content_d',
			'prize_ref', 'prize_ref_t', 'prize_ref_d', 'prize_lucky', 'prize_lucky_t', 'prize_lucky_d',
			'way1', 'way1_fine', 'way2', 'way2_fine', 'way3', 'way3_fine', 'rules', 'empty_title', 'empty_sub', 'layout', 'streak_txt', 'overtake_subj', 'overtake_body', 'digest_subj', 'perks' );
		foreach ( $ints as $k )  if ( array_key_exists( $k, $b ) ) $c[ $k ] = max( 0, (int) $b[ $k ] );
		if ( array_key_exists( 'min_order', $b ) ) $c['min_order'] = max( 0, (float) $b['min_order'] );
		foreach ( $texts as $k ) if ( array_key_exists( $k, $b ) ) $c[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
		if ( array_key_exists( 'missions', $b ) && is_array( $b['missions'] ) ) {
			$m = array();
			foreach ( array_slice( $b['missions'], 0, 12 ) as $row ) {
				if ( empty( $row['text'] ) ) continue;
				$m[] = array(
					'icon'        => sanitize_text_field( (string) ( $row['icon'] ?? '🎯' ) ),
					'text'        => sanitize_text_field( (string) $row['text'] ),
					'reward'      => sanitize_text_field( (string) ( $row['reward'] ?? '' ) ),
					'deadline'    => sanitize_text_field( (string) ( $row['deadline'] ?? '' ) ),
					'target_type' => in_array( $row['target_type'] ?? '', array( 'content_week', 'referrals_active', 'sales_month' ), true ) ? $row['target_type'] : '',
					'target_n'    => max( 1, (int) ( $row['target_n'] ?? 1 ) ),
				);
			}
			$c['missions'] = $m;
		}
		update_option( PP_Game::OPT, $c );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function game_content_list( WP_REST_Request $req ) {
		global $wpdb;
		$st = sanitize_key( (string) ( $req->get_param( 'status' ) ?: 'pending' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'content' ) . ' WHERE status = %s ORDER BY content_id DESC LIMIT 100', $st ) );
		$names = $this->partner_names();
		return rest_ensure_response( array_map( function ( $r ) use ( $names ) {
			return array(
				'content_id' => (int) $r->content_id,
				'partner'    => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ),
				'url'        => $r->url,
				'status'     => $r->status,
				'points'     => (int) $r->points,
				'note'       => (string) $r->note,
				'created_at' => $r->created_at,
			);
		}, $rows ) );
	}

	public function game_content_moderate( WP_REST_Request $req ) {
		$b   = (array) $req->get_json_params();
		$res = PP_Game::moderate( (int) $req['id'], 'approve' === ( $b['action'] ?? '' ), (string) ( $b['note'] ?? '' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	public function game_finalize() {
		$final = PP_Game::finalize();
		return rest_ensure_response( array( 'ok' => true, 'final' => $final ) );
	}

	public function game_draw() {
		$res = PP_Game::draw();
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true, 'draw' => $res ) );
	}

	public function game_standings() {
		$pm = PP_Game::points_map(); $cm = PP_Game::category_map(); $names = $this->partner_names();
		$rows = array();
		foreach ( $pm as $pid => $v ) {
			$rows[] = array( 'partner' => $names[ $pid ] ?? ( '#' . $pid ), 'active' => $v['active'], 'locked' => $v['locked'] )
				+ ( $cm[ $pid ] ?? array( 'sales' => 0, 'content' => 0, 'refs' => 0 ) );
		}
		usort( $rows, function ( $a, $b ) { return $b['active'] <=> $a['active']; } );
		return rest_ensure_response( array( 'rows' => $rows, 'draw' => PP_Game::config()['draw'] ) );
	}

	/** F1: годишен отчет — per партньор всичко изплатено за годината (за счетоводството / чл. 73). */
	public function annual_report( WP_REST_Request $req ) {
		global $wpdb;
		$year = min( 2100, max( 2020, (int) ( $req->get_param( 'year' ) ?: gmdate( 'Y' ) ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT partner_id, COUNT(*) AS cnt, SUM(amount) AS total, MIN(created_at) AS first_p, MAX(created_at) AS last_p
			 FROM ' . PP_Schema::table( 'payouts' ) . " WHERE created_at BETWEEN %s AND %s GROUP BY partner_id ORDER BY total DESC",
			$year . '-01-01 00:00:00', $year . '-12-31 23:59:59'
		) );
		$out = array();
		foreach ( $rows as $r ) {
			$p = PP_Partners::get( (int) $r->partner_id );
			if ( ! $p ) continue;
			$out[] = array(
				'partner'    => $p->display_name,
				'legal_name' => (string) ( $p->legal_name ?? '' ),
				'iban'       => (string) ( $p->iban ?? '' ),
				'email'      => (string) $p->email,
				'payouts'    => (int) $r->cnt,
				'total'      => round( (float) $r->total, 2 ),
				'first'      => substr( (string) $r->first_p, 0, 10 ),
				'last'       => substr( (string) $r->last_p, 0, 10 ),
			);
		}
		return rest_ensure_response( array( 'year' => $year, 'rows' => $out, 'grand_total' => round( array_sum( array_column( $out, 'total' ) ), 2 ) ) );
	}

	/**
	 * Анонс до партньорите: тест към админа или batched залп през Action
	 * Scheduler (1 писмо / 4 сек — SendGrid-friendly, никакъв burst).
	 */
	public function migration_announce( WP_REST_Request $req ) {
		$b    = (array) $req->get_json_params();
		$body = sanitize_textarea_field( (string) ( $b['body'] ?? '' ) );
		if ( '' === $body ) return new WP_Error( 'ap_mig', 'Празен текст.', array( 'status' => 400 ) );

		// Запази текста като настройка (следващия път е там).
		$st = get_option( 'proof_partner_settings', array() );
		$st['migration_email'] = $body;
		update_option( 'proof_partner_settings', $st );

		$subject = proof_partner_brand_name() . ' — новата партньорска платформа е тук';

		if ( ! empty( $b['test'] ) ) {
			$me   = wp_get_current_user();
			$sent = proof_partner_send_announce_mail( $me->user_email, $me->display_name ?: 'партньор', $body, $subject );
			return $sent ? rest_ensure_response( array( 'ok' => true, 'test' => true, 'email' => $me->user_email ) )
				: new WP_Error( 'ap_mail', 'Тестовият email не тръгна — провери SMTP/SendGrid.', array( 'status' => 500 ) );
		}

		$targets = array();
		foreach ( PP_Partners::all() as $p ) {
			if ( ! in_array( $p->status, array( 'active', 'paused' ), true ) ) continue;
			$u = $p->user_id ? get_user_by( 'id', (int) $p->user_id ) : null;
			$email = $u ? $u->user_email : $p->email;
			if ( $email && is_email( $email ) ) $targets[] = array( 'email' => $email, 'name' => current( explode( ' ', $p->display_name ) ) );
		}
		if ( empty( $targets ) ) return new WP_Error( 'ap_mig', 'Няма партньори с валиден email.', array( 'status' => 400 ) );

		update_option( 'proof_partner_announce', array( 'queued' => count( $targets ), 'sent' => 0, 'failed' => 0, 'started' => current_time( 'mysql' ) ), false );

		$i = 0;
		foreach ( $targets as $t ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5 + ( $i * 4 ), 'proof_partner_send_announce', array( $t['email'], $t['name'], $body, $subject ), 'proof-partner' );
			} else {
				proof_partner_send_announce_mail( $t['email'], $t['name'], $body, $subject );
			}
			$i++;
		}
		proof_partner_feed_push( 'partner', sprintf( 'Анонс кампания: %d писма на опашката (по 1 / 4 сек)', count( $targets ) ) );
		return rest_ensure_response( array( 'ok' => true, 'queued' => count( $targets ) ) );
	}

	public function migration_status() {
		return rest_ensure_response( (array) get_option( 'proof_partner_announce', array() ) );
	}

	/** Timeline бележка: добавя се отгоре с дата и автор, старите остават. */
	public function partner_note( WP_REST_Request $req ) {
		$partner = PP_Partners::get( (int) $req['id'] );
		if ( ! $partner ) return new WP_Error( 'ap_404', 'Партньорът липсва.', array( 'status' => 404 ) );
		$note = sanitize_text_field( (string) ( $req->get_json_params()['note'] ?? '' ) );
		if ( '' === $note ) return new WP_Error( 'ap_note', 'Празна бележка.', array( 'status' => 400 ) );
		$line = sprintf( '[%s · %s] %s', current_time( 'd.m.Y H:i' ), wp_get_current_user()->user_login, $note );
		$all  = trim( $line . "\n" . (string) $partner->notes );
		$r    = PP_Partners::update( $partner->partner_id, array( 'notes' => mb_substr( $all, 0, 8000 ) ) );
		if ( is_wp_error( $r ) ) return $r;
		return rest_ensure_response( array( 'ok' => true, 'notes' => $all ) );
	}

	private function partner_user( $pid ) {
		$partner = PP_Partners::get( (int) $pid );
		if ( ! $partner ) return new WP_Error( 'ap_404', 'Партньорът липсва.', array( 'status' => 404 ) );
		$user = $partner->user_id ? get_user_by( 'id', (int) $partner->user_id ) : false;
		if ( ! $user ) return new WP_Error( 'ap_nouser', 'Партньорът няма свързан WP профил — създай му през импорта или регистрацията.', array( 'status' => 400 ) );
		if ( user_can( $user, 'manage_woocommerce' ) ) return new WP_Error( 'ap_admin', 'Това е администраторски профил — не се пипа оттук.', array( 'status' => 403 ) );
		return array( $partner, $user );
	}

	/** Админ: сетва нова парола на партньора директно. */
	public function partner_password( WP_REST_Request $req ) {
		$r = $this->partner_user( $req['id'] );
		if ( is_wp_error( $r ) ) return $r;
		list( $partner, $user ) = $r;
		$pass = (string) ( $req->get_json_params()['password'] ?? '' );
		if ( strlen( $pass ) < 8 ) return new WP_Error( 'ap_pw', 'Минимум 8 знака.', array( 'status' => 400 ) );
		wp_set_password( $pass, $user->ID );
		return rest_ensure_response( array( 'ok' => true, 'login' => $user->user_login, 'email' => $user->user_email ) );
	}

	/** Админ: генерира reset линк към портала + го праща по email; връща линка за копиране. */
	public function partner_reset_link( WP_REST_Request $req ) {
		$r = $this->partner_user( $req['id'] );
		if ( is_wp_error( $r ) ) return $r;
		list( $partner, $user ) = $r;

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) return $key;
		$url   = home_url( '/partner?rp=1&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ) );
		$brand = proof_partner_brand_name();
		$sent  = wp_mail(
			$user->user_email,
			$brand . ' Партньори — нова парола',
			"Здравей!

Ето линк за нова парола на партньорския ти профил в {$brand}:
{$url}

Линкът е валиден 24 часа.

{$brand} Партньори"
		);
		return rest_ensure_response( array( 'ok' => true, 'url' => $url, 'emailed' => (bool) $sent, 'email' => $user->user_email ) );
	}

	/** Живо разпределение: колко партньори в кое ниво при подадените прагове. */
	public function tiers_preview( WP_REST_Request $req ) {
		global $wpdb;
		$b     = (array) $req->get_json_params();
		$tiers = array();
		foreach ( preg_split( '/[\r\n]+/', (string) ( $b['tiers'] ?? '' ) ) as $line ) {
			$x = array_map( 'trim', explode( '|', $line ) );
			if ( count( $x ) >= 3 && '' !== $x[0] ) $tiers[] = array( 'name' => sanitize_text_field( $x[0] ), 'min' => (float) $x[1], 'rate' => (float) $x[2] );
		}
		if ( empty( $tiers ) ) return rest_ensure_response( array( 'tiers' => array(), 'changes' => array() ) );
		usort( $tiers, function ( $a, $b ) { return $b['min'] <=> $a['min']; } );

		$sums = $wpdb->get_results( $wpdb->prepare(
			'SELECT partner_id, COALESCE(SUM(amount),0) AS s FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE type = 'commission' AND status != 'reversed' AND created_at >= %s GROUP BY partner_id",
			gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS )
		), OBJECT_K );

		$counts = array_fill_keys( array_column( $tiers, 'name' ), 0 );
		$changes = array();
		foreach ( PP_Partners::all() as $p ) {
			if ( 'active' !== $p->status ) continue;
			$sum = isset( $sums[ $p->partner_id ] ) ? (float) $sums[ $p->partner_id ]->s : 0.0;
			$t   = end( $tiers );
			foreach ( $tiers as $c ) { if ( $sum >= $c['min'] ) { $t = $c; break; } }
			$counts[ $t['name'] ]++;
			if ( abs( (float) $t['rate'] - (float) $p->rate ) >= 0.0001 && count( $changes ) < 30 ) {
				$changes[] = array( 'name' => $p->display_name, 'from' => (float) $p->rate, 'to' => (float) $t['rate'], 'sum30' => round( $sum, 2 ) );
			}
		}
		return rest_ensure_response( array(
			'tiers'   => array_map( function ( $t ) use ( $counts ) { $t['count'] = $counts[ $t['name'] ]; return $t; }, $tiers ),
			'changes' => $changes,
		) );
	}

	/** Ранглиста „кого да натиснем": 30д метрики + заспали. */
	public function ranking() {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT partner_id,
			        SUM(CASE WHEN type='commission' AND status != 'reversed' THEN amount ELSE 0 END) AS comm30,
			        SUM(CASE WHEN type='commission' AND status != 'reversed' THEN base_amount ELSE 0 END) AS rev30,
			        SUM(CASE WHEN type='commission' THEN 1 ELSE 0 END) AS orders30,
			        SUM(CASE WHEN type='reversal' THEN 1 ELSE 0 END) AS reversals30
			 FROM " . PP_Schema::table( 'commissions' ) . ' WHERE created_at >= %s GROUP BY partner_id', $since
		), OBJECT_K );
		$last = $wpdb->get_results(
			"SELECT partner_id, MAX(created_at) AS last_c FROM " . PP_Schema::table( 'commissions' ) . " WHERE type='commission' GROUP BY partner_id", OBJECT_K
		);

		$clicks_opt = get_option( PP_Coupons::CLICKS_OPT, array() );
		$cut30 = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		$clicks_by_code = array();
		foreach ( (array) $clicks_opt as $day => $codes ) {
			if ( $day < $cut30 ) continue;
			foreach ( (array) $codes as $c => $n ) $clicks_by_code[ $c ] = ( $clicks_by_code[ $c ] ?? 0 ) + (int) $n;
		}
		global $wpdb;
		$code_map = $wpdb->get_results( 'SELECT coupon_code, partner_id FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE active = 1' );
		$clicks_by_partner = array();
		foreach ( $code_map as $cm ) {
			$clicks_by_partner[ (int) $cm->partner_id ] = ( $clicks_by_partner[ (int) $cm->partner_id ] ?? 0 ) + ( $clicks_by_code[ wc_format_coupon_code( $cm->coupon_code ) ] ?? 0 );
		}

		$active = array(); $sleeping = array();
		$now = current_time( 'timestamp' );
		foreach ( PP_Partners::all() as $p ) {
			if ( ! in_array( $p->status, array( 'active', 'paused' ), true ) ) continue;
			$r  = $rows[ $p->partner_id ] ?? null;
			$o  = $r ? (int) $r->orders30 : 0;
			$cl = (int) ( $clicks_by_partner[ (int) $p->partner_id ] ?? 0 );
			$item = array(
				'partner_id' => (int) $p->partner_id,
				'name'       => $p->display_name,
				'email'      => $p->email,
				'rate'       => (float) $p->rate,
				'rev30'      => $r ? round( (float) $r->rev30, 2 ) : 0,
				'comm30'     => $r ? round( (float) $r->comm30, 2 ) : 0,
				'orders30'   => $o,
				'clicks30'   => $cl,
				'conv'       => $cl ? round( 100 * $o / $cl, 1 ) : null,
				'lost_pct'   => ( $r && ( $o + (int) $r->reversals30 ) > 0 ) ? round( 100 * (int) $r->reversals30 / max( 1, $o ), 1 ) : 0,
			);
			$last_c = isset( $last[ $p->partner_id ] ) ? strtotime( $last[ $p->partner_id ]->last_c ) : 0;
			if ( $o > 0 || ( $last_c && $now - $last_c < 60 * DAY_IN_SECONDS ) ) $active[] = $item;
			else { $item['last'] = $last_c ? gmdate( 'Y-m-d', $last_c ) : 'никога'; $sleeping[] = $item; }
		}
		usort( $active, function ( $a, $b ) { return $b['rev30'] <=> $a['rev30']; } );
		return rest_ensure_response( array( 'active' => $active, 'sleeping' => $sleeping ) );
	}

	/** Bulk операции върху партньори. */
	public function partners_bulk( WP_REST_Request $req ) {
		$b   = (array) $req->get_json_params();
		$ids = array_filter( array_map( 'absint', (array) ( $b['ids'] ?? array() ) ) );
		$act = sanitize_key( $b['action'] ?? '' );
		if ( empty( $ids ) ) return new WP_Error( 'ap_bulk', 'Няма избрани партньори.', array( 'status' => 400 ) );
		$done = 0;
		foreach ( $ids as $pid ) {
			if ( 'rate' === $act ) {
				$rate = (float) str_replace( ',', '.', (string) ( $b['rate'] ?? 0 ) );
				if ( $rate <= 0 || $rate > 90 ) return new WP_Error( 'ap_bulk', 'Невалидна ставка.', array( 'status' => 400 ) );
				$r = PP_Partners::update( $pid, array( 'rate' => $rate ) );
			} elseif ( in_array( $act, array( 'pause', 'activate' ), true ) ) {
				$r = PP_Partners::update( $pid, array( 'status' => 'pause' === $act ? 'paused' : 'active' ) );
			} else {
				return new WP_Error( 'ap_bulk', 'Непознато действие.', array( 'status' => 400 ) );
			}
			if ( ! is_wp_error( $r ) ) $done++;
		}
		proof_partner_feed_push( 'partner', sprintf( 'Bulk: %s върху %d партньори', $act, $done ) );
		return rest_ensure_response( array( 'ok' => true, 'done' => $done ) );
	}

	public function telegram_test() {
		$ok = proof_partner_telegram( 'Тест ✓ — известията работят. ' . current_time( 'mysql' ) );
		return $ok ? rest_ensure_response( array( 'ok' => true ) )
			: new WP_Error( 'ap_tg', 'Не се получи — провери token и chat id (ботът трябва да е стартиран с /start от теб).', array( 'status' => 400 ) );
	}

	/** №9: живият фийд. */
	public function feed() {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		return rest_ensure_response( array_reverse( array_slice( array_values( (array) get_option( 'proof_partner_feed', array() ) ), -10 ) ) );
	}

	/** Смяна на закона (trigger статус) с валидация срещу реалните WC статуси. */
	public function trigger_save( WP_REST_Request $req ) {
		$b      = (array) $req->get_json_params();
		$status = preg_replace( '/^wc-/', '', sanitize_key( $b['status'] ?? '' ) );
		$all    = array_map( function ( $k ) { return preg_replace( '/^wc-/', '', $k ); }, array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() ) );
		if ( ! in_array( $status, $all, true ) ) {
			return new WP_Error( 'ap_trigger', sprintf( 'Статус "%s" не съществува в магазина.', $status ), array( 'status' => 400 ) );
		}
		$s = get_option( 'proof_partner_settings', array() );
		$s['trigger_status'] = $status;
		update_option( 'proof_partner_settings', $s );
		return rest_ensure_response( array( 'ok' => true, 'won_statuses' => array_values( array_unique( array( $status, 'completed' ) ) ) ) );
	}

	/** Кит шаблони + кампания — редакция от приложението, не от wp-admin. */
	public function marketing_get() {
		$s = proof_partner_settings();
		return rest_ensure_response( array(
			'kit_story'     => (string) ( $s['kit_story'] ?? '' ),
			'kit_post'      => (string) ( $s['kit_post'] ?? '' ),
			'kit_campaign'  => (string) ( $s['kit_campaign'] ?? '' ),
			'fixed_msg_behind' => (string) ( $s['fixed_msg_behind'] ?? '' ),
			'fixed_msg_ahead'  => (string) ( $s['fixed_msg_ahead'] ?? '' ),
			'fixed_wish_mid'   => (string) ( $s['fixed_wish_mid'] ?? '' ), 'fixed_wish_end' => (string) ( $s['fixed_wish_end'] ?? '' ), 'fixed_wish_next' => (string) ( $s['fixed_wish_next'] ?? '' ),
			'tiers_enabled' => ! empty( $s['tiers_enabled'] ),
			'tiers'         => (string) ( $s['tiers'] ?? '' ),
			'brand_name'    => (string) ( $s['brand_name'] ?? '' ),
			'brand_company' => (string) ( $s['brand_company'] ?? '' ),
			'brand_company_id'      => (string) ( $s['brand_company_id'] ?? '' ),
			'brand_company_address' => (string) ( $s['brand_company_address'] ?? '' ),
			'brand_accent'  => (string) ( $s['brand_accent'] ?? '#f47a49' ),
			'brand_logo_url'=> (string) ( $s['brand_logo_url'] ?? '' ),
			'brand_accent2' => (string) ( $s['brand_accent2'] ?? '#f8a41d' ),
			'brand_code_suffix' => (string) ( $s['brand_code_suffix'] ?? 'PROOF' ),
			'tg_token'          => (string) ( $s['tg_token'] ?? '' ),
			'tg_chat'           => (string) ( $s['tg_chat'] ?? '' ),
			'kit_campaign_from' => (string) ( $s['kit_campaign_from'] ?? '' ),
			'kit_campaign_to'   => (string) ( $s['kit_campaign_to'] ?? '' ),
			'statement_offset'  => (int) ( $s['statement_offset'] ?? 142 ),
			'legacy_link_prefixes' => (string) ( $s['legacy_link_prefixes'] ?? 'hi' ),
			'legacy_portal_slug'   => (string) ( $s['legacy_portal_slug'] ?? '' ),
			'migration_email'      => (string) ( $s['migration_email'] ?? '' ),
		) );
	}

	public function marketing_save( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$s = get_option( 'proof_partner_settings', array() );
		foreach ( array( 'kit_story', 'kit_post', 'kit_campaign', 'tiers', 'fixed_msg_behind', 'fixed_msg_ahead', 'fixed_wish_mid', 'fixed_wish_end', 'fixed_wish_next' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) $s[ $k ] = sanitize_textarea_field( (string) $b[ $k ] );
		}
		if ( array_key_exists( 'tiers_enabled', $b ) )  $s['tiers_enabled'] = empty( $b['tiers_enabled'] ) ? 0 : 1;
		foreach ( array( 'brand_name', 'brand_company', 'brand_company_id', 'brand_company_address' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) $s[ $k ] = sanitize_text_field( (string) $b[ $k ] );
		}
		if ( array_key_exists( 'brand_accent', $b ) )   $s['brand_accent'] = sanitize_hex_color( (string) $b['brand_accent'] ) ?: '#2BB6EA';
		if ( array_key_exists( 'brand_logo_url', $b ) ) $s['brand_logo_url'] = esc_url_raw( (string) $b['brand_logo_url'] );
		if ( array_key_exists( 'brand_accent2', $b ) )  $s['brand_accent2'] = sanitize_hex_color( (string) $b['brand_accent2'] ) ?: '#16303F';
		if ( array_key_exists( 'brand_code_suffix', $b ) ) $s['brand_code_suffix'] = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $b['brand_code_suffix'] ) ) ?: 'PROOF';
		foreach ( array( 'tg_token', 'tg_chat' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) $s[ $k ] = sanitize_text_field( (string) $b[ $k ] );
		}
		if ( array_key_exists( 'statement_offset', $b ) ) $s['statement_offset'] = max( 0, (int) $b['statement_offset'] );
		$flush = false;
		if ( array_key_exists( 'legacy_link_prefixes', $b ) ) {
			$new = sanitize_text_field( (string) $b['legacy_link_prefixes'] );
			$flush = $new !== ( $s['legacy_link_prefixes'] ?? '' );
			$s['legacy_link_prefixes'] = $new;
		}
		if ( array_key_exists( 'legacy_portal_slug', $b ) ) $s['legacy_portal_slug'] = trim( sanitize_text_field( (string) $b['legacy_portal_slug'] ), '/' );
		foreach ( array( 'kit_campaign_from', 'kit_campaign_to' ) as $k ) {
			if ( array_key_exists( $k, $b ) ) $s[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $b[ $k ] ) ? (string) $b[ $k ] : '';
		}
		update_option( 'proof_partner_settings', $s );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * №9: съдбата на партньорските поръчки — взети / невзети / в движение.
	 * Източници: ledger редовете + поръчки със заключена атрибуция, които
	 * така и не стигнаха до комисиона (COD отказани преди доставка).
	 */
	public static function outcomes_for( $partner_id, $days = 90 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );

		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT order_id FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND order_id > 0 AND created_at >= %s", $partner_id, $since . ' 00:00:00'
		) ) );

		// Поръчки със заключена атрибуция (вкл. отказани ПРЕДИ Delivered — те нямат ledger ред).
		$locked = wc_get_orders( array(
			'limit'        => 300,
			'return'       => 'ids',
			'date_created' => '>' . $since,
			'meta_key'     => '_proof_partner_id',
			'meta_value'   => (int) $partner_id,
		) );
		$ids = array_slice( array_unique( array_merge( $ids, array_map( 'intval', (array) $locked ) ) ), 0, 400 );

		$won_st   = proof_partner_won_statuses();
		$lost_st  = array( 'cancelled', 'refunded', 'failed', 'trash' );
		$buckets  = array( 'won' => 0, 'lost' => array( 'refunded' => 0, 'cancelled' => 0, 'failed' => 0 ), 'moving' => array() );

		foreach ( $ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) continue;
			$st = $order->get_status();
			if ( in_array( $st, $won_st, true ) ) {
				$buckets['won']++;
			} elseif ( in_array( $st, $lost_st, true ) ) {
				$key = isset( $buckets['lost'][ $st ] ) ? $st : 'cancelled';
				$buckets['lost'][ $key ]++;
			} else {
				$buckets['moving'][ $st ] = ( $buckets['moving'][ $st ] ?? 0 ) + 1;
			}
		}

		$lost_total = array_sum( $buckets['lost'] );
		$decided    = $buckets['won'] + $lost_total;
		return array(
			'days'      => $days,
			'won'       => $buckets['won'],
			'lost'      => $lost_total,
			'lost_by'   => $buckets['lost'],
			'moving'    => array_sum( $buckets['moving'] ),
			'moving_by' => $buckets['moving'],
			'lost_pct'  => $decided ? round( 100 * $lost_total / $decided, 1 ) : 0,
			'total'     => count( $ids ),
		);
	}

	public function partner_outcomes( WP_REST_Request $req ) {
		return rest_ensure_response( self::outcomes_for( (int) $req['id'], min( 365, max( 30, (int) ( $req->get_param( 'days' ) ?: 90 ) ) ) ) );
	}

	/** №10: ръчен ред в книгата — бонус/корекция, append-only, с автор. */
	public function partner_adjustment( WP_REST_Request $req ) {
		global $wpdb;
		$b      = $req->get_json_params();
		$pid    = (int) $req['id'];
		$amount = round( (float) str_replace( ',', '.', (string) ( $b['amount'] ?? 0 ) ), 2 );
		$note   = sanitize_text_field( $b['note'] ?? '' );
		if ( ! PP_Partners::get( $pid ) )    return new WP_Error( 'ap_404', 'Партньорът липсва.', array( 'status' => 404 ) );
		if ( 0.0 === $amount )               return new WP_Error( 'ap_adj', 'Сумата не може да е 0.', array( 'status' => 400 ) );
		if ( '' === $note )                  return new WP_Error( 'ap_adj', 'Бележката е задължителна — книгата помни защо.', array( 'status' => 400 ) );
		if ( abs( $amount ) > 5000 )         return new WP_Error( 'ap_adj', 'Ръчен ред над 5000 — провери сумата.', array( 'status' => 400 ) );

		$user = wp_get_current_user();
		$now  = current_time( 'mysql' );
		$wpdb->insert( PP_Schema::table( 'commissions' ), array(
			'order_id' => 0, 'partner_id' => $pid, 'coupon_code' => '', 'type' => 'manual',
			'base_amount' => 0, 'rate_applied' => 0, 'amount' => $amount, 'currency' => 'EUR',
			'status' => 'approved', 'products' => '',
			'note' => sprintf( '%s — %s', $note, $user->user_login ),
			'created_at' => $now, 'updated_at' => $now,
		) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** №5: пълен JSON бекъп — disaster recovery + Commerce Brain ingestion. */
	public function full_export() {
		global $wpdb;
		$data = array(
			'exported_at' => current_time( 'mysql' ),
			'version'     => PROOF_PARTNER_VERSION,
			'settings'    => proof_partner_settings(),
			'partners'    => $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'partners' ) ),
			'coupons'     => $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'coupons' ) ),
			'commissions' => $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' ORDER BY created_at ASC, commission_id ASC' ),
			'payouts'     => $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' ORDER BY payout_id' ),
			'clicks'      => get_option( 'proof_partner_clicks', array() ),
			'migrations'  => get_option( 'proof_partner_migrations', array() ),
		);
		return rest_ensure_response( array(
			'filename' => 'proof-partner-backup-' . gmdate( 'Ymd-His' ) . '.json',
			'json'     => wp_json_encode( $data ),
		) );
	}

	/** Компактно резюме на поръчка — без да напускаш приложението. */
	public function order_info( WP_REST_Request $req ) {
		$order = wc_get_order( (int) $req['id'] );
		if ( ! $order ) return new WP_Error( 'ap_404', 'Поръчката липсва.', array( 'status' => 404 ) );

		$items = array();
		foreach ( $order->get_items() as $it ) {
			$items[] = array( 'name' => $it->get_name(), 'qty' => (int) $it->get_quantity(), 'total' => round( (float) $it->get_total(), 2 ) );
		}

		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$lock     = $order->get_meta( PP_Attribution::META_LOCK );
		$log      = array_slice( (array) $order->get_meta( PP_Attribution::META_LOG ), -5 );

		return rest_ensure_response( array(
			'order_id'     => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => $statuses[ 'wc-' . $order->get_status() ] ?? $order->get_status(),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'd.m.Y H:i' ) : '',
			'payment'      => $order->get_payment_method_title(),
			'total'        => round( (float) $order->get_total(), 2 ),
			'shipping'     => round( (float) $order->get_shipping_total(), 2 ),
			'currency'     => $order->get_currency(),
			'customer'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'city'         => $order->get_billing_city(),
			'phone'        => $order->get_billing_phone(),
			'email'        => $order->get_billing_email(),
			'coupons'      => array_map( 'strtoupper', $order->get_coupon_codes() ),
			'partner_cost' => (string) $order->get_meta( '_proof_partner_cost' ),
			'items'        => $items,
			'lock'         => is_array( $lock ) ? $lock : null,
			'log'          => array_values( $log ),
			'edit_url'     => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
		) );
	}

	public function diagnostics_run() {
		return rest_ensure_response( PP_Diagnostics::run() );
	}

	public function recon_get() {
		// Самоуправление: лека самосверка (без наваксване — то е в Комисиони),
		// после отчетът показва състоянието СЛЕД поправките.
		try { PP_Engine::self_reconcile(); } catch ( \Throwable $e ) { proof_partner_log_shield( 'recon_get', $e ); }
		$report = (array) get_option( PP_Reconcile::OPT, array( 'ran_at' => null, 'missed' => array(), 'orphans' => array(), 'premature' => array() ) );
		$report['autofix_log'] = array_slice( (array) get_option( 'proof_partner_autofix', array() ), -12 );
		return rest_ensure_response( $report );
	}

	public function recon_run() {
		$report = PP_Reconcile::build_report();
		update_option( PP_Reconcile::OPT, $report, false );
		return rest_ensure_response( $report );
	}

	public function recon_fix( WP_REST_Request $req ) {
		$b    = $req->get_json_params();
		$what = sanitize_key( $b['what'] ?? '' );
		if ( 'missed' === $what ) {
			$n = PP_Reconcile::fix_missed();
		} elseif ( 'orphans' === $what ) {
			$n = PP_Reconcile::fix_orphans();
		} elseif ( 'premature' === $what ) {
			$n = PP_Reconcile::fix_premature();
		} else {
			return new WP_Error( 'ap_fix', 'Unknown fix target.', array( 'status' => 400 ) );
		}
		$report = PP_Reconcile::build_report();
		update_option( PP_Reconcile::OPT, $report, false );
		return rest_ensure_response( array( 'fixed' => $n, 'report' => $report ) );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private function partner_names() {
		$names = array();
		foreach ( PP_Partners::all() as $p ) $names[ (int) $p->partner_id ] = $p->display_name;
		return $names;
	}

	private $backfills = 0;

	private function ledger_row_out( $r, $names ) {
		// Lazy backfill: rows created before the products column existed get
		// their snapshot computed once and persisted (capped per request).
		if ( ( null === $r->products || '' === $r->products ) && $r->order_id && 'commission' === $r->type && $this->backfills < 60 ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $r->order_id ) : false;
			if ( $order ) {
				global $wpdb;
				$r->products = PP_Engine::products_summary( $order );
				$wpdb->update( PP_Schema::table( 'commissions' ), array( 'products' => $r->products ), array( 'commission_id' => $r->commission_id ) );
				$this->backfills++;
			}
		}

		return array(
			'commission_id' => (int) $r->commission_id,
			'order_id'      => (int) $r->order_id,
			'partner_id'    => (int) $r->partner_id,
			'partner'       => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ),
			'coupon_code'   => $r->coupon_code,
			'type'          => $r->type,
			'base_amount'   => (float) $r->base_amount,
			'rate_applied'  => (float) $r->rate_applied,
			'amount'        => (float) $r->amount,
			'currency'      => $r->currency,
			'status'        => $r->status,
			'payout_id'     => (int) $r->payout_id,
			'products'      => (string) ( $r->products ?? '' ),
			'note'          => $r->note,
			'created_at'    => $r->created_at,
			'order_url'     => $r->order_id ? admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' ) : '',
		);
	}

	private function partner_out( $p, $with_detail = false ) {
		$user   = get_user_by( 'id', $p->user_id );
		$totals = PP_Partners::totals( $p->partner_id );
		$out    = array(
			'partner_id'        => (int) $p->partner_id,
			'display_name'      => $p->display_name,
			'email'             => $user ? $user->user_email : '',
			'status'            => $p->status,
			'rate'              => (float) $p->rate,
			'payment_method'    => $p->payment_method,
			'payment_details'   => (string) $p->payment_details,
			'legal_name'        => (string) ( $p->legal_name ?? '' ),
			'iban'              => (string) ( $p->iban ?? '' ),
			'bic'               => (string) ( $p->bic ?? '' ),
			'phone'             => (string) ( $p->phone ?? '' ),
			'channels'          => (string) ( $p->channels ?? '' ),
			'lifetime_imported' => (string) $p->lifetime_imported,
			'tracked_from'      => isset( $p->tracked_from ) && $p->tracked_from ? substr( (string) $p->tracked_from, 0, 10 ) : '',
			'pay_model'         => (string) ( $p->pay_model ?? 'commission' ),
			'company_name'      => (string) ( $p->company_name ?? '' ), 'company_id' => (string) ( $p->company_id ?? '' ), 'company_vat' => (string) ( $p->company_vat ?? '' ), 'company_address' => (string) ( $p->company_address ?? '' ), 'company_mol' => (string) ( $p->company_mol ?? '' ),
			'msg_behind' => (string) ( $p->msg_behind ?? '' ), 'msg_ahead' => (string) ( $p->msg_ahead ?? '' ), 'msg_wish_mid' => (string) ( $p->msg_wish_mid ?? '' ), 'msg_wish_end' => (string) ( $p->msg_wish_end ?? '' ), 'msg_wish_next' => (string) ( $p->msg_wish_next ?? '' ),
			'msg_preview' => class_exists( 'PP_Proof' ) ? PP_Proof::fixed_message( $p, true ) : array( 'text' => '', 'why' => '' ),
			'notes'             => (string) $p->notes,
			'created_at'        => $p->created_at,
			'totals'            => $totals,
			'coupons'           => array(),
		);
		foreach ( PP_Coupons::for_partner( $p->partner_id ) as $c ) {
			$out['coupons'][] = array(
				'id'          => (int) $c->id,
				'coupon_code' => $c->coupon_code,
				'wc_coupon_id'=> (int) $c->wc_coupon_id,
				'active'      => (int) $c->active,
				'ref_link'    => home_url( '/?ref=' . rawurlencode( $c->coupon_code ) ),
				'vanity'      => home_url( '/p/' . rawurlencode( strtoupper( $c->coupon_code ) ) ),
				'clicks_30d'  => PP_Coupons::clicks_30d( $c->coupon_code ),
				'edit_url'    => get_edit_post_link( $c->wc_coupon_id, 'raw' ),
			);
		}
		if ( $with_detail ) {
			global $wpdb;
			$names = $this->partner_names();
			$rows  = $wpdb->get_results( $wpdb->prepare(
				'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . " WHERE partner_id = %d AND type <> 'history' ORDER BY created_at DESC, commission_id DESC LIMIT 150",
				$p->partner_id
			) );
			$out['ledger'] = array_map( function ( $r ) use ( $names ) { return $this->ledger_row_out( $r, $names ); }, $rows );
			/* Proof: историята отделно — най-новите 30 + брой; не се меси с реалните движения */
			$out['history_count'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . " WHERE partner_id = %d AND type = 'history'", $p->partner_id ) );
			$hist = $out['history_count'] ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . " WHERE partner_id = %d AND type = 'history' ORDER BY created_at DESC, commission_id DESC LIMIT 30", $p->partner_id ) ) : array();
			$out['history'] = array_map( function ( $r ) use ( $names ) { return $this->ledger_row_out( $r, $names ); }, $hist );
			$out['shipments'] = class_exists( 'PP_Proof' ) ? PP_Proof::shipments_totals( $p->partner_id ) : null;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Endpoints                                                           */
	/* ------------------------------------------------------------------ */

	public function overview() {
		PP_Engine::sweep_async(); // след отговора — екранът е мигновен; тротлирана мрежа: отваряш Таблото → изпуснатото се наваксва
		global $wpdb;
		$t     = PP_Schema::table( 'commissions' );
		$names = $this->partner_names();

		$payable = $wpdb->get_results( "SELECT currency, SUM(amount) AS total FROM {$t} WHERE status = 'approved' GROUP BY currency" );
		$m_now   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE type='commission' AND status != 'reversed' AND created_at >= %s", gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) ) );
		$m_prev  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$t} WHERE type='commission' AND status != 'reversed' AND created_at >= %s AND created_at < %s", gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month', current_time( 'timestamp' ) ) ), gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) ) );
		$pending = $wpdb->get_results( "SELECT currency, SUM(amount) AS total FROM {$t} WHERE status = 'pending' GROUP BY currency" );

		$month_start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
		$month = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM {$t}
			 WHERE type = 'commission' AND status != 'reversed' AND created_at >= %s", $month_start
		) );

		$top = $wpdb->get_results( $wpdb->prepare(
			"SELECT partner_id, COUNT(*) AS cnt, SUM(amount) AS total FROM {$t}
			 WHERE type = 'commission' AND status != 'reversed' AND created_at >= %s
			 GROUP BY partner_id ORDER BY total DESC LIMIT 7", $month_start
		) );

		$recent = $wpdb->get_results( "SELECT * FROM {$t} WHERE type <> 'history' ORDER BY created_at DESC, commission_id DESC LIMIT 12" );

		$partners_active = count( PP_Partners::all( 'active' ) );

		return rest_ensure_response( array(
			'payable'  => array_map( function ( $r ) { return array( 'currency' => $r->currency, 'total' => round( (float) $r->total, 2 ) ); }, $payable ),
			'month_now'  => round( $m_now, 2 ),
			'month_prev' => round( $m_prev, 2 ),
			'pending'  => array_map( function ( $r ) { return array( 'currency' => $r->currency, 'total' => round( (float) $r->total, 2 ) ); }, $pending ),
			'month'    => array( 'count' => (int) $month->cnt, 'total' => round( (float) $month->total, 2 ) ),
			'partners_active' => $partners_active,
			'top'      => array_map( function ( $r ) use ( $names ) {
				return array( 'partner_id' => (int) $r->partner_id, 'partner' => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ), 'count' => (int) $r->cnt, 'total' => round( (float) $r->total, 2 ) );
			}, $top ),
			'recent'   => array_map( function ( $r ) use ( $names ) { return $this->ledger_row_out( $r, $names ); }, $recent ),
			'alerts'   => array_values( (array) get_option( 'proof_partner_alerts', array() ) ),
		) );
	}

	public function partners_list( WP_REST_Request $req ) {
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$search = strtolower( trim( (string) $req->get_param( 'search' ) ) );
		$out    = array();

		foreach ( PP_Partners::all( $status ) as $p ) {
			$row = $this->partner_out( $p );
			if ( '' !== $search ) {
				$hay = strtolower( $row['display_name'] . ' ' . $row['email'] . ' ' . implode( ' ', wp_list_pluck( $row['coupons'], 'coupon_code' ) ) );
				if ( false === strpos( $hay, $search ) ) continue;
			}
			$out[] = $row;
		}
		return rest_ensure_response( $out );
	}

	public function partners_get( WP_REST_Request $req ) {
		PP_Engine::sweep_async(); // след отговора — екранът е мигновен; тротлирано: профилът показва пълната картина
		$p = PP_Partners::get( (int) $req['id'] );
		if ( ! $p ) return new WP_Error( 'ap_404', 'Partner not found.', array( 'status' => 404 ) );
		return rest_ensure_response( $this->partner_out( $p, true ) );
	}

	public function partners_create( WP_REST_Request $req ) {
		$b = $req->get_json_params();
		$partner_id = PP_Partners::create( array(
			'display_name'    => $b['display_name'] ?? '',
			'email'           => $b['email'] ?? '',
			'rate'            => $b['rate'] ?? proof_partner_settings()['default_rate'],
			'payment_method'  => $b['payment_method'] ?? 'bank',
			'legal_name'      => $b['legal_name'] ?? '',
			'iban'            => $b['iban'] ?? '',
			'bic'             => $b['bic'] ?? '',
			'payment_details' => $b['payment_details'] ?? '',
			'notes'           => $b['notes'] ?? '',
			'tracked_from'    => $b['tracked_from'] ?? '',
		) );
		if ( is_wp_error( $partner_id ) ) return new WP_Error( 'ap_create', $partner_id->get_error_message(), array( 'status' => 400 ) );

		$coupon_warning = '';
		if ( ! empty( $b['coupon_code'] ) ) {
			$c = PP_Coupons::create_for_partner( $partner_id, $b['coupon_code'], sanitize_key( $b['coupon_type'] ?? 'percent' ), $b['coupon_amount'] ?? 5 );
			if ( is_wp_error( $c ) ) $coupon_warning = $c->get_error_message();
			elseif ( PP_Coupons::$last_warning ) $coupon_warning = PP_Coupons::$last_warning;
		}
		return rest_ensure_response( array( 'partner_id' => $partner_id, 'coupon_warning' => $coupon_warning ) );
	}

	public function partners_update( WP_REST_Request $req ) {
		$b   = $req->get_json_params();
		$res = PP_Partners::update( (int) $req['id'], array_intersect_key( (array) $b, array_flip( array( 'display_name', 'status', 'rate', 'payment_method', 'payment_details', 'legal_name', 'iban', 'bic', 'notes', 'tracked_from', 'lifetime_imported', 'pay_model', 'company_name', 'company_id', 'company_vat', 'company_address', 'company_mol', 'msg_behind', 'msg_ahead', 'msg_wish_mid', 'msg_wish_end', 'msg_wish_next' ) ) ) );
		if ( is_wp_error( $res ) ) return new WP_Error( 'ap_update', $res->get_error_message(), array( 'status' => 400 ) );
		return $this->partners_get( $req );
	}

	public function coupons_add( WP_REST_Request $req ) {
		$b = $req->get_json_params();
		$c = PP_Coupons::create_for_partner( (int) $req['id'], $b['coupon_code'] ?? '', sanitize_key( $b['coupon_type'] ?? 'percent' ), $b['coupon_amount'] ?? 5 );
		if ( is_wp_error( $c ) ) return new WP_Error( 'ap_coupon', $c->get_error_message(), array( 'status' => 400 ) );
		$resp = $this->partners_get( $req );
		if ( PP_Coupons::$last_warning && $resp instanceof WP_REST_Response ) {
			$data = $resp->get_data();
			$data['coupon_warning'] = PP_Coupons::$last_warning;
			$resp->set_data( $data );
		}
		return $resp;
	}

	public function coupons_toggle( WP_REST_Request $req ) {
		global $wpdb;
		$b   = $req->get_json_params();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'coupons' ) . ' WHERE id = %d', (int) $req['id'] ) );
		if ( ! $row ) return new WP_Error( 'ap_404', 'Coupon mapping not found.', array( 'status' => 404 ) );
		PP_Coupons::set_active( $row->id, ! empty( $b['active'] ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function commissions_list( WP_REST_Request $req ) {
		PP_Engine::sweep_async(); // след отговора — екранът е мигновен; тротлирано: екранът винаги показва актуалното
		global $wpdb;
		$t      = PP_Schema::table( 'commissions' );
		$names  = $this->partner_names();
		$status = sanitize_key( (string) $req->get_param( 'status' ) );
		$pid    = absint( $req->get_param( 'partner_id' ) );
		$per   = 20; // твърдо: 20 на страница — никакво влачене на стотици редове
		$page  = max( 1, absint( $req->get_param( 'page' ) ?: 1 ) );
		$off   = ( $page - 1 ) * $per;

		$where = array( '1=1' ); $args = array();
		if ( $status ) { $where[] = 'status = %s';     $args[] = $status; }
		else { $where[] = "type <> 'history'"; } /* „Всички" = реалните движения */
		if ( $pid )    { $where[] = 'partner_id = %d'; $args[] = $pid; }
		$w = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) : $wpdb->get_var( $total_sql ) );

		$sql  = "SELECT * FROM {$t} WHERE {$w} ORDER BY created_at DESC, commission_id DESC LIMIT {$per} OFFSET {$off}";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		// Per-status counts for the tab strip (respect partner filter, not status).
		$cw   = $pid ? $wpdb->prepare( 'WHERE partner_id = %d', $pid ) : '';
		$cnts = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$t} {$cw} GROUP BY status", OBJECT_K );
		$counts = array( 'all' => 0 );
		foreach ( array( 'pending', 'approved', 'paid', 'reversed' ) as $st ) {
			$counts[ $st ] = isset( $cnts[ $st ] ) ? (int) $cnts[ $st ]->c : 0;
			$counts['all'] += $counts[ $st ];
		}
		$counts['history'] = isset( $cnts['history'] ) ? (int) $cnts['history']->c : 0; /* Proof: старите поръчки, без пари — отделен таб, не влизат във „Всички" */

		return rest_ensure_response( array(
			'rows'   => array_map( function ( $r ) use ( $names ) { return $this->ledger_row_out( $r, $names ); }, $rows ),
			'counts' => $counts,
			'total'  => $total,
			'page'   => $page,
			'pages'  => max( 1, (int) ceil( $total / $per ) ),
		) );
	}

	public function commissions_status( WP_REST_Request $req ) {
		global $wpdb;
		$b   = $req->get_json_params();
		$to  = sanitize_key( $b['to'] ?? '' );
		$cid = (int) $req['id'];
		if ( ! in_array( $to, array( 'approved', 'reversed', 'pending' ), true ) ) return new WP_Error( 'ap_status', 'Invalid target status.', array( 'status' => 400 ) );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE commission_id = %d', $cid ) );
		if ( ! $row ) return new WP_Error( 'ap_404', 'Commission not found.', array( 'status' => 404 ) );
		if ( 'paid' === $row->status ) return new WP_Error( 'ap_paid', 'Paid rows are immutable — corrections happen via reversal rows.', array( 'status' => 400 ) );

		$wpdb->update( PP_Schema::table( 'commissions' ),
			array( 'status' => $to, 'note' => trim( $row->note . ' | manual: ' . $to ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'commission_id' => $cid )
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function payables( WP_REST_Request $req = null ) {
		$until = $req ? sanitize_text_field( (string) $req->get_param( 'until' ) ) : '';
		$names = $this->partner_names();
		$out   = array();
		foreach ( PP_Payouts::payable_balances( $until ) as $b ) {
			$p = PP_Partners::get( $b->partner_id );
			$out[] = array(
				'partner_id'      => (int) $b->partner_id,
				'partner'         => $names[ (int) $b->partner_id ] ?? ( '#' . $b->partner_id ),
				'currency'        => $b->currency,
				'net'             => round( (float) $b->net, 2 ),
				'rows'            => (int) $b->rows_cnt,
				'period_from'     => substr( (string) $b->period_from, 0, 10 ),
				'period_to'       => substr( (string) $b->period_to, 0, 10 ),
				'has_iban'        => $p && '' !== trim( (string) ( $p->iban ?? '' ) ),
				'payment_details' => $p ? (string) $p->payment_details : '',
			);
		}
		return rest_ensure_response( $out );
	}

	public function pay( WP_REST_Request $req ) {
		$b   = $req->get_json_params();
		$ids = array_filter( array_map( 'absint', (array) ( $b['partner_ids'] ?? array() ) ) );
		if ( empty( $ids ) ) return new WP_Error( 'ap_pay', 'No partners selected.', array( 'status' => 400 ) );

		$until  = sanitize_text_field( (string) ( $b['until'] ?? '' ) );
		$label  = sanitize_text_field( (string) ( $b['period_label'] ?? '' ) );
		$batch  = 'PAY-' . gmdate( 'Ymd-His' ) . ( count( $ids ) === 1 ? '-P' . reset( $ids ) : '' );
		$send_email = ! isset( $b['send_email'] ) || ! empty( $b['send_email'] );
		$report = PP_Payouts::create_batch( $ids, $batch, $until, $label, $send_email );
		return rest_ensure_response( array( 'batch' => $batch, 'report' => $report ) );
	}

	public function payouts_list() {
		global $wpdb;
		$names = $this->partner_names();
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' ORDER BY payout_id DESC LIMIT 150' );
		return rest_ensure_response( array_map( function ( $r ) use ( $names ) {
			return array(
				'payout_id'  => (int) $r->payout_id,
				'partner_id' => (int) $r->partner_id,
				'partner'    => $names[ (int) $r->partner_id ] ?? ( '#' . $r->partner_id ),
				'amount'     => (float) $r->amount,
				'currency'   => $r->currency,
				'note'       => (string) $r->note,
				'method'     => $r->method,
				'reference'  => $r->reference,
				'created_at' => $r->created_at,
			);
		}, $rows ) );
	}

	public function payouts_get( WP_REST_Request $req ) {
		global $wpdb;
		$names  = $this->partner_names();
		$payout = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE payout_id = %d', (int) $req['id'] ) );
		if ( ! $payout ) return new WP_Error( 'ap_404', 'Payout not found.', array( 'status' => 404 ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE payout_id = %d ORDER BY created_at ASC', (int) $req['id']
		) );

		return rest_ensure_response( array(
			'payout_id'  => (int) $payout->payout_id,
			'partner_id' => (int) $payout->partner_id,
			'partner'    => $names[ (int) $payout->partner_id ] ?? ( '#' . $payout->partner_id ),
			'amount'     => (float) $payout->amount,
			'currency'   => $payout->currency,
			'method'     => $payout->method,
			'reference'  => $payout->reference,
			'created_at' => $payout->created_at,
			'rows'       => array_map( function ( $r ) use ( $names ) { return $this->ledger_row_out( $r, $names ); }, $rows ),
		) );
	}

	public function payouts_notify( WP_REST_Request $req ) {
		$b = (array) $req->get_json_params();
		$r = PP_Payouts::notify_batch( sanitize_text_field( $b['batch'] ?? '' ) );
		return is_wp_error( $r ) ? $r : rest_ensure_response( $r );
	}

	public function payouts_csv( WP_REST_Request $req ) {
		global $wpdb;
		$batch  = sanitize_text_field( (string) $req->get_param( 'batch' ) );
		$format = sanitize_key( (string) $req->get_param( 'format' ) ); // '' | 'wise'
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE reference = %s ORDER BY payout_id ASC', $batch ) );
		if ( empty( $rows ) ) return new WP_Error( 'ap_404', 'No payouts in this batch.', array( 'status' => 404 ) );

		$files = array();
		if ( 'wise' === $format ) {
			// Точно по шаблоните на Wise. Партньори с IBAN → Send-to-bank-accounts;
			// без IBAN → Send-by-email (Wise им праща линк да си приберат сумата).
			$bank  = array( array( 'name', 'recipientEmail', 'paymentReference', 'receiverType', 'amountCurrency', 'amount', 'sourceCurrency', 'targetCurrency', 'IBAN' ) );
			$email = array( array( 'name', 'recipientEmail', 'paymentReference', 'receiverType', 'amountCurrency', 'amount', 'sourceCurrency', 'targetCurrency', 'type' ) );

			foreach ( $rows as $r ) {
				// Одит F11: документът чете СНИМКАТА от момента на партидата, не живия профил.
				$snap = ! empty( $r->snapshot ) ? json_decode( (string) $r->snapshot, true ) : null;
				if ( is_array( $snap ) ) {
					$name = trim( (string) ( $snap['legal_name'] ?? '' ) ) ?: (string) ( $snap['display_name'] ?? ( '#' . $r->partner_id ) );
					$iban = (string) ( $snap['iban'] ?? '' );
					$mail = (string) ( $snap['email'] ?? '' );
				} else {
					$p    = PP_Partners::get( $r->partner_id );
					$user = $p ? get_user_by( 'id', $p->user_id ) : null;
					$name = $p ? ( trim( (string) ( $p->legal_name ?? '' ) ) ?: $p->display_name ) : ( '#' . $r->partner_id );
					$iban = $p ? strtoupper( preg_replace( '/\s+/', '', (string) ( $p->iban ?? '' ) ) ) : '';
					$mail = $user ? $user->user_email : '';
				}
				$base = array(
					$name,
					$mail,
					'proof partner ' . $r->reference,
					'PERSON',
					'source',
					number_format( (float) $r->amount, 2, '.', '' ),
					$r->currency,
					$r->currency,
				);
				if ( '' !== $iban ) {
					$base[] = $iban;
					$bank[] = $base;
				} else {
					$base[]  = 'EMAIL';
					$email[] = $base;
				}
			}

			if ( count( $bank ) > 1 )  $files[] = array( 'filename' => 'wise-bank-' . $batch . '.csv',  'lines' => $bank );
			if ( count( $email ) > 1 ) $files[] = array( 'filename' => 'wise-email-' . $batch . '.csv', 'lines' => $email );
			if ( empty( $files ) ) return new WP_Error( 'ap_wise', 'No payable rows for Wise export.', array( 'status' => 400 ) );
		} else {
			$lines   = array();
			$lines[] = array( 'payout_id', 'partner', 'legal_name', 'email', 'amount', 'currency', 'method', 'iban', 'bic', 'payment_details', 'reference', 'date' );
			foreach ( $rows as $r ) {
				$p    = PP_Partners::get( $r->partner_id );
				$user = $p ? get_user_by( 'id', $p->user_id ) : null;
				$lines[] = array(
					$r->payout_id,
					$p ? $p->display_name : ( '#' . $r->partner_id ),
					$p ? (string) ( $p->legal_name ?? '' ) : '',
					$user ? $user->user_email : '',
					number_format( (float) $r->amount, 2, '.', '' ),
					$r->currency, $r->method,
					$p ? (string) ( $p->iban ?? '' ) : '',
					$p ? (string) ( $p->bic ?? '' ) : '',
					$p ? preg_replace( '/\s+/', ' ', (string) $p->payment_details ) : '',
					$r->reference, $r->created_at,
				);
			}
			$files[] = array( 'filename' => 'proof-partner-' . $batch . '.csv', 'lines' => $lines );
		}

		$out = array();
		foreach ( $files as $f ) {
			$fh = fopen( 'php://temp', 'w+' );
			fputs( $fh, "\xEF\xBB\xBF" );
			foreach ( $f['lines'] as $l ) fputcsv( $fh, $l );
			rewind( $fh );
			$out[] = array( 'filename' => $f['filename'], 'csv' => stream_get_contents( $fh ) );
			fclose( $fh );
		}

		// Back-compat shape + multi-file support.
		return rest_ensure_response( array(
			'filename' => $out[0]['filename'],
			'csv'      => $out[0]['csv'],
			'files'    => $out,
		) );
	}

	public function alerts_clear( WP_REST_Request $req = null ) {
		$b = $req ? (array) $req->get_json_params() : array();
		if ( ! empty( $b['time'] ) && isset( $b['message'] ) ) {
			$alerts = (array) get_option( 'proof_partner_alerts', array() );
			$alerts = array_values( array_filter( $alerts, function ( $a ) use ( $b ) {
				return ! ( ( $a['time'] ?? '' ) === $b['time'] && ( $a['message'] ?? '' ) === $b['message'] );
			} ) );
			update_option( 'proof_partner_alerts', $alerts, false );
			return rest_ensure_response( array( 'ok' => true, 'left' => count( $alerts ) ) );
		}
		delete_option( 'proof_partner_alerts' );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
