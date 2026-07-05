<?php
/**
 * Database installer / schema.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	const DB_VERSION_OPTION = 'promeng_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Promotions table name (with prefix).
	 */
	public static function promotions_table() {
		global $wpdb;
		return $wpdb->prefix . 'pe_promotions';
	}

	/**
	 * Usage / redemptions table name (with prefix).
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'pe_promotion_usage';
	}

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		if ( ! wp_next_scheduled( 'promeng_giorgio_sync' ) ) {
			// Placeholder cron hook for the future Giorgio definition sync.
			wp_schedule_event( time() + 300, 'hourly', 'promeng_giorgio_sync' );
		}
	}

	/**
	 * Clear scheduled events on deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'promeng_giorgio_sync' );
	}

	/**
	 * Create / upgrade tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$promotions      = self::promotions_table();
		$usage           = self::usage_table();

		// config holds type-specific JSON so the schema stays stable across types.
		$sql_promotions = "CREATE TABLE {$promotions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(40) NOT NULL DEFAULT 'discount',
			active TINYINT(1) NOT NULL DEFAULT 1,
			channels VARCHAR(120) NOT NULL DEFAULT 'web',
			coupon_code VARCHAR(120) DEFAULT NULL,
			requires_coupon TINYINT(1) NOT NULL DEFAULT 0,
			show_label TINYINT(1) NOT NULL DEFAULT 0,
			config LONGTEXT NULL,
			limit_per_order INT DEFAULT NULL,
			limit_per_customer INT DEFAULT NULL,
			starts_at DATETIME DEFAULT NULL,
			ends_at DATETIME DEFAULT NULL,
			weekdays VARCHAR(40) DEFAULT NULL,
			priority INT NOT NULL DEFAULT 10,
			source VARCHAR(20) NOT NULL DEFAULT 'local',
			external_id VARCHAR(120) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY active_type (active, type),
			KEY coupon_code (coupon_code),
			KEY external_id (external_id)
		) {$charset_collate};";

		$sql_usage = "CREATE TABLE {$usage} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			promotion_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			customer_email VARCHAR(191) DEFAULT NULL,
			channel VARCHAR(20) NOT NULL DEFAULT 'web',
			discount_amount DECIMAL(12,4) NOT NULL DEFAULT 0,
			reported_to_giorgio TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY promotion_id (promotion_id),
			KEY order_id (order_id),
			KEY customer_lookup (promotion_id, customer_id),
			KEY customer_email (customer_email)
		) {$charset_collate};";

		dbDelta( $sql_promotions );
		dbDelta( $sql_usage );
	}
}
