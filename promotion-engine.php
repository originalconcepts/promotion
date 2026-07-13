<?php
/**
 * Plugin Name:       Promotion Engine
 * Plugin URI:        https://giorgio.co.il
 * Description:        A self-contained promotions engine for WooCommerce (% / ₪ discount, Buy X Pay Y, Buy X Get Y). Works standalone, and can sync promotion definitions from Giorgio via API. Display name is a placeholder — rebrand via PROMENG_DISPLAY_NAME.
 * Version:           0.9.8
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Original Concepts
 * Text Domain:       promotion-engine
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 *
 * @package PromoEngine
 *
 * --------------------------------------------------------------------------
 * ARCHITECTURE NOTE
 * --------------------------------------------------------------------------
 * This plugin IS the promotions engine. It computes prices locally in PHP and
 * applies them to the WooCommerce cart. It does NOT depend on Giorgio at
 * runtime — the checkout keeps working even if Giorgio is offline.
 *
 * For Giorgio clients, promotion *definitions* are pushed from Giorgio into
 * this plugin's tables (source = 'giorgio', read-only in WP admin), and
 * redemption events are reported back up so Giorgio's unified dashboard
 * reflects website activity. That sync layer lives in includes/giorgio/ and
 * is built in a later phase — the seams are already in place here.
 * --------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PROMENG_VERSION', '0.9.8' );
define( 'PROMENG_FILE', __FILE__ );
define( 'PROMENG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROMENG_URL', plugin_dir_url( __FILE__ ) );
define( 'PROMENG_BASENAME', plugin_basename( __FILE__ ) );

// Rebrand point: change this one string to white-label the plugin in the UI.
if ( ! defined( 'PROMENG_DISPLAY_NAME' ) ) {
	define( 'PROMENG_DISPLAY_NAME', 'Promotion Engine' );
}

// --- Includes -------------------------------------------------------------
require_once PROMENG_DIR . 'includes/class-updater.php';
require_once PROMENG_DIR . 'includes/class-installer.php';
require_once PROMENG_DIR . 'includes/class-promotion.php';
require_once PROMENG_DIR . 'includes/class-repository.php';
require_once PROMENG_DIR . 'includes/types/interface-type.php';
require_once PROMENG_DIR . 'includes/types/class-type-discount.php';
require_once PROMENG_DIR . 'includes/types/class-type-buy-x-pay-y.php';
require_once PROMENG_DIR . 'includes/types/class-type-buy-x-get-y.php';
require_once PROMENG_DIR . 'includes/class-engine.php';
require_once PROMENG_DIR . 'includes/class-usage.php';
require_once PROMENG_DIR . 'includes/class-cart.php';
require_once PROMENG_DIR . 'includes/class-catalog.php';
require_once PROMENG_DIR . 'includes/class-coupon.php';
require_once PROMENG_DIR . 'includes/class-influencer.php';
require_once PROMENG_DIR . 'includes/class-settings.php';
require_once PROMENG_DIR . 'includes/class-app.php';
require_once PROMENG_DIR . 'includes/giorgio/class-settings.php';
require_once PROMENG_DIR . 'includes/giorgio/class-rest.php';
require_once PROMENG_DIR . 'includes/giorgio/class-client.php';
require_once PROMENG_DIR . 'includes/admin/class-admin.php';
require_once PROMENG_DIR . 'includes/class-plugin.php';

// --- Lifecycle ------------------------------------------------------------
// Self-updates from GitHub (originalconcepts/promotion, branch main). Runs
// everywhere, including wp-cron, so pushed updates install automatically.
\PromoEngine\Updater::instance();

register_activation_hook( __FILE__, array( 'PromoEngine\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PromoEngine\\Installer', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded, but only if WooCommerce exists.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html( PROMENG_DISPLAY_NAME . ': WooCommerce ' . __( 'is required for this plugin to work.', 'promotion-engine' ) )
						. '</p></div>';
				}
			);
			return;
		}
		\PromoEngine\Plugin::instance();
	}
);

// Load translations (English source strings; Hebrew shipped in /languages).
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'promotion-engine', false, dirname( PROMENG_BASENAME ) . '/languages' );
	}
);

// Declare HPOS (High-Performance Order Storage) compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PROMENG_FILE, true );
		}
	}
);
