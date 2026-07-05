<?php
/**
 * Catalog display: image-overlay label banner + before/after pricing.
 *
 * Pricing supports simple/external (single price) and variable (price range).
 * Grouped products fall back to default html for now (their range is composed
 * of child prices); their children still discount correctly in the cart.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Catalog {

	/** @var Promotion[]|null cached active+live discount promotions. */
	private $promos = null;

	/** @var bool inside a shop-loop item? */
	private $in_loop = false;

	public function __construct() {
		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'open_loop' ), 1 );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'close_loop' ), 99 );
		add_filter( 'woocommerce_product_get_image', array( $this, 'wrap_loop_image' ), 20, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'catalog_price_html' ), 20, 2 );
		// Manual integration hook for fully custom catalog grids.
		add_filter( 'promeng_product_label', array( $this, 'product_label_filter' ), 10, 2 );
	}

	/**
	 * Returns the promotion label for a product (or the passed default/empty),
	 * for themes that render product cards outside the WooCommerce loop.
	 *
	 * @param string $default
	 * @param int    $product_id
	 * @return string
	 */
	public function product_label_filter( $default, $product_id ) {
		$label = $this->label_for( (int) $product_id );
		return '' !== $label ? $label : (string) $default;
	}

	public function open_loop() {
		$this->in_loop = true;
	}

	public function close_loop() {
		$this->in_loop = false;
	}

	/**
	 * @return Promotion[] all active + live promotions.
	 */
	private function promos() {
		if ( null !== $this->promos ) {
			return $this->promos;
		}
		$this->promos = array();
		foreach ( Repository::active() as $p ) {
			if ( $p->is_live() && App::promotion_runs_here( $p ) ) {
				$this->promos[] = $p;
			}
		}
		return $this->promos;
	}

	private function targets_product( Promotion $p, $product_id, array $product_cats ) {
		$applies = $p->get( 'applies_to', 'all' );
		if ( 'cart' === $applies ) {
			return false;
		}
		$excluded = array_map( 'intval', (array) $p->get( 'excluded_product_ids', array() ) );
		if ( in_array( (int) $product_id, $excluded, true ) ) {
			return false;
		}
		if ( 'all' === $applies ) {
			return true;
		}
		if ( 'products' === $applies ) {
			return in_array( (int) $product_id, array_map( 'intval', (array) $p->get( 'product_ids', array() ) ), true );
		}
		if ( 'categories' === $applies ) {
			return (bool) array_intersect( array_map( 'intval', (array) $p->get( 'category_ids', array() ) ), $product_cats );
		}
		return false;
	}

	private function has_condition( Promotion $p ) {
		return (int) $p->get( 'condition_min_items', 0 ) > 0 || (float) $p->get( 'condition_min_amount', 0 ) > 0;
	}

	private function product_cats( $product_id ) {
		$cats = wc_get_product_term_ids( $product_id, 'product_cat' );
		$cats = $cats ? $cats : array();
		$all  = $cats;
		foreach ( $cats as $cat_id ) {
			$all = array_merge( $all, get_ancestors( $cat_id, 'product_cat' ) );
		}
		return array_values( array_unique( array_map( 'intval', $all ) ) );
	}

	/**
	 * Does this promotion target the product (for the catalog label)?
	 * Dispatches per type so labels work for all three promotion types.
	 */
	private function label_targets( Promotion $p, $product_id, array $product_cats ) {
		// A discount shown as a cart-summary line is not displayed on products.
		if ( 'discount' === $p->type && $p->get( 'as_cart_discount', false ) ) {
			return false;
		}
		switch ( $p->type ) {
			case 'discount':
				return $this->targets_product( $p, $product_id, $product_cats );
			case 'buy_x_pay_y':
				return $this->targets_buy_x_pay_y( $p, $product_id, $product_cats );
			case 'buy_x_get_y':
				return $this->targets_buy_x_get_y( $p, $product_id, $product_cats );
		}
		return false;
	}

	private function targets_buy_x_pay_y( Promotion $p, $product_id, array $product_cats ) {
		$excluded = array_map( 'intval', (array) $p->get( 'excluded_product_ids', array() ) );
		if ( in_array( (int) $product_id, $excluded, true ) ) {
			return false;
		}
		if ( 'category' === $p->get( 'scope', 'product' ) ) {
			return (bool) array_intersect( array_map( 'intval', (array) $p->get( 'category_ids', array() ) ), $product_cats );
		}
		return in_array( (int) $product_id, array_map( 'intval', (array) $p->get( 'product_ids', array() ) ), true );
	}

	private function targets_buy_x_get_y( Promotion $p, $product_id, array $product_cats ) {
		// The benefit product always carries the badge ("get this cheaper").
		$benefit = array_map( 'intval', (array) $p->get( 'benefit_product_ids', array() ) );
		if ( in_array( (int) $product_id, $benefit, true ) ) {
			return true;
		}
		// The trigger products carry it too — unless the trigger is "any product"
		// (labelling the whole catalog would be wrong).
		$buy_applies = $p->get( 'buy_applies_to', 'all' );
		if ( 'products' === $buy_applies ) {
			return in_array( (int) $product_id, array_map( 'intval', (array) $p->get( 'buy_product_ids', array() ) ), true );
		}
		if ( 'categories' === $buy_applies ) {
			return (bool) array_intersect( array_map( 'intval', (array) $p->get( 'buy_category_ids', array() ) ), $product_cats );
		}
		return false;
	}

	/**
	 * Label (promotion name) for a product, or '' — includes conditional promos.
	 */
	private function label_for( $product_id ) {
		$cats = $this->product_cats( $product_id );
		foreach ( $this->promos() as $p ) {
			if ( $p->show_label && $this->label_targets( $p, $product_id, $cats ) ) {
				return $p->name ? $p->name : __( 'On Sale', 'promotion-engine' );
			}
		}
		return '';
	}

	/**
	 * Best unconditional, automatic discount spec for catalog display.
	 *
	 * @return array|null ['type'=>'percent'|'fixed','value'=>float]
	 */
	private function best_catalog_spec( $product_id, $basis_price ) {
		$cats       = $this->product_cats( $product_id );
		$best       = null;
		$best_price = $basis_price;
		foreach ( $this->promos() as $p ) {
			if ( 'discount' !== $p->type ) {
				continue; // before/after catalog price is a discount-type concept.
			}
			if ( $p->get( 'as_cart_discount', false ) ) {
				continue; // shown in the cart totals, not on the product.
			}
			if ( ! $p->is_automatic() || $this->has_condition( $p ) ) {
				continue;
			}
			// Item-limited discounts cap how many units are discounted, so a
			// per-product catalog price cannot be guaranteed — skip those.
			if ( (int) $p->get( 'limit_discounted_items', 0 ) > 0 ) {
				continue;
			}
			if ( ! $this->targets_product( $p, $product_id, $cats ) ) {
				continue;
			}
			$type  = $p->get( 'discount_type', 'percent' );
			$value = (float) $p->get( 'discount_value', 0 );
			if ( $value <= 0 ) {
				continue;
			}
			$new = $this->apply_spec( $basis_price, array( 'type' => $type, 'value' => $value ) );
			if ( $new < $best_price ) {
				$best_price = $new;
				$best       = array( 'type' => $type, 'value' => $value );
			}
		}
		return $best;
	}

	private function apply_spec( $price, array $spec ) {
		return 'percent' === $spec['type'] ? $price * ( 1 - $spec['value'] / 100 ) : max( 0, $price - $spec['value'] );
	}

	public function wrap_loop_image( $html, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $html;
		}
		// Show on the shop loop, and also on custom catalog grids that render
		// product images on archive/search pages without firing the loop hooks
		// — but never on cart, checkout or single-product thumbnails.
		$on_catalog = $this->in_loop
			|| ( ! is_admin() && ! is_cart() && ! is_checkout() && ! is_product()
				&& ( is_shop() || is_product_taxonomy() || is_search() || is_post_type_archive( 'product' ) ) );
		if ( ! $on_catalog ) {
			return $html;
		}
		$label = $this->label_for( $product->get_id() );
		if ( ! $label ) {
			return $html;
		}
		return '<span class="promeng-thumb"><span class="promeng-banner">' . esc_html( $label ) . '</span>' . $html . '</span>';
	}

	/**
	 * Before/after price html for simple/external and variable products.
	 */
	public function catalog_price_html( $html, $product ) {
		if ( is_admin() || ! $product instanceof \WC_Product ) {
			return $html;
		}
		$type = $product->get_type();

		if ( 'simple' === $type || 'external' === $type ) {
			$base = (float) wc_get_price_to_display( $product );
			if ( $base <= 0 ) {
				return $html;
			}
			$spec = $this->best_catalog_spec( $product->get_id(), $base );
			if ( ! $spec ) {
				return $html;
			}
			$new = $this->apply_spec( $base, $spec );
			if ( $new >= $base ) {
				return $html;
			}
			return $this->ba( wc_price( $base ), wc_price( $new ) );
		}

		if ( 'variable' === $type ) {
			$min = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'min' ) ) );
			$max = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'max' ) ) );
			if ( $min <= 0 ) {
				return $html;
			}
			// Pick the promo on the min price; apply the same spec across the range.
			$spec = $this->best_catalog_spec( $product->get_id(), $min );
			if ( ! $spec ) {
				return $html;
			}
			$new_min = $this->apply_spec( $min, $spec );
			$new_max = $this->apply_spec( $max, $spec );
			if ( $new_min >= $min ) {
				return $html;
			}
			$old_html = ( $min === $max ) ? wc_price( $min ) : wc_format_price_range( $min, $max );
			$new_html = ( $new_min === $new_max ) ? wc_price( $new_min ) : wc_format_price_range( $new_min, $new_max );
			return $this->ba( $old_html, $new_html );
		}

		// grouped / other types: leave default for now (cart still discounts children).
		return $html;
	}

	private function ba( $old, $new ) {
		return '<span class="promeng-was" style="text-decoration:line-through;color:#6b7280;opacity:1;margin-inline-end:6px;">' . wp_kses_post( $old ) . '</span> '
			. '<span class="promeng-now" style="color:#dc2626;font-weight:700;">' . wp_kses_post( $new ) . '</span>';
	}
}
