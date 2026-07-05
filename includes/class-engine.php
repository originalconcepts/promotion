<?php
/**
 * Promotion engine.
 *
 * Selects active + live promotions, runs each through its type handler against
 * a normalized cart snapshot, and aggregates the results. This is the single
 * place pricing decisions are made — both the cart integration and (later) the
 * REST evaluate endpoint go through here, so there is one source of truth in
 * the plugin.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

use PromoEngine\Types\Type;
use PromoEngine\Types\Discount;
use PromoEngine\Types\BuyXPayY;
use PromoEngine\Types\BuyXGetY;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engine {

	/** @var Type[] keyed by type key. */
	private $types = array();

	public function __construct() {
		$this->register( new Discount() );
		$this->register( new BuyXPayY() );
		$this->register( new BuyXGetY() );

		/**
		 * Allow add-ons (or the Giorgio module) to register more types.
		 *
		 * @param Engine $engine
		 */
		do_action( 'promeng_register_types', $this );
	}

	public function register( Type $type ) {
		$this->types[ $type->key() ] = $type;
	}

	/**
	 * Evaluate the cart and return aggregated results per promotion.
	 *
	 * @param array $cart    Normalized lines (see Type interface).
	 * @param array $context ['subtotal'=>float,'customer_id'=>int,'customer_email'=>string].
	 *
	 * @return array {
	 *   'line_discounts' => [ cart_key => total_per_unit_discount ],
	 *   'cart_fees'      => [ ['name'=>string,'amount'=>float] ],
	 *   'applied'        => [ promotion_id => ['promotion'=>Promotion,'saved'=>float,'label'=>string] ],
	 *   'messages'       => string[],
	 * }
	 */
	public function evaluate( array $cart, array $context = array() ) {
		$out = array(
			'line_discounts' => array(),
			'cart_fees'      => array(),
			'applied'        => array(),
			'messages'       => array(),
		);

		$context = wp_parse_args(
			$context,
			array(
				'subtotal'       => 0.0,
				'customer_id'    => 0,
				'customer_email' => '',
			)
		);

		foreach ( Repository::active() as $promotion ) {
			if ( ! isset( $this->types[ $promotion->type ] ) ) {
				continue; // type not implemented yet (e.g. buy_x_* in v0.1).
			}
			if ( ! $promotion->is_live() ) {
				continue;
			}
			if ( ! App::promotion_runs_here( $promotion, isset( $context['channel'] ) ? $context['channel'] : null ) ) {
				continue; // promotion isn't enabled on this platform (web/app).
			}
			if ( ! $promotion->is_automatic() ) {
				// Coupon-required. Discount-type coupons are applied natively by
				// WooCommerce (see Coupon), so the engine leaves those alone.
				// Buy-X-Pay-Y / Buy-X-Get-Y can't map to a native coupon, so the
				// engine applies them here once their code is in the cart.
				if ( 'discount' === $promotion->type ) {
					continue;
				}
				$code    = strtolower( (string) $promotion->coupon_code );
				$applied = isset( $context['applied_coupons'] ) ? (array) $context['applied_coupons'] : array();
				if ( '' === $code || ! in_array( $code, $applied, true ) ) {
					continue;
				}
			}
			// Per-customer redemption cap.
			if ( $promotion->limit_per_customer && Usage::customer_reached_limit( $promotion, $context['customer_id'], $context['customer_email'] ) ) {
				continue;
			}

			$result = $this->types[ $promotion->type ]->evaluate( $promotion, $cart, $context );
			if ( empty( $result['qualifies'] ) ) {
				continue;
			}

			// Merge per-line discounts (largest discount wins per unit; full
			// cross-promotion stacking rules are a deliberate later decision).
			foreach ( $result['line_discounts'] as $key => $per_unit ) {
				$current = isset( $out['line_discounts'][ $key ] ) ? $out['line_discounts'][ $key ] : 0.0;
				$out['line_discounts'][ $key ] = max( $current, $per_unit );
			}

			if ( ! empty( $result['cart_discount'] ) ) {
				$out['cart_fees'][] = array(
					'name'   => $promotion->name ? $promotion->name : $result['label'],
					'amount' => (float) $result['cart_discount'],
				);
			}

			$out['applied'][ $promotion->id ] = array(
				'promotion' => $promotion,
				'saved'     => (float) $result['saved'],
				'label'     => $result['label'],
			);

			if ( ! empty( $result['message'] ) ) {
				$out['messages'][] = $result['message'];
			}
		}

		return $out;
	}

	/**
	 * Collect cart encouragement nudges ("add X to unlock…") from every live,
	 * automatic promotion. Independent of evaluate(): a promotion that doesn't
	 * yet qualify can still produce a nudge.
	 *
	 * @return string[] de-duplicated messages.
	 */
	public function messages( array $cart, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'subtotal'       => 0.0,
				'customer_id'    => 0,
				'customer_email' => '',
			)
		);

		$messages = array();
		foreach ( Repository::active() as $promotion ) {
			if ( ! isset( $this->types[ $promotion->type ] ) || ! $promotion->is_live() || ! $promotion->is_automatic() ) {
				continue;
			}
			if ( ! App::promotion_runs_here( $promotion, isset( $context['channel'] ) ? $context['channel'] : null ) ) {
				continue;
			}
			if ( $promotion->limit_per_customer && Usage::customer_reached_limit( $promotion, $context['customer_id'], $context['customer_email'] ) ) {
				continue;
			}
			$msg = $this->types[ $promotion->type ]->encouragement( $promotion, $cart, $context );
			if ( is_string( $msg ) && '' !== $msg ) {
				$messages[] = $msg;
			}
		}
		return array_values( array_unique( $messages ) );
	}

	/**
	 * Get a registered type handler (used by catalog label rendering).
	 *
	 * @return Type|null
	 */
	public function get_type( $key ) {
		return isset( $this->types[ $key ] ) ? $this->types[ $key ] : null;
	}

	/**
	 * Desired auto-add free gifts for the current cart.
	 *
	 * @param array $cart    Cart lines WITHOUT any auto-added gift items.
	 * @param array $context Cart context.
	 * @return array map of promotion_id => array('product_id'=>int,'qty'=>int)
	 */
	public function auto_gifts( array $cart, array $context = array() ) {
		$out = array();
		foreach ( Repository::active() as $promotion ) {
			if ( 'buy_x_get_y' !== $promotion->type ) {
				continue;
			}
			if ( ! $promotion->is_live() || ! $promotion->is_automatic() ) {
				continue;
			}
			if ( ! App::promotion_runs_here( $promotion, isset( $context['channel'] ) ? $context['channel'] : null ) ) {
				continue;
			}
			if ( $promotion->limit_per_customer && Usage::customer_reached_limit( $promotion, isset( $context['customer_id'] ) ? $context['customer_id'] : 0, isset( $context['customer_email'] ) ? $context['customer_email'] : '' ) ) {
				continue;
			}
			$type = $this->get_type( $promotion->type );
			if ( ! $type || ! method_exists( $type, 'auto_gift' ) ) {
				continue;
			}
			$gift = $type->auto_gift( $promotion, $cart, $context );
			if ( $gift && (int) $gift['product_id'] > 0 && (int) $gift['qty'] > 0 ) {
				$out[ $promotion->id ] = $gift;
			}
		}
		return $out;
	}
}
