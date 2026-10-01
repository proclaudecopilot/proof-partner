<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Admin {

	const CAP   = 'manage_woocommerce';
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

	private function notices() {
		if ( ! empty( $_GET['ap_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( $_GET['ap_error'] ) ) . '</p></div>';
		}
		if ( ! empty( $_GET['ap_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( ucwords( str_replace( '_', ' ', sanitize_key( $_GET['ap_msg'] ) ) ) ) . '.</p></div>';
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
