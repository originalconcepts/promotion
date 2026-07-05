<?php
/**
 * "% / ₪ הנחה" promotion type.
 *
 * Supports:
 *   applies_to: all | products | categories | cart
 *   discount_type: percent | fixed
 *   exclusions (for all/categories)
 *   buy conditions: min_items, min_amount
 *
 * Not yet implemented (flagged TODO for a later phase):
 *   - limit_discounted_items (the Israeli-law per-item allocation rule).
 *     Right now the discount applies to every qualifying unit.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Types;

use PromoEngine\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discount implements Type {

	public function key() {
		return 'discount';
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

		$applies_to     = $promotion->get( 'applies_to', 'all' );
		$discount_type  = $promotion->get( 'discount_type', 'percent' );
		$discount_value = (float) $promotion->get( 'discount_value', 0 );
		if ( $discount_value <= 0 ) {
			return $result;
		}

		$min_items  = (int) $promotion->get( 'condition_min_items', 0 );
		$min_amount = (float) $promotion->get( 'condition_min_amount', 0 );
		$subtotal   = isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0.0;
		// Threshold compares against the displayed (tax-inclusive) subtotal so
		// "on orders over X" matches what the customer sees in the cart.
		$threshold  = isset( $context['display_subtotal'] ) ? (float) $context['display_subtotal'] : $subtotal;

		// --- Whole-cart discount -------------------------------------------
		if ( 'cart' === $applies_to ) {
			$total_items = 0;
			foreach ( $cart as $line ) {
				$total_items += (float) $line['qty'];
			}
			if ( $min_items && $total_items < $min_items ) {
				return $result;
			}
			if ( $min_amount && $threshold < $min_amount ) {
				return $result;
			}
			$cart_discount = 'percent' === $discount_type
				? $subtotal * ( $discount_value / 100 )
				: min( $discount_value, $subtotal );

			$result['qualifies']     = $cart_discount > 0;
			$result['cart_discount'] = round( $cart_discount, wc_get_price_decimals() );
			$result['saved']         = $result['cart_discount'];
			return $result;
		}

		// --- Per-line discount (all / products / categories) ----------------
		$excluded = array_map( 'intval', (array) $promotion->get( 'excluded_product_ids', array() ) );
		$targets  = $applies_to;

		$qualifying_subtotal = 0.0;
		$qualifying_items    = 0;
		$matched_lines       = array();

		foreach ( $cart as $line ) {
			if ( ! $this->line_matches( $line, $promotion, $targets, $excluded ) ) {
				continue;
			}
			$matched_lines[]      = $line;
			$qualifying_items    += (float) $line['qty'];
			$qualifying_subtotal += (float) $line['line_total'];
		}

		if ( empty( $matched_lines ) ) {
			return $result;
		}

		// Buy conditions are evaluated against the whole cart (per spec: cart-level gate).
		if ( $min_items ) {
			$total_items = 0;
			foreach ( $cart as $line ) {
				$total_items += (float) $line['qty'];
			}
			if ( $total_items < $min_items ) {
				return $result;
			}
		}
		if ( $min_amount && $threshold < $min_amount ) {
			return $result;
		}

		$limit_items = (int) $promotion->get( 'limit_discounted_items', 0 );

		// "Limit discounted items" is a per-unit concept (how many units receive
		// the discount), so it only applies to PERCENTAGE discounts. A fixed ₪
		// amount is a single basket-level discount and ignores it.
		if ( $limit_items > 0 && 'percent' === $discount_type ) {
			$units    = array(); // each: ['key'=>, 'disc'=>] per whole unit.
			$line_qty = array();
			foreach ( $matched_lines as $line ) {
				$unit_price             = (float) $line['price'];
				$per_unit               = 'percent' === $discount_type ? $unit_price * ( $discount_value / 100 ) : min( $discount_value, $unit_price );
				$per_unit               = round( $per_unit, wc_get_price_decimals() );
				$line_qty[ $line['key'] ] = (float) $line['qty'];
				$whole_qty              = (int) floor( (float) $line['qty'] );
				for ( $i = 0; $i < $whole_qty; $i++ ) {
					$units[] = array(
						'key'  => $line['key'],
						'disc' => $per_unit,
					);
				}
			}
			if ( empty( $units ) ) {
				return $result;
			}

			usort(
				$units,
				static function ( $a, $b ) {
					return $b['disc'] <=> $a['disc']; // most valuable first.
				}
			);
			$taken = array_slice( $units, 0, $limit_items );

			$line_disc_total = array();
			$sum             = 0.0;
			foreach ( $taken as $u ) {
				$line_disc_total[ $u['key'] ] = ( isset( $line_disc_total[ $u['key'] ] ) ? $line_disc_total[ $u['key'] ] : 0 ) + $u['disc'];
				$sum                         += $u['disc'];
			}
			if ( $sum <= 0 ) {
				return $result;
			}

			foreach ( $line_disc_total as $key => $tot ) {
				$qty = $line_qty[ $key ];
				if ( $qty > 0 ) {
					$result['line_discounts'][ $key ] = $tot / $qty; // blended, full precision.
				}
			}
			$result['qualifies'] = true;
			$result['saved']     = round( $sum, wc_get_price_decimals() );
			return $this->finalize( $result, $promotion );
		}

		$saved = 0.0;
		if ( 'percent' === $discount_type ) {
			// Percentage comes off every qualifying unit.
			foreach ( $matched_lines as $line ) {
				$unit_price                               = (float) $line['price'];
				$per_unit                                 = round( $unit_price * ( $discount_value / 100 ), wc_get_price_decimals() );
				$result['line_discounts'][ $line['key'] ] = $per_unit;
				$saved                                   += $per_unit * (float) $line['qty'];
			}
		} else {
			// A fixed ₪ amount is a SINGLE discount off the matched subtotal
			// (e.g. "₪100 off"), distributed proportionally across the matched
			// lines and blended per unit — not ₪100 off each unit.
			$fixed_total = min( $discount_value, $qualifying_subtotal );
			foreach ( $matched_lines as $line ) {
				$line_total = (float) $line['line_total'];
				$qty        = (float) $line['qty'];
				if ( $qty <= 0 || $qualifying_subtotal <= 0 ) {
					continue;
				}
				$line_share                               = $fixed_total * ( $line_total / $qualifying_subtotal );
				$result['line_discounts'][ $line['key'] ] = $line_share / $qty; // blended, full precision.
			}
			$saved = $fixed_total;
		}

		$result['qualifies'] = $saved > 0;
		$result['saved']     = round( $saved, wc_get_price_decimals() );
		return $this->finalize( $result, $promotion );
	}

	/**
	 * When "show as a cart discount" is on, collapse the per-line discounts into
	 * a single basket-level discount that appears in the cart totals under the
	 * promotion's name (instead of changing each product's price + label).
	 */
	private function finalize( array $result, Promotion $promotion ) {
		if ( ! empty( $result['line_discounts'] ) && $promotion->get( 'as_cart_discount', false ) ) {
			$result['cart_discount']  = $result['saved'];
			$result['line_discounts'] = array();
		}
		return $result;
	}

	/**
	 * Does a cart line match the promotion's targeting?
	 */
	public function encouragement( Promotion $promotion, array $cart, array $context ) {
		if ( (float) $promotion->get( 'discount_value', 0 ) <= 0 ) {
			return '';
		}
		$min_items  = (int) $promotion->get( 'condition_min_items', 0 );
		$min_amount = (float) $promotion->get( 'condition_min_amount', 0 );
		if ( ! $min_items && ! $min_amount ) {
			return ''; // no threshold to progress toward.
		}

		$applies_to = $promotion->get( 'applies_to', 'all' );
		$excluded   = array_map( 'intval', (array) $promotion->get( 'excluded_product_ids', array() ) );

		// Only nudge if the cart has something the discount could apply to.
		$has_relevant = false;
		if ( 'cart' === $applies_to ) {
			$has_relevant = ! empty( $cart );
		} else {
			foreach ( $cart as $line ) {
				if ( $this->line_matches( $line, $promotion, $applies_to, $excluded ) ) {
					$has_relevant = true;
					break;
				}
			}
		}
		if ( ! $has_relevant ) {
			return '';
		}

		$threshold   = isset( $context['display_subtotal'] ) ? (float) $context['display_subtotal'] : ( isset( $context['subtotal'] ) ? (float) $context['subtotal'] : 0.0 );
		$total_items = 0.0;
		foreach ( $cart as $line ) {
			$total_items += (float) $line['qty'];
		}

		$items_ok  = ! $min_items || $total_items >= $min_items;
		$amount_ok = ! $min_amount || $threshold >= $min_amount;
		if ( $items_ok && $amount_ok ) {
			return ''; // already unlocked.
		}

		$name = $promotion->name ? $promotion->name : $this->label( $promotion );

		if ( $min_amount && $threshold < $min_amount ) {
			return sprintf(
				/* translators: 1: amount to add, 2: deal name */
				__( 'Add %1$s more to get: %2$s', 'promotion-engine' ),
				wp_strip_all_tags( wc_price( $min_amount - $threshold ) ),
				$name
			);
		}
		if ( $min_items && $total_items < $min_items ) {
			$remaining = max( 1, $min_items - (int) ceil( $total_items ) );
			return sprintf(
				/* translators: 1: number of items, 2: deal name */
				_n( 'Add %1$d more item to get: %2$s', 'Add %1$d more items to get: %2$s', $remaining, 'promotion-engine' ),
				$remaining,
				$name
			);
		}
		return '';
	}

	private function line_matches( array $line, Promotion $promotion, $targets, array $excluded ) {
		if ( ! empty( $line['is_gift'] ) ) {
			return false; // don't apply other discounts to an auto-added gift.
		}
		// A cart line can be a variation; match against both the variation id
		// and its parent id (admins usually pick the parent product).
		$ids = array( (int) $line['product_id'] );
		if ( ! empty( $line['parent_id'] ) ) {
			$ids[] = (int) $line['parent_id'];
		}

		if ( array_intersect( $ids, $excluded ) ) {
			return false;
		}

		if ( 'all' === $targets ) {
			return true;
		}

		if ( 'products' === $targets ) {
			$target_ids = array_map( 'intval', (array) $promotion->get( 'product_ids', array() ) );
			return (bool) array_intersect( $ids, $target_ids );
		}

		if ( 'categories' === $targets ) {
			$cat_ids   = array_map( 'intval', (array) $promotion->get( 'category_ids', array() ) );
			$line_cats = array_map( 'intval', (array) $line['category_ids'] );
			return (bool) array_intersect( $cat_ids, $line_cats );
		}

		return false;
	}

	/**
	 * Short human label for summary / catalog tag, e.g. "20% הנחה" or "₪10 הנחה".
	 */
	private function label( Promotion $promotion ) {
		$value = (float) $promotion->get( 'discount_value', 0 );
		$type  = $promotion->get( 'discount_type', 'percent' );
		if ( 'percent' === $type ) {
			return sprintf( '%s%% %s', rtrim( rtrim( (string) $value, '0' ), '.' ), __( 'off', 'promotion-engine' ) );
		}
		return sprintf( '%s %s', wp_strip_all_tags( wc_price( $value ) ), __( 'off', 'promotion-engine' ) );
	}
}
