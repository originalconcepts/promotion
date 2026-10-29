<?php
/**
 * The link to OC Pluginz (my.ocpluginz.com): the licence that brings updates
 * from the hub and lists this install among the hub's sites.
 *
 * One option (promeng_hub) holds the key and a copy of the entitlement the hub
 * last answered with. The hub is asked again hourly (WP-Cron) and when the
 * account tab opens; an unreachable hub is survived on the cached copy, a hub
 * that refuses the key is not.
 *
 * The licence never switches promotions off: discounts, coupons and the
 * Giorgio sync work with or without it. It decides two things only — whether
 * updates are offered from the hub (updates_allowed()) and whether this site
 * is listed in the owner's OC Pluginz account.
 *
 * Two kinds of installs share this code base:
 *   - Giorgio client sites: no licence key, updates come from GitHub through
 *     PromoEngine\Updater as before. Nothing here runs for them beyond a
 *     cheap option read, and nothing nags them.
 *   - OC Pluginz customers: a key is connected on the account tab (or fixed as
 *     PROMENG_LICENSE_KEY in wp-config.php). Once the hub has answered for
 *     that key, connected() is true, PromoEngine\HubUpdater serves updates and
 *     PromoEngine\Updater stays silent.
 * Agency mode (OC_PLUGINZ_AGENCY = true in wp-config.php) skips the hub
 * entirely: no calls, no notices, GitHub updates as on a Giorgio site.
 *
 * What goes to the hub: the key, the product, this site's domain and address,
 * the plugin and WordPress versions. Nothing about orders or customers.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub {

	const URL         = 'https://my.ocpluginz.com';
	const PRODUCT     = 'oc-promotions';
	const OPTION      = 'promeng_hub';
	const CRON        = 'promeng_hub_refresh';
	const ACCOUNT_URL = 'https://my.ocpluginz.com/account/';
	const PRICING_URL = 'https://ocpluginz.com/oc-promotions.html#pricing';

	/** The hub's thresholds (OCPZS_Entitlements), mirrored so a cached copy ages the same way. */
	const EXPIRING_DAYS = 14;
	const GRACE_DAYS    = 7;

	/** Codes that mean "this key buys nothing here": the cached entitlement goes. An outage keeps it. */
	const REFUSALS = array( 'ocpzs_bad_key', 'ocpzs_inactive', 'ocpzs_seats_full', 'ocpzs_bad_product', 'ocpzs_bad_request' );

	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		}
	}

	// ----- the rules, without WordPress ----------------------------------------

	/**
	 * valid | expiring | grace | expired, from expires_at as the hub ages it. No end date never expires.
	 *
	 * @param array|null $ent The entitlement.
	 * @param int        $now Unix time.
	 * @return string
	 */
	public static function lifecycle( $ent, $now ) {
		if ( ! is_array( $ent ) ) {
			return 'expired';
		}
		$end = isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '';
		if ( '' === $end ) {
			return 'valid';
		}
		$exp = strtotime( $end );
		if ( false === $exp ) {
			return isset( $ent['state'] ) ? (string) $ent['state'] : 'valid';
		}
		if ( $exp > $now ) {
			return ( $exp - $now < self::EXPIRING_DAYS * DAY_IN_SECONDS ) ? 'expiring' : 'valid';
		}
		return ( $now - $exp < self::GRACE_DAYS * DAY_IN_SECONDS ) ? 'grace' : 'expired';
	}

	/**
	 * Are updates offered? The hub's own rule for a download (OCPZS_Packages::license_for): an active
	 * licence, or one past due within its grace days.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return bool
	 */
	public static function decide( array $state, $key, $now ) {
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( '' === $key || null === $ent ) {
			return false;
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		$life   = self::lifecycle( $ent, $now );
		if ( in_array( $status, array( 'suspended', 'deleted' ), true ) || 'expired' === $life ) {
			return false;
		}
		return 'active' === $status || 'grace' === $life;
	}

	/**
	 * Where the licence stands, for the tab and the notice: '' (fine), no_key, refused, unverified,
	 * suspended, grace, expired.
	 *
	 * @param array  $state The option.
	 * @param string $key   The licence key.
	 * @param int    $now   Unix time.
	 * @return string
	 */
	public static function reason( array $state, $key, $now ) {
		if ( '' === $key ) {
			return 'no_key';
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return ! empty( $state['refused'] ) ? 'refused' : 'unverified';
		}
		if ( in_array( isset( $ent['status'] ) ? (string) $ent['status'] : '', array( 'suspended', 'deleted' ), true ) ) {
			return 'suspended';
		}
		if ( ! self::decide( $state, $key, $now ) ) {
			return 'expired';
		}
		return 'grace' === self::lifecycle( $ent, $now ) ? 'grace' : '';
	}

	/** An OC Pluginz key: ocpz_ and hex. */
	public static function looks_like_key( $key ) {
		return 1 === preg_match( '/^ocpz_[a-f0-9]{20,64}$/i', (string) $key );
	}

	/** The domain the hub binds a seat to: the host, lower case, without www. */
	public static function domain_of( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/** The key as the tab shows it: the start and the end. */
	public static function masked( $key ) {
		$key = (string) $key;
		return strlen( $key ) > 14 ? substr( $key, 0, 9 ) . '…' . substr( $key, -4 ) : $key;
	}

	// ----- the site's state ---------------------------------------------------

	public static function url() {
		return rtrim( defined( 'PROMENG_HUB_URL' ) ? (string) PROMENG_HUB_URL : self::URL, '/' );
	}

	/** Agency mode (our own sites): no licence, no hub calls, no notices. */
	public static function agency() {
		return defined( 'OC_PLUGINZ_AGENCY' ) && OC_PLUGINZ_AGENCY;
	}

	/** The key fixed in wp-config.php. */
	public static function key_from_constant() {
		return defined( 'PROMENG_LICENSE_KEY' ) && '' !== trim( (string) PROMENG_LICENSE_KEY );
	}

	/** @return array */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function patch( array $patch ) {
		update_option( self::OPTION, array_merge( self::state(), $patch ), false );
	}

	public static function key() {
		if ( self::key_from_constant() ) {
			return trim( (string) PROMENG_LICENSE_KEY );
		}
		$s = self::state();
		return isset( $s['key'] ) ? (string) $s['key'] : '';
	}

	public static function domain() {
		return self::domain_of( home_url( '/' ) );
	}

	/** @return array|null the entitlement the hub last answered with */
	public static function entitlement() {
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) ? $s['entitlement'] : null;
	}

	/**
	 * Is this site in the hub's hands? A key is set (not agency mode) and the hub has answered for it
	 * at least once — accepted it (entitlement cached) or refused it. From then on HubUpdater owns
	 * updates and the GitHub updater stays silent, whatever the licence's state: an expired or refused
	 * licence means no updates, not GitHub updates. Disconnecting (or removing the key) hands updates
	 * back to GitHub. A wp-config key the hub has never been reached for does not count yet.
	 *
	 * @return bool
	 */
	public static function connected() {
		if ( self::agency() || '' === self::key() ) {
			return false;
		}
		$s = self::state();
		return ( isset( $s['entitlement'] ) && is_array( $s['entitlement'] ) ) || ! empty( $s['refused'] );
	}

	public static function updates_allowed() {
		return ! self::agency() && self::decide( self::state(), self::key(), time() );
	}

	public static function status() {
		return self::reason( self::state(), self::key(), time() );
	}

	/** The account tab in this site's admin. */
	public static function settings_url() {
		return admin_url( 'admin.php?page=promeng-settings&tab=account' );
	}

	// ----- the hub --------------------------------------------------------------

	/** @return array */
	private static function identity() {
		return array(
			'key'            => self::key(),
			'product'        => self::PRODUCT,
			'domain'         => self::domain(),
			'site_url'       => home_url( '/' ),
			'version'        => PROMENG_VERSION,
			'plugin_version' => PROMENG_VERSION,
			'wp_version'     => (string) get_bloginfo( 'version' ),
		);
	}

	/**
	 * A call to /wp-json/ocpz/v1{$path}.
	 *
	 * @param string $method GET|POST.
	 * @param string $path   Under /wp-json/ocpz/v1.
	 * @param array  $body   Extra parameters.
	 * @return array|\WP_Error the decoded answer
	 */
	private static function request( $method, $path, array $body = array() ) {
		$body = array_merge( self::identity(), $body );
		$args = array(
			'timeout'   => 20,
			'sslverify' => true,
			'headers'   => array(
				'X-OCPZ-Key'     => self::key(),
				'X-OCPZ-Site'    => self::domain(),
				'X-OCPZ-Version' => PROMENG_VERSION,
				'Accept'         => 'application/json',
				'User-Agent'     => 'OC-Promotions/' . PROMENG_VERSION . '; ' . home_url( '/' ),
			),
		);
		$url  = self::url() . '/wp-json/ocpz/v1' . $path;
		if ( 'GET' === $method ) {
			$response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $body ), $url ), $args );
		} else {
			$args['body'] = $body;
			$response     = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'promeng_hub_http', sprintf( __( 'OC Pluginz answered with status %d.', 'promotion-engine' ), $code ) );
		}
		if ( $code >= 400 || ( isset( $data['ok'] ) && false === $data['ok'] ) ) {
			/* translators: %d: HTTP status code */
			$msg = isset( $data['message'] ) ? (string) $data['message'] : sprintf( __( 'OC Pluginz answered with status %d.', 'promotion-engine' ), $code );
			return new \WP_Error( isset( $data['code'] ) ? (string) $data['code'] : 'promeng_hub_http', $msg );
		}
		return $data;
	}

	/** The hub's refusals in words the store owner can act on. */
	private static function friendly( \WP_Error $error ) {
		switch ( $error->get_error_code() ) {
			case 'ocpzs_bad_key':
				return __( 'OC Pluginz does not recognise this key for OC Promotions. Check it in your OC Pluginz account.', 'promotion-engine' );
			case 'ocpzs_inactive':
				return __( 'This licence is not active. Renew it in your OC Pluginz account.', 'promotion-engine' );
			case 'ocpzs_seats_full':
				return __( 'Every site this licence allows is already connected. Disconnect another site first (in its Promotions settings, or in your OC Pluginz account).', 'promotion-engine' );
			case 'ocpzs_bad_product':
				return __( 'OC Pluginz does not offer OC Promotions yet. Contact Original Concepts.', 'promotion-engine' );
			case 'rest_forbidden':
				return __( 'OC Pluginz refused the request for now. Try again in a minute.', 'promotion-engine' );
		}
		return $error->get_error_message();
	}

	/**
	 * Keep what the hub answered.
	 *
	 * @param array $data The decoded answer.
	 * @return true|\WP_Error
	 */
	private static function accept( array $data ) {
		$ent = ( isset( $data['entitlement'] ) && is_array( $data['entitlement'] ) ) ? $data['entitlement'] : null;
		if ( null === $ent ) {
			return new \WP_Error( 'promeng_hub_shape', __( 'OC Pluginz answered without a licence.', 'promotion-engine' ) );
		}
		$s = self::state();
		self::patch(
			array(
				'entitlement' => $ent,
				'latest'      => isset( $data['latest'] ) ? (string) $data['latest'] : ( isset( $s['latest'] ) ? (string) $s['latest'] : '' ),
				'checked_at'  => time(),
				'error'       => '',
				'error_code'  => '',
				'error_at'    => 0,
				'refused'     => false,
			)
		);
		return true;
	}

	/** A failure: a refusal drops the cached entitlement, an outage does not. */
	private static function note_error( \WP_Error $error ) {
		$patch = array(
			'error'      => self::friendly( $error ),
			'error_code' => $error->get_error_code(),
			'error_at'   => time(),
		);
		if ( in_array( $error->get_error_code(), self::REFUSALS, true ) ) {
			$patch['entitlement'] = null;
			$patch['refused']     = true;
		}
		self::patch( $patch );
	}

	/**
	 * Take a seat for this site. The key is kept only once the hub accepts it, so a typo never leaves
	 * the site half connected.
	 *
	 * @param string $key The licence key.
	 * @return true|\WP_Error
	 */
	public static function connect( $key ) {
		$key = trim( (string) $key );
		if ( ! self::looks_like_key( $key ) ) {
			return new \WP_Error( 'promeng_hub_key', __( 'That does not look like an OC Pluginz licence key (ocpz_…).', 'promotion-engine' ) );
		}
		$previous = self::state();
		update_option( self::OPTION, array( 'key' => $key ), false );
		$res = self::request( 'POST', '/sdk/activate' );
		$ok  = is_wp_error( $res ) ? $res : self::accept( $res );
		if ( is_wp_error( $ok ) ) {
			update_option( self::OPTION, $previous, false );
			return new \WP_Error( $ok->get_error_code(), self::friendly( $ok ) );
		}
		self::patch( array( 'activated_at' => time() ) );
		self::maybe_schedule();
		return true;
	}

	/**
	 * Ask the hub again; a wp-config key that never took its seat takes it first.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh() {
		if ( self::agency() || '' === self::key() ) {
			return new \WP_Error( 'promeng_hub_no_key', __( 'No licence key.', 'promotion-engine' ) );
		}
		$s     = self::state();
		$fresh = self::key_from_constant() && null === self::entitlement() && empty( $s['refused'] );
		$res   = self::request( $fresh ? 'POST' : 'GET', $fresh ? '/sdk/activate' : '/sdk/status' );
		if ( is_wp_error( $res ) ) {
			self::note_error( $res );
			return new \WP_Error( $res->get_error_code(), self::friendly( $res ) );
		}
		$ok = self::accept( $res );
		if ( true === $ok && $fresh ) {
			self::patch( array( 'activated_at' => time() ) );
		}
		return $ok;
	}

	/** The hourly check (WP-Cron). */
	public static function cron() {
		self::refresh();
	}

	/**
	 * Ask again when the last contact is older than $max_age seconds (the account tab).
	 *
	 * @param int $max_age Seconds.
	 */
	public static function refresh_if_stale( $max_age = 600 ) {
		if ( self::agency() || '' === self::key() ) {
			return;
		}
		$s    = self::state();
		$last = max( isset( $s['checked_at'] ) ? (int) $s['checked_at'] : 0, isset( $s['error_at'] ) ? (int) $s['error_at'] : 0 );
		if ( time() - $last >= (int) $max_age ) {
			self::refresh();
		}
	}

	/** Free the seat at the hub and forget the key. Updates go back to GitHub. */
	public static function disconnect() {
		if ( '' !== self::key() && ! self::agency() ) {
			self::request( 'POST', '/sdk/deactivate' );
		}
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
		return true;
	}

	/** The hourly check runs while a key is set, and only then. */
	public static function maybe_schedule() {
		$want = '' !== self::key() && ! self::agency();
		$next = wp_next_scheduled( self::CRON );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	// ----- the notice -------------------------------------------------------------

	/**
	 * On the plugin's own screens, when a connected licence brings no updates any more. A site
	 * without a key (a Giorgio client) is never nagged: it was never meant to have one.
	 */
	public static function notice() {
		if ( self::agency() || '' === self::key() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		if ( ( Admin::PAGE !== $page && 0 !== strpos( $page, 'promeng-' ) ) || 'account' === $tab ) {
			return;
		}
		$reason = self::status();
		switch ( $reason ) {
			case 'refused':
				$text = __( 'OC Pluginz did not accept the OC Promotions licence key, so updates are off. Promotions keep working.', 'promotion-engine' );
				break;
			case 'suspended':
				$text = __( 'The OC Promotions licence is suspended, so updates are off. Promotions keep working.', 'promotion-engine' );
				break;
			case 'expired':
				$text = __( 'The OC Promotions licence has expired, so updates are off. Promotions keep working; renew to get updates again.', 'promotion-engine' );
				break;
			case 'grace':
				$text = __( 'The OC Promotions licence has expired and is on its grace days. Renew now to keep getting updates.', 'promotion-engine' );
				break;
			default:
				return;
		}
		echo '<div class="notice notice-' . ( 'grace' === $reason ? 'warning' : 'error' ) . '"><p>' . esc_html( $text )
			. ' <a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'OC Pluginz account', 'promotion-engine' ) . '</a></p></div>';
	}
}
