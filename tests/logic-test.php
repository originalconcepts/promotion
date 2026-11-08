<?php
/**
 * The licence and restricted-mode rules without a WordPress install.
 * Run: php tests/logic-test.php (CI runs it on every PHP version).
 *
 * WordPress is stubbed just enough: options live in an array, user meta in
 * another, $wpdb answers from fixtures, HTTP answers come from
 * $GLOBALS['t_http'], emails land in $GLOBALS['t_mail'].
 *
 * Covered: the mode matrix (every rule the user decided), the Giorgio
 * exemption (configured settings, pushed promotions, a suspended key next to
 * Giorgio), the outage rule both ways, the expiry-with-checked_at rule, the
 * transition and its single email, check-now, and the three gates: the engine
 * applies nothing, the plugin's coupon codes do not exist, the catalog shows
 * no label — all three back at once when the site is licensed again.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PROMENG_VERSION', '0.0.0-test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['t_options']  = array();
$GLOBALS['t_usermeta'] = array();
$GLOBALS['t_http']     = null;
$GLOBALS['t_mail']     = array();
$GLOBALS['t_user']     = 0;
$GLOBALS['t_admin']    = false;
$GLOBALS['t_get']      = array();

/* ---------------------------------------------------------------- WP stubs */
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $t, $d = '' ) { return $t; }
function esc_html_e( $t, $d = '' ) { echo $t; }
function esc_attr__( $t, $d = '' ) { return $t; }
function esc_attr_e( $t, $d = '' ) { echo $t; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return $t; }
function esc_url_raw( $t ) { return $t; }
function esc_js( $t ) { return $t; }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function do_action() {}
function did_action( $tag ) { return 1; }
function apply_filters( $tag, $value ) { return $value; }
function is_admin() { return $GLOBALS['t_admin']; }
function is_user_logged_in() { return $GLOBALS['t_user'] > 0; }
function get_current_user_id() { return $GLOBALS['t_user']; }
function current_user_can( $cap ) { return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['t_options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['t_options'][ $k ] ); return true; }
function get_user_meta( $uid, $key, $single = false ) { return isset( $GLOBALS['t_usermeta'][ $uid ][ $key ] ) ? $GLOBALS['t_usermeta'][ $uid ][ $key ] : ''; }
function update_user_meta( $uid, $key, $v ) { $GLOBALS['t_usermeta'][ $uid ][ $key ] = $v; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( (array) $defaults, (array) $args ); }
function home_url( $p = '' ) { return 'https://shop.example.com' . $p; }
function admin_url( $p = '' ) { return 'https://shop.example.com/wp-admin/' . $p; }
function get_bloginfo( $k ) { return 'name' === $k ? 'Example Shop' : '6.9'; }
function wp_specialchars_decode( $t, $q = 0 ) { return $t; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
function add_query_arg( $args, $url = '' ) { return $url; }
function wp_nonce_url( $url, $a = -1 ) { return $url; }
function sanitize_key( $t ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function is_email( $t ) { return false !== filter_var( $t, FILTER_VALIDATE_EMAIL ); }
function wp_unslash( $v ) { return $v; }
function wp_clear_scheduled_hook( $h ) {}
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( $t, $r, $h ) {}
function wp_mail( $to, $subject, $body ) { $GLOBALS['t_mail'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body ); return true; }
function wp_remote_get( $url, $args = array() ) { return $GLOBALS['t_http']; }
function wp_remote_post( $url, $args = array() ) { return $GLOBALS['t_http']; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : $ts ); }
function wc_get_price_decimals() { return 2; }
function wc_price( $v ) { return number_format( (float) $v, 2 ) . ' ₪'; }
function wc_get_product_term_ids( $id, $tax ) { return array(); }
function get_ancestors( $id, $tax ) { return array(); }
function current_time( $t ) { return 'timestamp' === $t ? time() : gmdate( 'Y-m-d H:i:s' ); }

class WP_Error {
	private $code; private $message; private $data; private $codes = array();
	public function __construct( $c = '', $m = '', $d = array() ) { if ( '' !== $c ) { $this->add( $c, $m, $d ); } }
	public function add( $c, $m, $d = array() ) { if ( ! $this->codes ) { $this->code = $c; $this->message = $m; $this->data = $d; } $this->codes[] = $c; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/** Enough of $wpdb: the promotions table from a fixture. */
class T_Wpdb {
	public $prefix = 'wp_';
	public $rows   = array(); // promotion rows
	public function prepare( $q, ...$args ) { return vsprintf( str_replace( array( '%d', '%s', '%f' ), array( '%d', "'%s'", '%F' ), $q ), $args ); }
	public function get_results( $q, $o = ARRAY_A ) {
		if ( false !== strpos( $q, 'WHERE active = 1' ) ) {
			return array_values( array_filter( $this->rows, function ( $r ) { return 1 === (int) $r['active']; } ) );
		}
		return array_values( $this->rows );
	}
	public function get_var( $q ) {
		if ( false !== strpos( $q, "source = 'giorgio'" ) ) {
			foreach ( $this->rows as $r ) { if ( 'giorgio' === $r['source'] ) { return $r['id']; } }
			return null;
		}
		return 0;
	}
	public function get_row( $q, $o = ARRAY_A ) { return null; }
}
define( 'ARRAY_A', 'ARRAY_A' );
$wpdb = new T_Wpdb();

$root = dirname( __DIR__ );
require $root . '/includes/hub/class-notices.php';
require $root . '/includes/hub/class-hub.php';
require $root . '/includes/class-installer.php';
require $root . '/includes/class-promotion.php';
require $root . '/includes/class-repository.php';
require $root . '/includes/class-app.php';
require $root . '/includes/class-usage.php';
require $root . '/includes/types/interface-type.php';
require $root . '/includes/types/class-type-discount.php';
require $root . '/includes/types/class-type-buy-x-pay-y.php';
require $root . '/includes/types/class-type-buy-x-get-y.php';
require $root . '/includes/class-engine.php';
require $root . '/includes/class-catalog.php';
require $root . '/includes/class-coupon.php';
require $root . '/includes/class-settings.php';
require $root . '/includes/giorgio/class-settings.php';
require $root . '/includes/admin/class-admin.php';

use PromoEngine\Hub;
use PromoEngine\Engine;
use PromoEngine\Catalog;
use PromoEngine\Coupon;

/* ------------------------------------------------------------------ harness */
$pass = 0; $fail = 0;
function ok( $cond, $what ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
}
function eq( $exp, $got, $what ) { ok( $exp === $got, $what . ( $exp === $got ? '' : ' (expected ' . var_export( $exp, true ) . ', got ' . var_export( $got, true ) . ')' ) ); }

$now = 1_800_000_000;
$key = 'ocpz_' . str_repeat( 'ab', 20 );
$day = DAY_IN_SECONDS;
$ent = function ( $status, $end ) { return array( 'status' => $status, 'plan' => 'yearly', 'expires_at' => $end ); };
$st  = function ( $entitlement, $more = array() ) use ( $key, $now ) { return array_merge( array( 'key' => $key, 'entitlement' => $entitlement, 'checked_at' => $now, 'refused' => false ), $more ); };
$d   = function ( $offset ) use ( $now ) { return gmdate( 'Y-m-d H:i:s', $now + $offset ); };

echo "mode(): the decision matrix\n";
eq( Hub::RESTRICTED, Hub::mode( array(), '', $now ), 'no key, no Giorgio -> restricted from day one' );
eq( Hub::FULL, Hub::mode( array(), '', $now, true ), 'no key, Giorgio connected -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', $d( 200 * $day ) ) ), $key, $now ), 'active -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', '' ) ), $key, $now ), 'lifetime (no end date) -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', $d( 5 * $day ) ) ), $key, $now ), 'expiring in 5 days -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', $d( -3 * $day ) ) ), $key, $now ), 'grace (3 days past) -> full' );
eq( Hub::RESTRICTED, Hub::mode( $st( $ent( 'suspended', $d( 200 * $day ) ) ), $key, $now ), 'suspended -> restricted at once' );
eq( Hub::RESTRICTED, Hub::mode( $st( $ent( 'deleted', $d( 200 * $day ) ) ), $key, $now ), 'deleted -> restricted at once' );
eq( Hub::RESTRICTED, Hub::mode( $st( null, array( 'refused' => true ) ), $key, $now ), 'refused key -> restricted at once' );
eq( Hub::FULL, Hub::mode( array( 'key' => $key ), $key, $now ), 'unverified key (hub never answered) -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'suspended', $d( 200 * $day ) ) ), $key, $now, true ), 'suspended key + Giorgio -> full (Giorgio wins)' );
eq( Hub::FULL, Hub::mode( $st( null, array( 'refused' => true ) ), $key, $now, true ), 'refused key + Giorgio -> full' );
eq( Hub::RESTRICTED, Hub::mode( $st( $ent( 'active', $d( -10 * $day ) ), array( 'checked_at' => $now ) ), $key, $now ), 'expired 10 days, hub confirmed after the grace days -> restricted' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', $d( -10 * $day ) ), array( 'checked_at' => $now - 20 * $day ) ), $key, $now ), 'expired 10 days, last hub contact before the end (outage) -> full' );
eq( Hub::FULL, Hub::mode( $st( $ent( 'active', $d( -10 * $day ) ), array( 'checked_at' => $now - 5 * $day ) ), $key, $now ), 'expired 10 days, hub last answered inside the grace days (outage since) -> full' );
eq( Hub::RESTRICTED, Hub::mode( $st( $ent( 'active', $d( -10 * $day ) ), array( 'checked_at' => $now - 3 * $day ) ), $key, $now ), 'expired 10 days, hub answered 7 days after the end -> restricted' );
eq( Hub::RESTRICTED, Hub::mode( $st( array( 'status' => 'active', 'state' => 'expired', 'expires_at' => 'not-a-date' ) ), $key, $now ), 'hub says expired (state) with an unreadable date -> its word stands' );
eq( 'no_key', Hub::reason( array(), '', $now ), 'reason: no key' );
eq( 'grace', Hub::reason( $st( $ent( 'active', $d( -3 * $day ) ) ), $key, $now ), 'reason: grace' );
eq( 'suspended', Hub::reason( $st( $ent( 'cancelled', $d( 200 * $day ) ) ), $key, $now ), 'reason: cancelled counts as suspended' );
ok( ! Hub::decide( $st( $ent( 'active', $d( -10 * $day ) ), array( 'checked_at' => $now - 20 * $day ) ), $key, $now ), 'updates_allowed() is unchanged: an aged copy brings no updates even while the site stays full' );

echo "giorgio_configured(): the exemption\n";
ok( Hub::giorgio_configured( array( 'base_url' => 'https://api.giorgio.co.il/v1', 'api_key' => 'k', 'enabled' => false ) ), 'base_url + api_key, sync switched off -> connected' );
ok( ! Hub::giorgio_configured( array( 'base_url' => 'https://api.giorgio.co.il/v1', 'api_key' => '', 'enabled' => true ) ), 'no api_key -> not connected' );
ok( ! Hub::giorgio_configured( array( 'base_url' => ' ', 'api_key' => 'k' ) ), 'blank base_url -> not connected' );
ok( ! Hub::giorgio_configured( array( 'webhook_secret' => 'x' ) ), 'only the generated secret (fresh install) -> not connected' );
ok( ! Hub::giorgio_configured( false ), 'no option -> not connected' );

echo "after_failure(): an outage keeps the verdict, a refusal drops it\n";
$s1 = $st( $ent( 'active', $d( 200 * $day ) ) );
$p  = Hub::after_failure( $s1, 'http_request_failed', 'cURL error 28', $now );
eq( Hub::FULL, Hub::mode( array_merge( $s1, $p ), $key, $now ), 'transport error on an active licence -> still full' );
$s2 = $st( $ent( 'suspended', $d( 200 * $day ) ) );
$p  = Hub::after_failure( $s2, 'promeng_hub_http', 'status 502', $now );
eq( Hub::RESTRICTED, Hub::mode( array_merge( $s2, $p ), $key, $now ), 'HTTP 502 on a suspended licence -> still restricted (outage keeps the last verdict both ways)' );
ok( is_array( array_merge( $s2, $p )['entitlement'] ), 'the entitlement survives the outage' );
$p = Hub::after_failure( $s1, 'ocpzs_bad_key', 'bad key', $now );
ok( null === $p['entitlement'] && true === $p['refused'], 'a refusal drops the entitlement' );
eq( Hub::RESTRICTED, Hub::mode( array_merge( $s1, $p ), $key, $now ), 'refused -> restricted' );
$p = Hub::after_failure( $s1, 'rest_forbidden', 'rate limited', $now );
eq( Hub::FULL, Hub::mode( array_merge( $s1, $p ), $key, $now ), 'rest_forbidden is not a refusal -> still full' );

echo "transition(): the record and the one email\n";
$t = Hub::transition( array(), '', $now );
eq( Hub::RESTRICTED, $t['event'], 'fresh keyless install: recorded restricted' );
ok( false === $t['mail'], 'fresh keyless install: no email (nobody lost anything)' );
$t = Hub::transition( array(), '', $now, true );
eq( Hub::FULL, $t['event'], 'fresh Giorgio install: recorded full' );
$full = array_merge( $st( $ent( 'active', $d( 200 * $day ) ) ), array( 'mode' => Hub::FULL, 'mode_since' => $now - $day ) );
$t    = Hub::transition( $full, $key, $now );
ok( array() === $t['patch'] && null === $t['event'], 'active and recorded full: nothing to do' );
$susp = array_merge( $full, array( 'entitlement' => $ent( 'suspended', $d( 200 * $day ) ) ) );
$t    = Hub::transition( $susp, $key, $now );
ok( Hub::RESTRICTED === $t['event'] && true === $t['mail'] && $now === $t['patch']['restricted_mail_at'], 'full -> suspended: restricted + email, flag stored' );
$susp2 = array_merge( $susp, $t['patch'] );
$t     = Hub::transition( $susp2, $key, $now + HOUR_IN_SECONDS );
ok( array() === $t['patch'], 'an hour later, still suspended: no second email' );
$back = array_merge( $susp2, array( 'entitlement' => $ent( 'active', $d( 200 * $day ) ) ) );
$t    = Hub::transition( $back, $key, $now + 2 * $day );
ok( Hub::FULL === $t['event'] && 0 === $t['patch']['restricted_mail_at'] && false === $t['mail'], 'back to full: flag cleared, no email' );
$again = array_merge( $back, $t['patch'], array( 'entitlement' => $ent( 'suspended', $d( 200 * $day ) ) ) );
$t     = Hub::transition( $again, $key, $now + 3 * $day );
ok( true === $t['mail'], 'suspended a second time, after a stay in full: email again' );
$t = Hub::transition( array_merge( $st( $ent( 'suspended', $d( 200 * $day ) ) ) ), $key, $now );
ok( true === $t['mail'], 'first verdict ever is suspended (update of a site with a closed key): email' );
$gio = array( 'mode' => Hub::FULL, 'mode_since' => $now - 30 * $day );
$t   = Hub::transition( $gio, '', $now, false );
ok( Hub::RESTRICTED === $t['event'] && true === $t['mail'], 'a Giorgio site that lost its connection (recorded full, no key): restricted + email' );
$t = Hub::transition( $gio, '', $now, true );
ok( array() === $t['patch'], 'a Giorgio site still connected: nothing to do' );

/* -------------------------------------------------- the site, through the stubs */
$reset = function () use ( $wpdb ) {
	$GLOBALS['t_options'] = array();
	$GLOBALS['t_mail']    = array();
	$GLOBALS['t_options']['admin_email'] = 'owner@example.com';
	$wpdb->rows           = array();
	Hub::reset();
};
$promo_row = function ( $id, $source = 'local', $extra = array() ) {
	return array_merge( array(
		'id' => $id, 'name' => 'Ten percent', 'type' => 'discount', 'active' => 1, 'channels' => 'web', 'customer_type' => 'all',
		'coupon_code' => null, 'requires_coupon' => 0, 'show_label' => 1,
		'config' => json_encode( array( 'applies_to' => 'all', 'discount_type' => 'percent', 'discount_value' => 10, 'product_ids' => array(), 'category_ids' => array(), 'excluded_product_ids' => array() ) ),
		'limit_per_order' => null, 'limit_per_customer' => null, 'starts_at' => null, 'ends_at' => null, 'weekdays' => '', 'priority' => 10,
		'source' => $source, 'external_id' => 'giorgio' === $source ? 'george-' . $id : null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
	), $extra );
};
$lines   = array( array( 'key' => 'k1', 'product_id' => 7, 'parent_id' => 0, 'category_ids' => array(), 'price' => 100.0, 'qty' => 2, 'line_total' => 200.0, 'is_gift' => false ) );
$context = array( 'subtotal' => 200.0, 'display_subtotal' => 200.0, 'customer_id' => 0, 'customer_email' => '', 'is_logged_in' => false, 'applied_coupons' => array(), 'channel' => 'web' );
$engine  = new Engine();
$catalog = new Catalog();
$coupon  = new Coupon();

echo "licensed(): the three gates\n";
$reset();
$wpdb->rows = array( $promo_row( 1 ), $promo_row( 2, 'local', array( 'name' => 'Code', 'coupon_code' => 'SAVE5', 'requires_coupon' => 1, 'config' => json_encode( array( 'applies_to' => 'all', 'discount_type' => 'percent', 'discount_value' => 5 ) ) ) ) );
ok( ! Hub::licensed(), 'no key, no Giorgio: restricted' );
$r = $engine->evaluate( $lines, $context );
ok( array() === $r['line_discounts'] && array() === $r['applied'] && array() === $r['cart_fees'], 'restricted: the engine applies nothing' );
ok( array() === $engine->messages( $lines, $context ) && array() === $engine->messages_detailed( $lines, $context ) && array() === $engine->auto_gifts( $lines, $context ), 'restricted: no nudges, no gifts' );
eq( false, $coupon->provide( false, 'SAVE5' ), 'restricted: the plugin\'s coupon code does not exist' );
eq( '', $catalog->product_label_filter( '', 7 ), 'restricted: no catalog label' );

$GLOBALS['t_options'][ PromoEngine\Giorgio\Settings::OPTION ] = array( 'enabled' => false, 'base_url' => 'https://api.giorgio.co.il/v1', 'api_key' => 'secret', 'webhook_secret' => 'w' );
Hub::reset();
ok( Hub::licensed(), 'Giorgio settings saved (base_url + api_key), no key: licensed' );
$r = $engine->evaluate( $lines, $context );
ok( isset( $r['line_discounts']['k1'] ) && 10.0 === (float) $r['line_discounts']['k1'] && isset( $r['applied'][1] ), 'Giorgio site: 10% applied (10 per unit)' );
ok( is_array( $coupon->provide( false, 'SAVE5' ) ) && 'percent' === $coupon->provide( false, 'SAVE5' )['discount_type'], 'Giorgio site: the coupon code exists' );
$catalog = new Catalog();
eq( 'Ten percent', $catalog->product_label_filter( '', 7 ), 'Giorgio site: the catalog label is back' );

$reset();
$wpdb->rows = array( $promo_row( 3, 'giorgio' ) );
ok( Hub::licensed(), 'no settings, but a promotion Giorgio pushed is in the table: licensed' );
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = $st( $ent( 'suspended', $d( 200 * $day ) ) );
$GLOBALS['t_options'][ PromoEngine\Giorgio\Settings::OPTION ] = array( 'base_url' => 'https://api.giorgio.co.il/v1', 'api_key' => 'secret' );
ok( Hub::licensed(), 'suspended hub key + Giorgio settings: licensed' );
eq( 'Connected to Giorgio — full mode, no key needed', Hub::card_status(), 'card says Giorgio' );
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = $st( $ent( 'suspended', $d( 200 * $day ) ) );
ok( ! Hub::licensed(), 'suspended hub key, no Giorgio: restricted' );
eq( 'Restricted mode — promotions are not applied', Hub::card_status(), 'card says restricted' );

echo "evaluate(): the record and the single email through the option\n";
$reset();
Hub::evaluate();
eq( Hub::RESTRICTED, Hub::mode_record()['mode'], 'fresh keyless install: recorded restricted' );
eq( 0, count( $GLOBALS['t_mail'] ), 'fresh keyless install: no email' );
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = array_merge( $st( $ent( 'active', $d( 200 * $day ) ) ), array( 'mode' => Hub::FULL ) );
$GLOBALS['t_options']['admin_email'] = 'owner@example.com';
Hub::evaluate();
eq( 0, count( $GLOBALS['t_mail'] ), 'active, recorded full: no email' );
$GLOBALS['t_options'][ Hub::OPTION ]['entitlement'] = $ent( 'suspended', $d( 200 * $day ) );
Hub::evaluate();
Hub::evaluate();
Hub::evaluate();
eq( 1, count( $GLOBALS['t_mail'] ), 'suspended, evaluated three times: exactly one email' );
ok( 'owner@example.com' === $GLOBALS['t_mail'][0]['to'] && false !== strpos( $GLOBALS['t_mail'][0]['subject'], 'Example Shop' ), 'the email goes to admin_email and names the site' );
ok( false !== strpos( $GLOBALS['t_mail'][0]['body'], Hub::settings_url() ), 'the email links the licence tab' );
eq( Hub::RESTRICTED, Hub::mode_record()['mode'], 'recorded restricted' );
ok( ! Hub::licensed(), 'licensed() false' );

echo "refresh(): the hub's answers and silences\n";
$answer = function ( $status, $end ) { return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'ok' => true, 'entitlement' => array( 'status' => $status, 'plan' => 'yearly', 'expires_at' => $end ), 'latest' => '0.12.0' ) ) ); };
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = array_merge( $st( $ent( 'active', $d( 200 * $day ) ) ), array( 'mode' => Hub::FULL ) );
$GLOBALS['t_http'] = new WP_Error( 'http_request_failed', 'cURL error 28' );
$res = Hub::refresh();
ok( is_wp_error( $res ) && Hub::licensed() && is_array( Hub::entitlement() ), 'outage on an active licence: still full, entitlement kept' );
eq( Hub::FULL, Hub::mode_record()['mode'], 'outage: record unchanged' );
$GLOBALS['t_http'] = $answer( 'suspended', $d( 200 * $day ) );
Hub::refresh();
ok( ! Hub::licensed() && 1 === count( $GLOBALS['t_mail'] ), 'the hub answers suspended: restricted + one email' );
$GLOBALS['t_http'] = array( 'response' => array( 'code' => 502 ), 'body' => '<html>bad gateway</html>' );
Hub::refresh();
ok( ! Hub::licensed() && 'suspended' === Hub::entitlement()['status'] && 1 === count( $GLOBALS['t_mail'] ), 'outage on a suspended licence: still restricted, no second email' );
$GLOBALS['t_http'] = $answer( 'active', $d( 200 * $day ) );
Hub::refresh();
ok( Hub::licensed() && 0 === Hub::state()['restricted_mail_at'], 'the hub answers active again: full, email flag cleared' );
$GLOBALS['t_http'] = array( 'response' => array( 'code' => 403 ), 'body' => json_encode( array( 'ok' => false, 'code' => 'ocpzs_inactive', 'message' => 'inactive' ) ) );
Hub::refresh();
ok( ! Hub::licensed() && null === Hub::entitlement() && 2 === count( $GLOBALS['t_mail'] ), 'the hub refuses the key: restricted, entitlement dropped, email (second stay)' );
// The expiry rule through a real answer: the hub answers "active" with an end date 10 days back (the hub itself
// ages it); checked_at is now, past expires_at + 7d -> restricted. An answer from inside the grace days keeps full.
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = array_merge( $st( $ent( 'active', $d( 200 * $day ) ) ), array( 'mode' => Hub::FULL ) );
$GLOBALS['t_http'] = $answer( 'active', gmdate( 'Y-m-d H:i:s', time() - 3 * $day ) );
Hub::refresh();
ok( Hub::licensed() && 'grace' === Hub::status(), 'the hub answers an end date 3 days back: grace, still full' );
$GLOBALS['t_http'] = $answer( 'active', gmdate( 'Y-m-d H:i:s', time() - 10 * $day ) );
Hub::refresh();
ok( ! Hub::licensed() && 'expired' === Hub::status(), 'the hub answers an end date 10 days back (checked now, past the grace days): restricted' );
$GLOBALS['t_http'] = new WP_Error( 'http_request_failed', 'down' );
Hub::refresh();
ok( ! Hub::licensed(), 'and an outage after that keeps it restricted' );
// The same expiry, but the hub went silent before the end: the copy ages past the grace days, the site stays full.
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = array_merge( $st( $ent( 'active', gmdate( 'Y-m-d H:i:s', time() - 10 * $day ) ), array( 'checked_at' => time() - 20 * $day, 'mode' => Hub::FULL ) ) );
$GLOBALS['t_http'] = new WP_Error( 'http_request_failed', 'down' );
Hub::refresh();
ok( Hub::licensed() && 'expired' === Hub::status() && 0 === count( $GLOBALS['t_mail'] ), 'expired on the aged copy, hub unreachable since before the end: still full, no email' );
ok( ! Hub::updates_allowed(), '...but updates are off on the aged copy (unchanged rule)' );

echo "connect(): a typo never leaves the site half connected, the record travels along\n";
$reset();
$GLOBALS['t_options'][ Hub::OPTION ] = array( 'mode' => Hub::RESTRICTED, 'mode_since' => $now );
$GLOBALS['t_http'] = array( 'response' => array( 'code' => 403 ), 'body' => json_encode( array( 'ok' => false, 'code' => 'ocpzs_bad_key', 'message' => 'no' ) ) );
$res = Hub::connect( $key );
ok( is_wp_error( $res ) && '' === Hub::key() && Hub::RESTRICTED === Hub::mode_record()['mode'], 'a refused connect leaves no key and the previous record' );
$GLOBALS['t_http'] = $answer( 'active', $d( 200 * $day ) );
$res = Hub::connect( $key );
ok( true === $res && Hub::licensed() && Hub::FULL === Hub::mode_record()['mode'], 'an accepted connect: full, recorded' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
