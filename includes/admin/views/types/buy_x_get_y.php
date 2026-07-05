<?php
/**
 * "Buy X Get Y" type fields. Included by edit.php within the shared shell.
 *
 * @package PromoEngine
 * @var array    $cfg
 * @var string   $disabled
 * @var callable $product_options
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$buy_applies     = isset( $cfg['buy_applies_to'] ) ? $cfg['buy_applies_to'] : 'products';
$benefit_type    = isset( $cfg['benefit_type'] ) ? $cfg['benefit_type'] : 'free';
$benefit_applies = isset( $cfg['benefit_applies_to'] ) ? $cfg['benefit_applies_to'] : 'products';
$benefit_cats    = isset( $cfg['benefit_category_ids'] ) ? array_map( 'intval', $cfg['benefit_category_ids'] ) : array();
$benefit_qty     = ( isset( $cfg['benefit_quantity'] ) && (int) $cfg['benefit_quantity'] > 0 ) ? (int) $cfg['benefit_quantity'] : 1;
$buy_cats        = isset( $cfg['buy_category_ids'] ) ? array_map( 'intval', $cfg['buy_category_ids'] ) : array();
$bxgy_terms      = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
$has_cond        = ! empty( $cfg['buy_min_amount'] );
?>
		<!-- Step 1: what to buy -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'What the customer must buy', 'promotion-engine' ); ?></h2>

			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'Buy', 'promotion-engine' ); ?></label>
				<input type="number" min="1" step="1" name="buy_min_items" value="<?php echo esc_attr( ( isset( $cfg['buy_min_items'] ) && (int) $cfg['buy_min_items'] > 0 ) ? (int) $cfg['buy_min_items'] : 1 ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<label><?php esc_html_e( 'from', 'promotion-engine' ); ?></label>
				<select name="buy_applies_to" id="promeng-buy-applies" <?php echo esc_attr( $disabled ); ?>>
					<option value="products" <?php selected( $buy_applies, 'products' ); ?>><?php esc_html_e( 'a product', 'promotion-engine' ); ?></option>
					<option value="categories" <?php selected( $buy_applies, 'categories' ); ?>><?php esc_html_e( 'a category', 'promotion-engine' ); ?></option>
					<option value="all" <?php selected( $buy_applies, 'all' ); ?>><?php esc_html_e( 'all products', 'promotion-engine' ); ?></option>
				</select>
			</p>
			<p class="description promeng-buy-hint"><?php esc_html_e( 'How many qualifying products earn one benefit. The deal repeats — buy twice as many to earn two benefits.', 'promotion-engine' ); ?></p>

			<p class="promeng-buy-target promeng-buy-products" style="<?php echo 'products' === $buy_applies ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="buy_product_ids[]" data-placeholder="<?php esc_attr_e( 'Search products...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['buy_product_ids'] ) ? $cfg['buy_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-buy-target promeng-buy-categories" style="<?php echo 'categories' === $buy_applies ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Categories', 'promotion-engine' ); ?></label><br>
				<select class="wc-enhanced-select" multiple name="buy_category_ids[]" data-placeholder="<?php esc_attr_e( 'Select categories...', 'promotion-engine' ); ?>" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $bxgy_terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $buy_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-buy-target promeng-buy-excluded" style="<?php echo in_array( $buy_applies, array( 'all', 'categories' ), true ) ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Excluded products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="buy_excluded_product_ids[]" data-placeholder="<?php esc_attr_e( 'Products to exclude...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['buy_excluded_product_ids'] ) ? $cfg['buy_excluded_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" id="promeng-has-condition" <?php checked( $has_cond ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><?php esc_html_e( 'Require a minimum order amount', 'promotion-engine' ); ?></span>
			</label>
			<div class="promeng-conditions" style="<?php echo $has_cond ? '' : 'display:none'; ?>">
				<p>
					<label><?php esc_html_e( 'On orders over', 'promotion-engine' ); ?></label>
					<input type="number" min="0" step="0.01" name="buy_min_amount" value="<?php echo esc_attr( isset( $cfg['buy_min_amount'] ) ? $cfg['buy_min_amount'] : '' ); ?>" placeholder="₪" <?php echo esc_attr( $disabled ); ?>>
				</p>
			</div>
		</div>

		<!-- Step 2: what they get -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'What the customer gets', 'promotion-engine' ); ?></h2>

			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'Get', 'promotion-engine' ); ?></label>
				<input type="number" min="1" step="1" name="benefit_quantity" value="<?php echo esc_attr( $benefit_qty ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<label><?php esc_html_e( 'from', 'promotion-engine' ); ?></label>
				<select name="benefit_applies_to" id="promeng-benefit-applies" <?php echo esc_attr( $disabled ); ?>>
					<option value="products" <?php selected( $benefit_applies, 'products' ); ?>><?php esc_html_e( 'a product', 'promotion-engine' ); ?></option>
					<option value="categories" <?php selected( $benefit_applies, 'categories' ); ?>><?php esc_html_e( 'a category', 'promotion-engine' ); ?></option>
					<option value="same" <?php selected( $benefit_applies, 'same' ); ?>><?php esc_html_e( 'the same items they buy', 'promotion-engine' ); ?></option>
					<option value="all" <?php selected( $benefit_applies, 'all' ); ?>><?php esc_html_e( 'all products', 'promotion-engine' ); ?></option>
				</select>
			</p>
			<p class="description"><?php esc_html_e( 'How many units the customer receives each time the buy condition is met (e.g. Buy 4, Get 2).', 'promotion-engine' ); ?></p>
			<p class="description promeng-benefit-same-hint" style="<?php echo 'same' === $benefit_applies ? '' : 'display:none'; ?>"><?php esc_html_e( 'The benefit applies to the same products/categories chosen in "What the customer must buy" above — ideal for 5+1 style deals.', 'promotion-engine' ); ?></p>

			<p class="promeng-benefit-target promeng-benefit-products" style="<?php echo 'products' === $benefit_applies ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Benefit product(s) — the customer gets one of these', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="benefit_product_ids[]" data-placeholder="<?php esc_attr_e( 'Search products...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['benefit_product_ids'] ) ? $cfg['benefit_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-benefit-target promeng-benefit-categories" style="<?php echo 'categories' === $benefit_applies ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Benefit categories', 'promotion-engine' ); ?></label><br>
				<select class="wc-enhanced-select" multiple name="benefit_category_ids[]" data-placeholder="<?php esc_attr_e( 'Select categories...', 'promotion-engine' ); ?>" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $bxgy_terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $benefit_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-benefit-target promeng-benefit-excluded" style="<?php echo in_array( $benefit_applies, array( 'all', 'categories', 'same' ), true ) ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Excluded products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="benefit_excluded_product_ids[]" data-placeholder="<?php esc_attr_e( 'Products to exclude...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['benefit_excluded_product_ids'] ) ? $cfg['benefit_excluded_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'Benefit type', 'promotion-engine' ); ?></label>
				<select name="benefit_type" id="promeng-benefit-type" <?php echo esc_attr( $disabled ); ?>>
					<option value="free" <?php selected( $benefit_type, 'free' ); ?>><?php esc_html_e( 'Free', 'promotion-engine' ); ?></option>
					<option value="percent" <?php selected( $benefit_type, 'percent' ); ?>><?php esc_html_e( '% off', 'promotion-engine' ); ?></option>
					<option value="fixed" <?php selected( $benefit_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed price', 'promotion-engine' ); ?></option>
				</select>
				<input type="number" min="0" step="0.01" name="benefit_value" id="promeng-benefit-value" value="<?php echo esc_attr( isset( $cfg['benefit_value'] ) ? $cfg['benefit_value'] : '' ); ?>" style="<?php echo 'free' === $benefit_type ? 'display:none' : ''; ?>" <?php echo esc_attr( $disabled ); ?>>
			</p>
			<?php
			$auto_add     = ! isset( $cfg['auto_add'] ) ? true : ! empty( $cfg['auto_add'] );
			$auto_visible = ( 'free' === $benefit_type && 'products' === $benefit_applies );
			?>
			<label class="promeng-row promeng-toggle-row" id="promeng-auto-add-row" style="<?php echo $auto_visible ? '' : 'display:none'; ?>">
				<span class="promeng-switch">
					<input type="checkbox" name="auto_add" id="promeng-auto-add" value="1" <?php checked( $auto_add ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Add the free product to the cart automatically', 'promotion-engine' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'When the customer qualifies, the free product is added for them. Turn off to only discount it if they add it themselves.', 'promotion-engine' ); ?></span></span>
			</label>

			<?php $cheapest = ! empty( $cfg['apply_to_cheapest'] ); ?>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="apply_to_cheapest" value="1" <?php checked( $cheapest ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Apply the discount to the cheapest items', 'promotion-engine' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'By default the benefit is spread across price tiers as required by law (e.g. 4 items with 2 free → one dearer and one cheaper item are free, not both cheap ones). Turn this on to discount only the cheapest items instead.', 'promotion-engine' ); ?></span></span>
			</label>
		</div>

		<!-- Limits -->
		<?php $has_limits = ( $promotion->limit_per_order || $promotion->limit_per_customer ); ?>
		<div class="promeng-card">
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" id="promeng-has-limits" <?php checked( $has_limits ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Limits', 'promotion-engine' ); ?></strong></span>
			</label>
			<div class="promeng-limits" style="<?php echo $has_limits ? '' : 'display:none'; ?>">
				<p>
					<label><?php esc_html_e( 'Limit (per order)', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="limit_per_order" value="<?php echo esc_attr( (string) $promotion->limit_per_order ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
					<span class="description"><?php esc_html_e( 'Cap on benefit units per order. Leave empty to earn one benefit each time the buy condition is met.', 'promotion-engine' ); ?></span>
				</p>
				<p>
					<label><?php esc_html_e( 'Limit (per customer)', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="limit_per_customer" value="<?php echo esc_attr( (string) $promotion->limit_per_customer ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
					<span class="description"><?php esc_html_e( 'How many times a single customer can use this promotion in total (across orders). Leave empty for no limit.', 'promotion-engine' ); ?></span>
				</p>
			</div>
		</div>
