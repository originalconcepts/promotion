<?php
/**
 * "Buy X Get Y" (קנה X קבל Y) promotion type.
 *
 * Step 1 — what the customer must buy: all / specific products / categories
 * (with exclusions) plus an optional condition (min items / min amount).
 * Step 2 — what they get: a benefit (free / % off / fixed price) on one of the
 * configured benefit products.
 *
 * The benefit is applied to the benefit product when it is present in the cart,
 * for up to limit_per_order units (default 1). Auto-adding a free gift to the
 * cart is a front-end behaviour from the cart-messages spec and is deferred to a
 * later phase — for now the customer adds the benefit product themselves.
 *
 * Applied as a capped cart discount (fee) so "get 1 free" discounts exactly one
 * unit even when several are in the cart.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Types;

use PromoEngine\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuyXGetY implements Type {

	public function key() {
		return 'buy_x_get_y';
	}

	/**
	 * How many times the "buy" side is satisfied — the number of benefits earned.
	 *
	 *  - Amount-gated with no per-item multiple ("spend over X, get Y"): once per
	 *    full multiple of the spend threshold, so a ₪1,869 cart over a ₪1,000
	 *    threshold earns ONE benefit — not one per item in the cart.
	 *  - Otherwise ("buy N, get Y"): once per buy_quantity qualifying items.
	 *
	 * Set "limit per order" to cap it (e.g. exactly one gift per order).
	 *
	 * @return int
	 */
	private function fulfillments( $buy_qty, $buy_quantity, $min_amount, $threshold ) {
		if ( $min_amount > 0 && $buy_quantity <= 1 ) {
			return (int) floor( $threshold / $min_amount );
		}
		return (int) floor( $buy_qty / $buy_quantity );
	}

	public function evaluate( Promotion $promotion, array $cart, array $context ) {
		$result = array(
			'qualifies'      => false,
			'line_discounts' => array(),
			'cart_discount'  => 0.0,
			'saved'          => 0.0,
			'label'          => $this->label( $promotion ),
			'message'        => '',
		);

		$benefit_applies = $promotion->get( 'benefit_applies_to', 'products' );
		$benefit_ids     = array_map( 'intval', (array) $promotion->get( 'benefit_product_ids', array() ) );
		if ( 'products' === $benefit_applies && empty( $benefit_ids ) ) {
			return $result;
		}

		// --- Step 1: is the buy condition satisfied? ----------------------
		$buy_applies  = $promotion->get( 'buy_applies_to', 'all' );
		$buy_excluded = array_map( 'intval', (array) $promotion->get( 'buy_excluded_product_ids', array() ) );
		$buy_qty      = 0.0;
		foreach ( $cart as $line ) {
			if ( $this->buy_matches( $line, $promotion, $buy_applies, $buy_excluded ) ) {
				$buy_qty += (float) $line['qty'];
			}
		}

		// Buy quantity X: how many qualifying units earn one benefit. The deal
		// repeats — buy 2X and earn two benefits, etc.
		$buy_quantity = max( 1, (int) $promotion->get( 'buy_min_items', 1 ) );
		$min_amount   = (float) $promotion->get( 'buy_min_amount', 0 );
		$threshold    = isset( $context['display_subtotal'] ) ? (float) $context['display_subtotal'] : ( isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0.0 );

		if ( $buy_qty < $buy_quantity ) {
			return $result; // not enough qualifying products to earn one benefit.
		}
		if ( $min_amount && $threshold < $min_amount ) {
			return $result; // optional minimum-spend gate not met.
		}

		// --- Step 2: apply the benefit to the configured benefit lines ----
		$benefit_type     = $promotion->get( 'benefit_type', 'free' );
		$benefit_value    = (float) $promotion->get( 'benefit_value', 0 );
		$benefit_applies  = $promotion->get( 'benefit_applies_to', 'products' );
		$benefit_cats     = array_map( 'intval', (array) $promotion->get( 'benefit_category_ids', array() ) );
		$benefit_excluded = array_map( 'intval', (array) $promotion->get( 'benefit_excluded_product_ids', array() ) );

		// Gather benefit lines with their per-unit benefit (discount) amount.
		$blines = array();
		foreach ( $cart as $line ) {
			if ( ! $this->benefit_matches( $line, $promotion, $benefit_applies, $benefit_ids, $benefit_cats, $benefit_excluded ) ) {
				continue;
			}
			$price = (float) $line['price'];
			if ( 'free' === $benefit_type ) {
				$per_unit = $price;
			} elseif ( 'percent' === $benefit_type ) {
				$per_unit = $price * ( $benefit_value / 100 );
			} else { // fixed price
				$per_unit = max( 0, $price - $benefit_value );
			}
			$per_unit = round( $per_unit, wc_get_price_decimals() );
			if ( $per_unit <= 0 ) {
				continue;
			}
			$blines[] = array(
				'key'      => $line['key'],
				'per_unit' => $per_unit,
				'qty'      => (float) $line['qty'],
			);
		}

		if ( empty( $blines ) ) {
			return $result; // nothing eligible for the benefit in the cart yet.
		}

		// Expand eligible benefit lines into whole units (per-unit value + qty).
		$units    = array();
		$per_unit = array();
		$line_qty = array();
		foreach ( $blines as $bl ) {
			$whole = (int) floor( $bl['qty'] );
			if ( $whole < 1 ) {
				continue;
			}
			$per_unit[ $bl['key'] ] = $bl['per_unit'];
			$line_qty[ $bl['key'] ] = $bl['qty'];
			for ( $i = 0; $i < $whole; $i++ ) {
				$units[] = array( 'key' => $bl['key'], 'value' => $bl['per_unit'] );
			}
		}
		if ( empty( $units ) ) {
			return $result;
		}
		$eligible_units = count( $units );

		// How many units are given for free / discounted.
		$benefit_quantity = max( 1, (int) $promotion->get( 'benefit_quantity', 1 ) );
		if ( 'products' === $benefit_applies ) {
			// A distinct benefit product (a separate gift): each fulfillment earns
			// benefit_quantity free units of it (amount- or item-count-driven).
			$fulfillments = $this->fulfillments( $buy_qty, $buy_quantity, $min_amount, $threshold );
		} else {
			// Shared pool (same / all / category): each fulfillment consumes a
			// whole group of buy_quantity paid + benefit_quantity free items from
			// the cart, so "Buy 2 Get 2" over 4 items frees 2, not 4.
			$group        = $buy_quantity + $benefit_quantity;
			$fulfillments = $group > 0 ? (int) floor( $eligible_units / $group ) : 0;
		}
		if ( $fulfillments < 1 ) {
			return $result;
		}

		$benefit_units = $fulfillments * $benefit_quantity;
		if ( $promotion->limit_per_order ) {
			$benefit_units = min( $benefit_units, (int) $promotion->limit_per_order );
		}

		// Allocate the benefit across the eligible units. By default — per Israeli
		// consumer law — the benefit is spread across price tiers rather than
		// landing only on the cheapest items: the units are split into as many
		// groups as there are free units and the cheapest of each group is given
		// (4 items, 2 free → one dearer + one cheaper). Merchants can instead
		// discount the cheapest units via apply_to_cheapest.
		$cheapest = ! empty( $promotion->get( 'apply_to_cheapest', false ) );

		$chosen = $this->select_units( $units, $benefit_units, $cheapest );

		// Aggregate the chosen units back to their lines and blend each line's
		// discount across its full quantity for an exact, evenly-priced line.
		$freed = array();
		foreach ( $chosen as $key ) {
			$freed[ $key ] = isset( $freed[ $key ] ) ? $freed[ $key ] + 1 : 1;
		}
		$line_discounts = array();
		$saved          = 0.0;
		foreach ( $freed as $key => $count ) {
			$line_total_discount    = $per_unit[ $key ] * $count;
			$line_discounts[ $key ] = $line_total_discount / $line_qty[ $key ];
			$saved                 += $line_total_discount;
		}

		if ( $saved <= 0 ) {
			return $result;
		}

		$result['qualifies']      = true;
		$result['line_discounts'] = $line_discounts;
		$result['saved']          = round( $saved, wc_get_price_decimals() );
		return $result;
	}

	public function encouragement( Promotion $promotion, array $cart, array $context ) {
		$benefit_applies = $promotion->get( 'benefit_applies_to', 'products' );
		$benefit_ids     = array_map( 'intval', (array) $promotion->get( 'benefit_product_ids', array() ) );
		if ( 'products' === $benefit_applies && empty( $benefit_ids ) ) {
			return '';
		}

		$buy_applies  = $promotion->get( 'buy_applies_to', 'all' );
		$buy_excluded = array_map( 'intval', (array) $promotion->get( 'buy_excluded_product_ids', array() ) );
		$buy_qty      = 0.0;
		foreach ( $cart as $line ) {
			if ( $this->buy_matches( $line, $promotion, $buy_applies, $buy_excluded ) ) {
				$buy_qty += (float) $line['qty'];
			}
		}

		$buy_quantity = max( 1, (int) $promotion->get( 'buy_min_items', 1 ) );
		$min_amount   = (float) $promotion->get( 'buy_min_amount', 0 );
		$threshold    = isset( $context['display_subtotal'] ) ? (float) $context['display_subtotal'] : ( isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0.0 );
		$name         = $promotion->name ? $promotion->name : $this->label( $promotion );

		// Nothing qualifying in the cart yet — don't nag.
		if ( $buy_qty <= 0 ) {
			return '';
		}

		// Short of the next whole multiple? Nudge toward the next earned benefit.
		$remainder = fmod( $buy_qty, $buy_quantity );
		$earned    = (int) floor( $buy_qty / $buy_quantity );
		if ( $earned < 1 || $remainder > 0 ) {
			$needed = $buy_quantity - $remainder;
			if ( $min_amount && $threshold < $min_amount && $earned < 1 ) {
				return sprintf(
					/* translators: 1: amount to add, 2: deal name */
					__( 'Add %1$s more to unlock: %2$s', 'promotion-engine' ),
					wp_strip_all_tags( wc_price( $min_amount - $threshold ) ),
					$name
				);
			}
			$needed_int = max( 1, (int) ceil( $needed ) );
			return sprintf(
				/* translators: 1: number of items, 2: deal name */
				_n( 'Add %1$d more item to unlock: %2$s', 'Add %1$d more items to unlock: %2$s', $needed_int, 'promotion-engine' ),
				$needed_int,
				$name
			);
		}

		// Minimum-spend gate not met yet.
		if ( $min_amount && $threshold < $min_amount ) {
			return sprintf(
				__( 'Add %1$s more to unlock: %2$s', 'promotion-engine' ),
				wp_strip_all_tags( wc_price( $min_amount - $threshold ) ),
				$name
			);
		}

		// Condition met — for a specific benefit product, prompt to add it.
		if ( 'products' !== $benefit_applies ) {
			return '';
		}
		$benefit_in_cart = false;
		foreach ( $cart as $line ) {
			$ids = array( (int) $line['product_id'] );
			if ( ! empty( $line['parent_id'] ) ) {
				$ids[] = (int) $line['parent_id'];
			}
			if ( array_intersect( $ids, $benefit_ids ) ) {
				$benefit_in_cart = true;
				break;
			}
		}
		if ( ! $benefit_in_cart ) {
			return sprintf(
				/* translators: deal name */
				__( 'You unlocked %s — add the offer item to your cart to claim it.', 'promotion-engine' ),
				$name
			);
		}
		return '';
	}

	/**
	 * For an auto-add free gift: the gift product + how many free units the cart
	 * currently earns, or null if it doesn't qualify. The buy condition is read
	 * from $cart, which the caller passes WITHOUT any auto-added gift lines.
	 */
	public function auto_gift( Promotion $promotion, array $cart, array $context ) {
		if ( 'free' !== $promotion->get( 'benefit_type', 'free' ) ) {
			return null;
		}
		if ( empty( $promotion->get( 'auto_add', false ) ) ) {
			return null;
		}
		// Auto-add only makes sense for a specific benefit product.
		if ( 'products' !== $promotion->get( 'benefit_applies_to', 'products' ) ) {
			return null;
		}
		$benefit_ids = array_values( array_filter( array_map( 'intval', (array) $promotion->get( 'benefit_product_ids', array() ) ) ) );
		if ( empty( $benefit_ids ) ) {
			return null;
		}

		$buy_applies  = $promotion->get( 'buy_applies_to', 'all' );
		$buy_excluded = array_map( 'intval', (array) $promotion->get( 'buy_excluded_product_ids', array() ) );
		$buy_qty      = 0.0;
		foreach ( $cart as $line ) {
			if ( $this->buy_matches( $line, $promotion, $buy_applies, $buy_excluded ) ) {
				$buy_qty += (float) $line['qty'];
			}
		}
		$buy_quantity = max( 1, (int) $promotion->get( 'buy_min_items', 1 ) );
		$min_amount   = (float) $promotion->get( 'buy_min_amount', 0 );
		$threshold    = isset( $context['display_subtotal'] ) ? (float) $context['display_subtotal'] : ( isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0.0 );

		if ( $buy_qty < $buy_quantity ) {
			return null;
		}
		if ( $min_amount && $threshold < $min_amount ) {
			return null;
		}
		$fulfillments = $this->fulfillments( $buy_qty, $buy_quantity, $min_amount, $threshold );
		if ( $fulfillments < 1 ) {
			return null;
		}
		$benefit_quantity = max( 1, (int) $promotion->get( 'benefit_quantity', 1 ) );
		$units            = $fulfillments * $benefit_quantity;
		if ( $promotion->limit_per_order ) {
			$units = min( $units, (int) $promotion->limit_per_order );
		}
		if ( $units < 1 ) {
			return null;
		}
		return array(
			'product_id' => $benefit_ids[0],
			'qty'        => $units,
		);
	}

	/**
	 * Choose which benefit units receive the discount.
	 *
	 * $cheapest = true  → the cheapest $need units overall (merchant-friendly).
	 * $cheapest = false → legal default: sort units by value descending and split
	 *   them into $need contiguous groups as evenly as possible, then free the
	 *   cheapest (last) unit of each group. This spreads the benefit across price
	 *   tiers instead of landing only on the cheapest items — e.g. 4 items with
	 *   2 free yields one dearer and one cheaper free, not the two cheapest.
	 *
	 * @return string[] one line key per freed unit (never more than $need, and
	 *                  never more units of a line than it has).
	 */
	private function select_units( array $units, $need, $cheapest ) {
		$need  = (int) $need;
		$total = count( $units );
		if ( $need < 1 || 0 === $total ) {
			return array();
		}

		if ( $cheapest ) {
			usort(
				$units,
				static function ( $a, $b ) {
					return $a['value'] <=> $b['value'];
				}
			);
			$picked = array();
			for ( $i = 0; $i < $total && count( $picked ) < $need; $i++ ) {
				$picked[] = $units[ $i ]['key'];
			}
			return $picked;
		}

		usort(
			$units,
			static function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		// Free every unit when the benefit covers them all.
		if ( $need >= $total ) {
			return wp_list_pluck( $units, 'key' );
		}

		// Split into $need contiguous groups (as even as possible) and free the
		// cheapest (last) unit of each group — one freed unit per group.
		$picked = array();
		for ( $g = 0; $g < $need; $g++ ) {
			$end       = (int) floor( ( ( $g + 1 ) * $total ) / $need ); // group is [start, end)
			$free_idx  = $end - 1;                                       // cheapest of this group.
			$picked[]  = $units[ $free_idx ]['key'];
		}
		return $picked;
	}

	/**
	 * Does a cart line qualify to receive the benefit?
	 * - products: specific benefit products (auto-added gift lines included).
	 * - categories: lines in the benefit categories, minus exclusions, no gifts.
	 * - same: the same products/categories the customer must buy (5+1 deals),
	 *         minus the benefit exclusions, no gifts.
	 * - all: any line, minus exclusions, no gifts.
	 */
	private function benefit_matches( array $line, Promotion $promotion, $applies, array $benefit_ids, array $benefit_cats, array $excluded ) {
		$ids = array( (int) $line['product_id'] );
		if ( ! empty( $line['parent_id'] ) ) {
			$ids[] = (int) $line['parent_id'];
		}
		if ( 'products' === $applies ) {
			return (bool) array_intersect( $ids, $benefit_ids );
		}
		if ( ! empty( $line['is_gift'] ) ) {
			return false;
		}
		if ( array_intersect( $ids, $excluded ) ) {
			return false;
		}
		if ( 'categories' === $applies ) {
			$line_cats = array_map( 'intval', (array) $line['category_ids'] );
			return (bool) array_intersect( $benefit_cats, $line_cats );
		}
		if ( 'same' === $applies ) {
			$buy_applies  = $promotion->get( 'buy_applies_to', 'all' );
			$buy_excluded = array_map( 'intval', (array) $promotion->get( 'buy_excluded_product_ids', array() ) );
			return $this->buy_matches( $line, $promotion, $buy_applies, $buy_excluded );
		}
		return true; // all products.
	}

	private function buy_matches( array $line, Promotion $promotion, $applies, array $excluded ) {
		if ( ! empty( $line['is_gift'] ) ) {
			return false; // an auto-added gift never counts as a purchased item.
		}
		$ids = array( (int) $line['product_id'] );
		if ( ! empty( $line['parent_id'] ) ) {
			$ids[] = (int) $line['parent_id'];
		}
		if ( array_intersect( $ids, $excluded ) ) {
			return false;
		}
		if ( 'all' === $applies ) {
			return true;
		}
		if ( 'products' === $applies ) {
			return (bool) array_intersect( $ids, array_map( 'intval', (array) $promotion->get( 'buy_product_ids', array() ) ) );
		}
		if ( 'categories' === $applies ) {
			$line_cats = array_map( 'intval', (array) $line['category_ids'] );
			return (bool) array_intersect( array_map( 'intval', (array) $promotion->get( 'buy_category_ids', array() ) ), $line_cats );
		}
		return false;
	}

	private function label( Promotion $promotion ) {
		$type = $promotion->get( 'benefit_type', 'free' );
		if ( 'free' === $type ) {
			return __( 'Buy & get a gift', 'promotion-engine' );
		}
		if ( 'percent' === $type ) {
			return sprintf(
				/* translators: %s: percentage */
				__( 'Buy & get %s%% off', 'promotion-engine' ),
				rtrim( rtrim( (string) (float) $promotion->get( 'benefit_value', 0 ), '0' ), '.' )
			);
		}
		return __( 'Buy & get a special price', 'promotion-engine' );
	}
}
