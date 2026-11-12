<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings → spam protection and updater token.
 */
class Spiral_Forms_Settings_Page {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_spiral_forms_check_update', array( __CLASS__, 'check_update' ) );
	}

	public static function register() {
		register_setting(
			'spiral_forms',
			Spiral_Forms_Settings::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$old   = Spiral_Forms_Settings::all();
		$input = (array) $input;
		$out   = array(
			'captcha_provider'     => in_array( $input['captcha_provider'] ?? '', array( 'none', 'turnstile', 'recaptcha' ), true ) ? $input['captcha_provider'] : 'none',
			'turnstile_site_key'   => sanitize_text_field( $input['turnstile_site_key'] ?? '' ),
			'turnstile_secret_key' => sanitize_text_field( $input['turnstile_secret_key'] ?? '' ),
			'recaptcha_site_key'   => sanitize_text_field( $input['recaptcha_site_key'] ?? '' ),
			'recaptcha_secret_key' => sanitize_text_field( $input['recaptcha_secret_key'] ?? '' ),
			'github_token'         => sanitize_text_field( $input['github_token'] ?? '' ),
			'load_css'             => empty( $input['load_css'] ) ? 0 : 1,
		);
		// Masked secrets: keep the stored value unless a new one was typed.
		foreach ( array( 'turnstile_secret_key', 'recaptcha_secret_key', 'github_token' ) as $k ) {
			if ( '' === $out[ $k ] || self::mask( $old[ $k ] ) === $out[ $k ] ) {
				$out[ $k ] = ! empty( $input[ $k . '_clear' ] ) ? '' : $old[ $k ];
			}
		}
		if ( $out['github_token'] !== $old['github_token'] ) {
			Spiral_Forms_Updater::flush();
		}
		return $out;
	}

	private static function mask( $secret ) {
		$secret = (string) $secret;
		return '' === $secret ? '' : str_repeat( '•', 8 ) . substr( $secret, -4 );
	}

	public static function check_update() {
		check_admin_referer( 'sf_check_update' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		Spiral_Forms_Updater::flush();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms-settings&sf_checked=1' ) );
		exit;
	}

	private static function secret_field( $key, $value ) {
		$name = Spiral_Forms_Settings::OPTION . '[' . $key . ']';
		echo '<input type="text" class="regular-text code" autocomplete="off" name="' . esc_attr( $name ) . '" value="' . esc_attr( self::mask( $value ) ) . '">';
		if ( $value ) {
			echo ' <label><input type="checkbox" name="' . esc_attr( Spiral_Forms_Settings::OPTION . '[' . $key . '_clear]' ) . '" value="1"> ' . esc_html__( 'Clear', 'spiral-forms' ) . '</label>';
		}
	}

	public static function page() {
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			return;
		}
		$s   = Spiral_Forms_Settings::all();
		$opt = Spiral_Forms_Settings::OPTION;
		?>
		<div class="wrap sf-admin">
			<h1><?php esc_html_e( 'Spiral Forms Settings', 'spiral-forms' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'spiral_forms' ); ?>

				<h2><?php esc_html_e( 'Spam protection', 'spiral-forms' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A hidden honeypot field and a minimum fill-time check are always on. Add a CAPTCHA for stronger protection.', 'spiral-forms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'CAPTCHA', 'spiral-forms' ); ?></th>
						<td>
							<?php
							$providers = array(
								'none'      => __( 'None', 'spiral-forms' ),
								'turnstile' => __( 'Cloudflare Turnstile (recommended)', 'spiral-forms' ),
								'recaptcha' => __( 'Google reCAPTCHA v2 (checkbox)', 'spiral-forms' ),
							);
							foreach ( $providers as $k => $label ) {
								echo '<label><input type="radio" name="' . esc_attr( $opt ) . '[captcha_provider]" value="' . esc_attr( $k ) . '" ' . checked( $s['captcha_provider'], $k, false ) . '> ' . esc_html( $label ) . '</label><br>';
							}
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Turnstile site key', 'spiral-forms' ); ?></th>
						<td><input type="text" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Turnstile secret key', 'spiral-forms' ); ?></th>
						<td><?php self::secret_field( 'turnstile_secret_key', $s['turnstile_secret_key'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'reCAPTCHA site key', 'spiral-forms' ); ?></th>
						<td><input type="text" class="regular-text code" name="<?php echo esc_attr( $opt ); ?>[recaptcha_site_key]" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>"></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'reCAPTCHA secret key', 'spiral-forms' ); ?></th>
						<td><?php self::secret_field( 'recaptcha_secret_key', $s['recaptcha_secret_key'] ); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Appearance', 'spiral-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Styles', 'spiral-forms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[load_css]" value="1" <?php checked( $s['load_css'] ); ?>> <?php esc_html_e( 'Load the default form styles', 'spiral-forms' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Updates', 'spiral-forms' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Updates come from the private GitHub repository. Paste a fine-grained GitHub token with read-only "Contents" access to that repository. You can instead define SPIRAL_FORMS_GITHUB_TOKEN in wp-config.php.', 'spiral-forms' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'GitHub token', 'spiral-forms' ); ?></th>
						<td>
							<?php if ( defined( 'SPIRAL_FORMS_GITHUB_TOKEN' ) && SPIRAL_FORMS_GITHUB_TOKEN ) : ?>
								<em><?php esc_html_e( 'Set in wp-config.php.', 'spiral-forms' ); ?></em>
							<?php else : ?>
								<?php self::secret_field( 'github_token', $s['github_token'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'spiral-forms' ); ?></th>
						<td>
							<?php
							/* translators: %s: version */
							echo esc_html( sprintf( __( 'Installed: %s', 'spiral-forms' ), SPIRAL_FORMS_VERSION ) );
							if ( Spiral_Forms_Settings::github_token() ) {
								$rel = Spiral_Forms_Updater::latest_release();
								echo ' &middot; ';
								if ( $rel ) {
									/* translators: %s: version */
									echo esc_html( sprintf( __( 'Latest release: %s', 'spiral-forms' ), $rel['version'] ) );
								} else {
									echo '<span style="color:#b32d2e">' . esc_html__( 'Could not read releases — check the token and that a release with spiral-forms.zip exists.', 'spiral-forms' ) . '</span>';
								}
								echo ' &middot; <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_check_update' ), 'sf_check_update' ) ) . '">' . esc_html__( 'Check now', 'spiral-forms' ) . '</a>';
							}
							?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
