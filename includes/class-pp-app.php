<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fullscreen Partners app. Lives at admin.php?page=proof-partner (the
 * top-level menu), hides the entire wp-admin chrome and renders the SPA.
 * Classic screens remain available as submenus (and via the exit link).
 *
 * Proof 2.1: същото приложение се рендира и на /partner/<slug> (входът за
 * мениджъра, PP_Manager) — като самостоятелна страница, без wp-admin.
 */
class PP_App {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/** Данните, с които стартира app.js — едни и същи в wp-admin и на /partner/<slug>. */
	public static function boot( array $extra = array() ) {
		$is_mgr = class_exists( 'PP_Manager' ) && PP_Manager::is_manager();
		return array_merge( array(
			'restUrl'    => esc_url_raw( rest_url( PP_Rest::NS . '/' ) ),
			'emailOnPayout' => (int) ! empty( proof_partner_settings()['email_on_payout'] ),
			'home'       => esc_url_raw( home_url() ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'user'       => wp_get_current_user()->display_name,
			'site'       => get_bloginfo( 'name' ),
			'currency'   => get_option( 'woocommerce_currency', 'EUR' ),
			'adminUrl'   => admin_url(),
			'classicUrl' => admin_url( 'admin.php?page=proof-partner-classic' ),
			'settingsUrl'=> admin_url( 'admin.php?page=proof-partner-settings' ),
			'defaultRate' => (float) proof_partner_settings()['default_rate'],
			'defaultCoupon' => (float) proof_partner_settings()['default_coupon_amount'],
			'sub_policy' => class_exists( 'PP_Offers' ) ? PP_Offers::global_policy() : null, // Proof 2.2: глобалната абонаментна политика (плейсхолдъри в картата)
			'logoUrl'    => ( proof_partner_settings()['brand_logo_url'] ?: '' ),
			'brand'      => proof_partner_brand_name(),
			// Proof 2.1: мениджър (роля proof_partner_manager) / администратор; „💶 Бонус“ в менюто —
			// администраторът винаги; мениджърът само ако бонусът е включен за профила му (вид „мениджър“).
			'isManager'  => (int) $is_mgr,
			'isAdmin'    => (int) ( current_user_can( 'manage_woocommerce' ) && ! $is_mgr ),
			'bonusNav'   => (int) ( class_exists( 'PP_Bonus' ) && ( $is_mgr ? PP_Bonus::enabled_for( get_current_user_id() ) : current_user_can( 'manage_woocommerce' ) ) ),
			'logoutUrl'  => '',
		), $extra );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_proof-partner' !== $hook ) return;

		$url = plugin_dir_url( PROOF_PARTNER_FILE );
		wp_enqueue_style( 'proof-partner-app', $url . 'assets/app.css', array(), PROOF_PARTNER_VERSION );
		wp_enqueue_script( 'proof-partner-app', $url . 'assets/app.js', array(), PROOF_PARTNER_VERSION, true );

		wp_add_inline_style( 'proof-partner-app', '#proof-partner-app, .ap-overlay, .ap-toasts { ' . proof_partner_palette_css() . ' }' );
		wp_add_inline_script( 'proof-partner-app', 'window.PROOF_PARTNER_BOOT = ' . wp_json_encode( self::boot() ) . ';', 'before' );
	}

	public static function render() {
		if ( ! current_user_can( PP_Manager::CAP ) ) wp_die( 'Insufficient permissions.' );
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

	/**
	 * Proof 2.1: самостоятелна страница с приложението (извън wp-admin) — за /partner/<slug>.
	 * Извиква се само след проверка на правата (PP_Manager::render). Печата целия документ.
	 */
	public static function render_front( array $extra = array() ) {
		if ( ! current_user_can( PP_Manager::CAP ) ) wp_die( 'Insufficient permissions.' );
		$url   = plugin_dir_url( PROOF_PARTNER_FILE );
		$brand = proof_partner_brand_name();
		?><!doctype html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="generator" content="proof-partner <?php echo esc_attr( PROOF_PARTNER_VERSION ); ?>">
<title><?php echo esc_html( $brand ); ?> — Партньорска книга</title>
<link rel="stylesheet" href="<?php echo esc_url( $url . 'assets/app.css?v=' . PROOF_PARTNER_VERSION ); ?>">
<style>
	html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #eef6fa; }
	#proof-partner-app, .ap-overlay, .ap-toasts { <?php echo esc_html( proof_partner_palette_css() ); ?> }
</style>
<script>window.PROOF_PARTNER_BOOT = <?php echo wp_json_encode( self::boot( $extra ) ); ?>;</script>
</head>
<body>
<div id="proof-partner-app" aria-live="polite">
	<div class="ap-booting">Партньорска книга се зарежда…</div>
</div>
<script src="<?php echo esc_url( $url . 'assets/app.js?v=' . PROOF_PARTNER_VERSION ); ?>"></script>
</body>
</html><?php
	}
}
