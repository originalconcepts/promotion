<?php
/**
 * General plugin settings (store-wide, not per-promotion).
 *
 * Currently holds the catalog label position — where the "on sale" / promotion
 * name banner sits on the product image in shop/category grids. Stored in a
 * single option array so more global settings can join it later without new
 * options.
 *
 * @package PromoEngine
 */

namespace PromoEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'promeng_settings';

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
	}

	public function menu() {
		add_submenu_page(
			Admin::PAGE,
			__( 'Settings', 'promotion-engine' ),
			__( 'Settings', 'promotion-engine' ),
			'manage_woocommerce',
			'promeng-settings',
			array( $this, 'render' )
		);
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

		wp_safe_redirect( add_query_arg( array( 'page' => 'promeng-settings', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
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
		$current = self::label_position();
		$choices = $this->position_choices();
		?>
		<div class="wrap promeng-wrap">
			<h1><?php echo esc_html( PROMENG_DISPLAY_NAME . ' — ' . __( 'Settings', 'promotion-engine' ) ); ?></h1>
			<hr class="wp-header-end"><?php // keep other plugins' admin notices below the title. ?>

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
		</div>
		<?php
	}
}
