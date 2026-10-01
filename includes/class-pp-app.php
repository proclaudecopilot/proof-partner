<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fullscreen Partners app. Lives at admin.php?page=proof-partner (the
 * top-level menu), hides the entire wp-admin chrome and renders the SPA.
 * Classic screens remain available as submenus (and via the exit link).
 */
class PP_App {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_proof-partner' !== $hook ) return;

		$url = plugin_dir_url( PROOF_PARTNER_FILE );
		wp_enqueue_style( 'proof-partner-app', $url . 'assets/app.css', array(), PROOF_PARTNER_VERSION );
		wp_enqueue_script( 'proof-partner-app', $url . 'assets/app.js', array(), PROOF_PARTNER_VERSION, true );

		wp_add_inline_style( 'proof-partner-app', '#proof-partner-app, .ap-overlay, .ap-toasts { ' . proof_partner_palette_css() . ' }' );
		wp_add_inline_script( 'proof-partner-app', 'window.PROOF_PARTNER_BOOT = ' . wp_json_encode( array(
			'restUrl'    => esc_url_raw( rest_url( PP_Rest::NS . '/' ) ),
			'emailOnPayout' => (int) ! empty( proof_partner_settings()['email_on_payout'] ),
			'home'       => esc_url_raw( home_url() ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'user'       => wp_get_current_user()->display_name,
			'site'       => get_bloginfo( 'name' ),
			'currency'   => get_option( 'woocommerce_currency', 'EUR' ),
			'classicUrl' => admin_url( 'admin.php?page=proof-partner-classic' ),
			'settingsUrl'=> admin_url( 'admin.php?page=proof-partner-settings' ),
			'defaultRate' => (float) proof_partner_settings()['default_rate'],
			'defaultCoupon' => (float) proof_partner_settings()['default_coupon_amount'],
			'logoUrl'    => ( proof_partner_settings()['brand_logo_url'] ?: '' ),
			'brand'      => proof_partner_brand_name(),
		) ) . ';', 'before' );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Insufficient permissions.' );
		?>
		<style>
			/* Take over the viewport: kill wp-admin chrome on this screen only. */
			#adminmenumain, #adminmenuback, #adminmenuwrap, #wpadminbar, #wpfooter, #screen-meta, #screen-meta-links { display: none !important; }
			html.wp-toolbar { padding-top: 0 !important; }
			#wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
			#wpbody-content .notice, #wpbody-content .update-nag, #wpbody-content .updated, #wpbody-content .error { display: none !important; }
			html, body { height: 100%; overflow: hidden; }
		</style>
		<div id="proof-partner-app" aria-live="polite">
			<div class="ap-booting">Партньорска книга се зарежда…</div>
		</div>
		<?php
	}
}
