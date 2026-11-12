<?php
defined( 'ABSPATH' ) || exit;

/**
 * Global plugin settings (stored in one option).
 */
class Spiral_Forms_Settings {

	const OPTION = 'spiral_forms_settings';

	public static function defaults() {
		return array(
			'captcha_provider'     => 'none', // none | turnstile | recaptcha.
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'recaptcha_site_key'   => '',
			'recaptcha_secret_key' => '',
			'github_token'         => '',
			'load_css'             => 1,
		);
	}

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * The GitHub token can also be defined in wp-config.php so it never lives in the DB.
	 */
	public static function github_token() {
		if ( defined( 'SPIRAL_FORMS_GITHUB_TOKEN' ) && SPIRAL_FORMS_GITHUB_TOKEN ) {
			return SPIRAL_FORMS_GITHUB_TOKEN;
		}
		return (string) self::get( 'github_token' );
	}
}
