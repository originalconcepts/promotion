<?php
/**
 * General plugin settings (store-wide, not per-promotion).
 *
 * Currently holds the catalog label position — where the "on sale" / promotion
 * name banner sits on the product image in shop/category grids. Stored in a
 * single option array so more global settings can join it later without new
 * options.
 *
 * The screen has two tabs: "General" (the settings above) and "OC Pluginz
 * account" (the licence: connect / refresh / disconnect, status, seats,
 * updates — see includes/hub/class-hub.php). The licence tab is informational
 * on a site that has no key: promotions never depend on it.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'promeng_settings';

	const PAGE = 'promeng-settings';

	/**
	 * Allowed catalog-label positions. 'bottom' is the historical default.
	 */
	const POSITIONS = array( 'bottom', 'top', 'top-right', 'top-left' );

	/**
	 * Current settings with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return wp_parse_args(
			$saved,
			array(
				'label_position' => 'bottom',
			)
		);
	}

	/**
	 * The store-wide catalog label position, validated.
	 *
	 * @return string one of self::POSITIONS.
	 */
	public static function label_position() {
		$s   = self::get();
		$pos = isset( $s['label_position'] ) ? (string) $s['label_position'] : 'bottom';
		return in_array( $pos, self::POSITIONS, true ) ? $pos : 'bottom';
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		add_action( 'admin_post_promeng_settings_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_promeng_hub_connect', array( $this, 'handle_hub_action' ) );
		add_action( 'admin_post_promeng_hub_refresh', array( $this, 'handle_hub_action' ) );
		add_action( 'admin_post_promeng_hub_disconnect', array( $this, 'handle_hub_action' ) );
	}

	public function menu() {
		add_submenu_page(
			Admin::PAGE,
			__( 'Settings', 'promotion-engine' ),
			__( 'Settings', 'promotion-engine' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * The settings screen URL, optionally on a tab.
	 *
	 * @param string $tab general|account.
	 * @return string
	 */
	public static function url( $tab = 'general' ) {
		$args = array( 'page' => self::PAGE );
		if ( 'general' !== $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'promotion-engine' ) );
		}
		check_admin_referer( 'promeng_settings_save' );

		$position = isset( $_POST['label_position'] ) ? sanitize_key( wp_unslash( $_POST['label_position'] ) ) : 'bottom';
		if ( ! in_array( $position, self::POSITIONS, true ) ) {
			$position = 'bottom';
		}

		$settings                   = self::get();
		$settings['label_position'] = $position;
		update_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * admin-post handler for the licence tab: connect / refresh / disconnect.
	 * ("Check for updates" is HubUpdater::check_now, shared with the Plugins row link.)
	 */
	public function handle_hub_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'promotion-engine' ) );
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		check_admin_referer( $action );
		$back = self::url( 'account' );

		switch ( $action ) {
			case 'promeng_hub_connect':
				$res = Hub::connect( isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '' );
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'promeng_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				HubUpdater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'promeng_hub', 'connected', $back ) );
				exit;

			case 'promeng_hub_refresh':
				$res = Hub::refresh();
				if ( is_wp_error( $res ) ) {
					wp_safe_redirect( add_query_arg( 'promeng_err', rawurlencode( $res->get_error_message() ), $back ) );
					exit;
				}
				wp_safe_redirect( add_query_arg( 'promeng_hub', 'refreshed', $back ) );
				exit;

			case 'promeng_hub_disconnect':
				Hub::disconnect();
				HubUpdater::forget();
				delete_site_transient( 'update_plugins' );
				wp_safe_redirect( add_query_arg( 'promeng_hub', 'disconnected', $back ) );
				exit;
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Options for the label-position picker: value => [label, description].
	 *
	 * @return array
	 */
	private function position_choices() {
		return array(
			'bottom'    => array(
				'label' => __( 'Bottom (full width)', 'promotion-engine' ),
				'desc'  => __( 'A full-width banner across the bottom of the image (the current default).', 'promotion-engine' ),
			),
			'top'       => array(
				'label' => __( 'Top (full width)', 'promotion-engine' ),
				'desc'  => __( 'A full-width banner across the top of the image.', 'promotion-engine' ),
			),
			'top-right' => array(
				'label' => __( 'Top right', 'promotion-engine' ),
				'desc'  => __( 'A compact, text-sized tag in the top-right corner, with a small margin.', 'promotion-engine' ),
			),
			'top-left'  => array(
				'label' => __( 'Top left', 'promotion-engine' ),
				'desc'  => __( 'A compact, text-sized tag in the top-left corner, with a small margin.', 'promotion-engine' ),
			),
		);
	}

	/**
	 * A tiny CSS-only preview of where the label sits, per position.
	 */
	private function preview( $position ) {
		$style = 'position:absolute;background:#dd3333;color:#fff;font-size:9px;font-weight:700;line-height:1;padding:3px 5px;box-sizing:border-box;white-space:nowrap;';
		switch ( $position ) {
			case 'top':
				$style .= 'top:0;left:0;right:0;text-align:center;border-radius:0 0 4px 4px;';
				break;
			case 'top-right':
				$style .= 'top:6px;right:6px;border-radius:4px;';
				break;
			case 'top-left':
				$style .= 'top:6px;left:6px;border-radius:4px;';
				break;
			case 'bottom':
			default:
				$style .= 'bottom:0;left:0;right:0;text-align:center;border-radius:4px 4px 0 0;';
				break;
		}
		return '<span style="position:relative;display:block;width:96px;height:96px;background:#eef1f4;border:1px solid #dfe3e8;border-radius:6px;overflow:hidden;">'
			. '<span style="' . esc_attr( $style ) . '">' . esc_html__( 'Sale', 'promotion-engine' ) . '</span></span>';
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		if ( 'account' !== $tab ) {
			$tab = 'general';
		}
		$tabs = array(
			'general' => __( 'General', 'promotion-engine' ),
			'account' => __( 'OC Pluginz account', 'promotion-engine' ),
		);
		?>
		<div class="wrap promeng-wrap">
			<h1><?php echo esc_html( PROMENG_DISPLAY_NAME . ' — ' . __( 'Settings', 'promotion-engine' ) ); ?></h1>
			<hr class="wp-header-end"><?php // keep other plugins' admin notices below the title. ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'account' === $tab ) {
				$this->render_account();
			} else {
				$this->render_general();
			}
			?>
		</div>
		<?php
	}

	private function render_general() {
		$current = self::label_position();
		$choices = $this->position_choices();
		?>
			<?php if ( ! empty( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'promotion-engine' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="promeng_settings_save">
				<?php wp_nonce_field( 'promeng_settings_save' ); ?>

				<h2><?php esc_html_e( 'Catalog label position', 'promotion-engine' ); ?></h2>
				<p class="description" style="max-width:680px">
					<?php esc_html_e( 'Where the promotion label sits on the product image in shop and category grids. This is a store-wide setting and applies to every promotion label.', 'promotion-engine' ); ?>
				</p>

				<div style="display:flex;flex-wrap:wrap;gap:16px;margin:18px 0;">
					<?php foreach ( $choices as $value => $meta ) : ?>
						<label style="display:flex;gap:12px;align-items:flex-start;border:1px solid <?php echo $current === $value ? '#2271b1' : '#dfe3e8'; ?>;border-radius:10px;padding:14px;cursor:pointer;max-width:320px;background:<?php echo $current === $value ? '#f0f6fc' : '#fff'; ?>;">
							<input type="radio" name="label_position" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> style="margin-top:3px;">
							<?php echo $this->preview( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span style="flex:1;">
								<strong><?php echo esc_html( $meta['label'] ); ?></strong><br>
								<span class="description"><?php echo esc_html( $meta['desc'] ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>

				<?php submit_button( __( 'Save settings', 'promotion-engine' ) ); ?>
			</form>
		<?php
	}

	/**
	 * The "OC Pluginz account" tab: the licence that registers this site with the hub and brings
	 * updates from it. Opening the tab re-asks the hub when the last contact is older than 10 minutes.
	 */
	private function render_account() {
		Hub::refresh_if_stale();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['promeng_hub'] ) ? sanitize_key( wp_unslash( $_GET['promeng_hub'] ) ) : '';
		$error  = isset( $_GET['promeng_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['promeng_err'] ) ) ) : '';
		// phpcs:enable

		$state    = Hub::state();
		$ent      = Hub::entitlement();
		$constant = Hub::key_from_constant();
		$reason   = Hub::status();
		$life_map = array(
			'valid'    => __( 'Valid', 'promotion-engine' ),
			'expiring' => __( 'Renewing soon', 'promotion-engine' ),
			'grace'    => __( 'Grace days', 'promotion-engine' ),
			'expired'  => __( 'Expired', 'promotion-engine' ),
		);
		?>
		<div style="max-width:760px;">
			<?php if ( 'connected' === $notice ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Connected. The licence is active on this site.', 'promotion-engine' ); ?></p></div>
			<?php elseif ( 'disconnected' === $notice ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Disconnected. The licence seat for this site was released.', 'promotion-engine' ); ?></p></div>
			<?php elseif ( 'refreshed' === $notice ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Licence status refreshed.', 'promotion-engine' ); ?></p></div>
			<?php elseif ( 'updates' === $notice ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Checked for updates. If a new version is available it now appears on the Plugins screen.', 'promotion-engine' ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:680px">
				<?php esc_html_e( 'The licence controls updates and registers this site with OC Pluginz. Promotions keep working without it.', 'promotion-engine' ); ?>
			</p>

			<?php if ( Hub::agency() ) : ?>
				<div class="card" style="max-width:none;">
					<h2><?php esc_html_e( 'Agency mode', 'promotion-engine' ); ?></h2>
					<p><?php esc_html_e( 'OC_PLUGINZ_AGENCY is set in wp-config.php: this site does not use an OC Pluginz licence; updates come from GitHub.', 'promotion-engine' ); ?></p>
				</div>
			<?php elseif ( '' === Hub::key() ) : ?>
				<div class="card" style="max-width:none;">
					<h2><?php esc_html_e( 'Connect to OC Pluginz', 'promotion-engine' ); ?></h2>
					<p><strong style="color:#646970"><?php esc_html_e( 'Not connected', 'promotion-engine' ); ?></strong></p>
					<p><?php esc_html_e( 'Paste the licence key from your OC Pluginz account. It was sent by email with the purchase and is listed in your account.', 'promotion-engine' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'promeng_hub_connect' ); ?>
						<input type="hidden" name="action" value="promeng_hub_connect">
						<p>
							<label class="screen-reader-text" for="promeng-license-key"><?php esc_html_e( 'Licence key', 'promotion-engine' ); ?></label>
							<input type="text" id="promeng-license-key" name="license_key" class="regular-text code" dir="ltr" placeholder="ocpz_…" autocomplete="off" required style="direction:ltr;text-align:left;">
							<button class="button button-primary"><?php esc_html_e( 'Connect', 'promotion-engine' ); ?></button>
						</p>
					</form>
					<?php $this->account_links(); ?>
				</div>
			<?php else : ?>
				<div class="card" style="max-width:none;">
					<h2><?php esc_html_e( 'OC Pluginz licence', 'promotion-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Licence key', 'promotion-engine' ); ?></th>
							<td>
								<code dir="ltr"><?php echo esc_html( Hub::masked( Hub::key() ) ); ?></code>
								<?php if ( $constant ) : ?>
									<p class="description"><?php esc_html_e( 'Set by PROMENG_LICENSE_KEY in wp-config.php.', 'promotion-engine' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'promotion-engine' ); ?></th>
							<td>
								<?php
								if ( $ent ) {
									$life = Hub::lifecycle( $ent, time() );
									$ok   = Hub::updates_allowed();
									$txt  = isset( $life_map[ $life ] ) ? $life_map[ $life ] : $life;
									if ( 'suspended' === $reason ) {
										$txt = __( 'Suspended', 'promotion-engine' );
									}
									echo '<strong style="color:' . ( $ok ? '#15803d' : '#b32d2e' ) . '">' . esc_html( $txt ) . '</strong>';
									if ( ! empty( $ent['expires_at'] ) && false !== strtotime( (string) $ent['expires_at'] ) ) {
										/* translators: %s: date */
										echo ' <span class="description">· ' . esc_html( sprintf( __( 'until %s', 'promotion-engine' ), date_i18n( get_option( 'date_format' ), strtotime( (string) $ent['expires_at'] ) ) ) ) . '</span>';
									} else {
										echo ' <span class="description">· ' . esc_html__( 'no expiry', 'promotion-engine' ) . '</span>';
									}
								} elseif ( 'refused' === $reason ) {
									echo '<strong style="color:#b32d2e">' . esc_html__( 'Expired', 'promotion-engine' ) . '</strong>';
								} else {
									echo '<strong style="color:#b32d2e">' . esc_html__( 'Not confirmed yet', 'promotion-engine' ) . '</strong>';
								}
								if ( ! empty( $state['error'] ) ) {
									echo '<p class="description" style="color:#b32d2e">' . esc_html( (string) $state['error'] ) . '</p>';
								}
								?>
							</td>
						</tr>
						<?php if ( $ent ) : ?>
							<?php if ( ! empty( $ent['plan'] ) ) : ?>
								<tr>
									<th scope="row"><?php esc_html_e( 'Plan', 'promotion-engine' ); ?></th>
									<td><code><?php echo esc_html( (string) $ent['plan'] ); ?></code></td>
								</tr>
							<?php endif; ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Sites', 'promotion-engine' ); ?></th>
								<td>
									<?php
									$sites = ( isset( $ent['sites'] ) && is_array( $ent['sites'] ) ) ? $ent['sites'] : array();
									$seats = isset( $ent['seats'] ) ? (int) $ent['seats'] : 1;
									if ( ! $sites ) {
										echo '<span class="description">—</span>';
									}
									foreach ( $sites as $site ) {
										$d  = isset( $site['domain'] ) ? (string) $site['domain'] : '';
										$me = ( $d === Hub::domain() );
										echo '<div><code dir="ltr">' . esc_html( $d ) . '</code>' . ( $me ? ' <span class="description">(' . esc_html__( 'this site', 'promotion-engine' ) . ')</span>' : '' ) . '</div>';
									}
									/* translators: 1: sites connected, 2: seats on the licence */
									echo '<p class="description">' . esc_html( sprintf( __( '%1$d of %2$d sites in use.', 'promotion-engine' ), count( $sites ), max( 1, $seats ) ) ) . '</p>';
									?>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Updates', 'promotion-engine' ); ?></th>
							<td>
								<?php
								$latest = isset( $state['latest'] ) ? (string) $state['latest'] : '';
								/* translators: %s: version */
								echo esc_html( sprintf( __( 'Installed: %s', 'promotion-engine' ), PROMENG_VERSION ) );
								if ( '' !== $latest ) {
									/* translators: %s: version */
									echo ' · ' . esc_html( sprintf( __( 'Latest at OC Pluginz: %s', 'promotion-engine' ), $latest ) );
									if ( version_compare( $latest, PROMENG_VERSION, '>' ) ) {
										echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Update from the Plugins screen', 'promotion-engine' ) . '</a>';
									}
								}
								?>
								<p class="description"><?php esc_html_e( 'New versions are offered on the Plugins screen while the licence is valid.', 'promotion-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last check', 'promotion-engine' ); ?></th>
							<td>
								<?php
								$at = isset( $state['checked_at'] ) ? (int) $state['checked_at'] : 0;
								echo esc_html( $at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : '—' );
								?>
								<p class="description"><?php esc_html_e( 'The licence is re-checked every hour and whenever this tab opens.', 'promotion-engine' ); ?></p>
							</td>
						</tr>
					</table>
					<div style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'promeng_hub_refresh' ); ?>
							<input type="hidden" name="action" value="promeng_hub_refresh">
							<button class="button"><?php esc_html_e( 'Refresh', 'promotion-engine' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( HubUpdater::ACTION ); ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( HubUpdater::ACTION ); ?>">
							<button class="button"><?php esc_html_e( 'Check for updates', 'promotion-engine' ); ?></button>
						</form>
						<?php if ( ! $constant ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect this site from the licence? Updates from OC Pluginz stop until a key is connected again. Promotions keep working.', 'promotion-engine' ) ); ?>');">
								<?php wp_nonce_field( 'promeng_hub_disconnect' ); ?>
								<input type="hidden" name="action" value="promeng_hub_disconnect">
								<button class="button button-link-delete"><?php esc_html_e( 'Disconnect this site', 'promotion-engine' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
					<?php $this->account_links(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** The two links under the licence card: the account at the hub, and where a new licence is bought. */
	private function account_links() {
		?>
		<p class="description">
			<a href="<?php echo esc_url( Hub::ACCOUNT_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open your OC Pluginz account', 'promotion-engine' ); ?></a>
			&nbsp;·&nbsp;
			<?php esc_html_e( 'New licence?', 'promotion-engine' ); ?>
			<a href="<?php echo esc_url( Hub::PRICING_URL ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( Hub::PRICING_URL ); ?></a>
		</p>
		<?php
	}
}
