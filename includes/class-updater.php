<?php
/**
 * GitHub-based self-updater.
 *
 * Checks the plugin header on the repo's main branch and feeds any newer
 * version into the standard WordPress update pipeline, so pushing a commit
 * with a bumped `Version:` header rolls out to every installed site.
 * Auto-updates are forced on for this plugin (see `auto_update_plugin`).
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves plugin updates straight from the public GitHub repository.
 */
class Updater {

	const GITHUB_OWNER = 'originalconcepts';
	const GITHUB_REPO  = 'promotion';
	const BRANCH       = 'main';

	/**
	 * Transient key for the cached remote version.
	 */
	const CACHE_KEY = 'promeng_remote_version';

	/**
	 * How long to cache the remote version lookup (seconds).
	 */
	const CACHE_TTL = 3 * HOUR_IN_SECONDS;

	/**
	 * Singleton instance.
	 *
	 * @var Updater|null
	 */
	private static $instance = null;

	/**
	 * Boot the updater hooks.
	 *
	 * @return Updater
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( $this, 'force_auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
	}

	/**
	 * Fetch the `Version:` header from the main branch on GitHub (cached).
	 *
	 * @return string Remote version, or '' on failure.
	 */
	public function get_remote_version() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s/promotion-engine.php',
			self::GITHUB_OWNER,
			self::GITHUB_REPO,
			self::BRANCH
		);

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		$version  = '';

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			if ( preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', wp_remote_retrieve_body( $response ), $m ) ) {
				$version = $m[1];
			}
		}

		// Cache failures briefly too, so a GitHub hiccup can't hammer every page load.
		set_site_transient( self::CACHE_KEY, $version, $version ? self::CACHE_TTL : 10 * MINUTE_IN_SECONDS );

		return $version;
	}

	/**
	 * Zipball of the main branch, used as the update package.
	 *
	 * @return string
	 */
	private function package_url() {
		return sprintf(
			'https://github.com/%s/%s/archive/refs/heads/%s.zip',
			self::GITHUB_OWNER,
			self::GITHUB_REPO,
			self::BRANCH
		);
	}

	/**
	 * Add our update (or no_update entry) to the update_plugins transient.
	 *
	 * @param object $transient The update_plugins transient value.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote_version();
		if ( ! $remote ) {
			return $transient;
		}

		$item = (object) array(
			'id'          => 'github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'slug'        => 'promotion-engine',
			'plugin'      => PROMENG_BASENAME,
			'new_version' => $remote,
			'url'         => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'package'     => $this->package_url(),
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => '',
			'requires'    => '6.0',
		);

		if ( version_compare( $remote, PROMENG_VERSION, '>' ) ) {
			$transient->response[ PROMENG_BASENAME ] = $item;
			unset( $transient->no_update[ PROMENG_BASENAME ] );
		} else {
			// Listing it under no_update keeps the auto-update UI toggle visible.
			$transient->no_update[ PROMENG_BASENAME ] = $item;
			unset( $transient->response[ PROMENG_BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Minimal "View details" popup so the update screen has something to show.
	 *
	 * @param false|object|array $result Result object or false.
	 * @param string             $action The API action requested.
	 * @param object             $args   Request arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'promotion-engine' !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_version();

		return (object) array(
			'name'          => PROMENG_DISPLAY_NAME,
			'slug'          => 'promotion-engine',
			'version'       => $remote ? $remote : PROMENG_VERSION,
			'author'        => 'Original Concepts',
			'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'download_link' => $this->package_url(),
			'sections'      => array(
				'description' => 'Self-contained promotions engine for WooCommerce. Updates are delivered from GitHub (' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO . ', branch ' . self::BRANCH . ').',
			),
		);
	}

	/**
	 * GitHub zipballs extract to "promotion-main"; rename to the real slug so
	 * WordPress overwrites the existing plugin folder instead of adding a copy.
	 *
	 * @param string       $source        Extracted source path.
	 * @param string       $remote_source Remote source path.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Extra hook args.
	 * @return string|\WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) || PROMENG_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		global $wp_filesystem;

		$desired = trailingslashit( $remote_source ) . 'promotion-engine/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return $desired;
		}

		return $source;
	}

	/**
	 * Keep this plugin on automatic updates everywhere it is installed.
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   Update offer item.
	 * @return bool|null
	 */
	public function force_auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && PROMENG_BASENAME === $item->plugin ) {
			return true;
		}
		return $update;
	}

	/**
	 * Drop the cached remote version after this plugin gets updated.
	 *
	 * @param \WP_Upgrader $upgrader   Upgrader instance.
	 * @param array        $hook_extra Extra hook args.
	 */
	public function flush_cache( $upgrader, $hook_extra ) {
		if ( isset( $hook_extra['type'], $hook_extra['plugins'] ) && 'plugin' === $hook_extra['type'] && in_array( PROMENG_BASENAME, (array) $hook_extra['plugins'], true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}
}
