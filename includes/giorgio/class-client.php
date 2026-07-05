<?php
/**
 * Outbound client: plugin -> Giorgio.
 *
 * Reports store redemptions to Giorgio for the unified dashboard, and can pull
 * the full promotion list for an initial / recovery sync. Authenticates with
 * the API key as a Bearer token.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Giorgio;

use PromoEngine\Usage;
use PromoEngine\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Client {

	public function hooks() {
		// Flush pending redemptions on the recurring sync event.
		add_action( 'promeng_giorgio_sync', array( $this, 'report_usage' ) );
		// Also nudge a report shortly after an order records usage.
		add_action( 'promeng_usage_recorded', array( $this, 'schedule_flush' ) );
	}

	public function schedule_flush() {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( 'promeng_giorgio_sync' ) ) {
			wp_schedule_single_event( time() + 60, 'promeng_giorgio_sync' );
		}
	}

	/**
	 * POST all not-yet-reported redemptions to Giorgio, in batches.
	 */
	public function report_usage() {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		$batches = 0;
		do {
			$rows = Usage::pending( 100 );
			if ( empty( $rows ) ) {
				break;
			}

			$redemptions = array();
			$ids         = array();
			foreach ( $rows as $row ) {
				$ids[]         = (int) $row['id'];
				$redemptions[] = array(
					'promotion_external_id' => $row['external_id'],   // null for local-only promos
					'promotion_name'        => $row['promotion_name'],
					'order_id'              => (int) $row['order_id'],
					'customer_id'           => (int) $row['customer_id'],
					'customer_email'        => $row['customer_email'],
					'channel'               => $row['channel'],
					'discount_amount'       => (float) $row['discount_amount'],
					'redeemed_at'           => $row['created_at'],
				);
			}

			$response = $this->post( '/redemptions', array( 'redemptions' => $redemptions ) );
			if ( is_wp_error( $response ) ) {
				$this->log( 'report_usage failed: ' . $response->get_error_message() );
				break; // try again on the next run; rows stay unreported.
			}

			Usage::mark_reported( $ids );
			update_option( 'promeng_giorgio_last_sync', current_time( 'mysql' ), false );
			$batches++;
		} while ( count( $rows ) === 100 && $batches < 20 );
	}

	/**
	 * Pull the full promotion list from Giorgio and upsert locally.
	 * Optional — definitions normally arrive via the inbound REST push.
	 *
	 * @return int|\WP_Error Number upserted, or error.
	 */
	public function pull_definitions() {
		if ( ! Settings::is_enabled() ) {
			return new \WP_Error( 'promeng_disabled', __( 'Giorgio sync is not enabled.', 'promotion-engine' ) );
		}

		$response = $this->get( '/promotions' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = isset( $response['promotions'] ) && is_array( $response['promotions'] ) ? $response['promotions'] : array();
		$count = 0;
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$mapped = Rest::map_payload( $raw );
			if ( is_wp_error( $mapped ) ) {
				continue;
			}
			Repository::upsert_from_giorgio( $mapped );
			$count++;
		}
		return $count;
	}

	// --- HTTP helpers ------------------------------------------------------

	private function post( $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	private function get( $path ) {
		return $this->request( 'GET', $path, null );
	}

	private function request( $method, $path, $body ) {
		$settings = Settings::get();
		$url      = trailingslashit( $settings['base_url'] ) . ltrim( $path, '/' );

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['api_key'],
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'promeng_http_' . $code, sprintf( 'Giorgio returned HTTP %d.', $code ), $data );
		}
		return is_array( $data ) ? $data : array();
	}

	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Promotion Engine] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
