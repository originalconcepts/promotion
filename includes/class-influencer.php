<?php
/**
 * Bridge to the OC Influencer Dashboard plugin.
 *
 * When that plugin is active, a promotion's coupon code can be tied to an
 * influencer (assigned user + commission % + payout method). Instead of
 * relying on a real WooCommerce coupon post (our coupons are virtual), we
 * publish the mapping over a filter that the influencer plugin consumes:
 *
 *   add_filter( 'oc_influencer_coupon_map', ... )
 *
 * The map is keyed by the lower-cased coupon code and each entry is:
 *   array( 'user_id' => int, 'pct' => float, 'payout' => string, 'source' => 'promotion-engine' )
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Influencer {

	const CFG_USER   = 'influencer_user_id';
	const CFG_PCT    = 'influencer_commission_pct';
	const CFG_PAYOUT = 'influencer_payout';

	/**
	 * Is the influencer plugin available?
	 */
	public static function is_active() {
		return class_exists( '\OC_Influencer_Dashboard' );
	}

	/**
	 * Influencer assignment stored on a promotion, or null when none.
	 *
	 * @param Promotion $promotion
	 * @return array{user_id:int,pct:float,payout:string}|null
	 */
	public static function assignment( Promotion $promotion ) {
		$user_id = (int) $promotion->get( self::CFG_USER, 0 );
		if ( $user_id <= 0 ) {
			return null;
		}
		return array(
			'user_id' => $user_id,
			'pct'     => (float) $promotion->get( self::CFG_PCT, 0 ),
			'payout'  => (string) $promotion->get( self::CFG_PAYOUT, '' ),
		);
	}

	/**
	 * Register hooks (only meaningful when the influencer plugin is active).
	 */
	public function hooks() {
		add_filter( 'oc_influencer_coupon_map', array( $this, 'coupon_map' ) );
		add_filter( 'oc_influencer_resolve_code', array( $this, 'resolve_code' ), 10, 2 );
	}

	/**
	 * Direct resolver: given a coupon code with no real coupon post, return the
	 * influencer assignment from a matching promotion (or pass through).
	 *
	 * @param array|null $result
	 * @param string     $code
	 * @return array|null
	 */
	public function resolve_code( $result, $code ) {
		if ( $result ) {
			return $result;
		}
		$code = strtolower( trim( (string) $code ) );
		if ( '' === $code ) {
			return $result;
		}
		foreach ( Repository::all() as $promotion ) {
			if ( ! $promotion->requires_coupon || strtolower( trim( (string) $promotion->coupon_code ) ) !== $code ) {
				continue;
			}
			$assignment = self::assignment( $promotion );
			if ( $assignment ) {
				return $assignment;
			}
		}
		return $result;
	}

	/**
	 * Merge promotion-engine coupon codes into the influencer plugin's map.
	 *
	 * @param array $map code(lowercase) => array(user_id,pct,payout,...)
	 * @return array
	 */
	public function coupon_map( $map ) {
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		foreach ( Repository::all() as $promotion ) {
			if ( ! $promotion->requires_coupon || ! $promotion->coupon_code ) {
				continue;
			}
			$assignment = self::assignment( $promotion );
			if ( ! $assignment ) {
				continue;
			}
			$code = strtolower( trim( (string) $promotion->coupon_code ) );
			if ( '' === $code ) {
				continue;
			}
			// A real coupon with the same code wins (the manager set it there explicitly).
			if ( isset( $map[ $code ] ) ) {
				continue;
			}
			$map[ $code ] = array(
				'user_id'        => (int) $assignment['user_id'],
				'pct'            => (float) $assignment['pct'],
				'payout'         => (string) $assignment['payout'],
				'source'         => 'promotion-engine',
				'promotion_id'   => (int) $promotion->id,
				'promotion_name' => (string) $promotion->name,
			);
		}
		return $map;
	}
}
