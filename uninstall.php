<?php
/**
 * Uninstall — DATA-PRESERVING by default.
 *
 * The auto-updater can leave stale duplicate copies of this plugin on disk
 * (e.g. "promotion-engine 2", "..bak-*"). ALL copies share the SAME database
 * tables (same prefix), so deleting ANY copy from wp-admin runs this file and
 * — historically — DROPPED the tables, destroying the LIVE promotions data of
 * the still-active copy. (This is the documented OC uninstall landmine.)
 *
 * Now data is preserved unless the site owner explicitly opts in, either
 * per-plugin or via the shared OC Pluginz purge constant, in wp-config.php:
 *     define( 'PROMENG_DELETE_DATA_ON_UNINSTALL', true );
 *   // or, to purge every OC plugin's data:
 *     define( 'OC_PLUGINZ_DELETE_DATA_ON_UNINSTALL', true );
 *
 * @package PromoEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Preserve data unless the owner explicitly opted in to a destructive purge.
$promeng_purge_data = ( defined( 'PROMENG_DELETE_DATA_ON_UNINSTALL' ) && PROMENG_DELETE_DATA_ON_UNINSTALL )
	|| ( defined( 'OC_PLUGINZ_DELETE_DATA_ON_UNINSTALL' ) && OC_PLUGINZ_DELETE_DATA_ON_UNINSTALL );
if ( ! $promeng_purge_data ) {
	return;
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
