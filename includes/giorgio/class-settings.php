<?php
/**
 * Giorgio connection settings.
 *
 * Stores the connection to a Giorgio account in a single option array:
 *   - enabled        : master toggle for the sync.
 *   - base_url       : Giorgio API base, e.g. https://api.giorgio.co.il/v1
 *   - api_key        : bearer token the plugin uses to call Giorgio
 *                      (outbound: usage reports, definition pull).
 *   - webhook_secret : shared secret Giorgio sends back to the plugin
 *                      (inbound: pushing promotion definitions). Auto-generated.
 *
 * @package PromoEngine
 */

namespace PromoEngine\Giorgio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'promeng_giorgio';

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_promeng_giorgio_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Current settings with defaults. Generates a webhook secret on first read
	 * so the merchant always has one to paste into Giorgio.
	 */
	public static function get() {
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$changed = false;

		if ( empty( $saved['webhook_secret'] ) ) {
			$saved['webhook_secret'] = wp_generate_password( 40, false, false );
			$changed                 = true;
		}
		if ( $changed ) {
			update_option( self::OPTION, $saved, false );
		}

		return wp_parse_args(
			$saved,
			array(
				'enabled'        => false,
				'base_url'       => '',
				'api_key'        => '',
				'webhook_secret' => '',
			)
		);
	}

	public static function is_enabled() {
		$s = self::get();
		return ! empty( $s['enabled'] ) && ! empty( $s['base_url'] ) && ! empty( $s['api_key'] );
	}

	public function menu() {
		add_submenu_page(
			'promotion-engine',
			__( 'Giorgio Sync', 'promotion-engine' ),
			__( 'Giorgio Sync', 'promotion-engine' ),
			'manage_woocommerce',
			'promeng-giorgio',
			array( $this, 'render' )
		);
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'promotion-engine' ) );
		}
		check_admin_referer( 'promeng_giorgio_save' );

		$current = self::get();
		$secret  = isset( $_POST['regenerate_secret'] ) ? wp_generate_password( 40, false, false ) : $current['webhook_secret'];

		$data = array(
			'enabled'        => ! empty( $_POST['enabled'] ),
			'base_url'       => isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '',
			'api_key'        => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '',
			'webhook_secret' => $secret,
		);
		update_option( self::OPTION, $data, false );

		wp_safe_redirect( add_query_arg( array( 'page' => 'promeng-giorgio', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		$s            = self::get();
		$rest_url     = rest_url( 'promeng/v1/promotions' );
		$last_sync    = get_option( 'promeng_giorgio_last_sync' );
		?>
		<div class="wrap promeng-giorgio">
			<h1><?php esc_html_e( 'Giorgio Sync', 'promotion-engine' ); ?></h1>

			<?php if ( ! empty( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'promotion-engine' ); ?></p></div>
			<?php endif; ?>

			<p class="description" style="max-width:680px">
				<?php esc_html_e( 'Connect this store to a Giorgio account. Promotions defined in Giorgio are pushed here and shown on the store; redemptions on the store are reported back to Giorgio for the unified dashboard.', 'promotion-engine' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="promeng_giorgio_save">
				<?php wp_nonce_field( 'promeng_giorgio_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable sync', 'promotion-engine' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Sync promotions and report redemptions to Giorgio', 'promotion-engine' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="promeng-base-url"><?php esc_html_e( 'Giorgio API base URL', 'promotion-engine' ); ?></label></th>
						<td><input type="url" id="promeng-base-url" class="regular-text code" name="base_url" value="<?php echo esc_attr( $s['base_url'] ); ?>" placeholder="https://api.giorgio.co.il/v1"></td>
					</tr>
					<tr>
						<th scope="row"><label for="promeng-api-key"><?php esc_html_e( 'API key (plugin &rarr; Giorgio)', 'promotion-engine' ); ?></label></th>
						<td>
							<input type="password" id="promeng-api-key" class="regular-text code" name="api_key" value="<?php echo esc_attr( $s['api_key'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Sent as a Bearer token when the plugin calls Giorgio.', 'promotion-engine' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Inbound (Giorgio &rarr; plugin)', 'promotion-engine' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Give these two values to your Giorgio developer so Giorgio can push promotion definitions into this store.', 'promotion-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'promotion-engine' ); ?></th>
						<td><input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $rest_url ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook secret', 'promotion-engine' ); ?></th>
						<td>
							<input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr( $s['webhook_secret'] ); ?>">
							<p class="description">
								<?php esc_html_e( 'Giorgio must send this in the X-Promeng-Secret header.', 'promotion-engine' ); ?>
								<label style="margin-inline-start:8px"><input type="checkbox" name="regenerate_secret" value="1"> <?php esc_html_e( 'Regenerate on save', 'promotion-engine' ); ?></label>
							</p>
						</td>
					</tr>
					<?php if ( $last_sync ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last usage report', 'promotion-engine' ); ?></th>
						<td><code><?php echo esc_html( $last_sync ); ?></code></td>
					</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( __( 'Save settings', 'promotion-engine' ) ); ?>
			</form>
		</div>
		<?php
	}
}
