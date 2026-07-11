<?php
/**
 * Coupon support.
 *
 * Coupon-required discount promotions are exposed to WooCommerce as virtual
 * coupons via `woocommerce_get_shop_coupon_data`. This reuses WooCommerce's
 * native coupon box, validation, tax handling and removal — no second engine.
 *
 * The automatic-promotion engine deliberately skips coupon-required promos
 * (Promotion::is_automatic() === false), so there is no double application.
 *
 * Note: WooCommerce's minimum-quantity is not a native coupon feature, so the
 * `condition_min_items` rule is enforced here via `woocommerce_coupon_is_valid`.
 * Per-customer usage caps on *virtual* coupons are limited by WooCommerce (no
 * coupon post to track against); automatic-promo caps still use our usage table.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coupon {

	public function __construct() {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'provide' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_min_items' ), 10, 2 );
	}

	/**
	 * Find an active, live, coupon-required discount promotion by code.
	 *
	 * @return Promotion|null
	 */
	private function find_by_code( $code ) {
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code ) {
			return null;
		}
		foreach ( Repository::active() as $p ) {
			if ( ! $p->requires_coupon || ! $p->coupon_code ) {
				continue;
			}
			if ( strtolower( $p->coupon_code ) === $code && $p->is_live() && App::promotion_runs_here( $p ) && $p->customer_type_allowed( is_user_logged_in() ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Supply virtual coupon data when the entered code matches one of our promos.
	 *
	 * @param mixed  $data WC coupon data (false when not found).
	 * @param string $code Entered code.
	 */
	public function provide( $data, $code ) {
		$promo = $this->find_by_code( $code );
		if ( ! $promo ) {
			return $data;
		}

		// Buy-X-Pay-Y / Buy-X-Get-Y don't map to a native coupon. The code acts
		// as an "unlock token" (zero WooCommerce discount); the engine applies the
		// real, cart-aware discount once the code is present.
		if ( 'discount' !== $promo->type ) {
			$coupon = array(
				'discount_type'  => 'fixed_cart',
				'amount'         => 0,
				'individual_use' => false,
			);
			if ( $promo->ends_at ) {
				$coupon['date_expires'] = strtotime( $promo->ends_at );
			}
			return $coupon;
		}

		$applies = $promo->get( 'applies_to', 'all' );
		$dtype   = $promo->get( 'discount_type', 'percent' );
		$value   = (float) $promo->get( 'discount_value', 0 );

		if ( 'percent' === $dtype ) {
			$wc_type = 'percent';
		} else {
			$wc_type = ( 'cart' === $applies ) ? 'fixed_cart' : 'fixed_product';
		}

		$coupon = array(
			'discount_type'        => $wc_type,
			'amount'               => $value,
			'individual_use'       => false,
			'product_ids'          => 'products' === $applies ? array_map( 'intval', (array) $promo->get( 'product_ids', array() ) ) : array(),
			'product_categories'   => 'categories' === $applies ? array_map( 'intval', (array) $promo->get( 'category_ids', array() ) ) : array(),
			'excluded_product_ids' => array_map( 'intval', (array) $promo->get( 'excluded_product_ids', array() ) ),
			'minimum_amount'       => (float) $promo->get( 'condition_min_amount', 0 ) ?: '',
			'usage_limit_per_user' => $promo->limit_per_customer ? (int) $promo->limit_per_customer : '',
			'limit_usage_to_x_items' => $promo->get( 'limit_discounted_items' ) ? (int) $promo->get( 'limit_discounted_items' ) : '',
		);

		if ( $promo->ends_at ) {
			$coupon['date_expires'] = strtotime( $promo->ends_at );
		}

		return $coupon;
	}

	/**
	 * Enforce condition_min_items for our virtual coupons (WC has no native
	 * minimum-quantity rule).
	 *
	 * @param bool       $valid
	 * @param \WC_Coupon $coupon
	 */
	public function validate_min_items( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) {
			return $valid;
		}
		$promo = $this->find_by_code( $coupon->get_code() );
		if ( ! $promo ) {
			return $valid; // not one of ours.
		}
		$min_items = (int) $promo->get( 'condition_min_items', 0 );
		if ( $min_items <= 0 ) {
			return $valid;
		}
		$count = 0.0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$count += (float) $item['quantity'];
			}
		}
		if ( $count < $min_items ) {
			throw new \Exception(
				sprintf(
					/* translators: %d: minimum number of items */
					esc_html__( 'This coupon requires at least %d items in the cart.', 'promotion-engine' ),
					$min_items
				)
			);
		}
		return $valid;
	}
}
