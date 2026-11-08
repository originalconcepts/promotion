<?php
/**
 * Restricted mode smoke test, run ON a WordPress site with WooCommerce and
 * the plugin active, no agency constant:
 *
 *     php tests/integration/licence-smoke.php            (plugin folder inside wp-content/plugins)
 *     PROMENG_WP_LOAD=/path/to/wp-load.php php tests/integration/licence-smoke.php
 *
 * A product at 100 and a 10% promotion on every product. Licensed (the hub's
 * answer written as accept() writes it): the cart line costs 90, the savings
 * summary names the promotion, the catalog label is there. The hub's answer
 * flipped to suspended: 100, no savings, no label, no nudges, the promotion is
 * still active in the table, the site-wide notice renders (and stays away for
 * a day once dismissed), ONE email; an unreachable hub changes nothing. Then
 * the hub option removed (no key) and fake Giorgio settings saved: 90 again
 * with no key at all; a suspended key next to Giorgio: 90; an outage: still
 * 90. Everything written is removed.
 *
 * The hub is never called for real: PROMENG_HUB_URL points at a closed port.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( "cli only\n" );
}
if ( ! defined( 'PROMENG_HUB_URL' ) ) {
	define( 'PROMENG_HUB_URL', 'http://127.0.0.1:1' );
}
$promeng_wp_load = getenv( 'PROMENG_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
if ( ! is_file( $promeng_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$promeng_wp_load}\n" );
	exit( 2 );
}
ini_set( 'display_errors', '1' );
error_reporting( E_ALL );
foreach ( array( 'HTTP_HOST' => 'localhost', 'SERVER_NAME' => 'localhost', 'REQUEST_URI' => '/', 'REQUEST_METHOD' => 'GET', 'SERVER_PORT' => '80', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php' ) as $k => $v ) {
	$_SERVER[ $k ] = $_SERVER[ $k ] ?? $v;
}
unset( $k, $v );
register_shutdown_function( function () {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		echo "\nFATAL: {$e['message']}\n  in {$e['file']}:{$e['line']}\n";
	}
} );
require $promeng_wp_load;
unset( $promeng_wp_load );

exit( promeng_licence_smoke() );

function promeng_licence_smoke() {
	global $wpdb;
	if ( ! class_exists( 'PromoEngine\\Hub' ) || ! class_exists( 'PromoEngine\\Plugin' ) ) {
		echo "  skip  OC Promotions is not active on this site\n";
		return 2;
	}
	if ( \PromoEngine\Hub::agency() ) {
		echo "  skip  OC_PLUGINZ_AGENCY is set: the licence does nothing on this site\n";
		return 0;
	}
	$fail  = 0;
	$check = function ( $ok, $what ) use ( &$fail ) {
		echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $what . "\n";
		if ( ! $ok ) {
			$fail++;
		}
	};
	$now = time();
	$key = 'ocpz_' . str_repeat( 'ab', 20 );

	// What the hub would have answered, written as accept() writes it; then the plugin's own evaluation.
	$hub = function ( $status, $end, $more = array() ) use ( $key, &$now ) {
		update_option( \PromoEngine\Hub::OPTION, array_merge( \PromoEngine\Hub::state(), array( 'key' => $key, 'entitlement' => array( 'status' => $status, 'plan' => 'yearly', 'expires_at' => $end ), 'checked_at' => $now, 'error' => '', 'error_code' => '', 'error_at' => 0, 'refused' => false ), $more ), false );
		\PromoEngine\Hub::evaluate();
	};
	// No mail leaves the CLI; the licence emails are counted.
	$mails = array();
	add_filter( 'pre_wp_mail', function ( $pre, $atts ) use ( &$mails ) {
		if ( 0 === strpos( (string) ( $atts['subject'] ?? '' ), 'OC Promotions' ) ) {
			$mails[] = $atts;
		}
		return true;
	}, 10, 2 );

	$hub_before     = get_option( \PromoEngine\Hub::OPTION, null );
	$giorgio_before = get_option( \PromoEngine\Giorgio\Settings::OPTION, null );
	$seen_before    = get_user_meta( 1, \PromoEngine\Hub::META_SEEN, true );
	$product        = null;
	$promo_id       = 0;

	$cleanup = function () use ( $wpdb, &$product, &$promo_id, $hub_before, $giorgio_before, $seen_before ) {
		if ( $product instanceof WC_Product ) {
			$product->delete( true );
		}
		if ( $promo_id ) {
			$wpdb->delete( \PromoEngine\Installer::promotions_table(), array( 'id' => $promo_id ) );
		}
		if ( null === $hub_before ) {
			delete_option( \PromoEngine\Hub::OPTION );
		} else {
			update_option( \PromoEngine\Hub::OPTION, $hub_before, false );
		}
		if ( null === $giorgio_before ) {
			delete_option( \PromoEngine\Giorgio\Settings::OPTION );
		} else {
			update_option( \PromoEngine\Giorgio\Settings::OPTION, $giorgio_before, false );
		}
		if ( '' === (string) $seen_before ) {
			delete_user_meta( 1, \PromoEngine\Hub::META_SEEN );
		} else {
			update_user_meta( 1, \PromoEngine\Hub::META_SEEN, $seen_before );
		}
		\PromoEngine\Hub::reset();
	};

	// A fresh cart with one unit of the product, totals calculated: what the line costs, the savings summary.
	$cart = function ( $pid ) {
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		\PromoEngine\Hub::reset();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $pid, 1 );
		WC()->cart->calculate_totals();
		$line = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$line = (float) $item['data']->get_price();
		}
		$fees = 0.0;
		foreach ( WC()->cart->get_fees() as $fee ) {
			$fees += (float) $fee->amount;
		}
		$out = array(
			'line'    => $line,
			'total'   => (float) WC()->cart->get_cart_contents_total() + $fees,
			'savings' => \PromoEngine\Cart::instance() ? \PromoEngine\Cart::instance()->savings_summary() : null,
			'panel'   => \PromoEngine\Cart::instance() ? \PromoEngine\Cart::instance()->panel_messages() : array(),
		);
		WC()->cart->empty_cart();
		return $out;
	};
	$label = function ( $pid ) {
		\PromoEngine\Hub::reset();
		$catalog = new \PromoEngine\Catalog();
		return (string) $catalog->product_label_filter( '', $pid );
	};
	$notice = function () {
		\PromoEngine\Hub::reset();
		ob_start();
		\PromoEngine\Hub::restricted_notice();
		return (string) ob_get_clean();
	};

	try {
		// ---- fixtures
		$product = new WC_Product_Simple();
		$product->set_name( 'Licence smoke product' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->save();
		$pid = $product->get_id();

		$promo_id = \PromoEngine\Repository::save( array(
			'name'       => 'Smoke ten percent',
			'type'       => 'discount',
			'active'     => 1,
			'customer_type' => 'all',
			'channels'   => array( 'web' ),
			'show_label' => 1,
			'config'     => array( 'applies_to' => 'all', 'discount_type' => 'percent', 'discount_value' => 10, 'product_ids' => array(), 'category_ids' => array(), 'excluded_product_ids' => array() ),
			'source'     => 'local',
		) );
		$check( $promo_id > 0 && $pid > 0, 'fixtures: product at 100, 10% promotion on every product' );

		wp_set_current_user( 1 );
		delete_user_meta( 1, \PromoEngine\Hub::META_SEEN );
		delete_option( \PromoEngine\Giorgio\Settings::OPTION );
		delete_option( \PromoEngine\Hub::OPTION );

		// ---- no key, no Giorgio: restricted from day one
		\PromoEngine\Hub::evaluate();
		$c = $cart( $pid );
		$check( ! \PromoEngine\Hub::licensed(), 'no key, no Giorgio: restricted' );
		$check( 100.0 === $c['line'] && null === $c['savings'], 'no key: the line costs 100, no savings (' . $c['line'] . ')' );
		$check( 0 === count( $mails ), 'no key from day one: no email' );
		$check( false !== strpos( $notice(), 'promeng-restricted' ), 'no key: the site-wide notice renders' );

		// ---- licensed: the promotion applies
		$hub( 'active', gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ) );
		$c = $cart( $pid );
		$check( \PromoEngine\Hub::licensed(), 'active licence: full mode' );
		$check( 90.0 === $c['line'], 'licensed: the line costs 90 (' . $c['line'] . ')' );
		$check( is_array( $c['savings'] ) && 10.0 === (float) $c['savings']['total'] && 'Smoke ten percent' === $c['savings']['items'][0]['name'], 'licensed: savings summary = 10, named after the promotion' );
		$check( 'Smoke ten percent' === $label( $pid ), 'licensed: catalog label' );
		$check( '' === $notice(), 'licensed: no notice' );
		$check( \PromoEngine\Hub::FULL === \PromoEngine\Hub::mode_record()['mode'], 'recorded full' );

		// ---- suspended: restricted at once
		$hub( 'suspended', gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ) );
		$c = $cart( $pid );
		$check( ! \PromoEngine\Hub::licensed(), 'suspended: restricted' );
		$check( 100.0 === $c['line'] && 100.0 === $c['total'], 'suspended: full price 100, no fee (' . $c['line'] . ' / ' . $c['total'] . ')' );
		$check( null === $c['savings'] && array() === $c['panel'], 'suspended: no savings box, no panel messages' );
		$check( '' === $label( $pid ), 'suspended: no catalog label' );
		$row = \PromoEngine\Repository::find( $promo_id );
		$check( $row && $row->active && 'Smoke ten percent' === $row->name, 'suspended: the promotion is still active in the table (nothing deleted or disabled)' );
		$check( 1 === count( $mails ), 'suspended: ONE email (' . count( $mails ) . ')' );
		$check( false !== strpos( (string) $mails[0]['to'], '@' ) && false !== strpos( (string) $mails[0]['message'], \PromoEngine\Hub::settings_url() ), 'the email goes to admin_email and links the licence tab' );
		$html = $notice();
		$check( false !== strpos( $html, 'promeng-restricted' ) && false !== strpos( $html, 'notice-error' ), 'suspended: the red site-wide notice renders' );
		\PromoEngine\Hub::evaluate();
		\PromoEngine\Hub::evaluate();
		$check( 1 === count( $mails ), 'evaluated again: still one email' );
		update_user_meta( 1, \PromoEngine\Hub::META_SEEN, time() );
		$check( '' === $notice(), 'dismissed: the notice stays away for a day' );
		delete_user_meta( 1, \PromoEngine\Hub::META_SEEN );

		// ---- outage: nothing changes, both ways
		$res = \PromoEngine\Hub::refresh();
		$c   = $cart( $pid );
		$check( is_wp_error( $res ) && ! \PromoEngine\Hub::licensed() && 100.0 === $c['line'], 'hub unreachable while suspended: still restricted (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'no error' ) . ')' );
		$check( 'suspended' === \PromoEngine\Hub::entitlement()['status'], 'the suspended entitlement survived the outage' );
		$hub( 'active', gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ) );
		$check( \PromoEngine\Hub::licensed() && 0 === (int) \PromoEngine\Hub::state()['restricted_mail_at'], 'active again: full, email flag cleared' );
		$res = \PromoEngine\Hub::refresh();
		$c   = $cart( $pid );
		$check( is_wp_error( $res ) && \PromoEngine\Hub::licensed() && 90.0 === $c['line'], 'hub unreachable while active: still full, 90' );

		// ---- Giorgio: no key at all, settings saved -> full
		delete_option( \PromoEngine\Hub::OPTION );
		\PromoEngine\Hub::reset();
		$c = $cart( $pid );
		$check( ! \PromoEngine\Hub::licensed() && 100.0 === $c['line'], 'key removed, no Giorgio: restricted, 100' );
		update_option( \PromoEngine\Giorgio\Settings::OPTION, array( 'enabled' => false, 'base_url' => 'https://api.giorgio.example/v1', 'api_key' => 'smoke-key', 'webhook_secret' => 'smoke' ), false );
		\PromoEngine\Hub::evaluate();
		$c = $cart( $pid );
		$check( \PromoEngine\Hub::licensed() && 90.0 === $c['line'] && is_array( $c['savings'] ), 'Giorgio settings saved, no key: full, 90, savings back (' . $c['line'] . ')' );
		$check( 'Smoke ten percent' === $label( $pid ), 'Giorgio: catalog label back' );
		$check( '' === $notice(), 'Giorgio: no notice (never nagged for a key)' );
		$check( false !== strpos( \PromoEngine\Hub::card_status(), 'Giorgio' ), 'the overview card says Giorgio' );
		$check( \PromoEngine\Hub::FULL === \PromoEngine\Hub::mode_record()['mode'], 'Giorgio: recorded full' );

		// ---- Giorgio + a suspended key: Giorgio wins; an outage changes nothing
		$hub( 'suspended', gmdate( 'Y-m-d H:i:s', $now + 200 * DAY_IN_SECONDS ) );
		$c = $cart( $pid );
		$check( \PromoEngine\Hub::licensed() && 90.0 === $c['line'], 'suspended key + Giorgio: still full, 90' );
		$res = \PromoEngine\Hub::refresh();
		$c   = $cart( $pid );
		$check( is_wp_error( $res ) && \PromoEngine\Hub::licensed() && 90.0 === $c['line'], 'hub unreachable + Giorgio: still 90' );
		$check( 1 === count( $mails ), 'no email through the Giorgio scenarios (' . count( $mails ) . ')' );

		// ---- Giorgio connection removed: restricted, and told once
		delete_option( \PromoEngine\Giorgio\Settings::OPTION );
		\PromoEngine\Hub::evaluate();
		$c = $cart( $pid );
		$check( ! \PromoEngine\Hub::licensed() && 100.0 === $c['line'], 'Giorgio settings removed, suspended key: restricted, 100' );
		$check( 2 === count( $mails ), 'losing the Giorgio connection: one more email (' . count( $mails ) . ')' );
	} catch ( \Throwable $e ) {
		$check( false, 'exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
	}

	$cleanup();
	$check( null === \PromoEngine\Repository::find( $promo_id ) && ! wc_get_product( $pid ), 'cleanup: fixtures removed' );
	echo "\n" . ( $fail ? "{$fail} FAILED" : 'all passed' ) . "\n";
	return $fail ? 1 : 0;
}
