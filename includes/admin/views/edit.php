<?php
/**
 * Admin edit/create form (discount type for v0.1).
 *
 * @package PromoEngine
 * @var \PromoEngine\Promotion $promotion
 * @var bool                   $readonly
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_new        = ! $promotion->id;
$cfg           = $promotion->config;
$channels      = $promotion->channels ? $promotion->channels : array( 'web' );
$customer_type = in_array( $promotion->customer_type, array( 'all', 'registered', 'guest' ), true ) ? $promotion->customer_type : 'all';

$applies_to = isset( $cfg['applies_to'] ) ? $cfg['applies_to'] : 'all';
$disabled   = $readonly ? 'disabled' : '';
$type       = isset( $type ) ? $type : ( $promotion->type ? $promotion->type : 'discount' );
$promeng_titles = array(
	'discount'    => __( 'Create Promotion — % / ₪ Discount', 'promotion-engine' ),
	'buy_x_pay_y' => __( 'Create Promotion — Buy X Pay Y', 'promotion-engine' ),
	'buy_x_get_y' => __( 'Create Promotion — Buy X Get Y', 'promotion-engine' ),
);

// Helper to fetch a product label for preselected ids.
$product_options = function ( $ids ) {
	$out = array();
	foreach ( (array) $ids as $pid ) {
		$product = wc_get_product( $pid );
		if ( $product ) {
			$out[ $pid ] = $product->get_formatted_name();
		}
	}
	return $out;
};
?>
<div class="wrap promeng-wrap promeng-edit">
	<h1><?php echo $is_new ? esc_html( isset( $promeng_titles[ $type ] ) ? $promeng_titles[ $type ] : $promeng_titles['discount'] ) : esc_html__( 'Edit Promotion', 'promotion-engine' ); ?></h1>

	<?php if ( $readonly ) : ?>
		<div class="notice notice-info"><p><?php esc_html_e( 'This promotion is managed in Giorgio and is read-only here. To edit it, update it in Giorgio.', 'promotion-engine' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="promeng-form">
		<input type="hidden" name="action" value="promeng_save">
		<input type="hidden" name="id" value="<?php echo esc_attr( $promotion->id ); ?>">
		<input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
		<?php wp_nonce_field( 'promeng_save' ); ?>

		<div class="promeng-layout">
		<div class="promeng-main">

		<?php if ( \PromoEngine\App::is_active() ) : ?>
		<!-- ערוץ מכירה (פלטפורמות) -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Where does it run?', 'promotion-engine' ); ?></h2>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="channels[]" value="web" <?php checked( in_array( 'web', $channels, true ) ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'Website', 'promotion-engine' ); ?></strong></span>
			</label>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="channels[]" value="app" <?php checked( in_array( 'app', $channels, true ) ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><strong><?php esc_html_e( 'App', 'promotion-engine' ); ?></strong></span>
			</label>
			<p class="description"><?php esc_html_e( 'Choose where this promotion is active. App-only promotions apply only to shoppers browsing inside the app.', 'promotion-engine' ); ?></p>
		</div>
		<?php else : ?>
			<input type="hidden" name="channels[]" value="web">
		<?php endif; ?>

		<!-- פרטי המבצע -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Promotion details', 'promotion-engine' ); ?></h2>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="active" value="1" <?php checked( $is_new ? true : $promotion->active ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><?php esc_html_e( 'Active', 'promotion-engine' ); ?></span>
			</label>
			<p>
				<label for="promeng-name"><strong><?php esc_html_e( 'Promotion name', 'promotion-engine' ); ?> *</strong></label><br>
				<input id="promeng-name" type="text" name="name" class="regular-text" required value="<?php echo esc_attr( $promotion->name ); ?>" placeholder="<?php esc_attr_e( 'e.g. 20% off all frozen items', 'promotion-engine' ); ?>" <?php echo esc_attr( $disabled ); ?>>
			</p>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="show_label" value="1" <?php checked( $promotion->show_label ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><?php esc_html_e( 'Show promotion label on the product in the catalog', 'promotion-engine' ); ?></span>
			</label>
		</div>


		<!-- קהל יעד (סוג לקוח) -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Who is it for?', 'promotion-engine' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Limit the promotion by customer type. It always applies within the selected audience.', 'promotion-engine' ); ?></p>
			<label class="promeng-row promeng-radio-row">
				<input type="radio" name="customer_type" value="all" <?php checked( $customer_type, 'all' ); ?> <?php echo esc_attr( $disabled ); ?>>
				<span><strong><?php esc_html_e( 'Everyone', 'promotion-engine' ); ?></strong> — <?php esc_html_e( 'all shoppers (registered and guests)', 'promotion-engine' ); ?></span>
			</label>
			<label class="promeng-row promeng-radio-row">
				<input type="radio" name="customer_type" value="registered" <?php checked( $customer_type, 'registered' ); ?> <?php echo esc_attr( $disabled ); ?>>
				<span><strong><?php esc_html_e( 'Registered customers', 'promotion-engine' ); ?></strong> — <?php esc_html_e( 'only shoppers who are logged in', 'promotion-engine' ); ?></span>
			</label>
			<label class="promeng-row promeng-radio-row">
				<input type="radio" name="customer_type" value="guest" <?php checked( $customer_type, 'guest' ); ?> <?php echo esc_attr( $disabled ); ?>>
				<span><strong><?php esc_html_e( 'Guests', 'promotion-engine' ); ?></strong> — <?php esc_html_e( 'only shoppers who are not logged in', 'promotion-engine' ); ?></span>
			</label>
		</div>


		<!-- קוד קופון -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Coupon code', 'promotion-engine' ); ?></h2>
			<label class="promeng-row promeng-toggle-row">
				<span class="promeng-switch">
					<input type="checkbox" name="requires_coupon" value="1" id="promeng-requires-coupon" <?php checked( $promotion->requires_coupon ); ?> <?php echo esc_attr( $disabled ); ?>>
					<span class="promeng-slider"></span>
				</span>
				<span><?php esc_html_e( 'Require coupon code', 'promotion-engine' ); ?></span>
			</label>
			<p class="promeng-coupon-field" style="<?php echo $promotion->requires_coupon ? '' : 'display:none'; ?>">
				<input type="text" name="coupon_code" class="regular-text" value="<?php echo esc_attr( (string) $promotion->coupon_code ); ?>" placeholder="WELCOME" <?php echo esc_attr( $disabled ); ?>>
				<span class="description"><?php esc_html_e( 'The promotion applies only when the customer enters this code at the cart or checkout.', 'promotion-engine' ); ?></span>
			</p>

			<?php if ( \PromoEngine\Influencer::is_active() ) : ?>
				<?php
				$promeng_inf       = \PromoEngine\Influencer::assignment( $promotion );
				$promeng_inf_user  = $promeng_inf ? (int) $promeng_inf['user_id'] : 0;
				$promeng_inf_pct   = $promeng_inf ? $promeng_inf['pct'] : '';
				$promeng_inf_pay   = $promeng_inf ? $promeng_inf['payout'] : '';
				$promeng_inf_label = '';
				if ( $promeng_inf_user ) {
					$promeng_u = get_userdata( $promeng_inf_user );
					if ( $promeng_u ) {
						$promeng_inf_label = $promeng_u->display_name . ' (' . $promeng_u->user_email . ')';
					}
				}
				?>
				<div class="promeng-influencer-fields promeng-coupon-field" style="<?php echo $promotion->requires_coupon ? '' : 'display:none'; ?>">
					<hr>
					<p class="promeng-influencer-head"><span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Influencer (OC Influencer Dashboard)', 'promotion-engine' ); ?></p>

					<label class="promeng-field">
						<span><?php esc_html_e( 'Assigned influencer', 'promotion-engine' ); ?></span>
						<select class="wc-customer-search" name="influencer_user_id" data-placeholder="<?php esc_attr_e( 'Search by name or email…', 'promotion-engine' ); ?>" data-allow_clear="true" data-action="woocommerce_json_search_customers" style="width:100%" <?php echo esc_attr( $disabled ); ?>>
							<?php if ( $promeng_inf_user ) : ?>
								<option value="<?php echo esc_attr( (string) $promeng_inf_user ); ?>" selected><?php echo esc_html( $promeng_inf_label ); ?></option>
							<?php endif; ?>
						</select>
					</label>

					<label class="promeng-field">
						<span><?php esc_html_e( 'Commission percentage (%)', 'promotion-engine' ); ?></span>
						<input type="number" min="0" step="0.1" name="influencer_commission_pct" value="<?php echo esc_attr( '' === $promeng_inf_pct ? '' : (string) $promeng_inf_pct ); ?>" placeholder="0" <?php echo esc_attr( $disabled ); ?>>
					</label>

					<label class="promeng-field">
						<span><?php esc_html_e( 'Commission payout', 'promotion-engine' ); ?></span>
						<select name="influencer_payout" <?php echo esc_attr( $disabled ); ?>>
							<option value="" <?php selected( $promeng_inf_pay, '' ); ?>><?php esc_html_e( 'Store default', 'promotion-engine' ); ?></option>
							<option value="points" <?php selected( $promeng_inf_pay, 'points' ); ?>><?php esc_html_e( 'Reward points (YITH)', 'promotion-engine' ); ?></option>
							<option value="cash" <?php selected( $promeng_inf_pay, 'cash' ); ?>><?php esc_html_e( 'Cash (money)', 'promotion-engine' ); ?></option>
						</select>
					</label>

					<span class="description"><?php esc_html_e( 'Purchases made with this coupon code are counted for the influencer in the OC Influencer Dashboard.', 'promotion-engine' ); ?></span>
				</div>
			<?php endif; ?>
		</div>

		<?php
		$promeng_type_view = PROMENG_DIR . 'includes/admin/views/types/' . sanitize_key( $type ) . '.php';
		if ( file_exists( $promeng_type_view ) ) {
			include $promeng_type_view;
		}
		?>

		<!-- תזמון -->
		<div class="promeng-card">
			<h2><?php esc_html_e( 'Scheduling', 'promotion-engine' ); ?></h2>
			<p>
				<label><?php esc_html_e( 'When?', 'promotion-engine' ); ?></label>
				<input type="date" name="starts_at" value="<?php echo esc_attr( $promotion->starts_at ? gmdate( 'Y-m-d', strtotime( $promotion->starts_at ) ) : '' ); ?>" <?php echo esc_attr( $disabled ); ?>>
				<label><?php esc_html_e( 'Until when? (optional)', 'promotion-engine' ); ?></label>
				<input type="date" name="ends_at" value="<?php echo esc_attr( $promotion->ends_at ? gmdate( 'Y-m-d', strtotime( $promotion->ends_at ) ) : '' ); ?>" <?php echo esc_attr( $disabled ); ?>>
			</p>
			<p>
				<label><?php esc_html_e( 'Recurring by weekday', 'promotion-engine' ); ?></label><br>
				<?php
				global $wp_locale;
				for ( $num = 0; $num <= 6; $num++ ) :
					$label = $wp_locale->weekday_abbrev[ $wp_locale->weekday[ $num ] ];
					?>
					<label class="promeng-day"><input type="checkbox" name="weekdays[]" value="<?php echo esc_attr( $num ); ?>" <?php checked( $is_new ? true : in_array( $num, $promotion->weekdays, true ) ); ?> <?php echo esc_attr( $disabled ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endfor; ?>
			</p>
		</div>

		</div><!-- .promeng-main -->

		<aside class="promeng-side">
			<div class="promeng-card promeng-side-summary" data-type="<?php echo esc_attr( $type ); ?>" data-currency="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>">
				<h2 class="promeng-summary-title"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Promotion summary', 'promotion-engine' ); ?></h2>

				<div class="promeng-sum-headline">
					<span class="promeng-sum-bolt" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13z"/></svg></span>
					<span class="promeng-sum-deal"></span>
				</div>

				<ul class="promeng-summary-lines"></ul>

				<p class="promeng-summary-note"><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e( 'The summary updates live as you fill in the form.', 'promotion-engine' ); ?></p>

				<?php if ( ! $readonly ) : ?>
				<div class="promeng-summary-actions">
					<button type="submit" class="button button-primary button-hero promeng-save-btn">+&nbsp;<?php echo $is_new ? esc_html__( 'Create promotion', 'promotion-engine' ) : esc_html__( 'Save promotion', 'promotion-engine' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => Admin::PAGE ), admin_url( 'admin.php' ) ) ); ?>" class="button button-hero promeng-cancel"><?php esc_html_e( 'Cancel', 'promotion-engine' ); ?></a>
				</div>
				<?php endif; ?>
			</div>

			<?php
			$promeng_stats   = $is_new ? array( 'redemptions' => 0, 'revenue' => 0.0 ) : \PromoEngine\Usage::stats( $promotion->id );
			$promeng_created = $promotion->created_at ? $promotion->created_at : '';
			if ( ! $promeng_created && ! $is_new ) {
				$promeng_created = \PromoEngine\Usage::first_usage_date( $promotion->id );
			}
			$promeng_redemptions = (int) $promeng_stats['redemptions'];
			?>
			<div class="promeng-card promeng-side-stats">
				<ul>
					<li>
						<span class="promeng-stat-label"><?php esc_html_e( 'Created', 'promotion-engine' ); ?></span>
						<span class="promeng-stat-value"><?php echo $promeng_created ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $promeng_created ) ) ) : '—'; ?></span>
					</li>
					<li>
						<span class="promeng-stat-label"><?php esc_html_e( 'Redemptions', 'promotion-engine' ); ?></span>
						<span class="promeng-stat-value">
							<?php if ( ! $is_new && $promeng_redemptions > 0 ) : ?>
								<a href="<?php echo esc_url( $this->orders_url( $promotion->id ) ); ?>"><?php echo esc_html( number_format_i18n( $promeng_redemptions ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( number_format_i18n( $promeng_redemptions ) ); ?>
							<?php endif; ?>
						</span>
					</li>
					<li>
						<span class="promeng-stat-label"><?php esc_html_e( 'Total revenue', 'promotion-engine' ); ?></span>
						<span class="promeng-stat-value"><?php echo wp_kses_post( wc_price( (float) $promeng_stats['revenue'] ) ); ?></span>
					</li>
				</ul>
			</div>
		</aside>

		</div><!-- .promeng-layout -->
	</form>
</div>
