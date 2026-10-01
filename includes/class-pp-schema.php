<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class PP_Schema {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'proof_partner_' . $name;
	}

	public static function has_column( $table, $col ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $col ) );
	}
	public static function ensure_column( $table, $col, $ddl ) {
		global $wpdb;
		if ( self::has_column( $table, $col ) ) return true;
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}" );
		return self::has_column( $table, $col );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$partners = self::table( 'partners' );
		$coupons  = self::table( 'coupons' );
		$ledger   = self::table( 'commissions' );
		$payouts  = self::table( 'payouts' );

		dbDelta( "CREATE TABLE {$partners} (
			partner_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			rate DECIMAL(6,3) NOT NULL DEFAULT 15.000,
			payment_method VARCHAR(50) NOT NULL DEFAULT 'bank',
			payment_details TEXT NULL,
			legal_name VARCHAR(191) NOT NULL DEFAULT '',
			iban VARCHAR(40) NOT NULL DEFAULT '',
			referred_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			public_name VARCHAR(120) NOT NULL DEFAULT '',
			bic VARCHAR(16) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			channels VARCHAR(255) NOT NULL DEFAULT '',
			lifetime_imported VARCHAR(50) NOT NULL DEFAULT '',
			tracked_from DATETIME NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (partner_id),
			KEY user_id (user_id),
			KEY status (status)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$coupons} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			coupon_code VARCHAR(191) NOT NULL,
			wc_coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			partner_id BIGINT UNSIGNED NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			auto_paused TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY coupon_code (coupon_code),
			KEY partner_id (partner_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$ledger} (
			commission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			coupon_code VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL DEFAULT 'commission',
			base_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			rate_applied DECIMAL(6,3) NOT NULL DEFAULT 0,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payout_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			products TEXT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			order_partner_key VARCHAR(40) NULL DEFAULT NULL,
			PRIMARY KEY  (commission_id),
			UNIQUE KEY uq_order_partner (order_partner_key),
			KEY order_id (order_id),
			KEY status_order (status, order_id),
			KEY partner_status (partner_id, status),
			KEY created_at (created_at),
			KEY partner_id (partner_id),
			KEY status (status),
			KEY payout_id (payout_id)
		) {$charset};" );

		/* Proof 1.1.x: явна гаранция за новите колони — dbDelta не е надежден за ADD COLUMN на жива таблица */
		self::ensure_column( $partners, 'tracked_from', 'DATETIME NULL' );
		/* Proof 1.6: модел на плащане + фирмени данни (фиксирано по фактура) */
		self::ensure_column( $partners, 'pay_model', "VARCHAR(20) NOT NULL DEFAULT 'commission'" );
		self::ensure_column( $partners, 'company_name', "VARCHAR(191) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'company_id', "VARCHAR(40) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'company_vat', "VARCHAR(40) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'company_address', "VARCHAR(255) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'company_mol', "VARCHAR(120) NOT NULL DEFAULT ''" );
		/* Proof 2.0.1: съобщението в портала е per партньор (празно = глобалният текст от Маркетинг) */
		self::ensure_column( $partners, 'msg_behind', "TEXT NULL" );
		self::ensure_column( $partners, 'msg_ahead', "TEXT NULL" );
		self::ensure_column( $partners, 'msg_wish_mid', "VARCHAR(255) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'msg_wish_end', "VARCHAR(255) NOT NULL DEFAULT ''" );
		self::ensure_column( $partners, 'msg_wish_next', "VARCHAR(255) NOT NULL DEFAULT ''" );
		$ship = self::table( 'shipments' );
		dbDelta( "CREATE TABLE {$ship} (
			shipment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			order_status VARCHAR(30) NOT NULL DEFAULT '',
			counted TINYINT(1) NOT NULL DEFAULT 0,
			items LONGTEXT NULL,
			qty INT NOT NULL DEFAULT 0,
			value DECIMAL(12,2) NOT NULL DEFAULT 0,
			order_date DATETIME NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (shipment_id),
			UNIQUE KEY order_id (order_id),
			KEY partner_id (partner_id)
		) {$charset};" );
		/* Proof 2.0: правила по оферти за партньор + разбивка на комисионите по оферта */
		$orules = self::table( 'offer_rules' );
		dbDelta( "CREATE TABLE {$orules} (
			rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			scope VARCHAR(80) NOT NULL,
			applies VARCHAR(12) NOT NULL DEFAULT 'any',
			rule LONGTEXT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (rule_id),
			UNIQUE KEY partner_scope (partner_id, scope, applies),
			KEY scope (scope)
		) {$charset};" );
		$citems = self::table( 'commission_items' );
		dbDelta( "CREATE TABLE {$citems} (
			item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			commission_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			scope VARCHAR(80) NOT NULL DEFAULT '',
			label VARCHAR(120) NOT NULL DEFAULT '',
			kind VARCHAR(12) NOT NULL DEFAULT 'onetime',
			renewal_n INT NOT NULL DEFAULT 0,
			base_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			qty INT NOT NULL DEFAULT 1,
			mode VARCHAR(12) NOT NULL DEFAULT 'rate',
			value DECIMAL(12,3) NOT NULL DEFAULT 0,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT '',
			text VARCHAR(255) NOT NULL DEFAULT '',
			order_date DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (item_id),
			KEY commission_id (commission_id),
			KEY scope (scope),
			KEY partner_id (partner_id),
			KEY order_date (order_date)
		) {$charset};" );
		$invoices = self::table( 'invoices' );
		dbDelta( "CREATE TABLE {$invoices} (
			invoice_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			number VARCHAR(60) NOT NULL DEFAULT '',
			issued_at DATE NULL,
			period_from DATE NULL,
			period_to DATE NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
			attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			paid TINYINT(1) NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (invoice_id),
			KEY partner_id (partner_id),
			KEY issued_at (issued_at)
		) {$charset};" );


		dbDelta( "CREATE TABLE {$payouts} (
			payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
			method VARCHAR(50) NOT NULL DEFAULT 'bank',
			reference VARCHAR(191) NOT NULL DEFAULT '',
			note VARCHAR(255) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			snapshot TEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (payout_id),
			KEY reference (reference),
			KEY partner_id (partner_id)
		) {$charset};" );

		$points  = self::table( 'points' );
		$content = self::table( 'content' );

		dbDelta( "CREATE TABLE {$points} (
			point_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'sale',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			points INT NOT NULL DEFAULT 0,
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ref_key VARCHAR(64) NOT NULL DEFAULT '',
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (point_id),
			UNIQUE KEY ref_key (ref_key),
			KEY partner_status (partner_id, status),
			KEY created_at (created_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$content} (
			content_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			url TEXT NULL,
			url_hash CHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			points INT NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			reviewed_at DATETIME NULL,
			PRIMARY KEY  (content_id),
			UNIQUE KEY url_hash (url_hash),
			KEY partner_id (partner_id),
			KEY status (status)
		) {$charset};" );
	}
}
