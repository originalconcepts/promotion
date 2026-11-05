<?php
/**
 * The link to OC Pluginz (my.ocpluginz.com): the licence that brings updates
 * from the hub and lists this install among the hub's sites.
 *
 * One option (promeng_hub) holds the key and a copy of the entitlement the hub
 * last answered with, plus the hub's notices for the admin (class-notices.php),
 * and the recorded mode (full | restricted) with its flags. The hub is asked
 * again hourly (WP-Cron), when a screen of this plugin opens after an hour of
 * silence, and when the account tab opens; an unreachable hub is survived on
 * the cached copy, a hub that refuses the key is not.
 *
 * Two things hang on the licence:
 *
 *   updates_allowed()  are updates offered — the hub's own download rule.
 *   licensed()         FULL or RESTRICTED mode (see mode()). Restricted is not
 *                      a shut-off: promotions stop being APPLIED — no line or
 *                      cart discounts, no savings rows or cart messages, no
 *                      virtual coupons, no catalog labels or sale prices, no
 *                      auto-added gifts — so customers see full prices.
 *                      Everything else stays exactly as it is: every promotion
 *                      and its editing, the statistics, the order meta, the
 *                      settings, the Giorgio sync and its REST endpoints.
 *                      Nothing is deleted or hidden in the database.
 *
 * The site never goes restricted because of an outage: only an explicit answer
 * from the hub changes the verdict (a refusal, a suspension, or an expiry the
 * hub confirmed after the grace days). A transport error keeps the last
 * entitlement as it was (after_failure).
 *
 * Giorgio client sites (StoreOS) run this plugin as part of their Giorgio
 * connection, without a hub key: a site whose Giorgio settings hold a base URL
 * and an API key — or that holds promotions Giorgio pushed — is licensed by that
 * connection alone, with or without a key, whatever the key's state. A Giorgio
 * outage never restricts a site, and nothing in includes/giorgio/ reads the
 * licence.
 *
 * Agency mode (OC_PLUGINZ_AGENCY = true in wp-config.php) skips the hub
 * entirely: no calls, no notices, always full, GitHub updates as on a Giorgio site.
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

	/** Licence statuses the hub may answer with that close the licence. */
	const CLOSED = array( 'suspended', 'cancelled', 'canceled', 'disabled', 'deleted' );

	const FULL       = 'full';
	const RESTRICTED = 'restricted';

	/** User meta: when this user last dismissed the restricted notice (shown again a day later). */
	const META_SEEN      = 'promeng_restricted_seen';
	const ACTION_DISMISS = 'promeng_dismiss_restricted';
	const SEEN_FOR       = DAY_IN_SECONDS;

	/** The verdict of this request (the storefront asks many times a request; the option is read once). */
	private static $licensed = null;

	/** Whether this site counts as connected to Giorgio, for this request. */
	private static $giorgio = null;

	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_schedule' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_refresh' ) );
			add_action( 'admin_notices', array( __CLASS__, 'restricted_notice' ), 1 );
			add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
			add_action( 'admin_post_' . self::ACTION_DISMISS, array( __CLASS__, 'dismiss_restricted' ) );
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
		if ( in_array( $status, self::CLOSED, true ) || 'expired' === $life ) {
			return false;
		}
		return 'active' === $status || 'grace' === $life;
	}

	/**
	 * Full or restricted. Restricted only on the hub's explicit word, and never for a Giorgio site:
	 *
	 *   Giorgio connected           full — with or without a key, whatever the key's state
	 *   no key                      restricted — a fresh install runs restricted until it is connected (no trial)
	 *   refused (bad key, inactive) restricted — the hub said so and the entitlement was dropped
	 *   unverified                  full — a key the hub never answered for (an outage at activation): no verdict yet
	 *   suspended / deleted         restricted — the hub's own status
	 *   valid / expiring / grace    full
	 *   expired                     restricted only when the hub CONFIRMED it after the grace days (checked_at past
	 *                               expires_at + 7 days); unreachable since before the end → full until it answers
	 *
	 * @param array  $state   The option.
	 * @param string $key     The key in use.
	 * @param int    $now     Unix time.
	 * @param bool   $giorgio Is this site connected to Giorgio?
	 * @return string full|restricted
	 */
	public static function mode( array $state, $key, $now, $giorgio = false ) {
		if ( $giorgio ) {
			return self::FULL;
		}
		if ( '' === $key ) {
			return self::RESTRICTED;
		}
		$ent = ( isset( $state['entitlement'] ) && is_array( $state['entitlement'] ) ) ? $state['entitlement'] : null;
		if ( null === $ent ) {
			return ! empty( $state['refused'] ) ? self::RESTRICTED : self::FULL;
		}
		$status = isset( $ent['status'] ) ? (string) $ent['status'] : '';
		if ( in_array( $status, self::CLOSED, true ) ) {
			return self::RESTRICTED;
		}
		if ( 'expired' !== self::lifecycle( $ent, $now ) ) {
			return self::FULL;
		}
		$exp = strtotime( isset( $ent['expires_at'] ) ? (string) $ent['expires_at'] : '' );
		if ( false === $exp ) {
			return self::RESTRICTED; // the hub's own "expired" state, with a date we cannot read: its word stands
		}
		$checked = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
		return $checked >= $exp + self::GRACE_DAYS * DAY_IN_SECONDS ? self::RESTRICTED : self::FULL;
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
		if ( in_array( isset( $ent['status'] ) ? (string) $ent['status'] : '', self::CLOSED, true ) ) {
			return 'suspended';
		}
		if ( ! self::decide( $state, $key, $now ) ) {
			return 'expired';
		}
		return 'grace' === self::lifecycle( $ent, $now ) ? 'grace' : '';
	}

	/**
	 * What a failed call to the hub leaves in the option: the error, and — for a refusal only — no
	 * entitlement. A transport error, a 5xx, an unreadable answer: the entitlement stays exactly as
	 * it was, so an outage never changes the verdict.
	 *
	 * @param array  $state   The option.
	 * @param string $code    The error code.
	 * @param string $message The error message.
	 * @param int    $now     Unix time.
	 * @return array The patch to merge into the option.
	 */
	public static function after_failure( array $state, $code, $message, $now ) {
		$patch = array( 'error' => (string) $message, 'error_code' => (string) $code, 'error_at' => (int) $now );
		if ( in_array( (string) $code, self::REFUSALS, true ) ) {
			$patch['entitlement'] = null;
			$patch['refused']     = true;
		}
		return $patch;
	}

	/**
	 * Has the mode changed since it was last recorded? The patch to write, the event
	 * (full | restricted | null) and whether the admin is to be emailed: once per stay in restricted
	 * mode, and again only after a return to full mode cleared the flag. No email for a site that
	 * never had a key nor a Giorgio connection (the notice says it; nobody lost anything) — but a
	 * site recorded as full that drops to restricted is always told, whatever the reason.
	 *
	 * @param array  $state   The option.
	 * @param string $key     The key in use.
	 * @param int    $now     Unix time.
	 * @param bool   $giorgio Is this site connected to Giorgio?
	 * @return array {patch: array, event: string|null, mail: bool}
	 */
	public static function transition( array $state, $key, $now, $giorgio = false ) {
		$mode = self::mode( $state, $key, $now, $giorgio );
		$prev = isset( $state['mode'] ) ? (string) $state['mode'] : '';
		if ( $mode === $prev ) {
			return array( 'patch' => array(), 'event' => null, 'mail' => false );
		}
		$patch = array( 'mode' => $mode, 'mode_since' => (int) $now );
		if ( self::FULL === $mode ) {
			$patch['restricted_mail_at'] = 0;
			return array( 'patch' => $patch, 'event' => self::FULL, 'mail' => false );
		}
		$mail = empty( $state['restricted_mail_at'] ) && ( self::FULL === $prev || 'no_key' !== self::reason( $state, $key, $now ) );
		if ( $mail ) {
			$patch['restricted_mail_at'] = (int) $now;
		}
		return array( 'patch' => $patch, 'event' => self::RESTRICTED, 'mail' => $mail );
	}

	/**
	 * Does a Giorgio settings array describe a connection? A base URL and an API key, both non-empty.
	 * The "enable sync" switch is not required: turning the sync off must not restrict a client site.
	 *
	 * @param mixed $settings The promeng_giorgio option.
	 * @return bool
	 */
	public static function giorgio_configured( $settings ) {
		return is_array( $settings )
			&& '' !== trim( isset( $settings['base_url'] ) ? (string) $settings['base_url'] : '' )
			&& '' !== trim( isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '' );
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

	/**
	 * Is this site connected to Giorgio? Read-only: the raw promeng_giorgio option (never through
	 * Giorgio\Settings::get(), which writes a secret on first read) and, failing that, whether any
	 * promotion Giorgio pushed is in the table. A Giorgio outage changes nothing here.
	 *
	 * @return bool
	 */
	public static function giorgio() {
		if ( null === self::$giorgio ) {
			self::$giorgio = self::giorgio_configured( get_option( \PromoEngine\Giorgio\Settings::OPTION, array() ) )
				|| Repository::has_giorgio();
		}
		return self::$giorgio;
	}

	/** The one switch the storefront reads: full mode, or restricted. Agency and Giorgio sites are always full. */
	public static function licensed() {
		if ( null === self::$licensed ) {
			self::$licensed = self::agency() || self::FULL === self::mode( self::state(), self::key(), time(), self::giorgio() );
		}
		return self::$licensed;
	}

	/** Forget this request's verdict (after the option changed: tests, the check-now button). */
	public static function reset() {
		self::$licensed = null;
		self::$giorgio  = null;
	}

	/** The recorded mode (full|restricted) and since when, for the tab. */
	public static function mode_record() {
		$s = self::state();
		return array(
			'mode'  => isset( $s['mode'] ) ? (string) $s['mode'] : '',
			'since' => isset( $s['mode_since'] ) ? (int) $s['mode_since'] : 0,
		);
	}

	/** The account tab in this site's admin. */
	public static function settings_url() {
		return admin_url( 'admin.php?page=promeng-settings&tab=account' );
	}

	/** The licence's state in one sentence, for the notice, the tab and the email. */
	public static function reason_text( $reason ) {
		switch ( $reason ) {
			case 'no_key':
				return __( 'No OC Pluginz licence key is connected to this site, and it is not connected to Giorgio.', 'promotion-engine' );
			case 'refused':
				return __( 'OC Pluginz did not accept the licence key of this site.', 'promotion-engine' );
			case 'suspended':
				return __( 'The OC Promotions licence is suspended.', 'promotion-engine' );
			case 'expired':
				return __( 'The OC Promotions licence has expired and its grace days are over.', 'promotion-engine' );
			case 'grace':
				return __( 'The OC Promotions licence has expired and is on its grace days.', 'promotion-engine' );
			case 'unverified':
				return __( 'OC Pluginz has not confirmed the licence key of this site yet.', 'promotion-engine' );
		}
		return __( 'The OC Promotions licence is active.', 'promotion-engine' );
	}

	/** One line for the OC Pluginz overview card. */
	public static function card_status() {
		if ( self::agency() ) {
			return __( 'Agency mode', 'promotion-engine' );
		}
		if ( self::giorgio() ) {
			return __( 'Connected to Giorgio — full mode, no key needed', 'promotion-engine' );
		}
		if ( self::licensed() ) {
			return 'grace' === self::status()
				? __( 'Grace days: renew the licence to keep promotions running', 'promotion-engine' )
				: __( 'Licence active — full mode', 'promotion-engine' );
		}
		return __( 'Restricted mode — promotions are not applied', 'promotion-engine' );
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
	 * Keep what the hub answered. Then the mode.
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
				'notices'     => Notices::from_answer( $data ),
				'latest'      => isset( $data['latest'] ) ? (string) $data['latest'] : ( isset( $s['latest'] ) ? (string) $s['latest'] : '' ),
				'checked_at'  => time(),
				'error'       => '',
				'error_code'  => '',
				'error_at'    => 0,
				'refused'     => false,
			)
		);
		self::evaluate();
		return true;
	}

	/** A failure: a refusal drops the cached entitlement, an outage does not (after_failure). Then the mode. */
	private static function note_error( \WP_Error $error ) {
		self::patch( self::after_failure( self::state(), (string) $error->get_error_code(), self::friendly( $error ), time() ) );
		self::evaluate();
	}

	/**
	 * Record a change of mode and, on the way into restricted mode, email the site admin once.
	 * Called after every answer from the hub (an accepted one or a failure), when a screen of this
	 * plugin opens, and from "check now". The verdict itself (licensed()) is computed live on every
	 * request; this only keeps the record and sends the one email.
	 */
	public static function evaluate() {
		self::reset();
		if ( self::agency() ) {
			return;
		}
		$t = self::transition( self::state(), self::key(), time(), self::giorgio() );
		if ( array() === $t['patch'] ) {
			return;
		}
		self::patch( $t['patch'] );
		self::reset();
		if ( $t['mail'] ) {
			self::mail_admin();
		}
		do_action( 'promeng_license_mode', (string) $t['event'] );
	}

	/** The one email on the way into restricted mode: what stopped, why, where to fix it. */
	public static function mail_admin() {
		$to = apply_filters( 'promeng_restricted_mail_to', get_option( 'admin_email' ) );
		if ( ! is_string( $to ) || ! is_email( $to ) ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === $site ) {
			$site = self::domain();
		}
		/* translators: %s: site name */
		$subject = sprintf( __( 'OC Promotions on %s: promotions are no longer applied', 'promotion-engine' ), $site );
		$lines   = array(
			self::reason_text( self::status() ),
			'',
			__( 'What this means: promotions are not applied in the cart and at checkout — customers see full prices, no savings lines, no coupon codes of the plugin, no promotion labels in the catalog.', 'promotion-engine' ),
			__( 'Nothing was deleted: every promotion, its statistics and the order history are kept, all admin screens are open, and the Giorgio sync keeps running. As soon as the licence is valid again everything resumes by itself.', 'promotion-engine' ),
			'',
			/* translators: %s: URL */
			sprintf( __( 'The licence tab on your site: %s', 'promotion-engine' ), self::settings_url() ),
			/* translators: %s: URL */
			sprintf( __( 'Your OC Pluginz account: %s', 'promotion-engine' ), self::ACCOUNT_URL ),
			'',
			__( 'This email is sent once, when promotions stop being applied.', 'promotion-engine' ),
		);
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Take a seat for this site. The key is kept only once the hub accepts it, so a typo never leaves
	 * the site half connected. The recorded mode travels along, so the transition is seen once.
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
		update_option( self::OPTION, array( 'key' => $key ) + array_intersect_key( $previous, array( 'mode' => 1, 'mode_since' => 1, 'restricted_mail_at' => 1 ) ), false );
		self::reset();
		$res = self::request( 'POST', '/sdk/activate' );
		$ok  = is_wp_error( $res ) ? $res : self::accept( $res );
		if ( is_wp_error( $ok ) ) {
			update_option( self::OPTION, $previous, false );
			self::reset();
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

	/**
	 * admin_init on the plugin's own screens: a site without a working WP-Cron still hears the hub's
	 * verdict within the hour of the team opening Promotions (one option read; a call to the hub at
	 * most hourly). Never on a customer's request.
	 */
	public static function maybe_refresh() {
		if ( self::agency() || ! self::on_our_screen() ) {
			return;
		}
		self::refresh_if_stale( HOUR_IN_SECONDS );
		self::evaluate();
	}

	/** Free the seat at the hub and forget the key. Without Giorgio the site is restricted from here (no key). */
	public static function disconnect() {
		if ( '' !== self::key() && ! self::agency() ) {
			self::request( 'POST', '/sdk/deactivate' );
		}
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
		self::reset();
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

	// ----- the notices -------------------------------------------------------------

	/** Is the request on one of this plugin's admin screens? */
	private static function on_our_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return Admin::PAGE === $page || 0 === strpos( $page, 'promeng-' );
	}

	/** Is the request on the account tab (whose own box says what the notices would)? */
	private static function on_account_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable
		return Settings::PAGE === $page && 'account' === $tab;
	}

	/**
	 * Restricted mode: a red notice on EVERY wp-admin screen for whoever can fix it, dismissible for
	 * a day per user. The store must know at once that its promotions are not being applied.
	 * A Giorgio site is never restricted, so it never sees this (and is never nagged for a key).
	 */
	public static function restricted_notice() {
		if ( self::agency() || ! current_user_can( 'manage_woocommerce' ) || self::licensed() || self::on_account_tab() ) {
			return;
		}
		$seen = (int) get_user_meta( get_current_user_id(), self::META_SEEN, true );
		if ( time() - $seen < self::SEEN_FOR ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS ), self::ACTION_DISMISS );
		echo '<div class="notice notice-error is-dismissible promeng-restricted" data-promeng-dismiss="' . esc_url( $dismiss ) . '"><p>'
			. '<strong>' . esc_html__( 'OC Promotions: promotions are not being applied right now.', 'promotion-engine' ) . '</strong> '
			. esc_html( self::reason_text( self::status() ) ) . ' '
			. esc_html__( 'Customers see full prices until the licence is back; the promotions, their statistics and the Giorgio sync stay as they are.', 'promotion-engine' )
			. ' <a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Fix the licence', 'promotion-engine' ) . '</a>'
			. ' · <a href="' . esc_url( self::ACCOUNT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'My OC Pluginz account', 'promotion-engine' ) . '</a>'
			. '</p></div>' . "\n";
		// The X WordPress adds only hides the notice for this page view; tell the plugin to remember it for a day.
		echo '<script>document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target.closest(".promeng-restricted .notice-dismiss"):null;if(!t)return;var n=t.closest(".promeng-restricted"),u=n?n.getAttribute("data-promeng-dismiss"):"";if(u){fetch(u,{credentials:"same-origin"}).catch(function(){});}});</script>' . "\n";
	}

	/** admin-post: the X on the restricted notice. */
	public static function dismiss_restricted() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'promotion-engine' ) );
		}
		check_admin_referer( self::ACTION_DISMISS );
		update_user_meta( get_current_user_id(), self::META_SEEN, time() );
		if ( ! headers_sent() ) {
			status_header( 204 );
		}
		exit;
	}

	/**
	 * On the plugin's own screens, in full mode: a licence that needs attention before it restricts
	 * anything (grace, unverified, expired but not yet confirmed by the hub). A site without a key
	 * (a Giorgio client) is never nagged: it was never meant to have one.
	 */
	public static function notice() {
		if ( self::agency() || '' === self::key() || ! current_user_can( 'manage_woocommerce' ) || ! self::on_our_screen() || self::on_account_tab() || ! self::licensed() ) {
			return;
		}
		$reason = self::status();
		switch ( $reason ) {
			case 'unverified':
				$text = __( 'OC Pluginz has not confirmed the OC Promotions licence key yet, so updates are off. Promotions keep working.', 'promotion-engine' );
				break;
			case 'expired':
				$text = __( 'The OC Promotions licence is past its end date. Once OC Pluginz confirms it, promotions stop being applied. Renew now.', 'promotion-engine' );
				break;
			case 'grace':
				$text = __( 'The OC Promotions licence has expired and is on its grace days. Renew now: in a week promotions stop being applied.', 'promotion-engine' );
				break;
			default:
				return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( $text )
			. ' <a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'OC Pluginz account', 'promotion-engine' ) . '</a></p></div>';
	}
}
