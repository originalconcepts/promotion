<?php
/**
 * Promotion type chooser (shown when creating a new promotion).
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base = add_query_arg( array( 'page' => Admin::PAGE, 'view' => 'edit' ), admin_url( 'admin.php' ) );

$types = array(
	'discount'    => array(
		'icon'  => '%',
		'title' => __( '% / ₪ Discount', 'promotion-engine' ),
		'desc'  => __( 'Discount on products, categories, or the whole order.', 'promotion-engine' ),
		'color' => '#6d28d9',
		'bg'    => '#ede9fe',
	),
	'buy_x_pay_y' => array(
		'icon'  => '📦',
		'title' => __( 'Buy X Pay Y', 'promotion-engine' ),
		'desc'  => __( 'Buy a quantity for a fixed price. E.g. 3 kg for ₪100.', 'promotion-engine' ),
		'color' => '#c2410c',
		'bg'    => '#ffedd5',
	),
	'buy_x_get_y' => array(
		'icon'  => '🎁',
		'title' => __( 'Buy X Get Y', 'promotion-engine' ),
		'desc'  => __( 'Buy a product and get a gift or a discount on another.', 'promotion-engine' ),
		'color' => '#15803d',
		'bg'    => '#dcfce7',
	),
);
?>
<div class="wrap promeng-wrap promeng-choose">
	<h1><?php esc_html_e( 'Choose promotion type', 'promotion-engine' ); ?></h1>
	<hr class="wp-header-end"><?php // keep other plugins' admin notices below the title. ?>
	<div class="promeng-choose-list">
		<?php foreach ( $types as $key => $t ) : ?>
			<a class="promeng-choose-item" href="<?php echo esc_url( add_query_arg( 'type', $key, $base ) ); ?>">
				<span class="promeng-choose-icon" style="background:<?php echo esc_attr( $t['bg'] ); ?>;color:<?php echo esc_attr( $t['color'] ); ?>"><?php echo esc_html( $t['icon'] ); ?></span>
				<span class="promeng-choose-text">
					<strong><?php echo esc_html( $t['title'] ); ?></strong>
					<span><?php echo esc_html( $t['desc'] ); ?></span>
				</span>
			</a>
		<?php endforeach; ?>
	</div>
</div>
