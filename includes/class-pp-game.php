<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Proof Partner — Играта (геймификация).
 * Точков ledger (append-only, огледало на паричния), реферали със заключени
 * монети, съдържание с ръчна верификация, класации, лотария.
 * ВСИЧКИ текстове и правила идват от конфига — нула hardcoded.
 */
class PP_Game {

	const OPT = 'proof_partner_game';

	private static $inst = null;
	public static function instance() { return self::$inst ?: self::$inst = new self(); }

	private function __construct() {
		add_action( 'proof_partner_commission_created', array( $this, 'on_commission' ), 10, 3 );
		add_action( 'proof_partner_commission_reversed', array( $this, 'on_reversal' ), 10, 1 );
		add_action( 'wp_ajax_proof_pp_content', array( $this, 'ajax_content_submit' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Конфиг: всичко editable                                             */
	/* ------------------------------------------------------------------ */

	/** Преглед в админа: временна конфигурация БЕЗ запис. */
	public static $preview_override = null;

	public static function config() {
		if ( is_array( self::$preview_override ) ) {
			return wp_parse_args( self::$preview_override, self::config_stored() );
		}
		return self::config_stored();
	}

	private static function config_stored() {
		$d = array(
			'enabled'        => 0,
			'from'           => '',
			'to'             => '',
			'grace_days'     => 14,
			'pts_sale'       => 30,
			'pts_content'    => 50,
			'pts_referral'   => 50,
			'min_order'      => 20,
			'content_cap'    => 5,
			'levels'         => "Бронз|0\nСребро|500\nЗлато|1500\nПлатина|3000",
			'hero_kick'      => 'Играта',
			'hero_title'     => 'Спечели седмица с катамаран из гръцките острови',
			'hero_sub'       => '12-метров катамаран за теб + петима. Капитан, храна и маршрут по островите — всичко е включено.',
			'hero_chips'     => "⛵ 7 дни\n👥 6 души\n🍽 храна\n🧭 капитан",
			'win_line'       => 'Щурвалът е за №1 по продажби. Играта свършва на {to}; броят се поръчки, направени до тогава и доставени до {final} — тогава обявяваме победителите.',
			'prize_p1'       => 'Катамаранът',
			'prize_p2'       => '1 500 €',
			'prize_p3'       => '1 500 €',
			'prize_content'  => '1 000 €',
			'prize_content_t'=> 'Короната на Твореца',
			'prize_content_d'=> 'най-много одобрено съдържание — дори със слаби продажби. Съвпадне ли с топ продавача — взима и двете.',
			'prize_ref'      => '1 000 €',
			'prize_ref_t'    => 'Короната на Свързващия',
			'prize_ref_d'    => 'най-много активни реферали — независимо от собствените ти продажби',
			'prize_lucky'    => '500 €',
			'prize_lucky_t'  => 'Лотарията',
			'prize_lucky_d'  => '1 печеливш на случаен принцип между всички играещи — точките нямат значение, шансът е равен',
			'way1'           => 'Всяка доставена поръчка над {min} € с твоя код или линк носи точки. Върната поръчка връща точките.',
			'way1_fine'      => 'Поръчки под {min} € носят комисиона, но не точки.',
			'way2'           => 'Качи линк към публичен клип/пост с твоя код. Преглеждаме до 48 ч. — одобреното носи точки.',
			'way2_fine'      => 'До {cap} одобрени на седмица · един линк се брои веднъж.',
			'way3'           => 'Приятел се регистрира през линка ти → точките са запазени, но заключени. Светват при първата му доставена продажба.',
			'way3_fine'      => 'Върне ли се тази продажба — монетата се заключва обратно.',
			'rules'          => "Броим само доставени поръчки над {min} €; финалът включва доставки до {grace} дни след {to}.\nВърната поръчка връща точките; върната първа продажба заключва обратно рефералната монета.\nПри равенство печели по-рано достигналият резултата.\nСъвпадащи данни между канещ и поканен минават ръчен преглед преди отключване.\nЛотарията се тегли с публично записан момент и метод — равен шанс за всеки играещ.",
			'empty_title'    => 'Първите ти {pts} точки са на едно споделяне разстояние',
			'empty_sub'      => 'Копирай линка си, пусни го в story — първата доставена поръчка ти носи точки, място в ранглистата и шанс за всичко горе.',
			'layout'         => 'dream,podium,prizes,ways,score,rank,missions,content,invite,diary,board,rules',
			'streak_weeks'   => 3,
			'streak_bonus'   => 50,
			'streak_txt'     => '🔥 Серия: {n} поредни седмици с продажба — бонус +{b} т.!',
			'overtake_notify'=> 1,
			'overtake_subj'  => 'Изпревариха те в Играта!',
			'overtake_body'  => "Здравей, {name}!\n\n{rival} току-що те измести от подиума по продажби. Сега си #{rank}.\nЕдна продажба може да те върне горе — линкът ти те чака в портала.",
			'digest_enabled' => 0,
			'digest_subj'    => 'Твоята седмица в Играта',
			'share_enabled'  => 1,
			'perks'          => "Бронз|старт\nСребро|приоритетна поддръжка\nЗлато|ранен достъп до нови продукти\nПлатина|персонални условия",
			'missions'       => array(), // [{icon,text,reward,deadline,target_type,target_n}]
			'draw'           => array(), // резултат от лотарията
		);
		$c = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $c ) ? $c : array(), $d );
	}

	public static function active() {
		$c = self::config();
		if ( empty( $c['enabled'] ) ) return false;
		$today = current_time( 'Y-m-d' );
		if ( $c['from'] && $today < $c['from'] ) return false;
		if ( $c['to'] && $today > gmdate( 'Y-m-d', strtotime( $c['to'] . ' + ' . (int) $c['grace_days'] . ' days' ) ) ) return false;
		return true;
	}

	/** Период за БРОЕНЕ на резултати (поръчки, направени във from..to). */
	public static function period() {
		$c = self::config();
		return array(
			'from' => ( $c['from'] ?: '1970-01-01' ) . ' 00:00:00',
			'to'   => ( $c['to'] ?: '2099-12-31' ) . ' 23:59:59',
		);
	}

	public static function tpl( $key ) {
		$c = self::config();
		$map = array(
			'{min}'   => rtrim( rtrim( number_format( (float) $c['min_order'], 2, '.', '' ), '0' ), '.' ),
			'{cap}'   => (int) $c['content_cap'],
			'{grace}' => (int) $c['grace_days'],
			'{to}'    => $c['to'] ? date_i18n( 'j F', strtotime( $c['to'] ) ) : '—',
			'{final}' => $c['to'] ? date_i18n( 'j F', strtotime( $c['to'] . ' + ' . (int) $c['grace_days'] . ' days' ) ) : '—',
			'{pts}'   => (int) $c['pts_sale'],
		);
		return strtr( (string) ( $c[ $key ] ?? '' ), $map );
	}

	public static function levels() {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) self::config()['levels'] ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) >= 2 && '' !== $p[0] ) $out[] = array( 'name' => $p[0], 'min' => (int) $p[1] );
		}
		usort( $out, function ( $a, $b ) { return $a['min'] <=> $b['min']; } );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Точков ledger                                                       */
	/* ------------------------------------------------------------------ */

	/** Награждава веднъж — UNIQUE ref_key пази от дубли. */
	public static function award( $partner_id, $type, $points, $ref_key, $ref_id = 0, $status = 'active', $note = '', $when = '' ) {
		global $wpdb;
		$t = PP_Schema::table( 'points' );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT point_id FROM {$t} WHERE ref_key = %s", $ref_key ) );
		if ( $exists ) return 0;
		$wpdb->insert( $t, array(
			'partner_id' => (int) $partner_id,
			'type'       => sanitize_key( $type ),
			'status'     => sanitize_key( $status ),
			'points'     => (int) $points,
			'ref_id'     => (int) $ref_id,
			'ref_key'    => sanitize_text_field( $ref_key ),
			'note'       => sanitize_text_field( $note ),
			'created_at' => $when ?: current_time( 'mysql' ),
		) );
		return (int) $wpdb->insert_id;
	}

	private static function set_status( $ref_key, $status, $note = null ) {
		global $wpdb;
		$data = array( 'status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $note ) $data['note'] = sanitize_text_field( $note );
		return $wpdb->update( PP_Schema::table( 'points' ), $data, array( 'ref_key' => $ref_key ) );
	}

	/** Комисиона създадена → точки за продажба + евентуално отключване на реферална монета. */
	public function on_commission( $order_id, $partner_id, $base_amount ) {
		try {
		if ( ! self::active() ) return;
		$c = self::config();
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		// само поръчки, НАПРАВЕНИ в периода
		$p = self::period();
		$created = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '';
		if ( $created < $p['from'] || $created > $p['to'] ) return;

		$pp_check = PP_Partners::get( (int) $partner_id );
		if ( $pp_check && PP_Engine::is_self_purchase( $order, $pp_check ) ) return; // самопокупка: нито точки, нито серия
		if ( (float) $base_amount >= (float) $c['min_order'] ) {
			// Точките носят датата на ПОРЪЧКАТА: доставка в grace периода
			// пак се брои в сезона, точно като комисионата в правилния месец.
			self::award( $partner_id, 'sale', (int) $c['pts_sale'], 'sale-' . (int) $order_id, $order_id, 'active',
				'Доставена поръчка #' . (int) $order_id, $created );
			$this->maybe_streak_bonus( (int) $partner_id, $c );
		}

		// отключване: първата доставена продажба на РЕФЕРИРАН партньор
		$partner = PP_Partners::get( (int) $partner_id );
		if ( $partner && ! empty( $partner->referred_by ) ) {
			$this->maybe_unlock_referral( $partner );
		}
		} catch ( \Throwable $e ) { proof_partner_log_shield( 'game_on_commission', $e ); }
	}

	/** Серии: N поредни ISO седмици с продажба → бонус (веднъж на седмица-връх). */
	public function maybe_streak_bonus( $partner_id, $c ) {
		$need = max( 2, (int) $c['streak_weeks'] );
		$bonus = (int) $c['streak_bonus'];
		if ( $bonus <= 0 ) return;
		global $wpdb;
		$weeks = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT YEARWEEK(created_at, 3) FROM " . PP_Schema::table( 'points' ) . "
			 WHERE partner_id = %d AND type = 'sale' AND status = 'active'
			 ORDER BY 1 DESC LIMIT 20", $partner_id ) );
		if ( count( $weeks ) < $need ) return;
		// консекутивност от най-новата назад
		$run = 1;
		for ( $i = 0; $i < count( $weeks ) - 1; $i++ ) {
			$a = (int) $weeks[ $i ]; $b = (int) $weeks[ $i + 1 ];
			$ay = intdiv( $a, 100 ); $aw = $a % 100;
			$prev = ( $aw > 1 ) ? ( $ay * 100 + $aw - 1 ) : (int) gmdate( 'oW', strtotime( ( $ay - 1 ) . '-12-28' ) );
			if ( $b === $prev ) { $run++; } else break;
		}
		if ( $run < $need ) return;
		$key = 'streak-' . $partner_id . '-' . $weeks[0];
		$txt = strtr( (string) $c['streak_txt'], array( '{n}' => $run, '{b}' => $bonus ) );
		if ( self::award( $partner_id, 'mission', $bonus, $key, 0, 'active', $txt ) ) {
			$pp = PP_Partners::get( $partner_id );
			proof_partner_feed_push( 'tier', '🔥 ' . ( $pp ? $pp->display_name : '#' . $partner_id ) . ': ' . $txt );
		}
	}

	/** Текуща серия (за показване в портала). */
	public static function streak_for( $partner_id ) {
		global $wpdb;
		$weeks = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT YEARWEEK(created_at, 3) FROM " . PP_Schema::table( 'points' ) . "
			 WHERE partner_id = %d AND type = 'sale' AND status = 'active' ORDER BY 1 DESC LIMIT 20", $partner_id ) );
		if ( ! $weeks ) return 0;
		$now = (int) current_time( 'oW' ); // текуща ISO
		$head = (int) $weeks[0];
		if ( $head < $now - 1 && ! ( $now % 100 === 1 ) ) return 0; // прекъсната (пази нова година грубо)
		$run = 1;
		for ( $i = 0; $i < count( $weeks ) - 1; $i++ ) {
			$a = (int) $weeks[ $i ]; $ay = intdiv( $a, 100 ); $aw = $a % 100;
			$prev = ( $aw > 1 ) ? ( $ay * 100 + $aw - 1 ) : (int) gmdate( 'oW', strtotime( ( $ay - 1 ) . '-12-28' ) );
			if ( (int) $weeks[ $i + 1 ] === $prev ) $run++; else break;
		}
		return $run;
	}

	/** Тик от sweep: подиум-стражата („изпревариха те") + седмичен дайджест. */
	public static function tick() {
		if ( ! self::active() ) return;
		$c = self::config();

		// --- Изпревариха те (на час) ---
		if ( ! empty( $c['overtake_notify'] ) && ! get_transient( 'proof_partner_podium_lock' ) ) {
			set_transient( 'proof_partner_podium_lock', 1, HOUR_IN_SECONDS );
			$cm = self::category_map();
			$rows = array();
			foreach ( $cm as $pid => $v ) if ( $v['sales'] > 0 ) $rows[] = array( 'pid' => $pid, 'n' => $v['sales'] );
			usort( $rows, function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
			$top = array_slice( wp_list_pluck( $rows, 'pid' ), 0, 3 );
			$old = (array) get_option( 'proof_partner_game_podium', array() );
			update_option( 'proof_partner_game_podium', $top, false );
			if ( $old ) {
				foreach ( $old as $old_pos => $pid ) {
					$new_pos = array_search( $pid, $top, true );
					if ( false === $new_pos || $new_pos > $old_pos ) {
						// паднал от/надолу в подиума
						$pp = PP_Partners::get( (int) $pid );
						if ( ! $pp ) continue;
						$rival_pid = $top[ $old_pos ] ?? 0;
						$rp = $rival_pid ? PP_Partners::get( (int) $rival_pid ) : null;
						$full = array_search( $pid, wp_list_pluck( $rows, 'pid' ), true );
						$body = strtr( (string) $c['overtake_body'], array(
							'{name}'  => current( explode( ' ', $pp->display_name ) ),
							'{rival}' => $rp ? $rp->display_name : 'Конкурент',
							'{rank}'  => false === $full ? '—' : ( $full + 1 ),
						) );
						$email = self::partner_email( $pp );
						if ( $email && function_exists( 'proof_partner_send_announce_mail' ) ) {
							proof_partner_send_announce_mail( $email, current( explode( ' ', $pp->display_name ) ), $body, (string) $c['overtake_subj'] );
						}
						proof_partner_feed_push( 'tier', '⚔️ Подиумът се размести: ' . $pp->display_name . ' отстъпи място' . ( $rp ? ' на ' . $rp->display_name : '' ) );
					}
				}
			}
		}

		// --- Седмичен дайджест (по 20 на пас, без cron) ---
		if ( ! empty( $c['digest_enabled'] ) ) {
			$st = (array) get_option( 'proof_partner_game_digest', array( 'week' => '', 'offset' => 0 ) );
			$wk = current_time( 'oW' );
			if ( $st['week'] !== $wk ) { $st = array( 'week' => $wk, 'offset' => 0 ); }
			$pm = self::points_map();
			arsort( $pm );
			$pids = array_keys( $pm );
			$batch = array_slice( $pids, (int) $st['offset'], 20 );
			foreach ( $batch as $rank0 => $pid ) {
				$pp = PP_Partners::get( (int) $pid );
				$email = $pp ? self::partner_email( $pp ) : '';
				if ( ! $email ) continue;
				$pos = (int) $st['offset'] + $rank0 + 1;
				$pts = $pm[ $pid ]['active'];
				$gap = $pos > 1 ? ( $pm[ $pids[ $pos - 2 ] ]['active'] - $pts ) : 0;
				$body = sprintf( "Здравей, %s!

Твоята седмица в Играта:
· Място: #%d от %d
· Точки: %d%s

Всяка доставена поръчка те качва нагоре — влез в портала за пълната картина.",
					current( explode( ' ', $pp->display_name ) ), $pos, count( $pids ), $pts,
					$gap > 0 ? sprintf( "
· До по-горния: %d т.", $gap ) : "
· Ти водиш! 🏆" );
				if ( function_exists( 'proof_partner_send_announce_mail' ) ) {
					proof_partner_send_announce_mail( $email, current( explode( ' ', $pp->display_name ) ), $body, (string) $c['digest_subj'] );
				}
			}
			$st['offset'] = (int) $st['offset'] + count( $batch );
			update_option( 'proof_partner_game_digest', $st, false );
		}
	}

	/** Публично име за класации: псевдоним ако е зададен. */
	public static function public_name( $pp ) {
		if ( ! $pp ) return '—';
		return ( ! empty( $pp->public_name ) ) ? $pp->public_name : $pp->display_name;
	}

	/** Има ли финализиран сезон за показване? */
	public static function has_final() {
		$a = (array) get_option( 'proof_partner_game_archive', array() );
		return ! empty( $a ) ? end( $a ) : null;
	}

	/** Приключване на сезона: архив + обявяване + изключване. */
	public static function finalize() {
		$c = self::config();
		$pm = self::points_map(); arsort( $pm );
		$cm = self::category_map();
		$name_of = function ( $pid ) { return self::public_name( PP_Partners::get( (int) $pid ) ); };
		$by = function ( $key ) use ( $cm ) {
			$rows = array();
			foreach ( $cm as $pid => $v ) if ( $v[ $key ] > 0 ) $rows[] = array( 'pid' => $pid, 'n' => $v[ $key ] );
			usort( $rows, function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
			return $rows;
		};
		$sales = $by( 'sales' );
		$board = array();
		$i = 0;
		foreach ( $pm as $pid => $v ) { $board[] = array( 'pos' => ++$i, 'name' => $name_of( $pid ), 'pts' => $v['active'] ); if ( $i >= 50 ) break; }
		$final = array(
			'season'    => ( $c['from'] ?: '?' ) . ' → ' . ( $c['to'] ?: '?' ),
			'closed_at' => current_time( 'mysql' ),
			'board'     => $board,
			'winners'   => array(
				'sales_1'  => isset( $sales[0] ) ? array( 'name' => $name_of( $sales[0]['pid'] ), 'n' => $sales[0]['n'], 'prize' => $c['prize_p1'] ) : null,
				'sales_2'  => isset( $sales[1] ) ? array( 'name' => $name_of( $sales[1]['pid'] ), 'n' => $sales[1]['n'], 'prize' => $c['prize_p2'] ) : null,
				'sales_3'  => isset( $sales[2] ) ? array( 'name' => $name_of( $sales[2]['pid'] ), 'n' => $sales[2]['n'], 'prize' => $c['prize_p3'] ) : null,
				'content'  => ( $cw = $by( 'content' ) ) && isset( $cw[0] ) ? array( 'name' => $name_of( $cw[0]['pid'] ), 'n' => $cw[0]['n'], 'prize' => $c['prize_content'] . ' · ' . $c['prize_content_t'] ) : null,
				'referral' => ( $rw = $by( 'refs' ) ) && isset( $rw[0] ) ? array( 'name' => $name_of( $rw[0]['pid'] ), 'n' => $rw[0]['n'], 'prize' => $c['prize_ref'] . ' · ' . $c['prize_ref_t'] ) : null,
				'lottery'  => ! empty( $c['draw']['winner_name'] ) ? array( 'name' => $c['draw']['winner_name'], 'prize' => $c['prize_lucky'] . ' · ' . $c['prize_lucky_t'] ) : null,
			),
		);
		$arch = (array) get_option( 'proof_partner_game_archive', array() );
		$arch[] = $final;
		update_option( 'proof_partner_game_archive', $arch, false );
		$c['enabled'] = 0;
		update_option( self::OPT, $c );
		proof_partner_feed_push( 'tier', '🏁 Сезонът е приключен: ' . $final['season'] . '. Класацията е замразена и обявена в портала.' );
		// имейл до победителите (макс 6)
		$mailed = array();
		foreach ( array( $sales[0] ?? null, $sales[1] ?? null, $sales[2] ?? null,
				( $cw[0] ?? null ), ( $rw[0] ?? null ) ) as $slot ) {
			if ( ! $slot ) continue;
			$pp = PP_Partners::get( (int) $slot['pid'] );
			$em = $pp ? self::partner_email( $pp ) : '';
			if ( $em && ! isset( $mailed[ $em ] ) && function_exists( 'proof_partner_send_announce_mail' ) ) {
				$mailed[ $em ] = 1;
				proof_partner_send_announce_mail( $em, current( explode( ' ', $pp->display_name ) ),
					"Честито! Сезонът приключи и ти си сред НАГРАДЕНИТЕ.

Влез в портала — финалното класиране е обявено, а ние ще се свържем с теб за наградата.",
					'🏆 Ти печелиш в Играта!' );
			}
		}
		return $final;
	}

	private static function partner_email( $pp ) {
		if ( ! empty( $pp->email ) && is_email( $pp->email ) ) return $pp->email;
		if ( ! empty( $pp->user_id ) ) { $u = get_userdata( (int) $pp->user_id ); if ( $u && is_email( $u->user_email ) ) return $u->user_email; }
		return '';
	}

	/** Перкове по ниво: Име => текст. */
	public static function perks_map() {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) self::config()['perks'] ) as $line ) {
			$p2 = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p2 ) >= 2 && '' !== $p2[0] ) $out[ $p2[0] ] = $p2[1];
		}
		return $out;
	}

	/** Личен рекорд: най-добрият месец по точки. */
	public static function best_month( $partner_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at, '%%Y-%%m') ym, SUM(points) pts FROM " . PP_Schema::table( 'points' ) . "
			 WHERE partner_id = %d AND status = 'active' GROUP BY ym ORDER BY pts DESC LIMIT 1", $partner_id ) );
	}

	private function maybe_unlock_referral( $partner ) {
		global $wpdb;
		$key = 'ref-' . (int) $partner->partner_id;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'points' ) . ' WHERE ref_key = %s', $key ) );
		if ( ! $row || 'locked' !== $row->status ) return;

		// Фрод гейт: съвпадащ IBAN между канещ и поканен → остава заключено + сигнал
		$referrer = PP_Partners::get( (int) $partner->referred_by );
		$ib1 = strtoupper( preg_replace( '/\s+/', '', (string) ( $partner->iban ?? '' ) ) );
		$ib2 = strtoupper( preg_replace( '/\s+/', '', (string) ( $referrer->iban ?? '' ) ) );
		if ( $referrer && '' !== $ib1 && $ib1 === $ib2 ) {
			self::set_status( $key, 'locked', 'фрод проверка: съвпадащ IBAN' );
			$alerts   = (array) get_option( 'proof_partner_alerts', array() );
			$alerts[] = array( 'partner_id' => (int) $referrer->partner_id, 'time' => current_time( 'mysql' ),
				'message' => sprintf( 'ИГРА: реферал %s → %s със СЪВПАДАЩ IBAN — монетата остава заключена до ръчен преглед.', $referrer->display_name, $partner->display_name ) );
			update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
			return;
		}

		self::set_status( $key, 'active', 'отключена: първа доставена продажба на ' . $partner->display_name );
		proof_partner_feed_push( 'tier', sprintf( '🎮 Монета отключена: %s (+%d т.) — %s направи първата си продажба',
			$referrer ? $referrer->display_name : '#' . $partner->referred_by, (int) $row->points, $partner->display_name ) );
	}

	/** Сторно на поръчка → сторно на точките + евентуален re-lock на монета. */
	public function on_reversal( $order_id ) {
		try {
		global $wpdb;
		$key = 'sale-' . (int) $order_id;
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'points' ) . ' WHERE ref_key = %s', $key ) );
		if ( $row && 'reversed' !== $row->status ) {
			self::set_status( $key, 'reversed', 'върната поръчка #' . (int) $order_id );
		}
		if ( ! $row ) return;

		// re-lock: ако това е била ЕДИНСТВЕНАТА доставена продажба на рефериран
		$partner = PP_Partners::get( (int) $row->partner_id );
		if ( ! $partner || empty( $partner->referred_by ) ) return;
		$live = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . PP_Schema::table( 'points' ) . "
			 WHERE partner_id = %d AND type = 'sale' AND status = 'active'", $partner->partner_id ) );
		if ( 0 === $live ) {
			self::set_status( 'ref-' . (int) $partner->partner_id, 'locked', 'заключена обратно: продажбата се върна' );
		}
		} catch ( \Throwable $e ) { proof_partner_log_shield( 'game_on_reversal', $e ); }
	}

	/** Реферална монета при активиране на поканен партньор. */
	public static function on_referred_registered( $new_partner_id, $referrer_id ) {
		if ( ! self::active() ) return;
		$c = self::config();
		self::award( (int) $referrer_id, 'referral', (int) $c['pts_referral'],
			'ref-' . (int) $new_partner_id, (int) $new_partner_id, 'locked', 'чака първа доставена продажба' );
	}

	/* ------------------------------------------------------------------ */
	/* Съдържание                                                          */
	/* ------------------------------------------------------------------ */

	public function ajax_content_submit() {
		nocache_headers();
		if ( ! is_user_logged_in() ) wp_send_json( array( 'ok' => false, 'msg' => 'Влез първо.' ) );
		$partner = PP_Partners::get_by_user( get_current_user_id() );
		if ( ! $partner || 'active' !== $partner->status ) wp_send_json( array( 'ok' => false, 'msg' => 'Нямаш активен партньорски профил.' ) );
		if ( ! self::active() ) wp_send_json( array( 'ok' => false, 'msg' => 'Играта не е активна в момента.' ) );

		$url = esc_url_raw( trim( (string) ( $_POST['url'] ?? '' ) ) );
		if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) wp_send_json( array( 'ok' => false, 'msg' => 'Постави валиден линк (https://…).' ) );

		global $wpdb;
		$t    = PP_Schema::table( 'content' );
		$hash = md5( strtolower( untrailingslashit( $url ) ) );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT content_id FROM {$t} WHERE url_hash = %s", $hash ) ) ) {
			wp_send_json( array( 'ok' => false, 'msg' => 'Този линк вече е предаден.' ) );
		}
		$cap  = (int) self::config()['content_cap'];
		$week = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE partner_id = %d AND status IN ('pending','approved') AND created_at >= %s",
			$partner->partner_id, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) ) );
		if ( $week >= $cap ) wp_send_json( array( 'ok' => false, 'msg' => sprintf( 'Лимитът е %d на седмица — пази ги за следващата.', $cap ) ) );

		$wpdb->insert( $t, array(
			'partner_id' => (int) $partner->partner_id,
			'url'        => $url,
			'url_hash'   => $hash,
			'status'     => 'pending',
			'created_at' => current_time( 'mysql' ),
		) );
		wp_send_json( array( 'ok' => true, 'msg' => 'Прието — преглеждаме до 48 часа.' ) );
	}

	public static function moderate( $content_id, $approve, $note = '' ) {
		global $wpdb;
		$t   = PP_Schema::table( 'content' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE content_id = %d", $content_id ) );
		if ( ! $row || 'pending' !== $row->status ) return new WP_Error( 'ap_game', 'Записът липсва или е обработен.' );
		$pts = $approve ? (int) self::config()['pts_content'] : 0;
		$wpdb->update( $t, array(
			'status'      => $approve ? 'approved' : 'rejected',
			'points'      => $pts,
			'note'        => sanitize_text_field( $note ),
			'reviewed_at' => current_time( 'mysql' ),
		), array( 'content_id' => (int) $content_id ) );
		if ( $approve ) {
			self::award( (int) $row->partner_id, 'content', $pts, 'content-' . (int) $content_id, $content_id, 'active', 'одобрено съдържание' );
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Класации и статистика                                               */
	/* ------------------------------------------------------------------ */

	/** Сезонни точки per партньор: map pid => [active, locked]. */
	public static function points_map() {
		global $wpdb;
		$p = self::period();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT partner_id,
			        SUM(CASE WHEN status='active' THEN points ELSE 0 END) AS act,
			        SUM(CASE WHEN status='locked' THEN points ELSE 0 END) AS lck
			 FROM " . PP_Schema::table( 'points' ) . "
			 WHERE created_at BETWEEN %s AND %s AND status != 'reversed'
			 GROUP BY partner_id", $p['from'], $p['to'] ), OBJECT_K );
		$out = array();
		foreach ( $rows as $pid => $r ) $out[ (int) $pid ] = array( 'active' => (int) $r->act, 'locked' => (int) $r->lck );
		return $out;
	}

	/** Категорийни бройки в периода: sales / content / referrals-active per партньор. */
	public static function category_map() {
		global $wpdb;
		$p = self::period();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT partner_id,
			        SUM(CASE WHEN type='sale' AND status='active' THEN 1 ELSE 0 END) AS sales,
			        SUM(CASE WHEN type='content' AND status='active' THEN 1 ELSE 0 END) AS content,
			        SUM(CASE WHEN type='referral' AND status='active' THEN 1 ELSE 0 END) AS refs
			 FROM " . PP_Schema::table( 'points' ) . "
			 WHERE created_at BETWEEN %s AND %s
			 GROUP BY partner_id", $p['from'], $p['to'] ), OBJECT_K );
		$out = array();
		foreach ( $rows as $pid => $r ) $out[ (int) $pid ] = array( 'sales' => (int) $r->sales, 'content' => (int) $r->content, 'refs' => (int) $r->refs );
		return $out;
	}

	/** Кариерни (all-time, не-сезонни) активни точки на партньор — за нивото. */
	public static function career_points( $partner_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(points),0) FROM " . PP_Schema::table( 'points' ) . "
			 WHERE partner_id = %d AND status = 'active'", $partner_id ) );
	}

	public static function level_for( $pts ) {
		$lv = self::levels();
		if ( empty( $lv ) ) return null;
		$cur = $lv[0]; $next = null;
		foreach ( $lv as $i => $l ) {
			if ( $pts >= $l['min'] ) { $cur = $l; $next = $lv[ $i + 1 ] ?? null; }
		}
		return array( 'cur' => $cur, 'next' => $next );
	}

	/** Лотария: тегли между играещите (≥1 точков ред в сезона), записва доказателството. */
	public static function draw() {
		$map = self::points_map();
		$pool = array();
		foreach ( $map as $pid => $v ) {
			$p = PP_Partners::get( $pid );
			if ( $p && 'active' === $p->status ) $pool[] = $pid;
		}
		if ( empty( $pool ) ) return new WP_Error( 'ap_draw', 'Няма играещи с точки — няма от кого да теглим.' );
		$c = self::config();
		if ( ! empty( $c['draw']['winner_id'] ) ) return new WP_Error( 'ap_draw', 'Лотарията вече е изтеглена: ' . $c['draw']['winner_name'] . ' (' . $c['draw']['at'] . ')' );
		$win_id = $pool[ wp_rand( 0, count( $pool ) - 1 ) ];
		$winner = PP_Partners::get( $win_id );
		$c['draw'] = array(
			'winner_id'   => (int) $win_id,
			'winner_name' => $winner->display_name,
			'at'          => current_time( 'mysql' ),
			'method'      => 'wp_rand',
			'pool'        => count( $pool ),
		);
		update_option( self::OPT, $c );
		proof_partner_feed_push( 'tier', sprintf( '🎲 Лотарията: %s печели (%d участници, %s)', $winner->display_name, count( $pool ), $c['draw']['at'] ) );
		return $c['draw'];
	}
}

PP_Game::instance();
