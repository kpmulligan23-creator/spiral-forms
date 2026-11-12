<?php
defined( 'ABSPATH' ) || exit;

/**
 * Self-hosted updates from GitHub Releases on a private repo.
 *
 * Each site stores a read-only fine-grained GitHub token (Contents: read) in
 * Settings → Spiral Forms, or defines SPIRAL_FORMS_GITHUB_TOKEN in wp-config.php.
 * Releases must have a `spiral-forms.zip` asset whose top folder is `spiral-forms/`
 * (the included GitHub Action builds this automatically when you push a tag like v0.2.0).
 */
class Spiral_Forms_Updater {

	const CACHE_KEY  = 'spiral_forms_release';
	const ASSET_NAME = 'spiral-forms.zip';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_flush_on_force_check' ) );
	}

	private static function basename() {
		return plugin_basename( SPIRAL_FORMS_FILE );
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	public static function maybe_flush_on_force_check() {
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::flush();
		}
	}

	/**
	 * Latest release info, cached for 6 hours.
	 *
	 * @return array|null { version, package, notes, published, url }
	 */
	public static function latest_release( $force = false ) {
		$token = Spiral_Forms_Settings::github_token();
		if ( ! $token ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . SPIRAL_FORMS_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => self::headers( $token ),
			)
		);

		$info = array( 'version' => '' );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$asset_url = '';
			foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
				if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) ) {
					$asset_url = $asset['url']; // API URL; needs auth + octet-stream Accept.
					break;
				}
			}
			if ( $asset_url && ! empty( $body['tag_name'] ) ) {
				$info = array(
					'version'   => ltrim( $body['tag_name'], 'vV' ),
					'package'   => $asset_url,
					'notes'     => (string) ( $body['body'] ?? '' ),
					'published' => (string) ( $body['published_at'] ?? '' ),
					'url'       => (string) ( $body['html_url'] ?? '' ),
				);
			}
		}

		// Cache failures briefly so a bad token doesn't hammer the API on every admin load.
		set_site_transient( self::CACHE_KEY, $info, $info['version'] ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
		return $info['version'] ? $info : null;
	}

	private static function headers( $token, $accept = 'application/vnd.github+json' ) {
		return array(
			'Authorization'        => 'Bearer ' . $token,
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'spiral-forms-updater',
		);
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => SPIRAL_FORMS_GITHUB_REPO,
			'slug'        => 'spiral-forms',
			'plugin'      => self::basename(),
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
		);
		if ( version_compare( $release['version'], SPIRAL_FORMS_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'spiral-forms' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest_release();
		return (object) array(
			'name'          => 'Spiral Forms',
			'slug'          => 'spiral-forms',
			'version'       => $release ? $release['version'] : SPIRAL_FORMS_VERSION,
			'author'        => 'Kevin Mulligan',
			'last_updated'  => $release ? $release['published'] : '',
			'sections'      => array(
				'description' => 'Lightweight forms builder.',
				'changelog'   => $release ? wpautop( esc_html( $release['notes'] ) ) : '',
			),
			'download_link' => $release ? $release['package'] : '',
		);
	}

	/**
	 * Download private release assets ourselves: GitHub needs the token on the API
	 * request, then redirects to a pre-signed URL that must be fetched WITHOUT it.
	 */
	public static function download( $reply, $package, $upgrader ) {
		$prefix = 'https://api.github.com/repos/' . SPIRAL_FORMS_GITHUB_REPO . '/releases/assets/';
		if ( false !== $reply || 0 !== strpos( (string) $package, $prefix ) ) {
			return $reply;
		}
		$token = Spiral_Forms_Settings::github_token();
		if ( ! $token ) {
			return new WP_Error( 'spiral_forms_no_token', __( 'Spiral Forms: add a GitHub token in Settings to download updates.', 'spiral-forms' ) );
		}

		$res = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::headers( $token, 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$location = wp_remote_retrieve_header( $res, 'location' );
		if ( ! $location ) {
			return new WP_Error( 'spiral_forms_download', __( 'Spiral Forms: could not get the release download link from GitHub. Check the token.', 'spiral-forms' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		return download_url( $location, 300 );
	}
}
