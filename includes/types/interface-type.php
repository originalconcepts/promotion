<?php
/**
 * Promotion type interface.
 *
 * Each promotion type (discount, buy_x_pay_y, buy_x_get_y) implements this so
 * the engine can treat them uniformly. Buy X Pay Y and Buy X Get Y plug in here
 * in phase 2.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Types;

use PromoEngine\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Type {

	/**
	 * Machine key, e.g. 'discount'.
	 */
	public function key();

	/**
	 * Evaluate the promotion against a cart snapshot.
	 *
	 * @param Promotion $promotion The promotion definition.
	 * @param array     $cart      Normalized cart: array of lines, each
	 *                             ['key','product_id','category_ids'(int[]),
	 *                              'price'(unit),'qty'(int),'line_total'(float)].
	 * @param array     $context   ['subtotal'=>float].
	 *
	 * @return array Result describing what to apply:
	 *   [
	 *     'qualifies'      => bool,
	 *     'line_discounts' => [ cart_key => discount_per_unit_float ],
	 *     'cart_discount'  => float,   // whole-cart discount (added as a fee)
	 *     'saved'          => float,   // total saved by this promotion
	 *     'label'          => string,  // short label, e.g. "20% הנחה"
	 *     'message'        => string,  // optional cart message
	 *   ]
	 */
	public function evaluate( Promotion $promotion, array $cart, array $context );

	/**
	 * Optional cart nudge: what the shopper can add to unlock or extend this
	 * promotion. Returns a ready-to-display string, or '' when there is nothing
	 * useful to suggest (already applied, or nothing relevant in the cart).
	 *
	 * @return string
	 */
	public function encouragement( Promotion $promotion, array $cart, array $context );
}
