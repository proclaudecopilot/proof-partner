<?php
/**
 * Plugin Name: LOGADOR GitHub Updater
 * Description: Един token за сайта; всеки плъгин с ред „GitHub Plugin URI: owner/repo“ в header-а си се обновява от GitHub Releases през стандартния WP ъпдейт (Update now / auto-updates). Инсталира се сам от плъгините на LOGADOR (mu-plugins).
 * Version: 1.0.1
 * Author: LOGADOR
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'LOGADOR_GH_UPDATER_VERSION' ) ) return; // вече е зареден (от друго копие)
define( 'LOGADOR_GH_UPDATER_VERSION', '1.0.1' );

final class LOGADOR_GitHub_Updater {
	const OPT_TOKEN = 'logador_github_token';
	const CACHE     = 'logador_gh_releases';
	const TTL       = 6 * HOUR_IN_SECONDS;

	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth' ), 10, 2 );
		add_filter( 'extra_plugin_headers', function ( $h ) { $h[] = 'GitHub Plugin URI'; return $h; } );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_logador_gh_save', array( $this, 'save' ) );
		add_action( 'admin_post_logador_gh_check', array( $this, 'check' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/* ---------- кои плъгини следим ---------- */
	public static function tracked() {
		if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $h ) {
			$repo = trim( (string) ( $h['GitHub Plugin URI'] ?? '' ) );
			$repo = preg_replace( '#^https?://github\.com/#', '', $repo ); $repo = rtrim( $repo, '/' ); $repo = preg_replace( '/\.git$/', '', $repo );
			if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) continue;
			$out[ $file ] = array( 'file' => $file, 'slug' => dirname( $file ), 'name' => $h['Name'], 'version' => $h['Version'], 'repo' => $repo );
		}
		return $out;
	}
	public static function token() { return trim( (string) get_option( self::OPT_TOKEN, '' ) ); }

	/* ---------- GitHub ---------- */
	public static function release( $repo, $force = false ) {
		$all = get_site_transient( self::CACHE ); if ( ! is_array( $all ) ) $all = array();
		if ( ! $force && isset( $all[ $repo ] ) && ( $all[ $repo ]['at'] ?? 0 ) > time() - self::TTL ) return $all[ $repo ];
		$args = array( 'timeout' => 12, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'logador-gh-updater/' . LOGADOR_GH_UPDATER_VERSION ) );
		if ( self::token() ) $args['headers']['Authorization'] = 'Bearer ' . self::token();
		$res  = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/latest', $args );
		$row  = array( 'at' => time(), 'data' => null, 'error' => '' );
		if ( is_wp_error( $res ) ) { $row['error'] = 'Сайтът не стигна до GitHub: ' . $res->get_error_message(); }
		else {
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 === $code && is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$pkg = ''; $api = '';
				foreach ( (array) ( $body['assets'] ?? array() ) as $a ) { if ( preg_match( '/\.zip$/i', (string) ( $a['name'] ?? '' ) ) ) { $pkg = (string) $a['browser_download_url']; $api = (string) $a['url']; break; } }
				$row['data'] = array( 'version' => ltrim( (string) $body['tag_name'], 'vV' ), 'tag' => (string) $body['tag_name'], 'package' => $pkg ?: (string) ( $body['zipball_url'] ?? '' ), 'asset_api' => $api,
					'notes' => (string) ( $body['body'] ?? '' ), 'published' => (string) ( $body['published_at'] ?? '' ), 'html_url' => (string) ( $body['html_url'] ?? '' ) );
			} elseif ( 404 === $code ) {
				$row['error'] = self::token() ? 'HTTP 404 — token-ът няма достъп до ' . $repo . ' (частно репо: Fine-grained token с „Only select repositories“ → това репо, Contents: Read; за организация — одобрен от owner-а) или още няма Release.' : 'HTTP 404 — репото е частно (трябва token) или няма Release.';
			} elseif ( 401 === $code ) { $row['error'] = 'HTTP 401 — невалиден/изтекъл token.'; }
			else { $row['error'] = 'GitHub HTTP ' . $code . ( isset( $body['message'] ) ? ': ' . $body['message'] : '' ); }
		}
		$all[ $repo ] = $row; set_site_transient( self::CACHE, $all, self::TTL );
		return $row;
	}

	/* ---------- WP ъпдейт механизъм ---------- */
	public function inject( $t ) {
		if ( ! is_object( $t ) ) $t = new stdClass();
		foreach ( self::tracked() as $file => $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$item = array( 'id' => 'github.com/' . $p['repo'], 'slug' => $p['slug'], 'plugin' => $file, 'new_version' => $p['version'], 'url' => 'https://github.com/' . $p['repo'], 'package' => '', 'icons' => array(), 'banners' => array(), 'banners_rtl' => array(), 'tested' => '', 'requires_php' => '7.4', 'compatibility' => new stdClass() );
			if ( ! $d || version_compare( $d['version'], $p['version'], '<=' ) ) {
				// Актуален: казваме го на WP през no_update — само така показва „Enable auto-updates“ и „Виж детайли“.
				unset( $t->response[ $file ] );
				if ( ! isset( $t->no_update ) || ! is_array( $t->no_update ) ) $t->no_update = array();
				$t->no_update[ $file ] = (object) $item;
				continue;
			}
			if ( isset( $t->no_update[ $file ] ) ) unset( $t->no_update[ $file ] );
			$item['new_version'] = $d['version']; $item['url'] = $d['html_url'];
			$item['package'] = ( self::token() && $d['asset_api'] ) ? $d['asset_api'] : $d['package'];
			$t->response[ $file ] = (object) $item;
		}
		return $t;
	}
	public function info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) return $res;
		foreach ( self::tracked() as $p ) {
			if ( $p['slug'] !== $args->slug ) continue;
			$d = self::release( $p['repo'] )['data'] ?? null; if ( ! $d ) return $res;
			return (object) array( 'name' => $p['name'], 'slug' => $p['slug'], 'version' => $d['version'], 'author' => 'LOGADOR', 'homepage' => 'https://github.com/' . $p['repo'], 'download_link' => $d['package'], 'last_updated' => $d['published'],
				'sections' => array( 'changelog' => nl2br( esc_html( $d['notes'] ?: 'Виж release-а в GitHub.' ) ) ) );
		}
		return $res;
	}
	/** zipball-ът се разархивира като owner-repo-<sha>/ — WP иска папка с името на плъгина. */
	public function fix_folder( $source, $remote, $upgrader, $extra ) {
		if ( empty( $extra['plugin'] ) ) return $source;
		$tr = self::tracked(); if ( ! isset( $tr[ $extra['plugin'] ] ) ) return $source;
		global $wp_filesystem;
		$want = trailingslashit( $remote ) . $tr[ $extra['plugin'] ]['slug'] . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) return $source;
		return ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) ? $want : $source;
	}
	public function auth( $args, $url ) {
		if ( ! self::token() ) return $args;
		if ( 0 !== strpos( $url, 'https://api.github.com/' ) ) {
			$ok = false; foreach ( self::tracked() as $p ) if ( 0 === strpos( $url, 'https://github.com/' . $p['repo'] ) ) $ok = true;
			if ( ! $ok ) return $args;
		}
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) $args['headers'] = array();
		$args['headers']['Authorization'] = 'Bearer ' . self::token();
		if ( false !== strpos( $url, '/releases/assets/' ) ) $args['headers']['Accept'] = 'application/octet-stream';
		return $args;
	}
	public function row_meta( $links, $file ) {
		$tr = self::tracked(); if ( isset( $tr[ $file ] ) ) $links[] = '<a href="https://github.com/' . esc_attr( $tr[ $file ]['repo'] ) . '/releases" target="_blank" rel="noopener">GitHub</a>';
		return $links;
	}

	/* ---------- Settings → GitHub ъпдейти ---------- */
	public function menu() { add_options_page( 'GitHub ъпдейти', 'GitHub ъпдейти', 'manage_options', 'logador-github', array( $this, 'page' ) ); }
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Няма достъп.' ); check_admin_referer( 'logador_gh_save' );
		update_option( self::OPT_TOKEN, sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ), false );
		delete_site_transient( self::CACHE ); delete_site_transient( 'update_plugins' );
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&saved=1' ) ); exit;
	}
	public function check() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Няма достъп.' ); check_admin_referer( 'logador_gh_check' );
		delete_site_transient( self::CACHE ); foreach ( self::tracked() as $p ) self::release( $p['repo'], true );
		delete_site_transient( 'update_plugins' ); wp_update_plugins();
		wp_safe_redirect( admin_url( 'options-general.php?page=logador-github&checked=1' ) ); exit;
	}
	public function page() {
		$tr = self::tracked();
		echo '<div class="wrap"><h1>GitHub ъпдейти (LOGADOR)</h1>';
		if ( ! empty( $_GET['saved'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Записано.</p></div>';
		if ( ! empty( $_GET['checked'] ) ) echo '<div class="notice notice-success is-dismissible"><p>Проверено — виж таблицата; новите версии са и в Plugins → Update now.</p></div>';
		echo '<p>Един token за всички плъгини. Всеки плъгин с ред <code>GitHub Plugin URI: owner/repo</code> в header-а си се появява тук и се обновява от последния GitHub Release. Публично репо не иска token.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'logador_gh_save' ); echo '<input type="hidden" name="action" value="logador_gh_save">';
		echo '<table class="form-table"><tr><th>GitHub token</th><td><input type="password" name="token" class="regular-text" value="' . esc_attr( self::token() ) . '" autocomplete="new-password"><p class="description">Fine-grained PAT: Repository access → всички репота на LOGADOR (или „All repositories“), Permissions → Contents: Read-only. Един за целия сайт.</p></td></tr></table>';
		submit_button( 'Запази' ); echo '</form>';
		echo '<h2>Следени плъгини</h2><table class="widefat striped"><thead><tr><th>Плъгин</th><th>Репо</th><th>Тук</th><th>В GitHub</th><th>Състояние</th></tr></thead><tbody>';
		if ( ! $tr ) echo '<tr><td colspan="5">Няма плъгин с „GitHub Plugin URI“ в header-а.</td></tr>';
		foreach ( $tr as $p ) {
			$r = self::release( $p['repo'] ); $d = $r['data'] ?? null;
			$st = $d ? ( version_compare( $d['version'], $p['version'], '>' ) ? '<b style="color:#b8481c">нова версия → Plugins → Update now</b>' : '<span style="color:#1f7a4d">актуален</span>' ) : '<b style="color:#d64545">' . esc_html( $r['error'] ?: 'няма данни' ) . '</b>';
			echo '<tr><td><b>' . esc_html( $p['name'] ) . '</b></td><td><a href="https://github.com/' . esc_attr( $p['repo'] ) . '/releases" target="_blank">' . esc_html( $p['repo'] ) . '</a></td><td>' . esc_html( $p['version'] ) . '</td><td>' . esc_html( $d['version'] ?? '—' ) . '</td><td>' . $st . '</td></tr>';
		}
		echo '</tbody></table><p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=logador_gh_check' ), 'logador_gh_check' ) ) . '">Провери сега</a> &nbsp; <span class="description">Иначе се проверява на 6 ч. Auto-updates се включват от Plugins → „Enable auto-updates“ за всеки плъгин.</span></p></div>';
	}
}
LOGADOR_GitHub_Updater::instance();
