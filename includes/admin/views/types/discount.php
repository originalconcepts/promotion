<?php
/**
 * Discount-type fields (% / ₪). Included by edit.php within the shared shell.
 *
 * @package PromoEngine
 * @var array    $cfg
 * @var string   $applies_to
 * @var string   $disabled
 * @var callable $product_options
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<!-- על מה חל המבצע? -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'What does the promotion apply to?', 'promotion-engine' ); ?></h2>
			<p>
				<label><?php esc_html_e( 'Choose product type', 'promotion-engine' ); ?></label><br>
				<select name="applies_to" id="promeng-applies-to" <?php echo esc_attr( $disabled ); ?>>
					<option value="all" <?php selected( $applies_to, 'all' ); ?>><?php esc_html_e( 'All products', 'promotion-engine' ); ?></option>
					<option value="products" <?php selected( $applies_to, 'products' ); ?>><?php esc_html_e( 'Specific products', 'promotion-engine' ); ?></option>
					<option value="categories" <?php selected( $applies_to, 'categories' ); ?>><?php esc_html_e( 'Specific categories', 'promotion-engine' ); ?></option>
				</select>
			</p>

			<p class="promeng-target promeng-target-products" style="<?php echo 'products' === $applies_to ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="product_ids[]" data-placeholder="<?php esc_attr_e( 'Search products...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['product_ids'] ) ? $cfg['product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-target promeng-target-categories" style="<?php echo 'categories' === $applies_to ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Categories', 'promotion-engine' ); ?></label><br>
				<select class="wc-enhanced-select" multiple name="category_ids[]" data-placeholder="<?php esc_attr_e( 'Select categories...', 'promotion-engine' ); ?>" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php
					$selected_cats = isset( $cfg['category_ids'] ) ? array_map( 'intval', $cfg['category_ids'] ) : array();
					$terms         = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
					foreach ( $terms as $term ) :
						?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $selected_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-target promeng-target-excluded" style="<?php echo in_array( $applies_to, array( 'all', 'categories' ), true ) ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Excluded products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="excluded_product_ids[]" data-placeholder="<?php esc_attr_e( 'Products to exclude...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['excluded_product_ids'] ) ? $cfg['excluded_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="as_cart_discount" value="1" <?php checked( ! empty( $cfg['as_cart_discount'] ) ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Show as a discount in the cart', 'promotion-engine' ); ?></strong><br>
				<span class="description"><?php esc_html_e( 'Instead of a before/after price and label on each product, the discount appears as a single line in the cart summary under the promotion name.', 'promotion-engine' ); ?></span></span>
			</label>

			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" id="promeng-has-condition" <?php checked( ! empty( $cfg['condition_min_items'] ) || ! empty( $cfg['condition_min_amount'] ) ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><?php esc_html_e( 'Apply purchase condition', 'promotion-engine' ); ?></span>
			</label>
			<div class="promeng-conditions" style="<?php echo ( ! empty( $cfg['condition_min_items'] ) || ! empty( $cfg['condition_min_amount'] ) ) ? '' : 'display:none'; ?>">
				<p>
					<label><?php esc_html_e( 'Buy at least', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="condition_min_items" value="<?php echo esc_attr( isset( $cfg['condition_min_items'] ) ? $cfg['condition_min_items'] : '' ); ?>" placeholder="<?php esc_attr_e( 'items', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
				</p>
				<p>
					<label><?php esc_html_e( 'On orders over', 'promotion-engine' ); ?></label>
					<input type="number" min="0" step="0.01" name="condition_min_amount" value="<?php echo esc_attr( isset( $cfg['condition_min_amount'] ) ? $cfg['condition_min_amount'] : '' ); ?>" placeholder="₪"<?php echo esc_attr( $disabled ); ?>>
				</p>
			</div>
		</div>

		<!-- מה ההנחה? -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'What is the discount?', 'promotion-engine' ); ?></h2>
			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'Discount of', 'promotion-engine' ); ?></label>
				<input type="number" min="0" step="0.01" name="discount_value" required value="<?php echo esc_attr( isset( $cfg['discount_value'] ) ? $cfg['discount_value'] : '' ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<select name="discount_type" <?php echo esc_attr( $disabled ); ?>>
					<option value="percent" <?php selected( ( isset( $cfg['discount_type'] ) ? $cfg['discount_type'] : 'percent' ), 'percent' ); ?>>%</option>
					<option value="fixed" <?php selected( ( isset( $cfg['discount_type'] ) ? $cfg['discount_type'] : 'percent' ), 'fixed' ); ?>>₪</option>
				</select>
			</p>
		</div>

		<!-- Limits -->
		<?php $has_limits = ( ! empty( $cfg['limit_discounted_items'] ) || $promotion->limit_per_customer ); ?>
		<div class="promeng-card">
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="promeng_has_limits" id="promeng-has-limits" value="1" <?php checked( $has_limits ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Limits', 'promotion-engine' ); ?></strong></span>
			</label>
			<div class="promeng-limits" style="<?php echo $has_limits ? '' : 'display:none'; ?>">
				<p>
					<label><?php esc_html_e( 'Limit (items)', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="limit_discounted_items" value="<?php echo esc_attr( isset( $cfg['limit_discounted_items'] ) ? $cfg['limit_discounted_items'] : '' ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
					<span class="description"><?php esc_html_e( 'Discount applies to at most this many units; the rest stay full price. Leave empty for no limit.', 'promotion-engine' ); ?></span>
				</p>
				<p>
					<label><?php esc_html_e( 'Limit (per customer)', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="limit_per_customer" value="<?php echo esc_attr( (string) $promotion->limit_per_customer ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
					<span class="description"><?php esc_html_e( 'How many times a single customer can use this promotion in total (across orders). Leave empty for no limit.', 'promotion-engine' ); ?></span>
				</p>
			</div>
		</div>
