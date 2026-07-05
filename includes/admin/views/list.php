<?php
/**
 * Admin list view — promotions dashboard.
 *
 * @package PromoEngine
 * @var array $promeng  Prepared data from Admin::render_list().
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nav_url = function ( $args ) use ( $promeng ) {
	$keep = array(
		'page'    => Admin::PAGE,
		'range'   => $promeng['range'],
		'tab'     => $promeng['tab'],
		'ptype'   => $promeng['f_type'],
		'channel' => $promeng['f_chan'],
		's'       => $promeng['search'],
	);
	return esc_url( add_query_arg( array_merge( $keep, $args ), admin_url( 'admin.php' ) ) );
};

$new_url  = add_query_arg( array( 'page' => Admin::PAGE, 'view' => 'choose' ), admin_url( 'admin.php' ) );
$currency = html_entity_decode( get_woocommerce_currency_symbol() );

$type_meta = array(
	'discount'    => array( 'label' => __( '% / ₪ Discount', 'promotion-engine' ), 'icon' => 'dashicons-tag', 'color' => '#7c3aed' ),
	'buy_x_pay_y' => array( 'label' => __( 'Buy X Pay Y', 'promotion-engine' ), 'icon' => 'dashicons-randomize', 'color' => '#ea580c' ),
	'buy_x_get_y' => array( 'label' => __( 'Buy X Get Y', 'promotion-engine' ), 'icon' => 'dashicons-products', 'color' => '#16a34a' ),
);
$chan_meta = array(
	'web'   => array( 'icon' => 'dashicons-admin-site-alt3', 'label' => __( 'Web', 'promotion-engine' ) ),
	'app'   => array( 'icon' => 'dashicons-smartphone', 'label' => __( 'App', 'promotion-engine' ) ),
	'pos'   => array( 'icon' => 'dashicons-desktop', 'label' => __( 'POS', 'promotion-engine' ) ),
	'kiosk' => array( 'icon' => 'dashicons-desktop', 'label' => __( 'Kiosk', 'promotion-engine' ) ),
);
$app_on = App::is_active();

$scope_label = function ( $p ) {
	$names = function ( $ids, $is_term ) {
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( empty( $ids ) ) {
			return '';
		}
		$ids   = array_values( $ids );
		if ( $is_term ) {
			$t     = get_term( $ids[0] );
			$first = ( $t && ! is_wp_error( $t ) ) ? $t->name : '';
		} else {
			$pr    = wc_get_product( $ids[0] );
			$first = $pr ? $pr->get_name() : '';
		}
		$extra = count( $ids ) - 1;
		return $extra > 0 ? sprintf( '%s +%d', $first, $extra ) : $first;
	};
	if ( 'discount' === $p->type ) {
		$a = $p->get( 'applies_to', 'all' );
		if ( 'cart' === $a ) {
			return __( 'Entire order', 'promotion-engine' );
		}
		if ( 'products' === $a ) {
			return $names( $p->get( 'product_ids', array() ), false ) ?: __( 'Selected products', 'promotion-engine' );
		}
		if ( 'categories' === $a ) {
			return $names( $p->get( 'category_ids', array() ), true ) ?: __( 'Categories', 'promotion-engine' );
		}
		return __( 'All products', 'promotion-engine' );
	}
	if ( 'buy_x_pay_y' === $p->type ) {
		return 'category' === $p->get( 'scope' )
			? ( $names( $p->get( 'category_ids', array() ), true ) ?: __( 'Categories', 'promotion-engine' ) )
			: ( $names( $p->get( 'product_ids', array() ), false ) ?: __( 'Selected products', 'promotion-engine' ) );
	}
	$a = $p->get( 'buy_applies_to', 'all' );
	if ( 'products' === $a ) {
		return $names( $p->get( 'buy_product_ids', array() ), false ) ?: __( 'Selected products', 'promotion-engine' );
	}
	if ( 'categories' === $a ) {
		return $names( $p->get( 'buy_category_ids', array() ), true ) ?: __( 'Categories', 'promotion-engine' );
	}
	return __( 'All products', 'promotion-engine' );
};

$validity_badge = function ( $p ) {
	$now = current_time( 'timestamp' );
	if ( $p->starts_at && strtotime( $p->starts_at ) > $now ) {
		return array( sprintf( __( 'From %s', 'promotion-engine' ), date_i18n( 'd.m.y', strtotime( $p->starts_at ) ) ), 'scheduled' );
	}
	if ( ! $p->ends_at ) {
		return array( __( 'No limit', 'promotion-engine' ), 'none' );
	}
	$end  = strtotime( $p->ends_at );
	$days = (int) floor( ( $end - $now ) / DAY_IN_SECONDS );
	if ( $end < $now ) {
		return array( __( 'Ended', 'promotion-engine' ), 'ended' );
	}
	if ( $days <= 0 ) {
		return array( __( 'Ends today!', 'promotion-engine' ), 'urgent' );
	}
	if ( $days <= 7 ) {
		return array( sprintf( _n( '%d day left', '%d days left', $days, 'promotion-engine' ), $days ), 'soon' );
	}
	return array( sprintf( __( 'Until %s', 'promotion-engine' ), date_i18n( 'd.m.y', $end ) ), 'normal' );
};

$tabs = array(
	'all'       => __( 'All', 'promotion-engine' ),
	'active'    => __( 'Active', 'promotion-engine' ),
	'scheduled' => __( 'Scheduled', 'promotion-engine' ),
	'draft'     => __( 'Drafts', 'promotion-engine' ),
	'ended'     => __( 'Ended', 'promotion-engine' ),
);
$ranges = array(
	'all'       => __( 'All time', 'promotion-engine' ),
	'month'     => __( 'This month', 'promotion-engine' ),
	'lastmonth' => __( 'Last month', 'promotion-engine' ),
	'week'      => __( 'Last 7 days', 'promotion-engine' ),
);
?>
<div class="wrap promeng-wrap promeng-dashboard">

	<div class="promeng-dash-head">
		<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary promeng-new-btn">+&nbsp;<?php esc_html_e( 'Create promotion', 'promotion-engine' ); ?></a>
		<h1><?php esc_html_e( 'Promotions', 'promotion-engine' ); ?></h1>
	</div>

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Promotion saved.', 'promotion-engine' ); ?></p></div>
	<?php endif; ?>

	<?php $k = $promeng['kpi']; ?>
	<div class="promeng-kpis">
		<div class="promeng-kpi">
			<span class="promeng-kpi-ic" style="background:#fff3e0;color:#f59e0b;"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13z"/></svg></span>
			<div class="promeng-kpi-body">
				<span class="promeng-kpi-label"><?php esc_html_e( 'Active promotions', 'promotion-engine' ); ?></span>
				<span class="promeng-kpi-value"><?php echo esc_html( number_format_i18n( $k['active'] ) ); ?></span>
				<span class="promeng-kpi-sub promeng-amber"><?php echo esc_html( sprintf( _n( '%d ending this week', '%d ending this week', $k['ending'], 'promotion-engine' ), $k['ending'] ) ); ?></span>
			</div>
		</div>
		<div class="promeng-kpi">
			<span class="promeng-kpi-ic" style="background:#e6f4ea;color:#16a34a;"><strong><?php echo esc_html( $currency ); ?></strong></span>
			<div class="promeng-kpi-body">
				<span class="promeng-kpi-label"><?php esc_html_e( 'Revenue with a promotion', 'promotion-engine' ); ?></span>
				<span class="promeng-kpi-value"><?php echo wp_kses_post( wc_price( $k['revenue'] ) ); ?></span>
				<span class="promeng-kpi-sub"><?php echo null === $k['revenue_pct'] ? '&nbsp;' : esc_html( sprintf( __( '%d%% of total revenue', 'promotion-engine' ), $k['revenue_pct'] ) ); ?></span>
			</div>
		</div>
		<div class="promeng-kpi">
			<span class="promeng-kpi-ic" style="background:#fff3e0;color:#ea580c;"><span class="dashicons dashicons-tag"></span></span>
			<div class="promeng-kpi-body">
				<span class="promeng-kpi-label"><?php esc_html_e( 'Discounts given', 'promotion-engine' ); ?></span>
				<span class="promeng-kpi-value"><?php echo wp_kses_post( wc_price( $k['discount'] ) ); ?></span>
				<span class="promeng-kpi-sub"><?php echo esc_html( sprintf( _n( '%s order', '%s orders', $k['orders'], 'promotion-engine' ), number_format_i18n( $k['orders'] ) ) ); ?></span>
			</div>
		</div>
		<div class="promeng-kpi">
			<span class="promeng-kpi-ic" style="background:#f3e8ff;color:#7c3aed;"><span class="dashicons dashicons-chart-pie"></span></span>
			<div class="promeng-kpi-body">
				<span class="promeng-kpi-label"><?php esc_html_e( 'Promotion ROI', 'promotion-engine' ); ?></span>
				<span class="promeng-kpi-value"><?php echo null === $k['roi'] ? '&mdash;' : wp_kses_post( wc_price( $k['roi'] ) ); ?></span>
				<span class="promeng-kpi-sub"><?php echo esc_html( sprintf( __( 'per %s of discount given', 'promotion-engine' ), $currency ) ); ?></span>
			</div>
		</div>
	</div>

	<div class="promeng-panel">
		<div class="promeng-tabs">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo $nav_url( array( 'tab' => $key, 'paged' => 1 ) ); ?>" class="promeng-tab <?php echo $promeng['tab'] === $key ? 'is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
					<span class="promeng-tab-count"><?php echo esc_html( number_format_i18n( $promeng['counts'][ $key ] ?? 0 ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<form method="get" class="promeng-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $promeng['tab'] ); ?>">
			<select name="range" onchange="this.form.submit()">
				<?php foreach ( $ranges as $rk => $rl ) : ?>
					<option value="<?php echo esc_attr( $rk ); ?>" <?php selected( $promeng['range'], $rk ); ?>><?php echo esc_html( $rl ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $app_on ) : ?>
			<select name="channel" onchange="this.form.submit()">
				<option value="" <?php selected( $promeng['f_chan'], '' ); ?>><?php esc_html_e( 'All platforms', 'promotion-engine' ); ?></option>
				<?php foreach ( array( 'web', 'app' ) as $ck ) : ?>
					<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $promeng['f_chan'], $ck ); ?>><?php echo esc_html( $chan_meta[ $ck ]['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>
			<select name="ptype" onchange="this.form.submit()">
				<option value="" <?php selected( $promeng['f_type'], '' ); ?>><?php esc_html_e( 'Type: all', 'promotion-engine' ); ?></option>
				<?php foreach ( $type_meta as $tk => $tm ) : ?>
					<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $promeng['f_type'], $tk ); ?>><?php echo esc_html( $tm['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="promeng-search">
				<span class="dashicons dashicons-search"></span>
				<input type="search" name="s" value="<?php echo esc_attr( $promeng['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search promotion or category…', 'promotion-engine' ); ?>">
			</span>
		</form>

		<table class="promeng-table">
			<thead>
				<tr>
					<th class="promeng-col-name"><?php esc_html_e( 'Promotion', 'promotion-engine' ); ?></th>
					<th><?php esc_html_e( 'Type', 'promotion-engine' ); ?></th>
					<?php if ( $app_on ) : ?><th><?php esc_html_e( 'Platform', 'promotion-engine' ); ?></th><?php endif; ?>
					<th><?php esc_html_e( 'Applies to', 'promotion-engine' ); ?></th>
					<th><?php esc_html_e( 'Validity', 'promotion-engine' ); ?></th>
					<th class="promeng-num"><?php esc_html_e( 'Redemptions', 'promotion-engine' ); ?></th>
					<th class="promeng-num"><?php esc_html_e( 'Revenue', 'promotion-engine' ); ?></th>
					<th class="promeng-num"><?php esc_html_e( 'Discounts', 'promotion-engine' ); ?></th>
					<th><?php esc_html_e( 'Status', 'promotion-engine' ); ?></th>
					<th class="promeng-col-act"></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $promeng['page_rows'] ) ) : ?>
				<tr><td colspan="<?php echo $app_on ? 10 : 9; ?>" class="promeng-empty"><?php esc_html_e( 'No promotions match your filters.', 'promotion-engine' ); ?></td></tr>
			<?php else : ?>
				<?php
				foreach ( $promeng['page_rows'] as $row ) :
					$p     = $row['p'];
					$stats = $row['stats'];
					$tm    = $type_meta[ $p->type ] ?? array( 'label' => $p->type, 'icon' => 'dashicons-tag', 'color' => '#6b7280' );
					list( $vtext, $vclass ) = $validity_badge( $p );
					$edit_url   = add_query_arg( array( 'page' => Admin::PAGE, 'view' => 'edit', 'id' => $p->id ), admin_url( 'admin.php' ) );
					$action_url = function ( $do ) use ( $p ) {
						return wp_nonce_url( add_query_arg( array( 'action' => 'promeng_action', 'do' => $do, 'id' => $p->id ), admin_url( 'admin-post.php' ) ), 'promeng_action_' . $p->id );
					};
					?>
					<tr>
						<td class="promeng-col-name">
							<a class="promeng-name" href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $p->name ); ?></a>
							<?php if ( $p->requires_coupon && $p->coupon_code ) : ?>
								<span class="promeng-code-badge"><?php echo esc_html( strtoupper( $p->coupon_code ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><span class="promeng-type"><span class="dashicons <?php echo esc_attr( $tm['icon'] ); ?>" style="color:<?php echo esc_attr( $tm['color'] ); ?>"></span><?php echo esc_html( $tm['label'] ); ?></span></td>
						<?php if ( $app_on ) : ?>
						<td>
							<span class="promeng-channels">
								<?php foreach ( (array) $p->channels as $ch ) : ?>
									<?php if ( isset( $chan_meta[ $ch ] ) ) : ?>
										<span class="dashicons <?php echo esc_attr( $chan_meta[ $ch ]['icon'] ); ?>" title="<?php echo esc_attr( $chan_meta[ $ch ]['label'] ); ?>"></span>
									<?php endif; ?>
								<?php endforeach; ?>
							</span>
						</td>
						<?php endif; ?>
						<td class="promeng-scope"><?php echo esc_html( $scope_label( $p ) ); ?></td>
						<td><span class="promeng-validity v-<?php echo esc_attr( $vclass ); ?>"><?php echo esc_html( $vtext ); ?></span></td>
						<td class="promeng-num">
							<?php if ( $stats['redemptions'] > 0 ) : ?>
								<a href="<?php echo esc_url( $this->orders_url( $p->id ) ); ?>"><?php echo esc_html( number_format_i18n( $stats['redemptions'] ) ); ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td class="promeng-num"><?php echo wp_kses_post( wc_price( $stats['revenue'] ) ); ?></td>
						<td class="promeng-num promeng-discount"><?php echo $stats['discount'] > 0 ? '&minus;' . wp_kses_post( wc_price( $stats['discount'] ) ) : wp_kses_post( wc_price( 0 ) ); ?></td>
						<td>
							<a class="promeng-toggle-switch <?php echo $p->active ? 'on' : 'off'; ?>" href="<?php echo esc_url( $action_url( 'toggle' ) ); ?>" aria-label="<?php echo $p->active ? esc_attr__( 'Active', 'promotion-engine' ) : esc_attr__( 'Off', 'promotion-engine' ); ?>"><span class="promeng-knob"></span></a>
						</td>
						<td class="promeng-col-act">
							<details class="promeng-menu">
								<summary aria-label="<?php esc_attr_e( 'Actions', 'promotion-engine' ); ?>"><span class="dashicons dashicons-ellipsis"></span></summary>
								<div class="promeng-menu-pop">
									<?php if ( 'giorgio' !== $p->source ) : ?>
										<a href="<?php echo esc_url( $edit_url ); ?>"><span class="dashicons dashicons-edit"></span><?php esc_html_e( 'Edit', 'promotion-engine' ); ?></a>
									<?php endif; ?>
									<a href="<?php echo esc_url( $action_url( 'duplicate' ) ); ?>"><span class="dashicons dashicons-admin-page"></span><?php esc_html_e( 'Duplicate', 'promotion-engine' ); ?></a>
									<?php if ( 'giorgio' !== $p->source ) : ?>
										<a class="promeng-danger" href="<?php echo esc_url( $action_url( 'delete' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this promotion?', 'promotion-engine' ) ); ?>');"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Delete', 'promotion-engine' ); ?></a>
									<?php endif; ?>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $promeng['pages'] > 1 ) : ?>
			<div class="promeng-pagination">
				<span class="promeng-page-info">
					<?php
					$first = ( $promeng['paged'] - 1 ) * $promeng['per_page'] + 1;
					$last  = min( $promeng['total'], $promeng['paged'] * $promeng['per_page'] );
					echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s', 'promotion-engine' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $promeng['total'] ) ) );
					?>
				</span>
				<span class="promeng-page-links">
					<?php if ( $promeng['paged'] > 1 ) : ?>
						<a class="promeng-page" href="<?php echo $nav_url( array( 'paged' => $promeng['paged'] - 1 ) ); ?>">&lsaquo;</a>
					<?php endif; ?>
					<?php for ( $i = 1; $i <= $promeng['pages']; $i++ ) : ?>
						<a class="promeng-page <?php echo $i === $promeng['paged'] ? 'is-active' : ''; ?>" href="<?php echo $nav_url( array( 'paged' => $i ) ); ?>"><?php echo esc_html( number_format_i18n( $i ) ); ?></a>
					<?php endfor; ?>
					<?php if ( $promeng['paged'] < $promeng['pages'] ) : ?>
						<a class="promeng-page" href="<?php echo $nav_url( array( 'paged' => $promeng['paged'] + 1 ) ); ?>">&rsaquo;</a>
					<?php endif; ?>
				</span>
			</div>
		<?php endif; ?>
	</div>
</div>
