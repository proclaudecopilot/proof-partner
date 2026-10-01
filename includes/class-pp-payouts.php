<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Payouts {

	const CAP   = 'manage_woocommerce';
	const NONCE = 'proof_partner_payouts';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_init', array( $this, 'handle' ) );
	}

	public function menu() {
		add_submenu_page( 'proof-partner', 'Payouts', 'Payouts', self::CAP, 'proof-partner-payouts', array( $this, 'page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Net payable per partner per currency (approved commissions + approved
	 * negative reversal rows).
	 */
	public static function payable_balances( $until = '' ) {
		global $wpdb;
		$where = "status = 'approved'";
		if ( $until && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
			$where .= $wpdb->prepare( ' AND created_at <= %s', $until . ' 23:59:59' );
		}
		return $wpdb->get_results(
			'SELECT partner_id, currency, SUM(amount) AS net, COUNT(*) AS rows_cnt,
			        MIN(created_at) AS period_from, MAX(created_at) AS period_to
			 FROM ' . PP_Schema::table( 'commissions' ) . "
			 WHERE {$where}
			 GROUP BY partner_id, currency
			 HAVING net > 0
			 ORDER BY net DESC"
		);
	}

	/**
	 * Create payouts for selected partners. All approved rows (positive and
	 * negative) per partner+currency are marked paid and attached to the payout,
	 * so clawbacks net out exactly once.
	 *
	 * @return array report lines
	 */
	public static function create_batch( $partner_ids, $batch_ref, $until = '', $period_label = '', $send_email = true ) {
		global $wpdb;
		$t_led  = PP_Schema::table( 'commissions' );
		$t_pay  = PP_Schema::table( 'payouts' );
		$report = array();
		$now    = current_time( 'mysql' );
		$cutoff = ( $until && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) ? $until . ' 23:59:59' : '';

		foreach ( array_map( 'absint', $partner_ids ) as $pid ) {
			$partner = PP_Partners::get( $pid );
			if ( ! $partner ) { $report[] = "Partner #{$pid}: not found — skipped."; continue; }

			// Последна проверка на закона: „За изплащане" без Delivered НЕ се плаща.
			$check = $wpdb->get_results( $wpdb->prepare(
				"SELECT commission_id, order_id FROM {$t_led}
				 WHERE partner_id = %d AND status = 'approved' AND type = 'commission' AND order_id > 0", $pid
			) );
			$demoted = 0;
			$st_map  = proof_partner_order_statuses( wp_list_pluck( $check, 'order_id' ) );
			foreach ( $check as $cr ) {
				if ( proof_partner_status_is_won( $st_map[ (int) $cr->order_id ] ?? '' ) ) continue;
				$wpdb->update( $t_led, array( 'status' => 'pending', 'updated_at' => $now ), array( 'commission_id' => (int) $cr->commission_id ) );
				$demoted++;
			}
			if ( $demoted ) $report[] = sprintf( '%s: %d реда върнати в „Изчакващи" — поръчките им не са Delivered в момента.', $partner->display_name, $demoted );

			$cut_sql = $cutoff ? $wpdb->prepare( ' AND created_at <= %s', $cutoff ) : '';
			// Одит F02: партидата е СПИСЪК ОТ КОНКРЕТНИ РЕДОВЕ, замразен тук.
			// Сумата и по-късното маркиране работят върху същите ID-та.
			$eligible = $wpdb->get_results( $wpdb->prepare(
				"SELECT commission_id, currency, amount FROM {$t_led}
				 WHERE partner_id = %d AND status = 'approved'", $pid
			) . $cut_sql );
			$groups = array();
			foreach ( $eligible as $er ) {
				if ( ! isset( $groups[ $er->currency ] ) ) $groups[ $er->currency ] = array( 'net' => 0, 'ids' => array() );
				$groups[ $er->currency ]['net']  += (float) $er->amount;
				$groups[ $er->currency ]['ids'][] = (int) $er->commission_id;
			}

			foreach ( $groups as $cur => $gr ) {
				$g   = (object) array( 'currency' => $cur, 'net' => $gr['net'] );
				$ids = $gr['ids'];
				$net = round( (float) $g->net, 2 );
				if ( $net <= 0 ) {
					$report[] = sprintf( '%s (%s): net %s ≤ 0 — left for future netting.', $partner->display_name, $g->currency, number_format( $net, 2 ) );
					continue;
				}

				// Одит F02: пазач срещу двоен клик/refresh — същата партида в последните 10 мин.
				$dup = $wpdb->get_var( $wpdb->prepare(
					"SELECT payout_id FROM {$t_pay}
					 WHERE partner_id = %d AND currency = %s AND amount = %f AND created_at > %s LIMIT 1",
					$pid, $g->currency, $net, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS ) ) );
				if ( $dup ) {
					$report[] = sprintf( '%s (%s): идентична партида #%d от последните 10 мин — пропускам (вероятен повторен клик).', $partner->display_name, $g->currency, (int) $dup );
					continue;
				}

				// Одит F11: снимка на получателя В МОМЕНТА на партидата.
				// Wise файлът и справките четат нея — смяна на IBAN утре не пренаписва документа.
				$u_snap = $partner->user_id ? get_user_by( 'id', (int) $partner->user_id ) : null;
				$snapshot = wp_json_encode( array(
					'display_name' => $partner->display_name,
					'legal_name'   => (string) ( $partner->legal_name ?? '' ),
					'iban'         => strtoupper( preg_replace( '/\s+/', '', (string) ( $partner->iban ?? '' ) ) ),
					'email'        => $u_snap ? $u_snap->user_email : (string) ( $partner->email ?? '' ),
					'method'       => $partner->payment_method,
					'period'       => (string) $period_label,
					'ids'          => $ids,
				) );

				$wpdb->insert( $t_pay, array(
					'partner_id' => $pid,
					'amount'     => $net,
					'currency'   => $g->currency,
					'method'     => $partner->payment_method,
					'reference'  => $batch_ref,
					'note'       => sanitize_text_field( $period_label ),
					'created_by' => get_current_user_id(),
					'snapshot'   => $snapshot,
					'created_at' => $now,
				) );
				$payout_id = (int) $wpdb->insert_id;
				if ( ! $payout_id ) {
					$report[] = sprintf( '%s (%s): INSERT на партидата ПРОВАЛЕН — нито един ред не е пипнат.', $partner->display_name, $g->currency );
					continue;
				}
				proof_partner_feed_push( 'payout', sprintf( 'Изплащане: %s · %.2f %s', $partner->display_name, $net, $g->currency ) );


				$in = implode( ',', array_map( 'intval', $ids ) );
				$affected = (int) $wpdb->query( $wpdb->prepare(
					"UPDATE {$t_led} SET status = 'paid', payout_id = %d, updated_at = %s
					 WHERE commission_id IN ({$in}) AND status = 'approved'",
					$payout_id, $now
				) );
				if ( $affected !== count( $ids ) ) {
					// Ред е сменил статус между замразяването и маркирането — казваме го, не го крием.
					$msg = sprintf( '%s (%s): партида #%d — маркирани %d от %d реда! Ред е сменил статус междувременно. Провери преди Wise.', $partner->display_name, $g->currency, $payout_id, $affected, count( $ids ) );
					$report[] = '⚠️ ' . $msg;
					$alerts   = (array) get_option( 'proof_partner_alerts', array() );
					$alerts[] = array( 'partner_id' => $pid, 'time' => $now, 'message' => '⚠️ ' . $msg );
					update_option( 'proof_partner_alerts', array_slice( $alerts, -20 ), false );
				}

				$report[] = sprintf( '%s: payout #%d — %s %s (%d реда).', $partner->display_name, $payout_id, number_format( $net, 2 ), $g->currency, $affected );

				// F2: партньорът научава веднага, със справката — край на "платихте ли ми?".
				if ( $send_email && ! empty( proof_partner_settings()['email_on_payout'] ) ) {
					$u = $partner->user_id ? get_user_by( 'id', (int) $partner->user_id ) : null;
					$to = $u ? $u->user_email : $partner->email;
					if ( $to && is_email( $to ) ) {
						$brand  = proof_partner_brand_name();
						$accent = sanitize_hex_color( proof_partner_settings()['brand_accent'] ) ?: '#f47a49';
						$stmt   = home_url( '/partner?statement=' . $payout_id );
						$html   = '<div style="font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#3a2f2b;max-width:560px;margin:0 auto;padding:8px 4px">'
							. '<h2 style="color:' . esc_attr( $accent ) . ';font-size:19px;margin:0 0 14px">Изплатихме ти възнаграждение 🎉</h2>'
							. '<p>Здравей, ' . esc_html( current( explode( ' ', $partner->display_name ) ) ) . '!</p>'
							. '<p><strong>' . esc_html( number_format( $net, 2 ) . ' ' . $g->currency ) . '</strong>'
							. ( $period_label ? ' · ' . esc_html( str_replace( 'Период: ', 'за ', $period_label ) ) : '' ) . ' са на път към сметката ти.</p>'
							. '<p style="margin:22px 0"><a href="' . esc_url( $stmt ) . '" style="background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:999px;display:inline-block">Виж справката →</a></p>'
							. '<p style="font-size:12.5px;color:#8a7a74">' . esc_html( $brand ) . ' Партньори</p></div>';
						wp_mail( $to, $brand . ' — изплатено възнаграждение', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
					}
				}
			}
		}
		return $report;
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	public function handle() {
		if ( empty( $_REQUEST['ap_payout_action'] ) ) return;
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Insufficient permissions.' );
		check_admin_referer( self::NONCE );

		$action = sanitize_key( $_REQUEST['ap_payout_action'] );

		if ( 'create_batch' === $action ) {
			$ids = array_map( 'absint', (array) ( $_POST['partner_ids'] ?? array() ) );
			$ids = array_filter( $ids );
			if ( empty( $ids ) ) {
				wp_safe_redirect( add_query_arg( array( 'page' => 'proof-partner-payouts', 'ap_error' => rawurlencode( 'No partners selected.' ) ), admin_url( 'admin.php' ) ) );
				exit;
			}
			$batch_ref = 'BATCH-' . gmdate( 'Ymd-His' );
			$report    = self::create_batch( $ids, $batch_ref );
			set_transient( 'proof_partner_payout_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( array( 'page' => 'proof-partner-payouts', 'ap_batch' => rawurlencode( $batch_ref ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'pay_single' === $action ) {
			$pid = absint( $_GET['partner_id'] ?? 0 );
			if ( ! $pid ) $this->fail_redirect( 'Missing partner.' );
			$batch_ref = 'PAY-' . gmdate( 'Ymd-His' ) . '-P' . $pid;
			$report    = self::create_batch( array( $pid ), $batch_ref );
			set_transient( 'proof_partner_payout_report_' . get_current_user_id(), $report, 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( array( 'page' => 'proof-partner-payouts', 'ap_batch' => rawurlencode( $batch_ref ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( 'csv' === $action ) {
			$this->stream_csv( sanitize_text_field( wp_unslash( $_GET['batch'] ?? '' ) ) );
		}
	}

	private function fail_redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'proof-partner-payouts', 'ap_error' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function stream_csv( $batch_ref ) {
		global $wpdb;
		if ( '' === $batch_ref ) wp_die( 'Missing batch reference.' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE reference = %s ORDER BY payout_id ASC',
			$batch_ref
		) );
		if ( empty( $rows ) ) wp_die( 'No payouts in this batch.' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'proof-partner-' . $batch_ref ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel/bank portals
		fputcsv( $out, array( 'payout_id', 'partner', 'email', 'amount', 'currency', 'method', 'payment_details', 'reference', 'date' ) );

		foreach ( $rows as $r ) {
			$p    = PP_Partners::get( $r->partner_id );
			$user = $p ? get_user_by( 'id', $p->user_id ) : null;
			fputcsv( $out, array(
				$r->payout_id,
				$p ? $p->display_name : ( '#' . $r->partner_id ),
				$user ? $user->user_email : '',
				number_format( (float) $r->amount, 2, '.', '' ),
				$r->currency,
				$r->method,
				$p ? preg_replace( '/\s+/', ' ', (string) $p->payment_details ) : '',
				$r->reference,
				$r->created_at,
			) );
		}
		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public function page() {
		global $wpdb;

		if ( ! empty( $_GET['ap_view_payout'] ) ) {
			$this->payout_detail( absint( $_GET['ap_view_payout'] ) );
			return;
		}

		echo '<div class="wrap"><h1>Payouts</h1>';

		if ( ! empty( $_GET['ap_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $_GET['ap_error'] ) ) . '</p></div>';
		}
		$report = get_transient( 'proof_partner_payout_report_' . get_current_user_id() );
		if ( $report ) {
			delete_transient( 'proof_partner_payout_report_' . get_current_user_id() );
			echo '<div class="notice notice-success"><p><strong>Batch created:</strong><br>' . implode( '<br>', array_map( 'esc_html', $report ) ) . '</p>';
			if ( ! empty( $_GET['ap_batch'] ) ) {
				$csv = wp_nonce_url( add_query_arg( array(
					'page' => 'proof-partner-payouts', 'ap_payout_action' => 'csv',
					'batch' => rawurlencode( sanitize_text_field( wp_unslash( $_GET['ap_batch'] ) ) ),
				), admin_url( 'admin.php' ) ), self::NONCE );
				echo '<p><a class="button button-primary" href="' . esc_url( $csv ) . '">Download bank CSV</a></p>';
			}
			echo '</div>';
		}

		/* Payable balances */
		$balances = self::payable_balances();
		echo '<h2>Payable balances</h2>';

		if ( empty( $balances ) ) {
			echo '<p>Nothing payable right now.</p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
			wp_nonce_field( self::NONCE );
			echo '<input type="hidden" name="ap_payout_action" value="create_batch">';
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th style="width:30px"></th><th>Partner</th><th>Currency</th><th>Net payable</th><th>Ledger rows</th><th>Payment details</th><th>Orders</th><th></th></tr></thead><tbody>';
			foreach ( $balances as $b ) {
				$p          = PP_Partners::get( $b->partner_id );
				$orders_url = admin_url( 'admin.php?page=proof-partner-commissions&ap_status=approved&ap_partner=' . (int) $b->partner_id );
				$pay_url    = wp_nonce_url( add_query_arg( array(
					'page' => 'proof-partner-payouts', 'ap_payout_action' => 'pay_single', 'partner_id' => (int) $b->partner_id,
				), admin_url( 'admin.php' ) ), self::NONCE );
				printf(
					'<tr><td><input type="checkbox" name="partner_ids[]" value="%d" checked></td><td><a href="%s"><strong>%s</strong></a></td><td>%s</td><td><strong>%s</strong></td><td>%d</td><td>%s</td><td><a href="%s">View orders</a></td><td><a class="button button-small" href="%s" onclick="return confirm(\'Mark all payable rows for this partner as PAID and create the payout?\')">Pay now</a></td></tr>',
					(int) $b->partner_id,
					esc_url( admin_url( 'admin.php?page=proof-partner-classic&ap_view=' . (int) $b->partner_id ) ),
					esc_html( $p ? $p->display_name : ( '#' . $b->partner_id ) ),
					esc_html( $b->currency ),
					esc_html( number_format( (float) $b->net, 2 ) ),
					(int) $b->rows_cnt,
					esc_html( $p ? wp_trim_words( (string) $p->payment_details, 12 ) : '' ),
					esc_url( $orders_url ),
					esc_url( $pay_url )
				);
			}
			echo '</tbody></table>';
			echo '<p><button class="button button-primary button-hero" onclick="return confirm(\'Mark all approved rows for the selected partners as PAID and create payout records?\')">Create payout batch</button> ';
			echo '<span class="description">marks approved rows paid, creates payout records, then offers the bank CSV</span></p></form>';
		}

		/* History */
		$payouts = $wpdb->get_results( 'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' ORDER BY payout_id DESC LIMIT 100' );
		echo '<h2 style="margin-top:2em">History (last 100)</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>ID</th><th>Date</th><th>Partner</th><th>Amount</th><th>Method</th><th>Batch</th><th>CSV</th></tr></thead><tbody>';
		foreach ( $payouts as $r ) {
			$p   = PP_Partners::get( $r->partner_id );
			$csv = wp_nonce_url( add_query_arg( array(
				'page' => 'proof-partner-payouts', 'ap_payout_action' => 'csv', 'batch' => rawurlencode( $r->reference ),
			), admin_url( 'admin.php' ) ), self::NONCE );
			$view = admin_url( 'admin.php?page=proof-partner-payouts&ap_view_payout=' . (int) $r->payout_id );
			printf(
				'<tr><td><a href="%s"><strong>#%d</strong></a></td><td>%s</td><td>%s</td><td><strong>%s %s</strong></td><td>%s</td><td><code>%s</code></td><td><a href="%s">CSV</a></td></tr>',
				esc_url( $view ),
				(int) $r->payout_id,
				esc_html( $r->created_at ),
				esc_html( $p ? $p->display_name : ( '#' . $r->partner_id ) ),
				esc_html( number_format( (float) $r->amount, 2 ) ), esc_html( $r->currency ),
				esc_html( $r->method ),
				esc_html( $r->reference ),
				esc_url( $csv )
			);
		}
		if ( empty( $payouts ) ) echo '<tr><td colspan="7">No payouts yet.</td></tr>';
		echo '</tbody></table></div>';
	}

	/** Payout detail: every ledger row (order) this payout settled. */
	private function payout_detail( $payout_id ) {
		global $wpdb;
		$payout = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE payout_id = %d', $payout_id
		) );
		if ( ! $payout ) { echo '<div class="wrap"><h1>Payout not found</h1></div>'; return; }

		$p    = PP_Partners::get( $payout->partner_id );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'commissions' ) . ' WHERE payout_id = %d ORDER BY created_at ASC', $payout_id
		) );

		echo '<div class="wrap"><h1>Payout #' . (int) $payout->payout_id . ' — ' . esc_html( $p ? $p->display_name : ( '#' . $payout->partner_id ) ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=proof-partner-payouts' ) ) . '">&larr; back to Payouts</a></p>';
		echo '<p><strong>Amount:</strong> ' . esc_html( number_format( (float) $payout->amount, 2 ) . ' ' . $payout->currency )
			. ' &nbsp; <strong>Date:</strong> ' . esc_html( $payout->created_at )
			. ' &nbsp; <strong>Batch:</strong> <code>' . esc_html( $payout->reference ) . '</code></p>';

		echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Ledger #</th><th>Date</th><th>Order</th><th>Coupon</th><th>Type</th><th>Base</th><th>Rate</th><th>Amount</th><th>Note</th></tr></thead><tbody>';
		$sum = 0.0;
		foreach ( $rows as $r ) {
			$sum       += (float) $r->amount;
			$order_link = '';
			if ( $r->order_id ) {
				$o          = function_exists( 'wc_get_order' ) ? wc_get_order( $r->order_id ) : false;
				$order_link = $o ? $o->get_edit_order_url() : admin_url( 'post.php?post=' . (int) $r->order_id . '&action=edit' );
			}
			printf(
				'<tr><td>%d</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s%%</td><td><strong>%s %s</strong></td><td>%s</td></tr>',
				(int) $r->commission_id,
				esc_html( $r->created_at ),
				$r->order_id ? '<a href="' . esc_url( $order_link ) . '">#' . (int) $r->order_id . '</a>' : '—',
				esc_html( $r->coupon_code ),
				esc_html( $r->type ),
				esc_html( number_format( (float) $r->base_amount, 2 ) ),
				esc_html( rtrim( rtrim( number_format( (float) $r->rate_applied, 3 ), '0' ), '.' ) ),
				esc_html( number_format( (float) $r->amount, 2 ) ), esc_html( $r->currency ),
				esc_html( $r->note )
			);
		}
		echo '</tbody><tfoot><tr><th colspan="7" style="text-align:right">Total</th><th>' . esc_html( number_format( $sum, 2 ) . ' ' . $payout->currency ) . '</th><th></th></tr></tfoot></table></div>';
	}

	/** Уведомление за конкретна партида — вика се СЛЕД качването в Wise. */
	public static function notify_batch( $batch_ref ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . PP_Schema::table( 'payouts' ) . ' WHERE reference = %s', $batch_ref ) );
		if ( ! $rows ) return new WP_Error( 'ap_404', 'Няма такава партида.' );
		$sent = 0; $skipped = 0;
		foreach ( $rows as $r ) {
			if ( false !== strpos( (string) $r->note, '[уведомен' ) ) { $skipped++; continue; }
			$snap = ! empty( $r->snapshot ) ? json_decode( (string) $r->snapshot, true ) : array();
			$partner = PP_Partners::get( (int) $r->partner_id );
			$to = (string) ( $snap['email'] ?? '' );
			if ( ! $to && $partner && $partner->user_id ) { $u = get_user_by( 'id', (int) $partner->user_id ); $to = $u ? $u->user_email : ''; }
			if ( ! $to || ! is_email( $to ) ) { $skipped++; continue; }
			$first  = current( explode( ' ', $partner ? $partner->display_name : (string) ( $snap['display_name'] ?? '' ) ) );
			$brand  = proof_partner_brand_name();
			$accent = sanitize_hex_color( proof_partner_settings()['brand_accent'] ) ?: '#f47a49';
			$stmt   = home_url( '/partner?statement=' . (int) $r->payout_id );
			$html   = '<div style="font:15px/1.6 -apple-system,Segoe UI,Arial,sans-serif;color:#3a2f2b;max-width:560px;margin:0 auto;padding:8px 4px">'
				. '<h2 style="color:' . esc_attr( $accent ) . ';font-size:19px;margin:0 0 14px">Изплатихме ти възнаграждение 🎉</h2>'
				. '<p>Здравей, ' . esc_html( $first ) . '!</p>'
				. '<p><strong>' . esc_html( number_format( (float) $r->amount, 2 ) . ' ' . $r->currency ) . '</strong>'
				. ( $r->note ? ' · ' . esc_html( str_replace( 'Период: ', 'за ', $r->note ) ) : '' ) . ' са на път към сметката ти.</p>'
				. '<p style="margin:22px 0"><a href="' . esc_url( $stmt ) . '" style="background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:13px 30px;border-radius:999px;display:inline-block">Виж справката →</a></p>'
				. '<p style="font-size:12.5px;color:#8a7a74">' . esc_html( $brand ) . ' Партньори</p></div>';
			wp_mail( $to, $brand . ' — изплатено възнаграждение', $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			$wpdb->update( PP_Schema::table( 'payouts' ),
				array( 'note' => trim( $r->note . ' [уведомен ' . current_time( 'Y-m-d H:i' ) . ']' ) ),
				array( 'payout_id' => (int) $r->payout_id ) );
			$sent++;
		}
		return array( 'sent' => $sent, 'skipped' => $skipped );
	}
}
