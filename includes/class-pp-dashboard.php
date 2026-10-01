<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Front-end partner dashboard: [proof_partner_dashboard]
 * Bulgarian UI — partners are BG influencers. Read-only.
 */
class PP_Dashboard {

	public function __construct() {
		add_shortcode( 'proof_partner_dashboard', array( $this, 'render' ) );
	}

	private function status_label( $status ) {
		$map = array(
			'pending'  => 'Изчакваща',
			'approved' => 'За изплащане',
			'paid'     => 'Изплатена',
			'reversed' => 'Сторнирана',
		);
		return $map[ $status ] ?? $status;
	}

	public function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="proof-partner-dash"><p>Моля, влезте в профила си, за да видите партньорското си табло.</p>'
				. wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ) )
				. '</div>';
		}

		$partner = PP_Partners::get_by_user( get_current_user_id() );
		if ( ! $partner ) {
			return '<div class="proof-partner-dash"><p>Този профил не е свързан с партньорска програма. Ако смятате, че това е грешка, свържете се с нас.</p></div>';
		}

		global $wpdb;
		$totals = PP_Partners::totals( $partner->partner_id );

		$month_start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );
		$month_sum   = (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT COALESCE(SUM(amount),0) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND status != 'reversed' AND created_at >= %s",
			$partner->partner_id, $month_start
		) );

		$recent = $wpdb->get_results( $wpdb->prepare(
			'SELECT created_at, order_id, amount, currency, status, type FROM ' . PP_Schema::table( 'commissions' ) . '
			 WHERE partner_id = %d ORDER BY created_at DESC LIMIT 30',
			$partner->partner_id
		) );

		$payouts = $wpdb->get_results( $wpdb->prepare(
			'SELECT payout_id, created_at, amount, currency, reference, note FROM ' . PP_Schema::table( 'payouts' ) . '
			 WHERE partner_id = %d ORDER BY payout_id DESC LIMIT 20',
			$partner->partner_id
		) );

		$coupons = array_filter( PP_Coupons::for_partner( $partner->partner_id ), function ( $c ) { return (int) $c->active === 1; } );

		/* №11: статистики */
		$months = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at,'%%Y-%%m') AS ym, SUM(amount) AS total, SUM(type='commission') AS cnt
			 FROM " . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND status != 'reversed' AND created_at >= %s
			 GROUP BY ym ORDER BY ym",
			$partner->partner_id, gmdate( 'Y-m-01', strtotime( '-5 months', current_time( 'timestamp' ) ) )
		) );
		$orders_30 = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND created_at >= %s",
			$partner->partner_id, gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS )
		) );
		$clicks_30 = 0; $day_clicks = array();
		$click_opt = get_option( PP_Coupons::CLICKS_OPT, array() );
		$cut30     = gmdate( 'Y-m-d', current_time( 'timestamp' ) - 30 * DAY_IN_SECONDS );
		foreach ( $coupons as $c ) {
			$code = wc_format_coupon_code( $c->coupon_code );
			foreach ( (array) $click_opt as $day => $codes ) {
				if ( $day < $cut30 || empty( $codes[ $code ] ) ) continue;
				$clicks_30 += (int) $codes[ $code ];
				$wd = (int) gmdate( 'N', strtotime( $day ) );
				$day_clicks[ $wd ] = ( $day_clicks[ $wd ] ?? 0 ) + (int) $codes[ $code ];
			}
		}
		$conversion = $clicks_30 ? round( 100 * $orders_30 / $clicks_30, 1 ) : null;
		$best_day   = '';
		if ( $day_clicks ) {
			arsort( $day_clicks );
			$names    = array( 1 => 'понеделник', 'вторник', 'сряда', 'четвъртък', 'петък', 'събота', 'неделя' );
			$best_day = $names[ array_key_first( $day_clicks ) ];
		}
		$prod_rows = $wpdb->get_col( $wpdb->prepare(
			'SELECT products FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE partner_id = %d AND type = 'commission' AND status != 'reversed' AND products != '' AND created_at >= %s LIMIT 300",
			$partner->partner_id, gmdate( 'Y-m-d', current_time( 'timestamp' ) - 90 * DAY_IN_SECONDS )
		) );
		$prod_count = array();
		foreach ( $prod_rows as $pr ) {
			foreach ( explode( ',', $pr ) as $item ) {
				$name = trim( preg_replace( '/\s*[-–]\s*\d+\s*(кути|бро|x|×).*/ui', '', $item ) );
				if ( mb_strlen( $name ) < 4 ) continue;
				$prod_count[ $name ] = ( $prod_count[ $name ] ?? 0 ) + 1;
			}
		}
		arsort( $prod_count );
		$top_products = array_slice( array_keys( $prod_count ), 0, 3 );
		$max_month    = 0.01;
		foreach ( $months as $m ) $max_month = max( $max_month, (float) $m->total );

		/* №13: маркетинг кит */
		$kit_code = ! empty( $coupons ) ? strtoupper( current( $coupons )->coupon_code ) : '';
		$kit_link = $kit_code ? home_url( '/p/' . rawurlencode( $kit_code ) ) : '';
		$kit_pct  = '';
		if ( $kit_code && function_exists( 'wc_get_coupon_id_by_code' ) ) {
			$wc_id = wc_get_coupon_id_by_code( strtolower( $kit_code ) );
			if ( $wc_id ) {
				$wc_c    = new WC_Coupon( $wc_id );
				$kit_pct = 'percent' === $wc_c->get_discount_type()
					? rtrim( rtrim( number_format( (float) $wc_c->get_amount(), 2 ), '0' ), '.' ) . '%'
					: rtrim( rtrim( number_format( (float) $wc_c->get_amount(), 2 ), '0' ), '.' ) . ' €';
			}
		}

		ob_start();
		?>
		<div class="proof-partner-dash" style="max-width:760px">
			<style>
				.proof-partner-dash table{width:100%;border-collapse:collapse;margin:0 0 1.2em}
				.proof-partner-dash th,.proof-partner-dash td{padding:8px 10px;border-bottom:1px solid #e5e5e5;text-align:left;font-size:14px}
				.proof-partner-dash .ap-cards{display:flex;flex-wrap:wrap;gap:12px;margin:0 0 1.4em}
				.proof-partner-dash .ap-card{flex:1 1 150px;background:#f7f7f5;border-radius:10px;padding:14px 16px}
				.proof-partner-dash .ap-card b{display:block;font-size:20px;margin-top:4px}
				.proof-partner-dash code{background:#f0f0ee;padding:2px 6px;border-radius:4px}
			</style>

			<h2>Здравейте, <?php echo esc_html( $partner->display_name ); ?>!</h2>
			<p>Вашата партньорска ставка: <strong><?php echo esc_html( rtrim( rtrim( number_format( (float) $partner->rate, 3 ), '0' ), '.' ) ); ?>%</strong> от стойността на продуктите във Вашите поръчки (без доставка).</p>

			<div class="ap-cards">
				<div class="ap-card">Този месец<b><?php echo esc_html( number_format( $month_sum, 2 ) ); ?> €</b></div>
				<div class="ap-card">Изчакващи<b><?php echo esc_html( number_format( $totals['pending'], 2 ) ); ?> €</b></div>
				<div class="ap-card">За изплащане<b><?php echo esc_html( number_format( $totals['approved'], 2 ) ); ?> €</b></div>
				<div class="ap-card">Изплатени<b><?php echo esc_html( number_format( $totals['paid'], 2 ) ); ?> €</b></div>
			</div>

			<h3>Вашите промо кодове</h3>
			<?php if ( empty( $coupons ) ) : ?>
				<p>Нямате активни кодове в момента.</p>
			<?php else : ?>
				<table><thead><tr><th>Код</th><th>Линк за споделяне</th></tr></thead><tbody>
				<?php foreach ( $coupons as $c ) : $link = home_url( '/?ref=' . rawurlencode( $c->coupon_code ) ); ?>
					<tr><td><code><?php echo esc_html( strtoupper( $c->coupon_code ) ); ?></code></td>
					<td><code><?php echo esc_html( $link ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p style="font-size:13px;color:#666">Комисионата се начислява, когато клиент използва Вашия код при поръчка. Линкът просто прилага кода автоматично.</p>
			<?php endif; ?>

			<?php if ( ! empty( $months ) || $clicks_30 ) : ?>
			<h3>Твоите резултати</h3>
			<div class="ap-cards">
				<div class="ap-card">Кликове (30 дни)<b><?php echo (int) $clicks_30; ?></b></div>
				<div class="ap-card">Поръчки (30 дни)<b><?php echo (int) $orders_30; ?></b></div>
				<div class="ap-card">Конверсия<b><?php echo null === $conversion ? '—' : esc_html( $conversion . '%' ); ?></b></div>
			</div>
			<?php if ( ! empty( $months ) ) : ?>
			<div style="display:flex;gap:10px;align-items:flex-end;height:110px;margin:0 0 6px">
				<?php foreach ( $months as $m ) : $h = max( 6, round( 92 * (float) $m->total / $max_month ) ); ?>
				<div style="flex:1;text-align:center">
					<div style="font-size:11px;font-weight:700"><?php echo esc_html( number_format( (float) $m->total, 0 ) ); ?> €</div>
					<div style="height:<?php echo (int) $h; ?>px;background:#2BB6EA;border-radius:8px 8px 0 0;opacity:.85"></div>
					<div style="font-size:11px;color:#888"><?php echo esc_html( mysql2date( 'M', $m->ym . '-01' ) ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
			<p style="font-size:13px;color:#666">
				<?php if ( $best_day ) : ?>Най-много кликове събираш в <strong><?php echo esc_html( $best_day ); ?></strong> — планирай публикациите си тогава.<?php endif; ?>
				<?php if ( $top_products ) : ?> Най-купувани през твоя код: <strong><?php echo esc_html( implode( ', ', $top_products ) ); ?></strong>.<?php endif; ?>
			</p>
			<?php endif; ?>

			<?php if ( $kit_code ) : ?>
			<h3>Готово за споделяне</h3>
			<div style="background:#fdebe9;border-radius:14px;padding:14px 16px;margin:0 0 1.2em">
				<p style="margin:0 0 8px"><strong>За story:</strong> <span id="ap-kit-story"><?php echo esc_html( strtr( (string) proof_partner_settings()['kit_story'], array( '{code}' => $kit_code, '{discount}' => $kit_pct ?: 'отстъпка', '{link}' => $kit_link, '{brand}' => proof_partner_brand_name(), '{name}' => current( explode( ' ', $partner->display_name ) ) ) ) ); ?></span>
				<button type="button" onclick="navigator.clipboard.writeText(document.getElementById('ap-kit-story').textContent).then(()=>this.textContent='Копирано ✓')" style="margin-left:8px;border:0;background:#f47a49;color:#fff;border-radius:999px;padding:5px 14px;font-weight:800;cursor:pointer">Копирай</button></p>
				<p style="margin:0"><strong>За пост:</strong> <span id="ap-kit-post"><?php echo esc_html( strtr( (string) proof_partner_settings()['kit_post'], array( '{code}' => $kit_code, '{discount}' => $kit_pct ?: 'отстъпка', '{link}' => $kit_link, '{brand}' => proof_partner_brand_name(), '{name}' => current( explode( ' ', $partner->display_name ) ) ) ) ); ?></span>
				<button type="button" onclick="navigator.clipboard.writeText(document.getElementById('ap-kit-post').textContent).then(()=>this.textContent='Копирано ✓')" style="margin-left:8px;border:0;background:#f47a49;color:#fff;border-radius:999px;padding:5px 14px;font-weight:800;cursor:pointer">Копирай</button></p>
			</div>
			<?php endif; ?>

			<h3>Последни комисиони</h3>
			<?php if ( empty( $recent ) ) : ?>
				<p>Все още няма начислени комисиони.</p>
			<?php else : ?>
				<table><thead><tr><th>Дата</th><th>Поръчка</th><th>Сума</th><th>Статус</th></tr></thead><tbody>
				<?php foreach ( $recent as $r ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></td>
						<td>#<?php echo (int) $r->order_id; ?></td>
						<td><?php echo esc_html( number_format( (float) $r->amount, 2 ) . ' ' . $r->currency ); ?></td>
						<td><?php echo esc_html( 'reversal' === $r->type ? 'Корекция' : $this->status_label( $r->status ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p style="font-size:13px;color:#666">Изчакващите комисиони стават „За изплащане" след кратък период на задържане (защита при връщане на поръчки).</p>
			<?php endif; ?>

			<h3>Изплащания</h3>
			<?php if ( empty( $payouts ) ) : ?>
				<p>Все още няма изплащания.</p>
			<?php else : ?>
				<table><thead><tr><th>Дата</th><th>Сума</th><th>Референция</th><th></th></tr></thead><tbody>
				<?php foreach ( $payouts as $r ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></td>
						<td><?php echo esc_html( number_format( (float) $r->amount, 2 ) . ' ' . $r->currency ); ?></td>
						<td><code><?php echo esc_html( $r->reference ); ?></code></td>
						<td><a href="<?php echo esc_url( home_url( '/partner?statement=' . (int) $r->payout_id ) ); ?>" target="_blank" rel="noopener">Справка ↗</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p style="font-size:13px;color:#666">„Справка" отваря месечния отчет за печат/PDF — за твоето счетоводство.</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
