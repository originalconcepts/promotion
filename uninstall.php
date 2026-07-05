<?php
/**
 * Uninstall: drop plugin tables and options.
 * Only runs when the user deletes the plugin from WP admin.
 *
 * @package PromoEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$promotions = $wpdb->prefix . 'pe_promotions';
$usage      = $wpdb->prefix . 'pe_promotion_usage';
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$promotions}" );
$wpdb->query( "DROP TABLE IF EXISTS {$usage}" );
// phpcs:enable
delete_option( 'promeng_db_version' );
delete_option( 'promeng_giorgio' );
delete_option( 'promeng_giorgio_last_sync' );
wp_clear_scheduled_hook( 'promeng_giorgio_sync' );
