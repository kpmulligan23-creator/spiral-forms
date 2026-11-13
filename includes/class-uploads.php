<?php
defined( 'ABSPATH' ) || exit;

/**
 * File upload field support.
 *
 * Files are private: stored under wp-content/uploads/spiral-forms/ in random
 * folders, with direct web access denied, and downloaded only through an
 * admin-only endpoint. (Client documents should never sit at a public URL.)
 *
 * Stored entry value for a file field: list of records
 *   [ { name, file (path relative to the storage dir), size, mime } ]
 * or, for entries imported from WPForms, [ { name, url } ].
 */
class Spiral_Forms_Uploads {

	const DIR = 'spiral-forms';

	/** Never accepted, whatever a form allows. */
	const BLOCKED = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'msi', 'dll', 'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'htaccess', 'htpasswd', 'ini', 'asp', 'aspx', 'jsp', 'jar', 'vbs', 'ps1' );

	const DEFAULT_EXTENSIONS = 'jpg,jpeg,png,gif,webp,heic,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,rtf,csv,zip';

	public static function init() {
		add_action( 'admin_post_spiral_forms_file', array( __CLASS__, 'download' ) );
	}

	/* ---------- Field settings ---------- */

	public static function extensions( array $f ) {
		$list = '' !== trim( (string) ( $f['extensions'] ?? '' ) ) ? $f['extensions'] : self::DEFAULT_EXTENSIONS;
		$out  = array();
		foreach ( preg_split( '/[\s,;|]+/', strtolower( (string) $list ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( $ext && ! in_array( $ext, self::BLOCKED, true ) ) {
				$out[] = $ext;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Max size per file in bytes (field setting, capped by the server's limit). */
	public static function max_bytes( array $f ) {
		$server = (int) wp_max_upload_size();
		$mb     = (float) ( $f['max_size'] ?? 0 );
		$bytes  = $mb > 0 ? (int) ( $mb * MB_IN_BYTES ) : $server;
		return $server > 0 ? min( $bytes, $server ) : $bytes;
	}

	public static function max_files( array $f ) {
		$n = (int) ( $f['max_files'] ?? 1 );
		return max( 1, min( 20, $n ) );
	}

	/* ---------- Incoming files ---------- */

	/**
	 * Pending uploads for one field from $_FILES['sf_files'].
	 *
	 * @return array[] [ { name, tmp_name, size, error } ] (UPLOAD_ERR_NO_FILE entries removed)
	 */
	public static function pending( $files, $field_id ) {
		$out = array();
		if ( ! is_array( $files ) || ! isset( $files['name'][ $field_id ] ) ) {
			return $out;
		}
		$names = (array) $files['name'][ $field_id ];
		foreach ( $names as $i => $name ) {
			$error = (int) ( ( (array) $files['error'][ $field_id ] )[ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $error || '' === (string) $name ) {
				continue;
			}
			$out[] = array(
				'name'     => sanitize_file_name( wp_basename( (string) $name ) ),
				'tmp_name' => (string) ( ( (array) $files['tmp_name'][ $field_id ] )[ $i ] ?? '' ),
				'size'     => (int) ( ( (array) $files['size'][ $field_id ] )[ $i ] ?? 0 ),
				'error'    => $error,
			);
		}
		return $out;
	}

	/** @return string Error message, or '' when all pending files are acceptable. */
	public static function validate( array $f, array $pending ) {
		if ( ! $pending ) {
			return ! empty( $f['required'] ) ? __( 'Please choose a file to upload.', 'spiral-forms' ) : '';
		}
		$max = self::max_files( $f );
		if ( count( $pending ) > $max ) {
			/* translators: %d: max number of files */
			return sprintf( _n( 'Please upload no more than %d file.', 'Please upload no more than %d files.', $max, 'spiral-forms' ), $max );
		}
		$allowed = self::extensions( $f );
		$limit   = self::max_bytes( $f );
		foreach ( $pending as $p ) {
			if ( in_array( $p['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || $p['size'] > $limit ) {
				/* translators: 1: file name, 2: size limit */
				return sprintf( __( '"%1$s" is too large. The limit is %2$s per file.', 'spiral-forms' ), $p['name'], size_format( $limit ) );
			}
			if ( UPLOAD_ERR_OK !== $p['error'] || ! is_uploaded_file( $p['tmp_name'] ) ) {
				/* translators: %s: file name */
				return sprintf( __( '"%s" could not be uploaded. Please try again.', 'spiral-forms' ), $p['name'] );
			}
			$ext = strtolower( pathinfo( $p['name'], PATHINFO_EXTENSION ) );
			if ( ! $ext || ! in_array( $ext, $allowed, true ) ) {
				/* translators: 1: file name, 2: allowed extensions */
				return sprintf( __( '"%1$s" is not an allowed file type. Allowed: %2$s.', 'spiral-forms' ), $p['name'], implode( ', ', $allowed ) );
			}
			// If WordPress knows this extension, make sure the content really matches it.
			$known = wp_check_filetype( $p['name'] );
			if ( $known['ext'] ) {
				$real = wp_check_filetype_and_ext( $p['tmp_name'], $p['name'] );
				if ( ! $real['ext'] ) {
					/* translators: %s: file name */
					return sprintf( __( '"%s" does not look like the file type its name says.', 'spiral-forms' ), $p['name'] );
				}
			}
		}
		return '';
	}

	/* ---------- Storage ---------- */

	public static function base_dir() {
		$up = wp_upload_dir( null, false );
		return trailingslashit( $up['basedir'] ) . self::DIR;
	}

	private static function protect_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// Apache/LiteSpeed. (Nginx ignores this; folder names are random and unlisted.)
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\nOptions -Indexes\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Move validated pending uploads into private storage.
	 *
	 * @return array[] File records.
	 */
	public static function store( array $pending ) {
		$base = self::base_dir();
		self::protect_dir( $base );
		$rel  = gmdate( 'Y/m' ) . '/' . strtolower( wp_generate_password( 20, false ) );
		$dir  = $base . '/' . $rel;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$records = array();
		foreach ( $pending as $p ) {
			$name = wp_unique_filename( $dir, $p['name'] );
			if ( @move_uploaded_file( $p['tmp_name'], $dir . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$type      = wp_check_filetype( $name );
				$records[] = array(
					'name' => $p['name'],
					'file' => $rel . '/' . $name,
					'size' => (int) $p['size'],
					'mime' => (string) ( $type['type'] ? $type['type'] : 'application/octet-stream' ),
				);
			}
		}
		return $records;
	}

	/** Absolute path for a stored record, or '' if missing / outside storage. */
	public static function path( array $record ) {
		if ( empty( $record['file'] ) ) {
			return '';
		}
		$base = realpath( self::base_dir() );
		$path = realpath( self::base_dir() . '/' . $record['file'] );
		return ( $base && $path && 0 === strpos( $path, $base . DIRECTORY_SEPARATOR ) && is_file( $path ) ) ? $path : '';
	}

	public static function is_file_value( $value ) {
		return is_array( $value ) && $value && is_array( reset( $value ) ) && isset( reset( $value )['name'] );
	}

	/** Delete the stored files of an entry (hydrated row). */
	public static function delete_entry_files( array $entry ) {
		foreach ( (array) $entry['fields'] as $f ) {
			if ( ! self::is_file_value( $f['value'] ?? null ) ) {
				continue;
			}
			foreach ( $f['value'] as $rec ) {
				$path = self::path( (array) $rec );
				if ( $path ) {
					wp_delete_file( $path );
					$dir = dirname( $path );
					if ( is_file( $dir . '/index.php' ) && 1 === count( (array) glob( $dir . '/*' ) ) ) {
						wp_delete_file( $dir . '/index.php' );
						@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}
	}

	/* ---------- Download ---------- */

	/** Admin-only download link (works from notification emails once logged in). */
	public static function download_url( $entry_id, $field_id, $index, array $record = array() ) {
		if ( ! empty( $record['url'] ) ) {
			return $record['url']; // Imported from WPForms: original location.
		}
		return add_query_arg(
			array(
				'action' => 'spiral_forms_file',
				'entry'  => (int) $entry_id,
				'field'  => $field_id,
				'i'      => (int) $index,
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function download() {
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			auth_redirect();
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability-gated read; link must work from emails.
		$entry = Spiral_Forms_Entries::get( absint( $_GET['entry'] ?? 0 ) );
		$field = sanitize_key( wp_unslash( $_GET['field'] ?? '' ) );
		$i     = absint( $_GET['i'] ?? 0 );
		// phpcs:enable
		$record = null;
		foreach ( (array) ( $entry['fields'] ?? array() ) as $f ) {
			if ( ( $f['id'] ?? '' ) === $field && self::is_file_value( $f['value'] ?? null ) ) {
				$record = $f['value'][ $i ] ?? null;
			}
		}
		$path = $record ? self::path( (array) $record ) : '';
		if ( ! $path ) {
			wp_die( esc_html__( 'File not found.', 'spiral-forms' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $record['name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $record['name'] ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
