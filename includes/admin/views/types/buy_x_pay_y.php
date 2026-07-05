<?php
/**
 * "Buy X Pay Y" type fields. Included by edit.php within the shared shell.
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

$scope            = isset( $cfg['scope'] ) ? $cfg['scope'] : 'product';
$selected_cats    = isset( $cfg['category_ids'] ) ? array_map( 'intval', $cfg['category_ids'] ) : array();
$bxpy_terms       = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
?>
		<!-- Promotion rule -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Promotion rule', 'promotion-engine' ); ?></h2>

			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'Buy', 'promotion-engine' ); ?></label>
				<input type="number" min="0" step="0.001" name="buy_quantity" required value="<?php echo esc_attr( isset( $cfg['buy_quantity'] ) ? $cfg['buy_quantity'] : '' ); ?>" placeholder="<?php esc_attr_e( 'qty (kg / units)', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<label><?php esc_html_e( 'from', 'promotion-engine' ); ?></label>
				<select name="scope" id="promeng-scope" <?php echo esc_attr( $disabled ); ?>>
					<option value="product" <?php selected( $scope, 'product' ); ?>><?php esc_html_e( 'a product', 'promotion-engine' ); ?></option>
					<option value="category" <?php selected( $scope, 'category' ); ?>><?php esc_html_e( 'a category', 'promotion-engine' ); ?></option>
				</select>
			</p>

			<p class="promeng-target promeng-target-products" style="<?php echo 'product' === $scope ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="product_ids[]" data-placeholder="<?php esc_attr_e( 'Search products...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['product_ids'] ) ? $cfg['product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-target promeng-target-categories" style="<?php echo 'category' === $scope ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Categories', 'promotion-engine' ); ?></label><br>
				<select class="wc-enhanced-select" multiple name="category_ids[]" data-placeholder="<?php esc_attr_e( 'Select categories...', 'promotion-engine' ); ?>" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $bxpy_terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( $term->term_id, $selected_cats, true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-target promeng-target-excluded" style="<?php echo 'category' === $scope ? '' : 'display:none'; ?>">
				<label><?php esc_html_e( 'Excluded products', 'promotion-engine' ); ?></label><br>
				<select class="wc-product-search" multiple name="excluded_product_ids[]" data-placeholder="<?php esc_attr_e( 'Products to exclude...', 'promotion-engine' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
					<?php foreach ( $product_options( isset( $cfg['excluded_product_ids'] ) ? $cfg['excluded_product_ids'] : array() ) as $pid => $label ) : ?>
						<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="promeng-discount-row">
				<label><?php esc_html_e( 'and pay (total)', 'promotion-engine' ); ?></label>
				<input type="number" min="0" step="0.01" name="pay_amount" required value="<?php echo esc_attr( isset( $cfg['pay_amount'] ) ? $cfg['pay_amount'] : '' ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<span>₪</span>
			</p>
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
					<span class="description"><?php esc_html_e( 'How many times this deal can repeat in a single order. Leave empty for no limit.', 'promotion-engine' ); ?></span>
				</p>
				<p>
					<label><?php esc_html_e( 'Limit (per customer)', 'promotion-engine' ); ?></label>
					<input type="number" min="0" name="limit_per_customer" value="<?php echo esc_attr( (string) $promotion->limit_per_customer ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
					<span class="description"><?php esc_html_e( 'How many times a single customer can use this promotion in total (across orders). Leave empty for no limit.', 'promotion-engine' ); ?></span>
				</p>
			</div>
		</div>
