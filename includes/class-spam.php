<?php
defined( 'ABSPATH' ) || exit;

/**
 * Spam protection: honeypot, minimum fill time, Cloudflare Turnstile, Google reCAPTCHA v2.
 */
class Spiral_Forms_Spam {

	const HONEYPOT = 'sf_website_url';
	const MIN_SECONDS = 2;

	public static function provider() {
		$s = Spiral_Forms_Settings::all();
		$p = $s['captcha_provider'];
		if ( 'turnstile' === $p && $s['turnstile_site_key'] && $s['turnstile_secret_key'] ) {
			return 'turnstile';
		}
		if ( 'recaptcha' === $p && $s['recaptcha_site_key'] && $s['recaptcha_secret_key'] ) {
			return 'recaptcha';
		}
		return 'none';
	}

	/**
	 * Markup injected into every form.
	 */
	public static function render_fields( $form_id ) {
		$html  = '<div class="sf-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'spiral-forms' )
			. '<input type="text" name="' . esc_attr( self::HONEYPOT ) . '" value="" tabindex="-1" autocomplete="off"></label></div>';
		$html .= '<input type="hidden" name="sf_ts" value="' . esc_attr( self::sign_time( time() ) ) . '">';

		$s = Spiral_Forms_Settings::all();
		switch ( self::provider() ) {
			case 'turnstile':
				wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				$html .= '<div class="sf-captcha cf-turnstile" data-sitekey="' . esc_attr( $s['turnstile_site_key'] ) . '"></div>';
				break;
			case 'recaptcha':
				if ( 'v3' === $s['recaptcha_type'] ) {
					wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $s['recaptcha_site_key'] ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
					$html .= '<input type="hidden" name="g-recaptcha-response" class="sf-recaptcha-v3" data-sitekey="' . esc_attr( $s['recaptcha_site_key'] ) . '" value="">';
				} else {
					wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
					$html .= '<div class="sf-captcha g-recaptcha" data-sitekey="' . esc_attr( $s['recaptcha_site_key'] ) . '"></div>';
				}
				break;
		}
		return $html;
	}

	/**
	 * Timestamp signed with the site salt so bots can't forge it. Avoids nonces,
	 * which break on cached pages after 12–24h.
	 */
	private static function sign_time( $ts ) {
		return $ts . '.' . substr( hash_hmac( 'sha256', (string) $ts, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * @return true|string True when OK, otherwise an error message to show.
	 */
	public static function check( array $post ) {
		if ( ! empty( $post[ self::HONEYPOT ] ) ) {
			return 'spam';
		}

		$ts_raw = isset( $post['sf_ts'] ) ? (string) $post['sf_ts'] : '';
		$parts  = explode( '.', $ts_raw );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign_time( (int) $parts[0] ), $ts_raw ) ) {
			return 'spam';
		}
		if ( time() - (int) $parts[0] < self::MIN_SECONDS ) {
			return 'spam';
		}

		$s = Spiral_Forms_Settings::all();
		switch ( self::provider() ) {
			case 'turnstile':
				$res = self::verify_remote(
					'https://challenges.cloudflare.com/turnstile/v0/siteverify',
					$s['turnstile_secret_key'],
					$post['cf-turnstile-response'] ?? ''
				);
				$ok  = ! empty( $res['success'] );
				break;
			case 'recaptcha':
				$res = self::verify_remote(
					'https://www.google.com/recaptcha/api/siteverify',
					$s['recaptcha_secret_key'],
					$post['g-recaptcha-response'] ?? ''
				);
				$ok  = ! empty( $res['success'] );
				// v3: a valid token with a low score is a probable bot. Keep it as spam
				// (reviewable) rather than showing a real person an error they can't fix.
				if ( $ok && 'v3' === $s['recaptcha_type'] && isset( $res['score'] ) && (float) $res['score'] < (float) $s['recaptcha_threshold'] ) {
					return 'spam';
				}
				break;
			default:
				$ok = true;
		}
		return $ok ? true : __( 'Please complete the anti-spam check and try again.', 'spiral-forms' );
	}

	private static function verify_remote( $url, $secret, $token ) {
		if ( '' === (string) $token ) {
			return array();
		}
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => sanitize_text_field( $token ),
					'remoteip' => Spiral_Forms_Submission::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) ? $body : array();
	}
}
