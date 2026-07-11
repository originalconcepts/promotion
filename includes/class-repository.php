<?php
/**
 * Promotion repository — CRUD over the custom table.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Repository {

	/**
	 * Fetch one promotion by id.
	 *
	 * @return Promotion|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Installer::promotions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? Promotion::from_row( $row ) : null;
	}

	/**
	 * All promotions, newest first (for the admin list).
	 *
	 * @return Promotion[]
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$table = Installer::promotions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
		return array_map( array( Promotion::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * All promotions that are flagged active (cheap query; the engine then
	 * applies the full is_live() scheduling check in PHP).
	 *
	 * @return Promotion[]
	 */
	public static function active() {
		global $wpdb;
		$table = Installer::promotions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY priority ASC, id ASC", ARRAY_A );
		return array_map( array( Promotion::class, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Insert or update. Accepts a normalized data array (see Admin::sanitize()).
	 *
	 * @return int Promotion id.
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$table = Installer::promotions_table();
		$now   = current_time( 'mysql' );

		$record = array(
			'name'               => isset( $data['name'] ) ? (string) $data['name'] : '',
			'type'               => isset( $data['type'] ) ? (string) $data['type'] : 'discount',
			'active'             => ! empty( $data['active'] ) ? 1 : 0,
			'channels'           => isset( $data['channels'] ) ? implode( ',', (array) $data['channels'] ) : 'web',
			'customer_type'      => in_array( isset( $data['customer_type'] ) ? $data['customer_type'] : 'all', array( 'all', 'registered', 'guest' ), true ) ? $data['customer_type'] : 'all',
			'coupon_code'        => ! empty( $data['coupon_code'] ) ? (string) $data['coupon_code'] : null,
			'requires_coupon'    => ! empty( $data['requires_coupon'] ) ? 1 : 0,
			'show_label'         => ! empty( $data['show_label'] ) ? 1 : 0,
			'config'             => wp_json_encode( isset( $data['config'] ) ? $data['config'] : array() ),
			'limit_per_order'    => isset( $data['limit_per_order'] ) && $data['limit_per_order'] !== '' ? (int) $data['limit_per_order'] : null,
			'limit_per_customer' => isset( $data['limit_per_customer'] ) && $data['limit_per_customer'] !== '' ? (int) $data['limit_per_customer'] : null,
			'starts_at'          => ! empty( $data['starts_at'] ) ? $data['starts_at'] : null,
			'ends_at'            => ! empty( $data['ends_at'] ) ? $data['ends_at'] : null,
			'weekdays'           => isset( $data['weekdays'] ) ? implode( ',', array_map( 'intval', (array) $data['weekdays'] ) ) : null,
			'priority'           => isset( $data['priority'] ) ? (int) $data['priority'] : 10,
			'source'             => isset( $data['source'] ) ? (string) $data['source'] : 'local',
			'external_id'        => ! empty( $data['external_id'] ) ? (string) $data['external_id'] : null,
			'updated_at'         => $now,
		);

		if ( $id ) {
			$wpdb->update( $table, $record, array( 'id' => $id ) );
			return (int) $id;
		}

		$record['created_at'] = $now;
		$wpdb->insert( $table, $record );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Toggle the active flag from the list screen.
	 */
	public static function set_active( $id, $active ) {
		global $wpdb;
		$table = Installer::promotions_table();
		$wpdb->update(
			$table,
			array(
				'active'     => $active ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Find a promotion by its Giorgio external id.
	 *
	 * @return Promotion|null
	 */
	public static function find_by_external_id( $external_id ) {
		global $wpdb;
		$table = Installer::promotions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s AND source = 'giorgio'", $external_id ), ARRAY_A );
		return $row ? Promotion::from_row( $row ) : null;
	}

	/**
	 * Upsert a Giorgio-owned promotion (create or update by external_id).
	 * Forces source='giorgio'.
	 *
	 * @return int Promotion id.
	 */
	public static function upsert_from_giorgio( array $data ) {
		$data['source'] = 'giorgio';
		$existing       = isset( $data['external_id'] ) ? self::find_by_external_id( $data['external_id'] ) : null;
		return self::save( $data, $existing ? $existing->id : 0 );
	}

	/**
	 * Delete a Giorgio-owned promotion by external id.
	 *
	 * @return bool Whether a row was removed.
	 */
	public static function delete_by_external_id( $external_id ) {
		$existing = self::find_by_external_id( $external_id );
		if ( ! $existing ) {
			return false;
		}
		self::delete( $existing->id );
		return true;
	}

	/**
	 * Delete a promotion. Local promotions only — Giorgio-sourced ones are
	 * owned upstream and should be removed from Giorgio.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = Installer::promotions_table();
		$wpdb->delete( $table, array( 'id' => $id ) );
	}

	/**
	 * Duplicate a promotion (adds " - עותק" to the name, sets inactive).
	 *
	 * @return int New id, or 0 on failure.
	 */
	public static function duplicate( $id ) {
		$p = self::find( $id );
		if ( ! $p ) {
			return 0;
		}
		$data = array(
			'name'               => $p->name . ' - ' . __( 'Copy', 'promotion-engine' ),
			'type'               => $p->type,
			'active'             => 0,
			'channels'           => $p->channels,
			'customer_type'      => $p->customer_type,
			'coupon_code'        => null, // avoid duplicate coupon codes.
			'requires_coupon'    => $p->requires_coupon,
			'show_label'         => $p->show_label,
			'config'             => $p->config,
			'limit_per_order'    => $p->limit_per_order,
			'limit_per_customer' => $p->limit_per_customer,
			'starts_at'          => $p->starts_at,
			'ends_at'            => $p->ends_at,
			'weekdays'           => $p->weekdays,
			'priority'           => $p->priority,
			'source'             => 'local',
		);
		return self::save( $data );
	}
}
