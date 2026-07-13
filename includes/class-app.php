<?php
/**
 * Bridge to the OC App Platform plugin (the mobile app is a WebView wrapper
 * around the site; that companion plugin detects app traffic by user-agent).
 *
 * When that plugin is present, a promotion can be limited to a specific
 * platform — the website, the app, or both — and the admin gains a platform
 * selector on the promotion screen plus a channel filter on the dashboard.
 * When it is absent, every promotion is simply a website promotion and no
 * platform choice is shown.
 *
 * Detection / runtime contract (any one is enough):
 *   - the OC Webapp plugin's oc_webapp_is_in_app() function (preferred — this is
 *     the plugin Original Concepts already ships);
 *   - define( 'OC_APP_PLATFORM', true );           // generic presence flag
 *   - function oc_app_is_app_request(): bool        // generic current-request flag
 *   - add_filter( 'promeng_app_active', '__return_true' );
 *   - add_filter( 'promeng_current_channel', fn() => 'app' );  // when in app
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class App {

	/**
	 * Is the app-platform plugin available? When false the plugin behaves as a
	 * website-only promotions engine and hides every platform/channel control.
	 */
	public static function is_active() {
		// OC Webapp (the existing Original Concepts app companion plugin).
		if ( function_exists( 'oc_webapp_is_in_app' ) || defined( 'OC_WEBAPP_USER_AGENT_SUBSTR' ) ) {
			return true;
		}
		if ( defined( 'OC_APP_PLATFORM' ) && OC_APP_PLATFORM ) {
			return true;
		}
		if ( function_exists( 'oc_app_is_app_request' ) ) {
			return true;
		}
		return (bool) apply_filters( 'promeng_app_active', false );
	}

	/**
	 * Channels the merchant may target locally.
	 *
	 * @return string[] e.g. array( 'web' ) or array( 'web', 'app' ).
	 */
	public static function channels() {
		return self::is_active() ? array( 'web', 'app' ) : array( 'web' );
	}

	/**
	 * The channel the current storefront request belongs to: 'app' when the
	 * shopper is browsing inside the app's WebView, otherwise 'web'. Resolved
	 * from the OC Webapp user-agent check; without it everything is 'web'.
	 */
	public static function current_channel() {
		if ( ! self::is_active() ) {
			return 'web';
		}
		if ( function_exists( 'oc_webapp_is_in_app' ) ) {
			return \oc_webapp_is_in_app() ? 'app' : 'web';
		}
		if ( function_exists( 'oc_app_is_app_request' ) ) {
			return \oc_app_is_app_request() ? 'app' : 'web';
		}
		$channel = apply_filters( 'promeng_current_channel', 'web' );
		return ( 'app' === $channel ) ? 'app' : 'web';
	}

	/**
	 * Does a promotion run on the current request's channel? A promotion with no
	 * channels set is treated as website (back-compat).
	 *
	 * @param Promotion $promotion
	 * @param string    $channel Optional explicit channel.
	 * @return bool
	 */
	public static function promotion_runs_here( Promotion $promotion, $channel = null ) {
		// Interim measure: Giorgio does not yet send a per-promotion platform, so
		// every Giorgio-authored promotion is stored as web-only and would be
		// skipped inside the app. Until Giorgio can target platforms, run its
		// promotions on all channels (web + app). Flip the filter to false — or
		// remove this block — once Giorgio sends the channel per promotion.
		if ( 'giorgio' === $promotion->source
			&& apply_filters( 'promeng_giorgio_all_channels', true, $promotion ) ) {
			return true;
		}

		$channels = array_filter( (array) $promotion->channels );
		if ( empty( $channels ) ) {
			$channels = array( 'web' );
		}
		// When the app platform isn't installed everything is web; never block.
		if ( ! self::is_active() ) {
			return true;
		}
		$channel = $channel ? $channel : self::current_channel();
		return in_array( $channel, $channels, true );
	}
}
