<?php
/**
 * Main plugin orchestrator.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Engine */
	public $engine;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Safety net: make sure tables exist even if activation hook was missed.
		if ( get_option( Installer::DB_VERSION_OPTION ) !== Installer::DB_VERSION ) {
			Installer::create_tables();
			update_option( Installer::DB_VERSION_OPTION, Installer::DB_VERSION );
		}

		$this->engine = new Engine();

		// Front-end application.
		new Cart( $this->engine );
		new Catalog();
		new Coupon();

		// Influencer Dashboard integration (only when that plugin is present).
		if ( Influencer::is_active() ) {
			( new Influencer() )->hooks();
		}

		// Store-wide settings (catalog label position, …).
		( new Settings() )->hooks();

		// Giorgio sync layer (inbound REST push, outbound usage reporting).
		( new \PromoEngine\Giorgio\Settings() )->hooks();
		( new \PromoEngine\Giorgio\Rest() )->hooks();
		( new \PromoEngine\Giorgio\Client() )->hooks();

		// Admin.
		if ( is_admin() ) {
			new Admin();
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
	}

	public function frontend_assets() {
		wp_enqueue_style( 'promeng-front', PROMENG_URL . 'assets/front.css', array(), PROMENG_VERSION );
		wp_enqueue_script( 'promeng-front', PROMENG_URL . 'assets/front.js', array(), PROMENG_VERSION, true );
	}
}
