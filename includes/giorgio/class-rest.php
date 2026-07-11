<?php
/**
 * Inbound REST API: Giorgio -> plugin.
 *
 * Giorgio is the authoring source for its own clients. It pushes promotion
 * definitions into the store through these endpoints; the plugin stores them
 * with source='giorgio' (read-only in wp-admin) and the engine runs them.
 *
 * Namespace: promeng/v1
 *   POST   /promotions                 upsert one or many promotions
 *   DELETE /promotions/<external_id>   remove a Giorgio promotion
 *   GET    /promotions                 list Giorgio promotions (verification)
 *
 * Auth: every request must carry the webhook secret in the
 *       X-Promeng-Secret header (see Giorgio Sync settings).
 *
 * @package PromoEngine
 */

namespace PromoEngine\Giorgio;

use PromoEngine\Repository;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NS = 'promeng/v1';

	private static $allowed_types = array( 'discount', 'buy_x_pay_y', 'buy_x_get_y' );

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		register_rest_route(
			self::NS,
			'/promotions',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upsert' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/promotions/(?P<external_id>[A-Za-z0-9._\-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'external_id' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Constant-time check of the shared secret header.
	 */
	public function authorize( $request ) {
		$settings = Settings::get();
		$secret   = $settings['webhook_secret'];
		$given    = (string) $request->get_header( 'x-promeng-secret' );

		if ( empty( $secret ) || empty( $given ) || ! hash_equals( $secret, $given ) ) {
			return new WP_Error( 'promeng_forbidden', __( 'Invalid or missing secret.', 'promotion-engine' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Upsert one promotion or a batch ({"promotions":[...]}, or a bare array).
	 */
	public function upsert( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'promeng_bad_request', __( 'Expected a JSON body.', 'promotion-engine' ), array( 'status' => 400 ) );
		}

		if ( isset( $body['promotions'] ) && is_array( $body['promotions'] ) ) {
			$items = $body['promotions'];
		} elseif ( isset( $body['external_id'] ) ) {
			$items = array( $body ); // single object
		} else {
			$items = $body; // bare array of objects
		}

		$results = array();
		$errors  = array();
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$mapped = self::map_payload( $raw );
			if ( is_wp_error( $mapped ) ) {
				$errors[] = array(
					'external_id' => isset( $raw['external_id'] ) ? (string) $raw['external_id'] : null,
					'error'       => $mapped->get_error_message(),
				);
				continue;
			}
			$id        = Repository::upsert_from_giorgio( $mapped );
			$results[] = array(
				'external_id' => $mapped['external_id'],
				'id'          => $id,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'       => empty( $errors ),
				'upserted' => $results,
				'errors'   => $errors,
			),
			empty( $errors ) ? 200 : 207
		);
	}

	public function remove( $request ) {
		$external_id = $request['external_id'];
		$deleted     = Repository::delete_by_external_id( $external_id );
		return new WP_REST_Response(
			array(
				'ok'          => true,
				'deleted'     => $deleted,
				'external_id' => $external_id,
			),
			$deleted ? 200 : 404
		);
	}

	public function index() {
		$out = array();
		foreach ( Repository::all() as $promo ) {
			if ( 'giorgio' !== $promo->source ) {
				continue;
			}
			$out[] = array(
				'external_id' => $promo->external_id,
				'name'        => $promo->name,
				'type'        => $promo->type,
				'active'      => (bool) $promo->active,
			);
		}
		return new WP_REST_Response( array( 'promotions' => $out ), 200 );
	}

	/**
	 * Map a Giorgio promotion object to the repository's data shape.
	 * Config keys pass through 1:1 with the plugin's own config (product_ids,
	 * category_ids, discount_type, discount_value, buy_quantity, pay_amount,
	 * benefit_product_ids, benefit_type, benefit_value, etc.).
	 *
	 * @return array|WP_Error
	 */
	public static function map_payload( array $raw ) {
		if ( empty( $raw['external_id'] ) ) {
			return new WP_Error( 'promeng_missing_id', __( 'external_id is required.', 'promotion-engine' ) );
		}
		$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';
		if ( ! in_array( $type, self::$allowed_types, true ) ) {
			return new WP_Error( 'promeng_bad_type', __( 'Unknown promotion type.', 'promotion-engine' ) );
		}

		return array(
			'external_id'        => sanitize_text_field( $raw['external_id'] ),
			'name'               => isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '',
			'type'               => $type,
			'active'             => ! empty( $raw['active'] ),
			'channels'           => isset( $raw['channels'] ) ? array_map( 'sanitize_key', (array) $raw['channels'] ) : array( 'web' ),
			'customer_type'      => isset( $raw['customer_type'] ) && in_array( $raw['customer_type'], array( 'all', 'registered', 'guest' ), true ) ? $raw['customer_type'] : 'all',
			'coupon_code'        => ! empty( $raw['coupon_code'] ) ? sanitize_text_field( $raw['coupon_code'] ) : null,
			'requires_coupon'    => ! empty( $raw['requires_coupon'] ),
			'show_label'         => isset( $raw['show_label'] ) ? ! empty( $raw['show_label'] ) : true,
			'config'             => isset( $raw['config'] ) && is_array( $raw['config'] ) ? self::sanitize_config( $raw['config'] ) : array(),
			'limit_per_order'    => isset( $raw['limit_per_order'] ) && '' !== $raw['limit_per_order'] && null !== $raw['limit_per_order'] ? (int) $raw['limit_per_order'] : '',
			'limit_per_customer' => isset( $raw['limit_per_customer'] ) && '' !== $raw['limit_per_customer'] && null !== $raw['limit_per_customer'] ? (int) $raw['limit_per_customer'] : '',
			'starts_at'          => ! empty( $raw['starts_at'] ) ? self::sanitize_datetime( $raw['starts_at'] ) : null,
			'ends_at'            => ! empty( $raw['ends_at'] ) ? self::sanitize_datetime( $raw['ends_at'] ) : null,
			'weekdays'           => isset( $raw['weekdays'] ) ? array_map( 'intval', (array) $raw['weekdays'] ) : array(),
			'priority'           => isset( $raw['priority'] ) ? (int) $raw['priority'] : 10,
		);
	}

	/**
	 * Recursively sanitize a config tree: ints stay ints, strings get cleaned.
	 */
	private static function sanitize_config( array $config ) {
		$clean = array();
		foreach ( $config as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_config( $value );
			} elseif ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} else {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	private static function sanitize_datetime( $value ) {
		$ts = strtotime( (string) $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}
}
