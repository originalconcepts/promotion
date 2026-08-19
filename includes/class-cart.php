<?php
/**
 * WooCommerce cart integration.
 *
 * Applies engine results to the cart and renders before/after pricing.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cart {

	/** @var Engine */
	private $engine;

	/** @var array|null Cached last evaluation for the current request. */
	private $last_eval = null;

	/** @var bool Encouragement messages already rendered this request? */
	private $messages_rendered = false;

	/** @var bool Savings line already rendered this request? */
	private $savings_rendered = false;

	/** @var bool Mini-cart "total after discount" line already rendered this request? */
	private $total_after_rendered = false;

	/** @var bool Re-entrancy guard for auto-gift syncing. */
	private $syncing_gifts = false;

	/**
	 * The registered instance, for outside callers (theme mini-carts).
	 *
	 * @var Cart|null
	 */
	private static $instance = null;

	/**
	 * @return Cart|null
	 */
	public static function instance() {
		return self::$instance;
	}

	public function __construct( Engine $engine ) {
		self::$instance = $this;
		$this->engine = $engine;

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_line_discounts' ), 20, 1 );
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_cart_fees' ), 20, 1 );

		// Before/after price display in the cart line + subtotal.
		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price_html' ), 20, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'cart_item_subtotal_html' ), 20, 3 );

		// Cart page: render the discount as a row right inside the totals table,
		// next to the order total (not at the top of the page). A box after the
		// totals table is the only fallback, for themes that don't fire the
		// in-table hook.
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_cart_savings' ) );
		add_action( 'woocommerce_after_cart_totals', array( $this, 'render_cart_savings_box' ), 20 );
		// Mini-cart / side-drawer carts (used by many custom storefronts) fire
		// their own hooks rather than the cart-page ones above.
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'render_cart_savings_box' ), 5 );
		// Priority 15: AFTER WC's subtotal (10), so the mini-cart reads
		// subtotal → discount → total-after-discount (20).
		add_action( 'woocommerce_widget_shopping_cart_total', array( $this, 'render_cart_savings_box' ), 15 );
		add_action( 'woocommerce_after_mini_cart', array( $this, 'render_cart_savings_box' ), 5 );
		// Mini-cart only: a reconciling "total after discount" line, rendered just
		// after the subtotal (WC's subtotal is @ priority 10 on this same hook).
		add_action( 'woocommerce_widget_shopping_cart_total', array( $this, 'render_mini_cart_total_after' ), 20 );
		add_shortcode( 'promeng_savings', array( $this, 'savings_shortcode' ) );

		// Encouragement nudges. Several hook points for theme resilience; a
		// guard makes sure they render only once per page.
		add_action( 'woocommerce_before_cart', array( $this, 'render_cart_messages' ), 20 );
		add_action( 'woocommerce_before_cart_table', array( $this, 'render_cart_messages' ), 20 );
		add_action( 'woocommerce_before_cart_totals', array( $this, 'render_cart_messages' ), 5 );
		// Reliable fallback: a row inside the cart-totals table (fires on nearly
		// every classic theme, same place as the savings line).
		add_action( 'woocommerce_cart_totals_before_order_total', array( $this, 'render_cart_messages_row' ), 5 );
		// Checkout: the same nudges as a row inside the order-review table. This
		// hook is the right one because it fires BOTH on the initial page render
		// and inside every update_order_review AJAX refresh — a div outside the
		// table would duplicate or vanish when WooCommerce re-renders the review.
		add_action( 'woocommerce_review_order_before_order_total', array( $this, 'render_cart_messages_row' ), 5 );
		// Universal manual placement for any theme/builder.
		add_shortcode( 'promeng_cart_messages', array( $this, 'messages_shortcode' ) );

		// Persist the discounted prices onto the actual order line items so the
		// placed order reflects what the customer saw in the cart.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'set_order_line_item_discount' ), 20, 4 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_applied_on_order' ), 20, 2 );

		add_action( 'woocommerce_order_status_processing', array( Usage::class, 'record_for_order' ) );
		add_action( 'woocommerce_order_status_completed', array( Usage::class, 'record_for_order' ) );

		// Show the promotion savings in the customer's order (account + emails).
		add_filter( 'woocommerce_get_order_item_totals', array( $this, 'order_savings_total_row' ), 10, 3 );
		// Struck original + discounted price per line in the order (account + emails).
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( $this, 'order_line_subtotal_html' ), 10, 3 );
		// For our "unlock token" coupons, show the real engine savings (not ₪0).
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_savings_html' ), 10, 3 );

		// Auto-add free gifts: keep them in sync with cart lifecycle events.
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sync_auto_gifts' ), 20 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'sync_auto_gifts' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'sync_auto_gifts' ), 20 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'sync_auto_gifts' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'sync_auto_gifts' ), 20 );
		// Gift lines are managed by the plugin: lock qty, hide remove, badge it.
		// High priority so a gift line's quantity wins over unit/weight plugins
		// (e.g. OC Sale Units, which otherwise renders it as a kg weight).
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'gift_quantity_html' ), 999, 3 );
		add_filter( 'woocommerce_widget_cart_item_quantity', array( $this, 'gift_widget_quantity_html' ), 999, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( $this, 'gift_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'gift_item_name' ), 10, 2 );
	}

	/**
	 * Build a normalized snapshot of the WC cart for the engine.
	 *
	 * @return array [ array $lines, array $context ]
	 */
	private function snapshot( \WC_Cart $cart, $exclude_gifts = false ) {
		$lines             = array();
		$subtotal          = 0.0;
		$display_subtotal  = 0.0;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( $exclude_gifts && ! empty( $item['promeng_gift'] ) ) {
				continue; // don't let an auto-added gift count toward the buy condition.
			}
			$product = $item['data'];
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$unit_price = isset( $item['promeng_base_price'] ) ? (float) $item['promeng_base_price'] : (float) $product->get_price();
			$qty        = (float) $item['quantity'];
			$line_total = $unit_price * $qty;
			$subtotal  += $line_total;

			// Display total = what the customer sees (tax-inclusive if the store
			// shows prices incl. tax). Used for purchase-threshold comparisons so
			// "on orders over 300" matches the on-screen cart, not the ex-tax base.
			$display_subtotal += (float) wc_get_price_to_display( $product, array( 'price' => $unit_price, 'qty' => $qty ) );

			$lines[] = array(
				'key'          => $key,
				'product_id'   => $product->get_id(),
				'parent_id'    => $product->get_parent_id(),
				'category_ids' => $this->product_category_ids( $product ),
				'price'        => $unit_price,
				'qty'          => $qty,
				'line_total'   => $line_total,
				'is_gift'      => ! empty( $item['promeng_gift'] ),
			);
		}

		$customer_email = WC()->customer ? WC()->customer->get_billing_email() : '';

		$applied_coupons = array();
		foreach ( (array) $cart->get_applied_coupons() as $code ) {
			$applied_coupons[] = strtolower( (string) $code );
		}

		$context = array(
			'subtotal'         => $subtotal,
			'display_subtotal' => $display_subtotal,
			'customer_id'      => get_current_user_id(),
			'customer_email'   => $customer_email,
			'is_logged_in'     => is_user_logged_in(),
			'applied_coupons'  => $applied_coupons,
			'channel'          => App::current_channel(),
		);

		return array( $lines, $context );
	}

	/**
	 * Category ids including ancestors.
	 *
	 * @return int[]
	 */
	private function product_category_ids( \WC_Product $product ) {
		$id  = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$ids = wc_get_product_term_ids( $id, 'product_cat' );
		$all = $ids;
		foreach ( $ids as $cat_id ) {
			$all = array_merge( $all, get_ancestors( $cat_id, 'product_cat' ) );
		}
		return array_values( array_unique( array_map( 'intval', $all ) ) );
	}

	/**
	 * Apply per-line discounts by lowering each qualifying line's unit price.
	 *
	 * Idempotent: always computes from the stamped base price, so it is safe to
	 * run on every recalculation (no compounding) — which is why we do NOT use a
	 * did_action guard here.
	 */
	public function apply_line_discounts( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return;
		}

		// Stamp original prices once, before any discount, so recompute is stable.
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! isset( $cart->cart_contents[ $key ]['promeng_base_price'] ) ) {
				$cart->cart_contents[ $key ]['promeng_base_price'] = (float) $item['data']->get_price();
			}
		}

		list( $lines, $context ) = $this->snapshot( $cart );
		$eval                    = $this->engine->evaluate( $lines, $context );
		$this->last_eval         = $eval;

		foreach ( $cart->get_cart() as $key => $item ) {
			if ( isset( $eval['line_discounts'][ $key ] ) && $eval['line_discounts'][ $key ] > 0 ) {
				$base = (float) $cart->cart_contents[ $key ]['promeng_base_price'];
				$new  = max( 0, $base - (float) $eval['line_discounts'][ $key ] );
				$item['data']->set_price( $new );
			}
		}
	}

	/**
	 * Apply whole-cart discounts as negative fees.
	 */
	public function apply_cart_fees( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return;
		}

		if ( null === $this->last_eval ) {
			list( $lines, $context ) = $this->snapshot( $cart );
			$this->last_eval         = $this->engine->evaluate( $lines, $context );
		}

		foreach ( $this->last_eval['cart_fees'] as $fee ) {
			if ( $fee['amount'] > 0 ) {
				$cart->add_fee( $fee['name'], - $fee['amount'], false );
			}
		}
	}

	/**
	 * Cart line unit price: show original struck-through + discounted.
	 */
	public function cart_item_price_html( $price_html, $cart_item, $cart_item_key ) {
		if ( ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return $price_html;
		}
		// Auto-added free gift: show a marketing "Free gift" tag (works on themes
		// that render the price filter but not the cart-item-name filter).
		if ( ! empty( $cart_item['promeng_gift'] ) ) {
			$base = isset( $cart_item['promeng_base_price'] ) ? (float) $cart_item['promeng_base_price'] : (float) $cart_item['data']->get_price();
			return $this->gift_price_html( $base );
		}
		if ( ! isset( $cart_item['promeng_base_price'] ) ) {
			return $price_html;
		}
		$base    = (float) $cart_item['promeng_base_price'];
		$current = (float) $cart_item['data']->get_price();
		if ( $current >= $base ) {
			return $price_html;
		}
		return $this->before_after_html( $base, $current );
	}

	/**
	 * Cart line subtotal: show original struck-through + discounted (x qty).
	 */
	public function cart_item_subtotal_html( $subtotal_html, $cart_item, $cart_item_key ) {
		if ( ! ( $cart_item['data'] instanceof \WC_Product ) ) {
			return $subtotal_html;
		}
		if ( ! empty( $cart_item['promeng_gift'] ) ) {
			$qty  = (float) $cart_item['quantity'];
			$base = ( isset( $cart_item['promeng_base_price'] ) ? (float) $cart_item['promeng_base_price'] : (float) $cart_item['data']->get_price() ) * $qty;
			return $this->gift_price_html( $base );
		}
		if ( ! isset( $cart_item['promeng_base_price'] ) ) {
			return $subtotal_html;
		}
		$qty     = (float) $cart_item['quantity'];
		$base    = (float) $cart_item['promeng_base_price'] * $qty;
		$current = (float) $cart_item['data']->get_price() * $qty;
		if ( $current >= $base ) {
			return $subtotal_html;
		}
		return $this->before_after_html( $base, $current );
	}

	private function before_after_html( $base, $current ) {
		return '<span class="promeng-was" style="text-decoration:line-through;color:#6b7280;opacity:1;margin-inline-end:6px;">' . wp_kses_post( wc_price( $base ) ) . '</span> '
			. '<span class="promeng-now" style="color:#dc2626;font-weight:700;">' . wp_kses_post( wc_price( $current ) ) . '</span>';
	}

	/**
	 * Price/subtotal markup for an auto-added free gift line: the original value
	 * struck through, plus a "Free gift" tag. Shown via the cart-item price and
	 * subtotal filters so it appears even on themes that render the product name
	 * without the woocommerce_cart_item_name filter.
	 */
	private function gift_price_html( $base ) {
		$tag = '<span class="promeng-gift-badge">🎁 ' . esc_html__( 'Free gift', 'promotion-engine' ) . '</span>';
		if ( $base > 0 ) {
			return '<span class="promeng-was" style="text-decoration:line-through;color:#6b7280;margin-inline-end:6px;">' . wp_kses_post( wc_price( $base ) ) . '</span> ' . $tag;
		}
		return $tag;
	}

	/**
	 * Show a total-savings line in the cart totals.
	 */
	/**
	 * Encouragement nudges ("add X to unlock…") above the cart / checkout.
	 */
	/**
	 * Build the promotion-messages HTML, or '' when there's nothing to show:
	 * first the promotions already APPLIED (name + amount saved, with a check
	 * mark), then the encouragement nudges toward ones within reach.
	 */
	private function build_messages_html() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return '';
		}
		list( $lines, $context ) = $this->snapshot( WC()->cart );
		$messages                = $this->engine->messages( $lines, $context );
		$messages                = array_slice( $messages, 0, 4 ); // keep it from getting noisy.
		$savings                 = $this->savings_data();

		if ( empty( $messages ) && null === $savings ) {
			return '';
		}

		$icon = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false">'
			. '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
			. 'd="M19 5L5 19M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z"/></svg>';

		$check = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false">'
			. '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.5l5 5L19.5 7"/></svg>';

		$html = '<div class="promeng-cart-messages">';

		if ( null !== $savings ) {
			foreach ( $savings['items'] as $row ) {
				$html .= '<div class="promeng-cart-message promeng-cart-message--applied"><span class="promeng-cart-message-icon">' . $check . '</span>'
					. '<span class="promeng-cart-message-text">' . sprintf(
						/* translators: 1: promotion name, 2: amount saved. */
						esc_html__( '%1$s — you saved %2$s', 'promotion-engine' ),
						esc_html( $row['name'] ),
						wp_kses_post( wc_price( $row['saved'] ) )
					) . '</span></div>';
			}
		}

		foreach ( $messages as $m ) {
			$html .= '<div class="promeng-cart-message"><span class="promeng-cart-message-icon">' . $icon . '</span>'
				. '<span class="promeng-cart-message-text">' . esc_html( $m ) . '</span></div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Auto-injected nudges (div) for non-table hook points. Renders once.
	 */
	public function render_cart_messages() {
		if ( $this->messages_rendered ) {
			return;
		}
		$html = $this->build_messages_html();
		if ( '' === $html ) {
			return;
		}
		$this->messages_rendered = true;
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Fallback nudges as a full-width row inside the cart-totals table — the
	 * one place that renders on virtually every classic theme. Renders once.
	 */
	public function render_cart_messages_row() {
		if ( $this->messages_rendered ) {
			return;
		}
		$html = $this->build_messages_html();
		if ( '' === $html ) {
			return;
		}
		$this->messages_rendered = true;
		echo '<tr class="promeng-cart-messages-row"><td colspan="2">' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Universal manual placement: [promeng_cart_messages]. Works on any theme or
	 * builder (drop it on the cart/checkout page). Always renders if there are
	 * messages; suppresses the auto-injected copy to avoid duplicates.
	 */
	public function messages_shortcode() {
		$html = $this->build_messages_html();
		if ( '' === $html ) {
			return '';
		}
		$this->messages_rendered = true;
		return $html;
	}

	/**
	 * Structured messages for a mini-cart panel: applied promotions (name,
	 * amount saved) and encouragement nudges — each with the cart line keys
	 * it refers to, so the panel can pin it to the product's row.
	 *
	 * @return array<int,array{text:string,keys:array<int,string>,applied:bool}>
	 */
	public function panel_messages() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}

		list( $lines, $context ) = $this->snapshot( WC()->cart );
		$out                     = array();

		$savings = $this->savings_data();
		if ( null !== $savings ) {
			if ( null === $this->last_eval ) {
				$this->last_eval = $this->engine->evaluate( $lines, $context );
			}
			foreach ( $this->last_eval['applied'] as $info ) {
				if ( (float) $info['saved'] <= 0 || $this->is_free_gift_promo( isset( $info['promotion'] ) ? $info['promotion'] : null ) ) {
					continue;
				}
				$promotion = $info['promotion'];
				$type      = $this->engine->get_type( $promotion->type );
				$out[]     = array(
					'text'     => sprintf(
						/* translators: 1: promotion name, 2: amount saved. */
						__( '%1$s — you saved %2$s', 'promotion-engine' ),
						$promotion->name ? $promotion->name : $info['label'],
						html_entity_decode( wp_strip_all_tags( wc_price( (float) $info['saved'] ) ), ENT_QUOTES, 'UTF-8' )
					),
					'name'     => $promotion->name ? $promotion->name : $info['label'],
					'promo_id' => (int) $promotion->id,
					'pool'     => $this->pool_kind( $promotion ),
					'keys'     => $type ? array_map( 'strval', (array) $type->related_keys( $promotion, $lines ) ) : array(),
					'applied'  => true,
				);
			}
		}

		foreach ( $this->engine->messages_detailed( $lines, $context ) as $row ) {
			$promotion = isset( $row['promotion'] ) ? $row['promotion'] : null;
			$out[]     = array(
				'text'     => (string) $row['text'],
				'name'     => $promotion && $promotion->name ? $promotion->name : (string) $row['text'],
				'promo_id' => $promotion ? (int) $promotion->id : 0,
				'pool'     => $promotion ? $this->pool_kind( $promotion ) : 'none',
				'keys'     => array_map( 'strval', (array) $row['keys'] ),
				'applied'  => false,
			);
		}

		return $out;
	}

	/**
	 * What the promotion's target pool looks like: one specific product, a
	 * group (several products / categories), or nothing enumerable.
	 *
	 * @return string single|group|none
	 */
	private function pool_kind( Promotion $promotion ) {
		$ids  = $this->pool_product_ids( $promotion );
		$cats = $this->pool_category_ids( $promotion );

		if ( count( $ids ) === 1 && empty( $cats ) ) {
			return 'single';
		}
		if ( ! empty( $ids ) || ! empty( $cats ) ) {
			return 'group';
		}
		return 'none';
	}

	private function pool_product_ids( Promotion $promotion ) {
		foreach ( array( 'buy_product_ids', 'product_ids' ) as $field ) {
			$ids = array_filter( array_map( 'intval', (array) $promotion->get( $field, array() ) ) );
			if ( $ids ) {
				return array_values( $ids );
			}
		}
		return array();
	}

	private function pool_category_ids( Promotion $promotion ) {
		foreach ( array( 'buy_category_ids', 'category_ids' ) as $field ) {
			$ids = array_filter( array_map( 'intval', (array) $promotion->get( $field, array() ) ) );
			if ( $ids ) {
				return array_values( $ids );
			}
		}
		return array();
	}

	/**
	 * The products participating in a promotion, for a "show me them" popup.
	 *
	 * @param int $promo_id Promotion id.
	 * @param int $limit    Cap.
	 * @return int[] product ids.
	 */
	public function promotion_products( $promo_id, $limit = 12 ) {
		$promotion = Repository::find( (int) $promo_id );

		if ( ! $promotion ) {
			return array();
		}

		$ids = $this->pool_product_ids( $promotion );

		if ( $ids ) {
			return array_slice( $ids, 0, $limit );
		}

		$cats = $this->pool_category_ids( $promotion );

		if ( ! $cats ) {
			return array();
		}

		$slugs = array();
		foreach ( $cats as $cat_id ) {
			$slug = get_term_field( 'slug', $cat_id, 'product_cat' );
			if ( ! is_wp_error( $slug ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs ? wc_get_products(
			array(
				'status'   => 'publish',
				'limit'    => $limit,
				'category' => $slugs,
				'return'   => 'ids',
			)
		) : array();
	}

	/**
	 * Total saved + the per-promotion breakdown rows, or null if nothing saved.
	 */
	private function savings_data() {
		if ( null === $this->last_eval ) {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return null;
			}
			list( $lines, $context ) = $this->snapshot( WC()->cart );
			$this->last_eval         = $this->engine->evaluate( $lines, $context );
		}
		if ( empty( $this->last_eval['applied'] ) ) {
			return null;
		}
		$total = 0.0;
		$items = array();
		foreach ( $this->last_eval['applied'] as $info ) {
			if ( (float) $info['saved'] <= 0 ) {
				continue;
			}
			// A free gift is its own reward — it already shows a "gift" tag on the
			// line — so don't also list it as a "you saved" amount.
			if ( $this->is_free_gift_promo( isset( $info['promotion'] ) ? $info['promotion'] : null ) ) {
				continue;
			}
			$total   += (float) $info['saved'];
			$items[]  = array(
				// The promotion's own name (e.g. "5% off for members") so the shopper
				// sees what the discount is, not just a generic "you saved".
				'name'  => $info['promotion']->name ? $info['promotion']->name : $info['label'],
				'saved' => (float) $info['saved'],
			);
		}
		if ( $total <= 0 ) {
			return null;
		}
		return array( 'total' => $total, 'items' => $items );
	}

	/**
	 * Is this an auto-added free gift promotion (Buy X → get a specific product
	 * free)? Its saving is represented by the gift line itself, so it is kept out
	 * of the "you saved" summary.
	 */
	private function is_free_gift_promo( $promotion ) {
		return $promotion instanceof \PromoEngine\Promotion
			&& 'buy_x_get_y' === $promotion->type
			&& 'free' === $promotion->get( 'benefit_type', 'free' )
			&& 'products' === $promotion->get( 'benefit_applies_to', 'products' );
	}

	private function savings_info_svg() {
		return '<svg viewBox="0 0 20 20" width="15" height="15" aria-hidden="true" focusable="false">'
			. '<circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/>'
			. '<circle cx="10" cy="6" r="1.1" fill="currentColor"/>'
			. '<rect x="9.1" y="8.6" width="1.8" height="6" rx="0.9" fill="currentColor"/></svg>';
	}

	/**
	 * Savings line as a totals-table row (classic cart/checkout totals).
	 */
	public function render_cart_savings() {
		if ( $this->savings_rendered ) {
			return;
		}
		$data = $this->savings_data();
		if ( ! $data ) {
			return;
		}
		$this->savings_rendered = true;

		// One row per promotion, labelled with the promotion's name. Uses the same
		// <th>/<td> structure as the standard WooCommerce cart-totals rows so it
		// lines up with the subtotal / total rows around it.
		$html = '';
		foreach ( $data['items'] as $item ) {
			$html .= '<tr class="promeng-savings promeng-cart-discount">'
				. '<th class="promeng-savings-label">' . esc_html( $item['name'] ) . '</th>'
				// wc_price( -amount ) formats the minus the same way WooCommerce does
				// on the checkout fee row, so the sign sits on the same side everywhere.
				. '<td class="promeng-savings-amount" data-title="' . esc_attr( $item['name'] ) . '">' . wp_kses_post( wc_price( - $item['saved'] ) ) . '</td>'
				. '</tr>';
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Savings line as a standalone box, for hook points outside the totals
	 * table (and the same markup the shortcode uses). Renders once.
	 */
	public function render_cart_savings_box() {
		if ( $this->savings_rendered ) {
			return;
		}
		$html = $this->savings_shortcode(); // sets the guard + returns markup.
		if ( '' === $html ) {
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Universal manual placement of the savings line: [promeng_savings].
	 */
	public function savings_shortcode() {
		$data = $this->savings_data();
		if ( ! $data ) {
			return '';
		}
		$this->savings_rendered = true;

		$out = '';
		foreach ( $data['items'] as $item ) {
			// A plain line (promotion name + amount), like the cart-page discount
			// row. A <span> (not <p>/<div>) so it stays valid even when the theme
			// fires the mini-cart total hook from inside a <p> — the CSS makes it a
			// full-width flex line.
			$out .= '<span class="promeng-savings-line">'
				. '<span class="promeng-savings-label">' . esc_html( $item['name'] ) . '</span>'
				. '<span class="promeng-savings-amount">' . wp_kses_post( wc_price( - $item['saved'] ) ) . '</span>'
				. '</span>';
		}
		return $out;
	}

	/**
	 * Mini-cart only: a "total after discount" line, printed just under the subtotal.
	 *
	 * The mini-cart shows WooCommerce's cart *subtotal*, which by design excludes cart
	 * fees — and a whole-cart discount is applied as a negative fee — so the subtotal
	 * stays at the pre-discount amount. With a discount line sitting above it and no
	 * final total, the numbers don't reconcile. This adds one line showing what the
	 * shopper will actually pay for the goods (subtotal + fees), so it all adds up.
	 *
	 * Rendered only when a cart-level fee is actually reducing the total; pure line-level
	 * discounts already show through in the subtotal, so no reconciling line is needed.
	 */
	public function render_mini_cart_total_after() {
		if ( $this->total_after_rendered ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		// Populate the engine's evaluation (also confirms there is a saving to explain).
		if ( ! $this->savings_data() ) {
			return;
		}
		// Only the CART-LEVEL (fee) portion is missing from the subtotal; line-level
		// discounts already show through it. Read the fee portion straight from the
		// engine — WooCommerce's get_fee_total() isn't populated while the mini-cart
		// fragment renders, so relying on it would (wrongly) suppress this line.
		$cart_fee_savings = 0.0;
		if ( is_array( $this->last_eval ) && ! empty( $this->last_eval['cart_fees'] ) ) {
			foreach ( $this->last_eval['cart_fees'] as $fee ) {
				if ( (float) $fee['amount'] > 0 ) {
					$cart_fee_savings += (float) $fee['amount'];
				}
			}
		}
		if ( $cart_fee_savings <= 0.005 ) {
			return; // Nothing in the subtotal left to reconcile.
		}
		$this->total_after_rendered = true;

		$subtotal = (float) WC()->cart->get_subtotal();
		if ( WC()->cart->display_prices_including_tax() ) {
			$subtotal += (float) WC()->cart->get_subtotal_tax();
		}
		$net = $subtotal - $cart_fee_savings;
		if ( $net < 0 ) {
			$net = 0.0;
		}

		echo '<span class="promeng-savings-line promeng-total-after">'
			. '<span class="promeng-savings-label">' . esc_html__( 'Total after discount', 'promotion-engine' ) . '</span>'
			. '<span class="promeng-savings-amount">' . wp_kses_post( wc_price( $net ) ) . '</span>'
			. '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Write the discounted price onto the actual order line item.
	 *
	 * The cart shows discounted prices via a runtime set_price(); this makes the
	 * placed order match by setting the line subtotal (original) and total
	 * (discounted) so the order — and everything downstream — reflects the deal.
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array                  $values
	 * @param \WC_Order              $order
	 */
	public function set_order_line_item_discount( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['data'] ) || ! $values['data'] instanceof \WC_Product ) {
			return;
		}
		if ( ! isset( $values['promeng_base_price'] ) ) {
			return; // line we never touched.
		}
		$base    = (float) $values['promeng_base_price'];
		$current = (float) $values['data']->get_price();
		if ( $current >= $base ) {
			return; // no active discount on this line.
		}
		$qty = (float) $values['quantity'];

		// Subtotal = original (so the markdown is visible on the order),
		// total = discounted (what the customer actually pays).
		$item->set_subtotal( $base * $qty );
		$item->set_total( $current * $qty );
	}

	/**
	 * Keep auto-add free gifts in sync with what the cart currently earns.
	 * Runs outside totals calculation (cart lifecycle events) so adding/removing
	 * items is safe; a guard prevents re-entrancy.
	 */
	public function sync_auto_gifts() {
		if ( $this->syncing_gifts ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$this->syncing_gifts = true;

		// Evaluate the buy condition against non-gift lines only.
		list( $lines, $context ) = $this->snapshot( $cart, true );
		$desired                 = $this->engine->auto_gifts( $lines, $context );

		// What auto-gifts are already in the cart: promo_id => cart_item_key.
		$existing = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['promeng_gift'] ) ) {
				$existing[ (int) $item['promeng_gift'] ] = $key;
			}
		}

		// Remove gifts that are no longer earned.
		foreach ( $existing as $promo_id => $key ) {
			if ( empty( $desired[ $promo_id ] ) ) {
				$cart->remove_cart_item( $key );
			}
		}

		// Add or right-size the earned gifts.
		foreach ( $desired as $promo_id => $gift ) {
			$pid = (int) $gift['product_id'];
			$qty = (int) $gift['qty'];
			if ( $pid <= 0 || $qty <= 0 ) {
				continue;
			}
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			if ( isset( $existing[ $promo_id ] ) ) {
				$key   = $existing[ $promo_id ];
				$items = $cart->get_cart();
				if ( isset( $items[ $key ] ) && (float) $items[ $key ]['quantity'] !== (float) $qty ) {
					$cart->set_quantity( $key, $qty, false );
				}
			} else {
				$cart->add_to_cart( $pid, $qty, 0, array(), array( 'promeng_gift' => $promo_id ) );
			}
		}

		$this->syncing_gifts = false;
	}

	/**
	 * Lock the quantity field for gift lines (shown as plain text, not editable).
	 */
	public function gift_quantity_html( $html, $cart_item_key, $cart_item ) {
		if ( ! empty( $cart_item['promeng_gift'] ) ) {
			return $this->gift_quantity_label( $cart_item );
		}
		return $html;
	}

	/**
	 * Same, for the mini-cart / side drawer (different filter + argument order).
	 */
	public function gift_widget_quantity_html( $html, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['promeng_gift'] ) ) {
			return $this->gift_quantity_label( $cart_item );
		}
		return $html;
	}

	/**
	 * A gift line is a fixed, non-editable count of units — render it as
	 * "N pcs", never as a weight.
	 */
	private function gift_quantity_label( $cart_item ) {
		$qty = (float) $cart_item['quantity'];
		$n   = ( floor( $qty ) === $qty ) ? (string) (int) $qty : (string) $qty;
		return '<span class="quantity promeng-gift-qty">' . esc_html( $n . ' ' . __( 'pcs', 'promotion-engine' ) ) . '</span>';
	}

	/**
	 * Hide the remove link for gift lines (they manage themselves).
	 */
	public function gift_remove_link( $link, $cart_item_key ) {
		$cart = WC()->cart ? WC()->cart->get_cart() : array();
		if ( isset( $cart[ $cart_item_key ] ) && ! empty( $cart[ $cart_item_key ]['promeng_gift'] ) ) {
			return '';
		}
		return $link;
	}

	/**
	 * Tag the gift line name with a small "Free gift" badge.
	 */
	public function gift_item_name( $name, $cart_item ) {
		if ( ! empty( $cart_item['promeng_gift'] ) ) {
			$name .= ' <span class="promeng-gift-badge">' . esc_html__( 'Free gift', 'promotion-engine' ) . '</span>';
		}
		return $name;
	}

	/**
	 * Replace the "-₪0.00" shown for our unlock-token coupons with the real,
	 * engine-computed savings for the matching promotion.
	 */
	public function coupon_savings_html( $html, $coupon, $discount_html = '' ) {
		if ( ! $coupon instanceof \WC_Coupon || null === $this->last_eval || empty( $this->last_eval['applied'] ) ) {
			return $html;
		}
		$code = strtolower( (string) $coupon->get_code() );
		foreach ( $this->last_eval['applied'] as $info ) {
			$promo = isset( $info['promotion'] ) ? $info['promotion'] : null;
			if ( ! $promo || ! $promo->requires_coupon || 'discount' === $promo->type ) {
				continue;
			}
			if ( strtolower( (string) $promo->coupon_code ) !== $code ) {
				continue;
			}
			$saved = (float) $info['saved'];
			if ( $saved <= 0 ) {
				return $html;
			}
			$new_amount = '-' . wp_kses_post( wc_price( $saved ) );
			if ( '' !== $discount_html && false !== strpos( $html, $discount_html ) ) {
				return str_replace( $discount_html, $new_amount, $html );
			}
			return $new_amount;
		}
		return $html;
	}

	/**
	 * Show the original (struck) and discounted line price in the order, both in
	 * the customer's account and in order emails.
	 */
	public function order_line_subtotal_html( $subtotal_html, $item, $order ) {
		if ( ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order ) {
			return $subtotal_html;
		}
		$inc = ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) );
		$sub = (float) $order->get_line_subtotal( $item, $inc );
		$tot = (float) $order->get_line_total( $item, $inc );
		if ( $sub <= 0 || $tot >= $sub ) {
			return $subtotal_html; // no discount on this line.
		}
		$args = array( 'currency' => $order->get_currency() );
		return '<span style="text-decoration:line-through;color:#6b7280;margin-inline-end:6px;">' . wp_kses_post( wc_price( $sub, $args ) ) . '</span> '
			. '<span style="color:#16a34a;font-weight:700;">' . wp_kses_post( wc_price( $tot, $args ) ) . '</span>';
	}

	/**
	 * Inject a "You saved" row into the order totals shown in the customer's
	 * account and order emails. Skipped if WooCommerce already itemises a
	 * discount (e.g. a coupon), to avoid double-counting.
	 */
	public function order_savings_total_row( $total_rows, $order, $tax_display = '' ) {
		if ( ! $order instanceof \WC_Order ) {
			return $total_rows;
		}
		if ( (float) $order->get_total_discount() > 0 ) {
			return $total_rows; // already itemised as a discount.
		}
		$applied = $order->get_meta( '_promeng_applied' );
		if ( empty( $applied ) || ! is_array( $applied ) ) {
			return $total_rows;
		}
		$saved = 0.0;
		foreach ( $applied as $info ) {
			$saved += isset( $info['saved'] ) ? (float) $info['saved'] : 0.0;
		}
		if ( $saved <= 0 ) {
			return $total_rows;
		}

		$row = array(
			'label' => __( 'You saved on this purchase', 'promotion-engine' ),
			'value' => '-' . wp_strip_all_tags( wc_price( $saved, array( 'currency' => $order->get_currency() ) ) ),
		);

		$out      = array();
		$inserted = false;
		foreach ( $total_rows as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'cart_subtotal' === $key ) {
				$out['promeng_savings'] = $row;
				$inserted               = true;
			}
		}
		if ( ! $inserted ) {
			$out = array();
			foreach ( $total_rows as $key => $value ) {
				if ( 'order_total' === $key && ! isset( $out['promeng_savings'] ) ) {
					$out['promeng_savings'] = $row;
				}
				$out[ $key ] = $value;
			}
		}
		return $out;
	}


	public function store_applied_on_order( $order, $data ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}
		list( $lines, $context ) = $this->snapshot( $cart );
		$eval                    = $this->engine->evaluate( $lines, $context );

		$applied = array();
		foreach ( $eval['applied'] as $promotion_id => $info ) {
			$promotion = $info['promotion'];
			$applied[ $promotion_id ] = array(
				'name'        => $promotion->name,
				// external_id + type let Giorgio link this redemption back to its own promotion (george-{id})
				// and represent it correctly; null external_id = a promotion authored locally in WooCommerce.
				'type'        => $promotion->type,
				'external_id' => $promotion->external_id,
				'coupon_code' => $promotion->coupon_code,
				'saved'       => (float) $info['saved'],
			);
		}
		if ( $applied ) {
			$order->update_meta_data( '_promeng_applied', $applied );
		}
	}
}
