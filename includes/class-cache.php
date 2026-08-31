<?php
/**
 * Page-cache invalidation on promotion changes.
 *
 * The hosting's nginx page cache knows nothing about the promotions table:
 * creating or toggling a promotion used to leave anonymous shoppers on stale
 * pages — no labels, old catalog prices — until the cache TTL ran out
 * (hours). Admins bypass the page cache, so the merchant saw the promotion
 * live while shoppers did not.
 *
 * Whenever a promotion changes, this class purges the storefront pages a
 * promotion can affect. The built-in transport speaks HTTP PURGE straight to
 * this very server (bypassing any CDN in front, which would reject the
 * method), matching the "purge from local only" contract hosting platforms
 * expose. A host where PURGE is not honoured answers 405 and nothing is
 * worse than before — and a platform integration can take over entirely via
 * the promeng_handle_purge filter.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cache {

	/** @var bool A purge is already queued for this request. */
	private static $queued = false;

	public function hooks() {
		add_action( 'promeng_promotion_changed', array( __CLASS__, 'queue_purge' ) );
	}

	/**
	 * Queue one purge for the end of the request, however many promotion
	 * writes the request performs (a Giorgio sync can push several).
	 */
	public static function queue_purge() {
		if ( self::$queued ) {
			return;
		}
		self::$queued = true;
		add_action( 'shutdown', array( __CLASS__, 'purge_storefront' ) );
	}

	/**
	 * The pages a promotion can change: the front page, the shop page, and
	 * every product-category archive (labels and catalog prices live on
	 * their cards). Individual product pages are unbounded and are left to
	 * the cache TTL.
	 *
	 * @return string[] Absolute URLs.
	 */
	public static function storefront_urls() {
		$urls = array( home_url( '/' ) );

		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop = (int) wc_get_page_id( 'shop' );
			if ( $shop > 0 ) {
				$link = get_permalink( $shop );
				if ( $link ) {
					$urls[] = (string) $link;
				}
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$link = get_term_link( (int) $term_id, 'product_cat' );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = (string) $link;
				}
			}
		}

		return array_values( array_unique( apply_filters( 'promeng_purge_urls', $urls ) ) );
	}

	/**
	 * Purge the storefront. Runs on shutdown, after the admin redirect has
	 * already been sent, so however many URLs there are the merchant never
	 * waits on them.
	 */
	public static function purge_storefront() {
		$urls = self::storefront_urls();

		// A host/platform integration can claim the whole job.
		if ( apply_filters( 'promeng_handle_purge', false, $urls ) ) {
			return;
		}

		foreach ( $urls as $url ) {
			self::purge_url( $url );
		}
	}

	/**
	 * PURGE one URL against this very server. The connection goes to our own
	 * address while SNI and Host keep the site's name, so the request never
	 * crosses a CDN that would reject the method. Fails silently by design.
	 *
	 * @param string $url Absolute storefront URL.
	 */
	private static function purge_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$addr = isset( $_SERVER['SERVER_ADDR'] ) ? (string) $_SERVER['SERVER_ADDR'] : '';

		if ( ! $host || '' === $addr || ! function_exists( 'curl_init' ) ) {
			return;
		}

		$ch = curl_init( $url );
		if ( false === $ch ) {
			return;
		}

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => 'PURGE',
				CURLOPT_RESOLVE        => array( $host . ':443:' . $addr, $host . ':80:' . $addr ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_NOBODY         => true,
				CURLOPT_TIMEOUT        => 3,
				CURLOPT_CONNECTTIMEOUT => 2,
				CURLOPT_SSL_VERIFYPEER => false,
			)
		);
		curl_exec( $ch );
		curl_close( $ch );
	}
}
