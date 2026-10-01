<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Порталният екран „Играта" — рендер по v5 mockup, всички текстове от конфига. */
class PP_Game_View {

	/** Финално класиране на приключен сезон. */
	public static function final_card( $final ) {
		?>
		<div class="pp-card">
			<h2>🏁 Сезонът приключи</h2>
			<p class="pp-hint"><?php echo esc_html( $final['season'] ); ?> · обявено <?php echo esc_html( date_i18n( 'j M Y', strtotime( $final['closed_at'] ) ) ); ?></p>
			<div class="gm-list">
				<?php foreach ( array( 'sales_1' => '⛵ №1 продажби', 'sales_2' => '🥈 №2 продажби', 'sales_3' => '🥉 №3 продажби', 'content' => '🎬 Короната на Твореца', 'referral' => '🤝 Короната на Свързващия', 'lottery' => '🎲 Лотарията' ) as $k => $lbl ) :
					$w = $final['winners'][ $k ] ?? null; if ( ! $w ) continue; ?>
				<div class="gm-row"><div class="m"><div class="nm"><?php echo esc_html( $lbl ); ?>: <?php echo esc_html( $w['name'] ); ?></div><div class="mt2"><?php echo esc_html( $w['prize'] ); ?></div></div></div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="pp-card">
			<h2>Финална ранглиста</h2>
			<div class="gm-list">
				<?php foreach ( array_slice( (array) $final['board'], 0, 10 ) as $r ) : ?>
				<div class="gm-row"><div class="pl"><?php echo (int) $r['pos']; ?></div><div class="m"><div class="nm"><?php echo esc_html( $r['name'] ); ?></div></div><span class="gm-pv"><?php echo number_format( (int) $r['pts'], 0, '', ' ' ); ?> т.</span></div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}


	private static function name_of( $pid, $me_id ) {
		if ( (int) $pid === (int) $me_id ) return null; // caller решава как да покаже "ти"
		$p = PP_Partners::get( (int) $pid );
		return $p ? $p->display_name : ( '#' . (int) $pid );
	}

	public static function pane( $partner, $data ) {
		global $wpdb;
		if ( ! PP_Game::active() && ( $final = PP_Game::has_final() ) ) {
			self::final_card( $final );
			return;
		}
		$c   = PP_Game::config();
		$me  = (int) $partner->partner_id;
		$pm  = PP_Game::points_map();
		$cm  = PP_Game::category_map();

		$mine   = $pm[ $me ] ?? array( 'active' => 0, 'locked' => 0 );
		$mycat  = $cm[ $me ] ?? array( 'sales' => 0, 'content' => 0, 'refs' => 0 );

		// Общо класиране по активни сезонни точки
		$board = array();
		foreach ( $pm as $pid => $v ) {
			$p = PP_Partners::get( $pid );
			if ( $p && 'active' === $p->status ) $board[] = array( 'pid' => $pid, 'name' => PP_Game::public_name( $p ), 'pts' => $v['active'], 'cat' => $cm[ $pid ] ?? array( 'sales' => 0, 'content' => 0, 'refs' => 0 ) );
		}
		usort( $board, function ( $a, $b ) { return $b['pts'] <=> $a['pts']; } );
		$myrank = 0; $total = count( $board );
		foreach ( $board as $i => $r ) if ( $r['pid'] === $me ) { $myrank = $i + 1; break; }
		$gap_up = ( $myrank > 1 ) ? ( $board[ $myrank - 2 ]['pts'] - $mine['active'] ) : 0;
		$gap_nm = ( $myrank > 1 ) ? $board[ $myrank - 2 ]['name'] : '';

		// Категорийни класации (за подиум/корони/дистанции)
		$by = function ( $key ) use ( $cm ) {
			$rows = array();
			foreach ( $cm as $pid => $v ) if ( $v[ $key ] > 0 ) $rows[] = array( 'pid' => $pid, 'n' => $v[ $key ] );
			usort( $rows, function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
			return $rows;
		};
		$sales_b = $by( 'sales' ); $cont_b = $by( 'content' ); $refs_b = $by( 'refs' );
		$rank_in = function ( $rows ) use ( $me ) { foreach ( $rows as $i => $r ) if ( $r['pid'] === $me ) return $i + 1; return 0; };
		$leadtxt = function ( $rows, $unit ) use ( $me ) {
			if ( empty( $rows ) ) return 'още никой не води';
			$top = $rows[0];
			$p   = PP_Partners::get( $top['pid'] );
			$nm  = ( $top['pid'] === $me ) ? 'ти' : ( $p ? current( explode( ' ', PP_Game::public_name( $p ) ) ) : '#' . $top['pid'] );
			return sprintf( 'Води: %s · %d %s', $nm, $top['n'], $unit );
		};

		// Ниво (кариерно)
		$career = PP_Game::career_points( $me );
		$lv     = PP_Game::level_for( $career );
		$levels = PP_Game::levels();

		// Дневник: последните 10 точкови реда
		$diary = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'points' ) . ' WHERE partner_id = %d ORDER BY point_id DESC LIMIT 10', $me ) );

		// Моето съдържание + реферали
		$content = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'content' ) . ' WHERE partner_id = %d ORDER BY content_id DESC LIMIT 8', $me ) );
		$refrows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'points' ) . " WHERE partner_id = %d AND type = 'referral' ORDER BY point_id DESC LIMIT 8", $me ) );

		$join_code = '';
		foreach ( ( $data['coupons'] ?? array() ) as $cp ) if ( ! empty( $cp->active ) ) { $join_code = strtoupper( $cp->coupon_code ); break; }
		$join_url = $join_code ? home_url( '/join/' . rawurlencode( $join_code ) ) : '';

		$end_ts = $c['to'] ? strtotime( $c['to'] . ' 23:59:59' ) : 0;
		$chips  = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', (string) $c['hero_chips'] ) ) );
		$rules  = array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', PP_Game::tpl( 'rules' ) ) ) );
		?>

		<?php if ( 0 === $mine['active'] && 0 === $mine['locked'] ) : ?>
		<div class="pp-card gm-empty">
			<div class="gm-empty-i">🌱</div>
			<h2><?php echo esc_html( PP_Game::tpl( 'empty_title' ) ); ?></h2>
			<p><?php echo esc_html( PP_Game::tpl( 'empty_sub' ) ); ?></p>
			<?php if ( $join_url ) : ?><button type="button" class="pp-btn" data-copy="<?php echo esc_attr( home_url( '/p/' . rawurlencode( $join_code ) ) ); ?>">Копирай моя линк</button><?php endif; ?>
		</div>
		<?php endif; ?>

		<?php
		$layout = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $c['layout'] ?? '' ) ) ) ) );
		if ( empty( $layout ) ) $layout = array( 'dream', 'podium', 'prizes', 'ways', 'score', 'rank', 'missions', 'content', 'invite', 'diary', 'board', 'rules' );
		echo '<style>.gm-flex{display:flex;flex-direction:column}';
		foreach ( $layout as $ord => $sec_key ) printf( '.gm-flex .sec[data-sec="%s"]{order:%d}', esc_attr( $sec_key ), (int) $ord );
		echo '.gm-flex .sec{order:99}</style><div class="gm-flex">';
		?>
		<div class="gm-dream sec" data-sec="dream">
			<div class="gm-dream-t">
				<span class="gm-kick"><?php echo esc_html( $c['hero_kick'] ); ?><?php echo $c['to'] ? ' · до ' . esc_html( date_i18n( 'j F', strtotime( $c['to'] ) ) ) : ''; ?></span>
				<h1><?php echo esc_html( $c['hero_title'] ); ?></h1>
				<p><?php echo esc_html( $c['hero_sub'] ); ?></p>
				<?php if ( $end_ts ) : ?>
				<div class="gm-cd" data-end="<?php echo (int) $end_ts; ?>">
					<span class="u"><b data-u="d">–</b><i>дни</i></span>
					<span class="u"><b data-u="h">–</b><i>часа</i></span>
					<span class="u"><b data-u="m">–</b><i>мин</i></span>
				</div>
				<?php endif; ?>
				<?php if ( $chips ) : ?><div class="gm-chips"><?php foreach ( $chips as $ch ) echo '<span>' . esc_html( $ch ) . '</span>'; ?></div><?php endif; ?>
			</div>
			<div class="gm-win"><?php echo esc_html( PP_Game::tpl( 'win_line' ) ); ?></div>
		</div>

		<div class="pp-card sec" data-sec="podium">
			<h2>🛒 Подиум по продажби · на живо</h2>
			<div class="gm-podium">
				<?php
				$slot = function ( $rows, $i, $prize, $cls ) use ( $me ) {
					$r = $rows[ $i ] ?? null;
					$who = '—';
					if ( $r ) {
						$p = PP_Partners::get( $r['pid'] );
						$nm = ( $r['pid'] === $me ) ? 'ти!' : ( $p ? current( explode( ' ', PP_Game::public_name( $p ) ) ) : '#' . $r['pid'] );
						$who = esc_html( $nm . ' · ' . $r['n'] );
					}
					printf( '<div class="gm-pod %s"><div class="i">%s</div><div class="a">%s</div><div class="w">%s място</div><div class="n">%s</div></div>',
						esc_attr( $cls ), 1 === $i + 1 ? '⛵' : ( 2 === $i + 1 ? '🥈' : '🥉' ), esc_html( $prize ), (int) ( $i + 1 ) . '-' . ( 1 === $i + 1 ? 'во' : ( 2 === $i + 1 ? 'ро' : 'то' ) ), $who );
				};
				$slot( $sales_b, 1, $c['prize_p2'], 'p2' );
				$slot( $sales_b, 0, $c['prize_p1'], 'p1' );
				$slot( $sales_b, 2, $c['prize_p3'], 'p3' );
				?>
			</div>
		</div>

		<div class="pp-card gm-prize sec" data-sec="prizes"><div class="i">🎬</div><div><b><?php echo esc_html( $c['prize_content'] ); ?> · <?php echo esc_html( $c['prize_content_t'] ); ?></b><p><?php echo esc_html( $c['prize_content_d'] ); ?></p><span class="now"><?php echo esc_html( $leadtxt( $cont_b, 'одобрени' ) ); ?></span></div></div>
		<div class="pp-card gm-prize sec" data-sec="prizes"><div class="i">🤝</div><div><b><?php echo esc_html( $c['prize_ref'] ); ?> · <?php echo esc_html( $c['prize_ref_t'] ); ?></b><p><?php echo esc_html( $c['prize_ref_d'] ); ?></p><span class="now"><?php echo esc_html( $leadtxt( $refs_b, 'активни' ) ); ?></span></div></div>
		<div class="pp-card gm-prize lucky sec" data-sec="prizes"><div class="i">🎲</div><div><b><?php echo esc_html( $c['prize_lucky'] ); ?> · <?php echo esc_html( $c['prize_lucky_t'] ); ?></b><p><?php echo esc_html( $c['prize_lucky_d'] ); ?></p></div></div>

		<div class="pp-card sec" data-sec="ways">
			<h2>Три пътя, едни точки</h2>
			<?php
			$ways = array(
				array( '1', 'Продавай', '+' . (int) $c['pts_sale'] . ' т. на продажба', PP_Game::tpl( 'way1' ), PP_Game::tpl( 'way1_fine' ), '' ),
				array( '2', 'Създавай', '+' . (int) $c['pts_content'] . ' т. на одобрено', PP_Game::tpl( 'way2' ), PP_Game::tpl( 'way2_fine' ), '' ),
				array( '3', 'Канù приятели', '🔒 +' . (int) $c['pts_referral'] . ' т., отключващи се', PP_Game::tpl( 'way3' ), PP_Game::tpl( 'way3_fine' ), 'lock' ),
			);
			foreach ( $ways as $w ) : ?>
			<div class="gm-way"><div class="n"><?php echo esc_html( $w[0] ); ?></div><div><h3><?php echo esc_html( $w[1] ); ?> <span class="pts <?php echo esc_attr( $w[5] ); ?>"><?php echo esc_html( $w[2] ); ?></span></h3><p><?php echo esc_html( $w[3] ); ?></p><div class="fine"><?php echo esc_html( $w[4] ); ?></div></div></div>
			<?php endforeach; ?>
		</div>

		<div class="pp-card gm-score sec" data-sec="score">
			<div class="lbl">Сезонни точки<?php echo $c['from'] ? ' · от ' . esc_html( date_i18n( 'j M', strtotime( $c['from'] ) ) ) : ''; ?></div>
			<div class="big"><?php echo number_format( $mine['active'], 0, '', ' ' ); ?><small> т.</small></div>
			<?php if ( $mine['locked'] > 0 ) : ?><span class="locked">🔒 още <?php echo (int) $mine['locked']; ?> т. чакат отключване</span><?php endif; ?>
			<?php $streak = PP_Game::streak_for( $me ); if ( $streak >= 2 ) : ?>
			<div style="margin-top:9px;font-weight:900;color:#cf5c35">🔥 Серия: <?php echo (int) $streak; ?> поредни седмици с продажба</div>
			<?php endif; ?>
			<?php $best = PP_Game::best_month( $me ); if ( $best && (int) $best->pts > 0 ) : ?>
			<div style="margin-top:5px;font-size:13px;font-weight:800;color:#93817a">Личен рекорд: <?php echo (int) $best->pts; ?> т. (<?php echo esc_html( date_i18n( 'M Y', strtotime( $best->ym . '-01' ) ) ); ?>)<?php if ( $mine['active'] > (int) $best->pts * 0.8 && $mine['active'] < (int) $best->pts ) echo ' — на крачка си да го подобриш!'; ?></div>
			<?php endif; ?>

			<?php if ( $lv ) : $need = $lv['next'] ? max( 0, $lv['next']['min'] - $career ) : 0;
				$pct = $lv['next'] ? min( 100, round( 100 * $career / max( 1, $lv['next']['min'] ) ) ) : 100; ?>
			<div class="gm-level">
				<div class="top"><span class="b">Ниво: <?php echo esc_html( $lv['cur']['name'] ); ?></span><span class="c">кариерно · не се нулира</span>
					<?php if ( $lv['next'] ) : ?><div class="need">още <b><?php echo (int) $need; ?> т.</b> до <?php echo esc_html( $lv['next']['name'] ); ?></div><?php endif; ?></div>
				<div class="gm-bar big"><i style="width:<?php echo (int) $pct; ?>%"></i></div>
				<?php if ( $lv['next'] ) : ?><div class="nums"><?php echo number_format( $career, 0, '', ' ' ); ?> / <?php echo number_format( $lv['next']['min'], 0, '', ' ' ); ?> т.</div><?php endif; ?>
				<?php $perks = PP_Game::perks_map(); ?>
				<div class="gm-ladder"><?php foreach ( $levels as $l ) printf( '<span class="%s">%s<br><b>%s</b>%s</span>',
					esc_attr( ( $career >= $l['min'] ? 'done' : '' ) . ( $l['name'] === $lv['cur']['name'] ? ' here' : '' ) ),
					esc_html( $l['name'] ), number_format( $l['min'], 0, '', ' ' ),
					isset( $perks[ $l['name'] ] ) ? '<br><i style="font-style:normal;font-size:10.5px">' . esc_html( $perks[ $l['name'] ] ) . '</i>' : '' ); ?></div>
			</div>
			<?php endif; ?>

			<div class="gm-break">
				<?php
				$mx = max( 1, $mine['active'] + $mine['locked'] );
				$brows = array(
					array( '🛒 Продажби · ' . $mycat['sales'], (int) $c['pts_sale'] * $mycat['sales'], 'c1', '' ),
					array( '🎬 Съдържание · ' . $mycat['content'], (int) $c['pts_content'] * $mycat['content'], 'c2', '' ),
					array( '🤝 Реферали · ' . ( $mycat['refs'] + (int) ( $mine['locked'] / max( 1, (int) $c['pts_referral'] ) ) ) . ' (' . $mycat['refs'] . ' активни)', $mycat['refs'] * (int) $c['pts_referral'] + $mine['locked'], 'c3', $mine['locked'] > 0 ? '🔒 ' : '' ),
				);
				foreach ( $brows as $b ) : ?>
				<div class="brow"><div class="t"><span><?php echo esc_html( $b[0] ); ?></span><b><?php echo esc_html( $b[3] ); ?><?php echo (int) $b[1]; ?> т.</b></div><div class="gm-bar"><i class="<?php echo esc_attr( $b[2] ); ?>" style="width:<?php echo (int) min( 100, round( 100 * $b[1] / $mx ) ); ?>%"></i></div></div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( $myrank ) : ?>
		<div class="pp-card sec" data-sec="rank" data-share="<?php echo esc_attr( wp_json_encode( array(
			'rank' => $myrank, 'pts' => $mine['active'], 'name' => current( explode( ' ', $partner->display_name ) ),
			'brand' => proof_partner_brand_name(), 'on' => (int) ! empty( PP_Game::config()['share_enabled'] ) ) ) ); ?>">
			<div class="gm-rank2"><div class="badge">#<?php echo (int) $myrank; ?></div>
				<div><div class="l">Общо класиране · от <?php echo (int) $total; ?> играещи</div>
				<?php if ( $gap_up > 0 ) : ?><div class="g">▲ <b><?php echo (int) $gap_up; ?> т.</b> те делят от #<?php echo (int) ( $myrank - 1 ); ?> (<?php echo esc_html( current( explode( ' ', $gap_nm ) ) ); ?>)</div><?php else : ?><div class="g">Ти водиш общото класиране 🏆</div><?php endif; ?></div>
			</div>
			<div class="gm-dist-h">Колко ти остава до наградите</div>
			<div class="gm-dist">
				<?php
				$rs = $rank_in( $sales_b ); $rc = $rank_in( $cont_b ); $rr = $rank_in( $refs_b );
				$drow = function ( $ico, $title, $sub, $bignum, $unit, $lead = false ) {
					printf( '<div class="d %s"><div class="dl"><span class="di">%s</span><div><div class="dt">%s</div><div class="ds">%s</div></div></div><div class="dr"><b>%s</b><span>%s</span></div></div>',
						$lead ? 'lead' : '', $ico, esc_html( $title ), esc_html( $sub ), esc_html( $bignum ), $unit );
				};
				$to1 = ( $rs > 1 && ! empty( $sales_b ) ) ? ( $sales_b[0]['n'] - $mycat['sales'] + 1 ) : 0;
				$drow( '⛵', 'Подиум продажби', $rs ? 'ти си #' . $rs . ( $rs <= 3 ? ' — на подиума си' : '' ) : 'още нямаш продажба', $rs === 1 ? '№1' : (string) max( 0, $to1 ), $rs === 1 ? 'в момента' : 'продажби<br>до №1', $rs === 1 );
				$to1c = ( $rc > 1 && ! empty( $cont_b ) ) ? ( $cont_b[0]['n'] - $mycat['content'] + 1 ) : 0;
				$drow( '🎬', $c['prize_content_t'], $rc ? 'ти си #' . $rc : 'още нямаш одобрено', $rc === 1 ? '№1' : (string) max( 0, $to1c ), $rc === 1 ? 'в момента' : 'одобрени<br>до №1', $rc === 1 );
				$to1r = ( $rr > 1 && ! empty( $refs_b ) ) ? ( $refs_b[0]['n'] - $mycat['refs'] + 1 ) : 0;
				$drow( '🤝', $c['prize_ref_t'], $rr === 1 ? 'водиш — дръж преднината' : ( $rr ? 'ти си #' . $rr : 'още нямаш активен' ), $rr === 1 ? '№1' : (string) max( 0, $to1r ), $rr === 1 ? 'в момента' : 'активни<br>до №1', $rr === 1 );
				?>
			</div>
			<button type="button" class="pp-btn" id="gm-share" style="margin-top:14px;width:100%">📤 Сподели мястото си в Story</button>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $c['missions'] ) ) : ?>
		<div class="pp-card sec" data-sec="missions">
			<h2>Мисии от <?php echo esc_html( proof_partner_brand_name() ); ?></h2>
			<?php foreach ( (array) $c['missions'] as $m ) :
				$prog = 0; $target = max( 1, (int) ( $m['target_n'] ?? 1 ) );
				switch ( $m['target_type'] ?? '' ) {
					case 'content_week':
						$prog = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'content' ) . " WHERE partner_id = %d AND status IN ('pending','approved') AND created_at >= %s", $me, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ) ) );
						break;
					case 'referrals_active': $prog = $mycat['refs']; break;
					case 'sales_month':
						$prog = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PP_Schema::table( 'points' ) . " WHERE partner_id = %d AND type='sale' AND status='active' AND created_at >= %s", $me, gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) ) );
						break;
				}
				$pctm = min( 100, round( 100 * $prog / $target ) );
			?>
			<div class="gm-mission">
				<div class="top"><h3><?php echo esc_html( ( $m['icon'] ?? '🎯' ) . ' ' . ( $m['text'] ?? '' ) ); ?></h3><span class="rew"><?php echo esc_html( $m['reward'] ?? '' ); ?></span></div>
				<?php if ( ! empty( $m['deadline'] ) ) : ?><div class="tags"><span class="mt hot">⏳ <?php echo esc_html( $m['deadline'] ); ?></span></div><?php endif; ?>
				<div class="gm-bar big"><i class="c1" style="width:<?php echo (int) $pctm; ?>%"></i></div>
				<div class="prg"><?php echo (int) $prog; ?> от <?php echo (int) $target; ?></div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="pp-card sec" data-sec="content">
			<h2>🎬 Предай съдържание</h2>
			<p class="pp-hint">Публичен клип, снимка или пост с твоя код — линкът стига.</p>
			<div class="gm-urlrow">
				<input type="url" id="gm-url" class="pp-input" placeholder="https://instagram.com/reel/…">
				<button type="button" class="pp-btn" id="gm-url-send">Изпрати за одобрение</button>
			</div>
			<div id="gm-url-msg" class="pp-hint" style="display:none"></div>
			<?php if ( $content ) : ?>
			<div class="gm-list">
				<?php foreach ( $content as $ct ) :
					$host = wp_parse_url( $ct->url, PHP_URL_HOST );
					$pill = 'approved' === $ct->status ? '<span class="gm-pill ok">+' . (int) $ct->points . ' т.</span>'
						: ( 'pending' === $ct->status ? '<span class="gm-pill wait">чака преглед</span>'
						: '<span class="gm-pill no">отхвърлено' . ( $ct->note ? ' · ' . esc_html( $ct->note ) : '' ) . '</span>' ); ?>
				<div class="gm-row"><div class="m"><div class="nm"><?php echo esc_html( $host ?: 'линк' ); ?></div><div class="mt2"><?php echo esc_html( mb_substr( $ct->url, 0, 48 ) ); ?>…</div></div><?php echo $pill; // безопасно: изграден горе ?></div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>

		<div class="pp-card sec" data-sec="invite">
			<h2>🤝 Покани приятел</h2>
			<?php if ( $join_url ) : ?>
			<div class="gm-linkbox"><code><?php echo esc_html( preg_replace( '#^https?://(www\.)?#', '', $join_url ) ); ?></code><button type="button" class="pp-copy" data-copy="<?php echo esc_attr( $join_url ); ?>">Копирай</button></div>
			<?php endif; ?>
			<?php if ( $refrows ) : ?>
			<div class="gm-list">
				<?php foreach ( $refrows as $rr2 ) :
					$rp = PP_Partners::get( (int) $rr2->ref_id );
					$nm = $rp ? $rp->display_name : ( 'Партньор #' . (int) $rr2->ref_id );
					if ( 'active' === $rr2->status ) { $pill = '<span class="gm-pill ok">⚡ +' . (int) $rr2->points . ' т.</span>'; $meta = 'активиран'; }
					elseif ( false !== strpos( (string) $rr2->note, 'фрод' ) ) { $pill = '<span class="gm-pill check">🔎 проверка</span>'; $meta = 'съвпадащи данни — преглеждаме'; }
					elseif ( 'reversed' === $rr2->status ) { $pill = '<span class="gm-pill no">сторно</span>'; $meta = 'продажбата се върна'; }
					else { $pill = '<span class="gm-pill lock">🔒 +' . (int) $rr2->points . ' т.</span>'; $meta = 'чака първа продажба'; } ?>
				<div class="gm-row"><div class="m"><div class="nm"><?php echo esc_html( $nm ); ?></div><div class="mt2"><?php echo esc_html( $meta ); ?></div></div><?php echo $pill; ?></div>
				<?php endforeach; ?>
			</div>
			<?php else : ?><p class="pp-hint">Поканените с твоя линк ще се редят тук — със заключените си монети.</p><?php endif; ?>
		</div>

		<?php if ( $diary ) : ?>
		<div class="pp-card sec" data-sec="diary">
			<h2>Последните ти точки</h2>
			<div class="gm-list">
				<?php foreach ( $diary as $d ) :
					$neg = 'reversed' === $d->status;
					$ico = array( 'sale' => '🛒', 'content' => '🎬', 'referral' => '🤝', 'mission' => '🎯', 'admin' => '⭐' )[ $d->type ] ?? '⭐'; ?>
				<div class="gm-row"><div class="ic"><?php echo esc_html( $neg ? '↩' : $ico ); ?></div><div class="m"><div class="nm"><?php echo esc_html( $d->note ?: $d->type ); ?></div><div class="mt2"><?php echo esc_html( date_i18n( 'j M, H:i', strtotime( $d->created_at ) ) ); ?></div></div><span class="gm-pv<?php echo $neg ? ' neg' : ( 'locked' === $d->status ? ' lk' : '' ); ?>"><?php echo $neg ? '−' : ( 'locked' === $d->status ? '🔒 +' : '+' ); ?><?php echo (int) $d->points; ?></span></div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $board ) : ?>
		<div class="pp-card sec" data-sec="board">
			<h2>Ранглистата</h2>
			<p class="pp-hint">Сезонни точки · виждат всички</p>
			<div class="gm-list">
				<?php foreach ( array_slice( $board, 0, 5 ) as $i => $r ) :
					$isme = $r['pid'] === $me;
					$pl = $i < 3 ? array( '🥇', '🥈', '🥉' )[ $i ] : (string) ( $i + 1 ); ?>
				<div class="gm-row<?php echo $isme ? ' meline' : ''; ?>"><div class="pl"><?php echo esc_html( $pl ); ?></div><div class="m"><div class="nm"><?php echo esc_html( $isme ? $r['name'] . ' — ти' : $r['name'] ); ?></div></div><span class="gm-pv"><?php echo number_format( $r['pts'], 0, '', ' ' ); ?> т.</span></div>
				<?php endforeach; ?>
				<?php if ( $myrank > 5 ) : ?>
				<div class="gm-dots">···</div>
				<div class="gm-row meline"><div class="pl"><?php echo (int) $myrank; ?></div><div class="m"><div class="nm"><?php echo esc_html( $partner->display_name ); ?> — ти</div><?php if ( $gap_up > 0 ) : ?><div class="mt2">▲ <?php echo (int) $gap_up; ?> т. до #<?php echo (int) ( $myrank - 1 ); ?></div><?php endif; ?></div><span class="gm-pv"><?php echo number_format( $mine['active'], 0, '', ' ' ); ?> т.</span></div>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( $rules ) : ?>
		<div class="pp-card gm-fair sec" data-sec="rules">
			<b>⚖️ Честна игра — правилата:</b>
			<ul><?php foreach ( $rules as $r ) echo '<li>' . esc_html( $r ) . '</li>'; ?></ul>
		</div>
		<?php endif; ?>

		</div><!-- /gm-flex -->
		<script>
		(function(){
			var cd = document.querySelector('.gm-cd');
			if (cd) {
				var end = parseInt(cd.getAttribute('data-end'), 10) * 1000;
				function tick(){
					var ms = Math.max(0, end - Date.now());
					cd.querySelector('[data-u=d]').textContent = Math.floor(ms/864e5);
					cd.querySelector('[data-u=h]').textContent = Math.floor(ms%864e5/36e5);
					cd.querySelector('[data-u=m]').textContent = Math.floor(ms%36e5/6e4);
				}
				tick(); setInterval(tick, 30000);
			}
			var shareBtn = document.getElementById('gm-share');
			if (shareBtn) {
				var shd = {};
				try { shd = JSON.parse(shareBtn.closest('[data-share]').getAttribute('data-share')) || {}; } catch (e0) {}
				if (!shd.on) { shareBtn.style.display = 'none'; }
				shareBtn.addEventListener('click', function () {
					var cv = document.createElement('canvas'); cv.width = 1080; cv.height = 1920;
					var x = cv.getContext('2d');
					var g = x.createLinearGradient(0, 0, 0, 1920);
					g.addColorStop(0, '#f8a41d'); g.addColorStop(1, '#f47a49');
					x.fillStyle = g; x.fillRect(0, 0, 1080, 1920);
					x.fillStyle = 'rgba(255,255,255,.14)';
					x.beginPath(); x.arc(540, 700, 430, 0, 7); x.fill();
					x.fillStyle = '#fff'; x.textAlign = 'center';
					x.font = '900 90px Nunito, sans-serif';
					x.fillText(shd.name || '', 540, 430);
					x.font = '900 330px Nunito, sans-serif';
					x.fillText('#' + (shd.rank || '?'), 540, 830);
					x.font = '900 96px Nunito, sans-serif';
					x.fillText((shd.pts || 0).toLocaleString('bg-BG') + ' точки', 540, 1010);
					x.font = '800 58px Nunito, sans-serif';
					x.fillText('Играта на ' + (shd.brand || ''), 540, 1700);
					cv.toBlob(function (blob) {
						var file = new File([blob], 'igra.png', { type: 'image/png' });
						if (navigator.canShare && navigator.canShare({ files: [file] })) {
							navigator.share({ files: [file] }).catch(function () {});
						} else {
							var a = document.createElement('a');
							a.href = URL.createObjectURL(blob); a.download = 'igra.png'; a.click();
						}
					}, 'image/png');
				});
			}
			var send = document.getElementById('gm-url-send');
			if (send) send.addEventListener('click', function(){
				var inp = document.getElementById('gm-url'), msg = document.getElementById('gm-url-msg');
				send.disabled = true;
				var fd = new FormData(); fd.append('action', 'proof_pp_content'); fd.append('url', inp.value.trim());
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){ send.disabled = false; msg.style.display = 'block'; msg.textContent = j.msg || '…'; if (j.ok) inp.value = ''; })
					.catch(function(){ send.disabled = false; msg.style.display = 'block'; msg.textContent = 'Мрежова грешка — опитай пак.'; });
			});
		})();
		</script>
		<?php
	}
}
