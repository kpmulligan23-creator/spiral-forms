<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database setup and upgrades.
 */
class Spiral_Forms_Install {

	const DB_VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'spiral_forms_entries';
	}

	public static function activate() {
		self::create_tables();
		update_option( 'spiral_forms_db_version', self::DB_VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'spiral_forms_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				fields longtext NOT NULL,
				is_read tinyint(1) NOT NULL DEFAULT 0,
				ip varchar(100) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				page_url varchar(2048) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}
}
