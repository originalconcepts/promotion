<?php
/**
 * Admin: menu, list screen, edit screen, save handling, row actions.
 *
 * UI is PHP-rendered following WordPress admin conventions (RTL-aware via the
 * site locale). When the plugin is connected to Giorgio, Giorgio-sourced
 * promotions are shown read-only — Giorgio is authoritative for those.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const PAGE = 'promotion-engine';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_promeng_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_promeng_action', array( $this, 'handle_row_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_orders_query' ) );
		add_filter( 'request', array( $this, 'filter_orders_request' ) );
	}

	public function menu() {
		add_menu_page(
			PROMENG_DISPLAY_NAME,
			esc_html__( 'Promotions', 'promotion-engine' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-tag',
			56
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'promeng-admin', PROMENG_URL . 'assets/admin.css', array(), PROMENG_VERSION );
		// Product/category pickers use WooCommerce's enhanced selects.
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'promeng-admin', PROMENG_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), PROMENG_VERSION, true );
		wp_localize_script(
			'promeng-admin',
			'pengSum',
			array(
				'currency'        => html_entity_decode( get_woocommerce_currency_symbol() ),
				'percent_off'     => __( '%1$s%% off %2$s', 'promotion-engine' ),
				'amount_off'      => __( '%1$s off %2$s', 'promotion-engine' ),
				'scope_all'       => __( 'all products', 'promotion-engine' ),
				'scope_products'  => __( 'selected products', 'promotion-engine' ),
				'scope_cats'      => __( 'the %s category', 'promotion-engine' ),
				'scope_cart'      => __( 'the entire order', 'promotion-engine' ),
				'bxpy_deal'       => __( 'Buy %1$s %2$s, pay %3$s', 'promotion-engine' ),
				'bxgy_deal'       => __( 'Spend %1$s → get %2$s', 'promotion-engine' ),
				'bxgy_deal_items' => __( 'Buy %1$s → get %2$s', 'promotion-engine' ),
				'benefit_free'    => __( 'free', 'promotion-engine' ),
				'benefit_fixed'   => __( 'for %s', 'promotion-engine' ),
				'benefit_percent' => __( 'at %s%% off', 'promotion-engine' ),
				'a_product'       => __( 'a product', 'promotion-engine' ),
				'items'           => __( '%s items', 'promotion-engine' ),
				'cond_amount'     => __( 'On orders over %s', 'promotion-engine' ),
				'cond_items'      => __( 'On a purchase of at least %s items', 'promotion-engine' ),
				'autoadd'         => __( 'The free product is added to the cart automatically', 'promotion-engine' ),
				'cheapest_note'   => __( 'Discount applied to the cheapest items', 'promotion-engine' ),
				'per_order'       => __( 'Up to %s redemptions per order', 'promotion-engine' ),
				'limit_items'     => __( 'Discount applies to up to %s items', 'promotion-engine' ),
				'applies'         => __( 'Applies to %s', 'promotion-engine' ),
				'excludes'        => __( 'Excludes: %s', 'promotion-engine' ),
				'coupon'          => __( 'Coupon code:', 'promotion-engine' ),
				'sched_open'      => __( 'Active from today, no end date', 'promotion-engine' ),
				'sched_ended'     => __( 'This promotion ended on %s and no longer applies', 'promotion-engine' ),
				'sched_until'     => __( 'Active until %s', 'promotion-engine' ),
				'sched_from'      => __( 'Active from %s', 'promotion-engine' ),
				'sched_range'     => __( 'Active %1$s – %2$s', 'promotion-engine' ),
				'sched_days'      => __( 'on %s', 'promotion-engine' ),
				'limit_one'       => __( 'Limited to one purchase per customer', 'promotion-engine' ),
				'limit_n'         => __( 'Limited to %d purchases per customer', 'promotion-engine' ),
				'channel'         => __( 'Available on all sales channels', 'promotion-engine' ),
				'channel_both'    => __( 'Available on web and app', 'promotion-engine' ),
				'channel_web'     => __( 'Website only', 'promotion-engine' ),
				'channel_app'     => __( 'App only', 'promotion-engine' ),
				'days'            => array(
					__( 'Sun', 'promotion-engine' ),
					__( 'Mon', 'promotion-engine' ),
					__( 'Tue', 'promotion-engine' ),
					__( 'Wed', 'promotion-engine' ),
					__( 'Thu', 'promotion-engine' ),
					__( 'Fri', 'promotion-engine' ),
					__( 'Sat', 'promotion-engine' ),
				),
				'placeholder'     => __( 'Fill in the form to see the summary…', 'promotion-engine' ),
			)
		);
	}

	/**
	 * Route the top-level page to list or edit.
	 */
	public function render() {
		$action = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $action ) {
			$this->render_edit();
		} elseif ( 'choose' === $action ) {
			include PROMENG_DIR . 'includes/admin/views/choose.php';
		} else {
			$this->render_list();
		}
	}

	private function render_list() {
		$now = current_time( 'timestamp' );

		// Period range.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : 'all';
		list( $from, $to ) = $this->range_bounds( $range, $now );

		// Active filters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'all';
		$f_type  = isset( $_GET['ptype'] ) ? sanitize_key( $_GET['ptype'] ) : '';
		$f_chan  = isset( $_GET['channel'] ) ? sanitize_key( $_GET['channel'] ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all      = Repository::all();
		$rows     = array();
		$counts   = array( 'all' => 0, 'active' => 0, 'scheduled' => 0, 'draft' => 0, 'ended' => 0 );
		$kpi      = array( 'active' => 0, 'ending' => 0, 'revenue' => 0.0, 'discount' => 0.0, 'orders' => 0 );
		$week_end = $now + 7 * DAY_IN_SECONDS;

		foreach ( $all as $p ) {
			$cat                = $this->promo_category( $p, $now );
			$counts['all']++;
			$counts[ $cat ]     = ( $counts[ $cat ] ?? 0 ) + 1;
			$stats              = Usage::stats( $p->id, $from, $to );

			// Global KPIs (range-scoped, independent of tab/search).
			$kpi['revenue']  += $stats['revenue'];
			$kpi['discount'] += $stats['discount'];
			$kpi['orders']   += $stats['orders'];
			if ( 'active' === $cat ) {
				$kpi['active']++;
				if ( $p->ends_at && strtotime( $p->ends_at ) <= $week_end ) {
					$kpi['ending']++;
				}
			}

			$rows[] = array(
				'p'     => $p,
				'cat'   => $cat,
				'stats' => $stats,
			);
		}

		// Apply tab + filters to the table rows.
		$filtered = array_filter(
			$rows,
			function ( $r ) use ( $tab, $f_type, $f_chan, $search ) {
				$p = $r['p'];
				if ( 'all' !== $tab && $r['cat'] !== $tab ) {
					return false;
				}
				if ( $f_type && $p->type !== $f_type ) {
					return false;
				}
				if ( $f_chan && ! in_array( $f_chan, (array) $p->channels, true ) ) {
					return false;
				}
				if ( '' !== $search ) {
					$hay = $p->name . ' ' . (string) $p->coupon_code;
					if ( false === mb_stripos( $hay, $search ) ) {
						return false;
					}
				}
				return true;
			}
		);

		$total    = count( $filtered );
		$per_page = 10;
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$paged    = min( $paged, $pages );
		$page_rows = array_slice( array_values( $filtered ), ( $paged - 1 ) * $per_page, $per_page );

		// Total store revenue for the "% of revenue" KPI.
		$store_revenue       = $this->total_store_revenue( $from, $to );
		$kpi['revenue_pct']  = $store_revenue > 0 ? round( $kpi['revenue'] / $store_revenue * 100 ) : null;
		$kpi['roi']          = $kpi['discount'] > 0 ? $kpi['revenue'] / $kpi['discount'] : null;

		// Expose to the view.
		$promeng = compact( 'rows', 'page_rows', 'counts', 'kpi', 'tab', 'f_type', 'f_chan', 'search', 'range', 'paged', 'pages', 'total', 'per_page' );

		include PROMENG_DIR . 'includes/admin/views/list.php';
	}

	/**
	 * Categorise a promotion: active (live) | scheduled | ended | draft (off).
	 */
	private function promo_category( $p, $now ) {
		if ( $p->ends_at && strtotime( $p->ends_at ) < $now ) {
			return 'ended';
		}
		if ( $p->starts_at && strtotime( $p->starts_at ) > $now ) {
			return 'scheduled';
		}
		if ( ! $p->active ) {
			return 'draft';
		}
		return 'active';
	}

	/**
	 * [from, to] datetime bounds (Y-m-d H:i:s) for a period key, or [null,null].
	 */
	private function range_bounds( $range, $now ) {
		switch ( $range ) {
			case 'month':
				return array( gmdate( 'Y-m-01 00:00:00', $now ), gmdate( 'Y-m-d H:i:s', $now ) );
			case 'lastmonth':
				$first = strtotime( 'first day of last month', $now );
				$last  = strtotime( 'last day of last month', $now );
				return array( gmdate( 'Y-m-01 00:00:00', $first ), gmdate( 'Y-m-d 23:59:59', $last ) );
			case 'week':
				return array( gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now ) );
			default:
				return array( null, null );
		}
	}

	/**
	 * Total store revenue (completed + processing) within a range, cached.
	 */
	private function total_store_revenue( $from, $to ) {
		$key   = 'promeng_rev_' . md5( (string) $from . '|' . (string) $to );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		$args = array(
			'status'   => array( 'wc-completed', 'wc-processing' ),
			'limit'    => -1,
			'return'   => 'ids',
		);
		if ( $from ) {
			$args['date_created'] = '>=' . strtotime( $from );
		}
		$ids   = wc_get_orders( $args );
		$total = 0.0;
		foreach ( (array) $ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$total += (float) $o->get_total();
			}
		}
		set_transient( $key, $total, 10 * MINUTE_IN_SECONDS );
		return $total;
	}

	private function render_edit() {
		$id        = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$promotion = $id ? Repository::find( $id ) : new Promotion();
		$readonly  = $promotion && 'giorgio' === $promotion->source;

		$allowed = array( 'discount', 'buy_x_pay_y', 'buy_x_get_y' );
		if ( $id && $promotion ) {
			$type = $promotion->type;
		} else {
			$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'discount'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'discount';
		}

		include PROMENG_DIR . 'includes/admin/views/edit.php';
	}

	/**
	 * Handle the create/edit form submission.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'promotion-engine' ) );
		}
		check_admin_referer( 'promeng_save' );

		$id   = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$data = $this->sanitize( $_POST );

		// Don't allow editing Giorgio-owned definitions locally.
		if ( $id ) {
			$existing = Repository::find( $id );
			if ( $existing && 'giorgio' === $existing->source ) {
				wp_safe_redirect( $this->page_url() );
				exit;
			}
		}

		$new_id = Repository::save( $data, $id );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE, 'saved' => 1 ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize the submitted form into a normalized data array.
	 *
	 * @return array
	 */
	private function sanitize( array $post ) {
		$type = isset( $post['type'] ) ? sanitize_key( $post['type'] ) : 'discount';

		$config = array();
		if ( 'discount' === $type ) {
			$applies_to = isset( $post['applies_to'] ) ? sanitize_key( $post['applies_to'] ) : 'all';
			if ( ! in_array( $applies_to, array( 'all', 'products', 'categories' ), true ) ) {
				$applies_to = 'all';
			}
			$config     = array(
				'applies_to'           => $applies_to,
				'as_cart_discount'     => ! empty( $post['as_cart_discount'] ),
				'discount_type'        => isset( $post['discount_type'] ) && 'fixed' === $post['discount_type'] ? 'fixed' : 'percent',
				'discount_value'       => isset( $post['discount_value'] ) ? (float) $post['discount_value'] : 0,
				'product_ids'          => isset( $post['product_ids'] ) ? array_map( 'intval', (array) $post['product_ids'] ) : array(),
				'category_ids'         => isset( $post['category_ids'] ) ? array_map( 'intval', (array) $post['category_ids'] ) : array(),
				'excluded_product_ids' => isset( $post['excluded_product_ids'] ) ? array_map( 'intval', (array) $post['excluded_product_ids'] ) : array(),
				'condition_min_items'  => isset( $post['condition_min_items'] ) && $post['condition_min_items'] !== '' ? (int) $post['condition_min_items'] : 0,
				'condition_min_amount' => isset( $post['condition_min_amount'] ) && $post['condition_min_amount'] !== '' ? (float) $post['condition_min_amount'] : 0,
				'limit_discounted_items' => ( ! empty( $post['promeng_has_limits'] ) && isset( $post['limit_discounted_items'] ) && $post['limit_discounted_items'] !== '' ) ? (int) $post['limit_discounted_items'] : null,
			);
		} elseif ( 'buy_x_pay_y' === $type ) {
			$config = array(
				'scope'                => isset( $post['scope'] ) && 'category' === $post['scope'] ? 'category' : 'product',
				'buy_quantity'         => isset( $post['buy_quantity'] ) ? (float) $post['buy_quantity'] : 0,
				'pay_amount'           => isset( $post['pay_amount'] ) ? (float) $post['pay_amount'] : 0,
				'product_ids'          => isset( $post['product_ids'] ) ? array_map( 'intval', (array) $post['product_ids'] ) : array(),
				'category_ids'         => isset( $post['category_ids'] ) ? array_map( 'intval', (array) $post['category_ids'] ) : array(),
				'excluded_product_ids' => isset( $post['excluded_product_ids'] ) ? array_map( 'intval', (array) $post['excluded_product_ids'] ) : array(),
			);
		} elseif ( 'buy_x_get_y' === $type ) {
			$config = array(
				'buy_applies_to'           => isset( $post['buy_applies_to'] ) ? sanitize_key( $post['buy_applies_to'] ) : 'all',
				'buy_product_ids'          => isset( $post['buy_product_ids'] ) ? array_map( 'intval', (array) $post['buy_product_ids'] ) : array(),
				'buy_category_ids'         => isset( $post['buy_category_ids'] ) ? array_map( 'intval', (array) $post['buy_category_ids'] ) : array(),
				'buy_excluded_product_ids' => isset( $post['buy_excluded_product_ids'] ) ? array_map( 'intval', (array) $post['buy_excluded_product_ids'] ) : array(),
				'buy_min_items'            => isset( $post['buy_min_items'] ) && $post['buy_min_items'] !== '' ? max( 1, (int) $post['buy_min_items'] ) : 1,
				'buy_min_amount'           => isset( $post['buy_min_amount'] ) && $post['buy_min_amount'] !== '' ? (float) $post['buy_min_amount'] : 0,
				'benefit_applies_to'          => isset( $post['benefit_applies_to'] ) ? sanitize_key( $post['benefit_applies_to'] ) : 'products',
				'benefit_quantity'            => isset( $post['benefit_quantity'] ) && $post['benefit_quantity'] !== '' ? max( 1, (int) $post['benefit_quantity'] ) : 1,
				'benefit_product_ids'      => isset( $post['benefit_product_ids'] ) ? array_map( 'intval', (array) $post['benefit_product_ids'] ) : array(),
				'benefit_category_ids'        => isset( $post['benefit_category_ids'] ) ? array_map( 'intval', (array) $post['benefit_category_ids'] ) : array(),
				'benefit_excluded_product_ids' => isset( $post['benefit_excluded_product_ids'] ) ? array_map( 'intval', (array) $post['benefit_excluded_product_ids'] ) : array(),
				'benefit_type'             => isset( $post['benefit_type'] ) && in_array( $post['benefit_type'], array( 'free', 'percent', 'fixed' ), true ) ? $post['benefit_type'] : 'free',
				'benefit_value'            => isset( $post['benefit_value'] ) ? (float) $post['benefit_value'] : 0,
				'auto_add'                 => ! empty( $post['auto_add'] ),
				'apply_to_cheapest'           => ! empty( $post['apply_to_cheapest'] ),
			);
		}

		// Influencer assignment (when the OC Influencer Dashboard plugin is active).
		if ( Influencer::is_active() && ! empty( $post['requires_coupon'] ) ) {
			$config[ Influencer::CFG_USER ]   = isset( $post['influencer_user_id'] ) ? (int) $post['influencer_user_id'] : 0;
			$config[ Influencer::CFG_PCT ]    = isset( $post['influencer_commission_pct'] ) && $post['influencer_commission_pct'] !== '' ? (float) $post['influencer_commission_pct'] : 0;
			$payout                           = isset( $post['influencer_payout'] ) ? sanitize_key( $post['influencer_payout'] ) : '';
			$config[ Influencer::CFG_PAYOUT ] = in_array( $payout, array( 'points', 'cash' ), true ) ? $payout : '';
		}

		return array(
			'name'               => isset( $post['name'] ) ? sanitize_text_field( wp_unslash( $post['name'] ) ) : '',
			'type'               => $type,
			'active'             => ! empty( $post['active'] ),
			'channels'           => isset( $post['channels'] ) ? array_map( 'sanitize_key', (array) $post['channels'] ) : array( 'web' ),
			'coupon_code'        => isset( $post['coupon_code'] ) ? sanitize_text_field( wp_unslash( $post['coupon_code'] ) ) : '',
			'requires_coupon'    => ! empty( $post['requires_coupon'] ),
			'show_label'         => ! empty( $post['show_label'] ),
			'config'             => $config,
			'limit_per_order'    => isset( $post['limit_per_order'] ) ? $post['limit_per_order'] : '',
			'limit_per_customer' => isset( $post['limit_per_customer'] ) ? $post['limit_per_customer'] : '',
			'starts_at'          => ! empty( $post['starts_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $post['starts_at'] ) ) ) : null,
			'ends_at'            => ! empty( $post['ends_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $post['ends_at'] ) ) ) : null,
			'weekdays'           => isset( $post['weekdays'] ) ? array_map( 'intval', (array) $post['weekdays'] ) : array(),
			'source'             => 'local',
		);
	}

	/**
	 * Handle toggle / duplicate / delete from the list.
	 */
	public function handle_row_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'promotion-engine' ) );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( 'promeng_action_' . $id );

		switch ( $do ) {
			case 'toggle':
				$p = Repository::find( $id );
				if ( $p ) {
					Repository::set_active( $id, ! $p->active );
				}
				break;
			case 'duplicate':
				Repository::duplicate( $id );
				break;
			case 'delete':
				$p = Repository::find( $id );
				if ( $p && 'giorgio' !== $p->source ) {
					Repository::delete( $id );
				}
				break;
		}

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	private function page_url() {
		return add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL to the WooCommerce orders screen filtered to a promotion's redemptions.
	 *
	 * @param int $promotion_id
	 * @return string
	 */
	public function orders_url( $promotion_id ) {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$base = $hpos
			? admin_url( 'admin.php?page=wc-orders' )
			: admin_url( 'edit.php?post_type=shop_order' );
		return add_query_arg( 'promeng_promo', (int) $promotion_id, $base );
	}

	/**
	 * Restrict the HPOS orders list to a promotion's redemptions.
	 *
	 * @param array $args
	 * @return array
	 */
	public function filter_orders_query( $args ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['promeng_promo'] ) ) {
			return $args;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids              = Usage::order_ids( absint( wp_unslash( $_GET['promeng_promo'] ) ) );
		$args['post__in'] = $ids ? $ids : array( 0 );
		return $args;
	}

	/**
	 * Restrict the legacy (post-based) orders list to a promotion's redemptions.
	 *
	 * @param array $vars
	 * @return array
	 */
	public function filter_orders_request( $vars ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['promeng_promo'] ) ) {
			return $vars;
		}
		if ( empty( $vars['post_type'] ) || 'shop_order' !== $vars['post_type'] ) {
			return $vars;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids              = Usage::order_ids( absint( wp_unslash( $_GET['promeng_promo'] ) ) );
		$vars['post__in'] = $ids ? $ids : array( 0 );
		return $vars;
	}
}
