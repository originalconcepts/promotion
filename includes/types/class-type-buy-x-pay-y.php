<?php
/**
 * "Buy X Pay Y" (קנה X שלם Y) promotion type.
 *
 * Buy [buy_quantity] of a product (or from a category) and pay a fixed
 * [pay_amount] total for them. E.g. 3 kg ground beef for ₪100.
 *
 * Applied on the product line itself: for each complete "set" of buy_quantity
 * the bundle price replaces the normal price, shown as a struck-through
 * original and the deal price on the line. Excess units beyond complete sets
 * blend in at full price, so the line total stays correct and recalculates as
 * quantity changes. Quantity is float, so weight-based products (kg) work.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Types;

use PromoEngine\Promotion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BuyXPayY implements Type {

	public function key() {
		return 'buy_x_pay_y';
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

		$buy_qty = (float) $promotion->get( 'buy_quantity', 0 );
		$pay      = (float) $promotion->get( 'pay_amount', 0 );
		if ( $buy_qty <= 0 || $pay < 0 ) {
			return $result;
		}

		$scope    = $promotion->get( 'scope', 'product' );
		$excluded = array_map( 'intval', (array) $promotion->get( 'excluded_product_ids', array() ) );

		$qualifying = array();
		$total_qty  = 0.0;
		foreach ( $cart as $line ) {
			if ( ! $this->matches( $line, $promotion, $scope, $excluded ) ) {
				continue;
			}
			$qualifying[] = array( 'key' => $line['key'], 'price' => (float) $line['price'], 'qty' => (float) $line['qty'] );
			$total_qty   += (float) $line['qty'];
		}

		if ( $total_qty < $buy_qty || empty( $qualifying ) ) {
			return $result;
		}

		$sets = floor( $total_qty / $buy_qty );
		if ( $promotion->limit_per_order ) {
			$sets = min( $sets, (int) $promotion->limit_per_order );
		}
		if ( $sets < 1 ) {
			return $result;
		}

		// Value the bundled units, most expensive first (most generous).
		usort(
			$qualifying,
			static function ( $a, $b ) {
				return $b['price'] <=> $a['price'];
			}
		);

		$needed           = $buy_qty * $sets;
		$normal           = 0.0;
		$consumed_by_line = array(); // line key => normal value of its consumed units.
		$line_qty         = array(); // line key => total qty in the cart.
		foreach ( $qualifying as $q ) {
			$line_qty[ $q['key'] ] = $q['qty'];
		}
		foreach ( $qualifying as $q ) {
			if ( $needed <= 0 ) {
				break;
			}
			$take    = min( $q['qty'], $needed );
			$value   = $take * $q['price'];
			$normal += $value;

			$consumed_by_line[ $q['key'] ] = ( isset( $consumed_by_line[ $q['key'] ] ) ? $consumed_by_line[ $q['key'] ] : 0 ) + $value;
			$needed                       -= $take;
		}

		$discount = $normal - ( $pay * $sets );
		if ( $discount <= 0 || $normal <= 0 ) {
			return $result;
		}

		// Show the deal on the product line(s): distribute the discount across the
		// lines that contributed bundled units (proportional to their consumed
		// value), expressed as a per-unit reduction. Kept full-precision so the
		// line subtotal stays exact (e.g. 3 kg → ₪100, not ₪99.99). Overage units
		// blend into the same line; the line total stays correct and recalculates
		// automatically when quantity changes.
		$line_discounts = array();
		foreach ( $consumed_by_line as $key => $consumed_val ) {
			$qty = $line_qty[ $key ];
			if ( $qty <= 0 ) {
				continue;
			}
			$line_disc              = $discount * ( $consumed_val / $normal );
			$line_discounts[ $key ] = $line_disc / $qty; // per-unit, unrounded.
		}

		$result['qualifies']     = true;
		$result['line_discounts'] = $line_discounts;
		$result['saved']         = round( $discount, wc_get_price_decimals() );
		return $result;
	}

	public function encouragement( Promotion $promotion, array $cart, array $context ) {
		$buy_qty = (float) $promotion->get( 'buy_quantity', 0 );
		if ( $buy_qty <= 0 ) {
			return '';
		}
		$scope    = $promotion->get( 'scope', 'product' );
		$excluded = array_map( 'intval', (array) $promotion->get( 'excluded_product_ids', array() ) );

		$total_qty = 0.0;
		$present   = false;
		foreach ( $cart as $line ) {
			if ( $this->matches( $line, $promotion, $scope, $excluded ) ) {
				$total_qty += (float) $line['qty'];
				$present    = true;
			}
		}
		if ( ! $present || $total_qty <= 0 ) {
			return ''; // nothing qualifying in the cart — don't nag.
		}

		$name = $promotion->name ? $promotion->name : $this->label( $promotion );

		if ( $total_qty < $buy_qty ) {
			return sprintf(
				/* translators: 1: quantity to add, 2: deal name */
				__( 'Add %1$s more to get the deal: %2$s', 'promotion-engine' ),
				$this->fmt_qty( $buy_qty - $total_qty ),
				$name
			);
		}

		// Deal is active — nudge toward the next set, unless the per-order cap is reached.
		$sets = floor( $total_qty / $buy_qty );
		if ( $promotion->limit_per_order && $sets >= (int) $promotion->limit_per_order ) {
			return '';
		}
		$remainder = $total_qty - ( $sets * $buy_qty );
		if ( $remainder > 0 ) {
			return sprintf(
				/* translators: 1: quantity to add, 2: deal name */
				__( 'Add %1$s more for another deal: %2$s', 'promotion-engine' ),
				$this->fmt_qty( $buy_qty - $remainder ),
				$name
			);
		}
		return '';
	}

	private function fmt_qty( $qty ) {
		return rtrim( rtrim( number_format( (float) $qty, 3, '.', '' ), '0' ), '.' );
	}

	private function matches( array $line, Promotion $promotion, $scope, array $excluded ) {
		if ( ! empty( $line['is_gift'] ) ) {
			return false;
		}
		$ids = array( (int) $line['product_id'] );
		if ( ! empty( $line['parent_id'] ) ) {
			$ids[] = (int) $line['parent_id'];
		}
		if ( array_intersect( $ids, $excluded ) ) {
			return false;
		}
		if ( 'category' === $scope ) {
			$cat_ids   = array_map( 'intval', (array) $promotion->get( 'category_ids', array() ) );
			$line_cats = array_map( 'intval', (array) $line['category_ids'] );
			return (bool) array_intersect( $cat_ids, $line_cats );
		}
		$target = array_map( 'intval', (array) $promotion->get( 'product_ids', array() ) );
		return (bool) array_intersect( $ids, $target );
	}

	private function label( Promotion $promotion ) {
		$qty = (float) $promotion->get( 'buy_quantity', 0 );
		$pay = (float) $promotion->get( 'pay_amount', 0 );
		return sprintf(
			/* translators: 1: quantity, 2: price */
			__( 'Buy %1$s for %2$s', 'promotion-engine' ),
			rtrim( rtrim( (string) $qty, '0' ), '.' ),
			wp_strip_all_tags( wc_price( $pay ) )
		);
	}
}
