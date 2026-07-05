<?php
/**
 * Promotion model (value object).
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Promotion {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $name = '';
	/** @var string discount | buy_x_pay_y | buy_x_get_y */
	public $type = 'discount';
	/** @var bool */
	public $active = true;
	/** @var string[] */
	public $channels = array( 'web' );
	/** @var string|null */
	public $coupon_code = null;
	/** @var bool */
	public $requires_coupon = false;
	/** @var bool */
	public $show_label = false;
	/** @var array Type-specific config (decoded JSON). */
	public $config = array();
	/** @var int|null */
	public $limit_per_order = null;
	/** @var int|null */
	public $limit_per_customer = null;
	/** @var string|null Y-m-d H:i:s */
	public $starts_at = null;
	/** @var string|null Y-m-d H:i:s */
	public $ends_at = null;
	/** @var int[] Weekdays 0=Sunday..6=Saturday */
	public $weekdays = array();
	/** @var int */
	public $priority = 10;
	/** @var string local | giorgio */
	public $source = 'local';
	/** @var string|null */
	public $external_id = null;
	/** @var string|null Y-m-d H:i:s */
	public $created_at = null;
	/** @var string|null Y-m-d H:i:s */
	public $updated_at = null;

	/**
	 * Build from a DB row (associative array).
	 */
	public static function from_row( array $row ) {
		$p                    = new self();
		$p->id                = (int) $row['id'];
		$p->name              = (string) $row['name'];
		$p->type              = (string) $row['type'];
		$p->active            = (bool) $row['active'];
		$p->channels          = array_filter( explode( ',', (string) $row['channels'] ) );
		$p->coupon_code       = $row['coupon_code'] !== null ? (string) $row['coupon_code'] : null;
		$p->requires_coupon   = (bool) $row['requires_coupon'];
		$p->show_label        = (bool) $row['show_label'];
		$p->config            = $row['config'] ? (array) json_decode( $row['config'], true ) : array();
		$p->limit_per_order   = $row['limit_per_order'] !== null ? (int) $row['limit_per_order'] : null;
		$p->limit_per_customer = $row['limit_per_customer'] !== null ? (int) $row['limit_per_customer'] : null;
		$p->starts_at         = $row['starts_at'] && $row['starts_at'] !== '0000-00-00 00:00:00' ? $row['starts_at'] : null;
		$p->ends_at           = $row['ends_at'] && $row['ends_at'] !== '0000-00-00 00:00:00' ? $row['ends_at'] : null;
		$p->weekdays          = array_filter( array_map( 'intval', explode( ',', (string) $row['weekdays'] ) ), 'is_numeric' );
		$p->priority          = (int) $row['priority'];
		$p->source            = (string) $row['source'];
		$p->external_id       = $row['external_id'] !== null ? (string) $row['external_id'] : null;
		$p->created_at        = ( ! empty( $row['created_at'] ) && 0 !== strpos( (string) $row['created_at'], '0000' ) ) ? (string) $row['created_at'] : null;
		$p->updated_at        = ( ! empty( $row['updated_at'] ) && 0 !== strpos( (string) $row['updated_at'], '0000' ) ) ? (string) $row['updated_at'] : null;
		return $p;
	}

	/**
	 * Get a config value with a default.
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
	}

	/**
	 * Whether the promotion is currently within its scheduling window.
	 * Checks active flag, date range, and weekday restriction.
	 *
	 * @param int|null $now Unix timestamp (defaults to WP current_time).
	 */
	public function is_live( $now = null ) {
		if ( ! $this->active ) {
			return false;
		}
		$now = $now ? $now : current_time( 'timestamp' );

		if ( $this->starts_at && strtotime( $this->starts_at ) > $now ) {
			return false;
		}
		if ( $this->ends_at && strtotime( $this->ends_at ) < $now ) {
			return false;
		}
		if ( ! empty( $this->weekdays ) ) {
			$today = (int) wp_date( 'w', $now ); // 0 (Sun) .. 6 (Sat)
			if ( ! in_array( $today, $this->weekdays, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is this promotion applied automatically (no coupon code needed)?
	 * v0.1 only auto-applies automatic promotions. Coupon-gated promotions
	 * are stored and respected but their checkout entry is a later phase.
	 */
	public function is_automatic() {
		return ! $this->requires_coupon;
	}
}
