<?php
/**
 * Usage / redemption tracking.
 *
 * Records which promotions were redeemed on which orders. Powers:
 *   - per-customer limit enforcement,
 *   - the dashboard metrics (redemptions / revenue / discounts), and
 *   - the future upstream report to Giorgio (reported_to_giorgio flag).
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Usage {

	/**
	 * Has this customer already hit the per-customer cap for this promotion?
	 *
	 * @param Promotion $promotion
	 * @param int       $customer_id
	 * @param string    $email
	 */
	public static function customer_reached_limit( Promotion $promotion, $customer_id, $email ) {
		if ( ! $promotion->limit_per_customer ) {
			return false;
		}
		global $wpdb;
		$table = Installer::usage_table();

		if ( $customer_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE promotion_id = %d AND customer_id = %d", $promotion->id, $customer_id )
			);
		} elseif ( $email ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE promotion_id = %d AND customer_email = %s", $promotion->id, $email )
			);
		} else {
			return false; // guest with no email yet — can't cap reliably pre-checkout.
		}

		return $count >= (int) $promotion->limit_per_customer;
	}

	/**
	 * Aggregate stats for a promotion: number of redemptions, total discount
	 * given, and revenue from the orders that used it.
	 *
	 * @param int $promotion_id
	 * @return array{redemptions:int,discount:float,revenue:float}
	 */
	public static function stats( $promotion_id, $from = null, $to = null ) {
		global $wpdb;
		$table        = Installer::usage_table();
		$promotion_id = (int) $promotion_id;

		$where  = 'promotion_id = %d';
		$params = array( $promotion_id );
		if ( $from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $from;
		}
		if ( $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, discount_amount FROM {$table} WHERE {$where}", $params ), ARRAY_A );

		$redemptions = is_array( $rows ) ? count( $rows ) : 0;
		$discount    = 0.0;
		$order_ids   = array();
		foreach ( (array) $rows as $r ) {
			$discount                       += (float) $r['discount_amount'];
			$order_ids[ (int) $r['order_id'] ] = true;
		}
		$revenue = 0.0;
		foreach ( array_keys( $order_ids ) as $oid ) {
			$order = wc_get_order( $oid );
			if ( $order ) {
				$revenue += (float) $order->get_total();
			}
		}
		return array(
			'redemptions' => $redemptions,
			'orders'      => count( $order_ids ),
			'discount'    => $discount,
			'revenue'     => $revenue,
		);
	}

	/**
	 * Earliest recorded usage date for a promotion (fallback for created_at).
	 *
	 * @param int $promotion_id
	 * @return string|null Y-m-d H:i:s or null.
	 */
	public static function first_usage_date( $promotion_id ) {
		global $wpdb;
		$table = Installer::usage_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$date = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$table} WHERE promotion_id = %d", (int) $promotion_id ) );
		return ( $date && 0 !== strpos( (string) $date, '0000' ) ) ? (string) $date : null;
	}

	/**
	 * Distinct order IDs that redeemed a promotion.
	 *
	 * @param int $promotion_id
	 * @return int[]
	 */
	public static function order_ids( $promotion_id ) {
		global $wpdb;
		$table = Installer::usage_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$table} WHERE promotion_id = %d", (int) $promotion_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Record redemptions for an order. Hooked on order processing/completion.
	 *
	 * The discount breakdown per promotion is stored on the order meta at
	 * checkout time (see Cart::store_applied_on_order) so we can attribute the
	 * saved amount accurately here.
	 *
	 * @param int $order_id
	 */
	public static function record_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Idempotency: don't double-record.
		if ( $order->get_meta( '_promeng_usage_recorded' ) ) {
			return;
		}

		$applied = $order->get_meta( '_promeng_applied' );
		if ( empty( $applied ) || ! is_array( $applied ) ) {
			return;
		}

		global $wpdb;
		$table = Installer::usage_table();

		foreach ( $applied as $promotion_id => $info ) {
			$wpdb->insert(
				$table,
				array(
					'promotion_id'        => (int) $promotion_id,
					'order_id'            => (int) $order_id,
					'customer_id'         => (int) $order->get_customer_id(),
					'customer_email'      => $order->get_billing_email(),
					'channel'             => 'web',
					'discount_amount'     => isset( $info['saved'] ) ? (float) $info['saved'] : 0.0,
					'reported_to_giorgio' => 0,
					'created_at'          => current_time( 'mysql' ),
				)
			);
		}

		$order->update_meta_data( '_promeng_usage_recorded', 1 );
		$order->save();

		do_action( 'promeng_usage_recorded', $order_id );
	}

	/**
	 * Usage rows not yet reported to Giorgio, joined with the promotion's
	 * external id (Giorgio identifies promotions by external_id, not our id).
	 *
	 * @param int $limit Max rows per batch.
	 * @return array
	 */
	public static function pending( $limit = 100 ) {
		global $wpdb;
		$usage = Installer::usage_table();
		$promo = Installer::promotions_table();
		$limit = max( 1, (int) $limit );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.*, p.external_id, p.name AS promotion_name
				 FROM {$usage} u
				 LEFT JOIN {$promo} p ON p.id = u.promotion_id
				 WHERE u.reported_to_giorgio = 0
				 ORDER BY u.id ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Mark usage rows as reported to Giorgio.
	 *
	 * @param int[] $ids
	 */
	public static function mark_reported( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		global $wpdb;
		$usage        = Installer::usage_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$usage} SET reported_to_giorgio = 1 WHERE id IN ({$placeholders})", $ids ) );
	}
}
