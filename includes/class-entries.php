<?php
defined( 'ABSPATH' ) || exit;

/**
 * Entry storage.
 *
 * Each entry's `fields` column is JSON: [ { id, label, type, value }, ... ]
 * so entries stay readable even after a form's fields change.
 */
class Spiral_Forms_Entries {

	public static function insert( $form_id, array $fields, array $meta = array() ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Spiral_Forms_Install::table(),
			array(
				'form_id'    => (int) $form_id,
				'fields'     => wp_json_encode( array_values( $fields ) ),
				'is_read'    => 0,
				'status'     => (string) ( $meta['status'] ?? '' ),
				'ip'         => substr( (string) ( $meta['ip'] ?? '' ), 0, 100 ),
				'user_agent' => substr( (string) ( $meta['user_agent'] ?? '' ), 0, 255 ),
				'page_url'   => substr( (string) ( $meta['page_url'] ?? '' ), 0, 2048 ),
				'user_id'    => (int) ( $meta['user_id'] ?? 0 ),
				'created_at' => empty( $meta['created_at'] ) ? current_time( 'mysql', true ) : $meta['created_at'],
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array $args form_id, search, per_page, page, orderby, order.
	 * @return array { items: array, total: int }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args(
			$args,
			array(
				'form_id'  => 0,
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
				'order'    => 'DESC',
				'ids'      => array(),
				'status'   => '', // '' = real entries, 'spam', or 'any'.
			)
		);
		$table = Spiral_Forms_Install::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( $args['form_id'] ) {
			$where[] = 'form_id = %d';
			$vals[]  = (int) $args['form_id'];
		}
		if ( 'any' !== $args['status'] ) {
			$where[] = 'status = %s';
			$vals[]  = (string) $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$where[] = 'fields LIKE %s';
			$vals[]  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		if ( $args['ids'] ) {
			$ids     = array_map( 'intval', (array) $args['ids'] );
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$vals    = array_merge( $vals, $ids );
		}
		$where_sql = implode( ' AND ', $where );
		$order     = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql );

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id {$order}";
		if ( $args['per_page'] > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['per_page'], max( 0, ( $args['page'] - 1 ) * $args['per_page'] ) );
		}
		$rows = $wpdb->get_results( $vals ? $wpdb->prepare( $sql, $vals ) : $sql, ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
	}

	public static function count_unread( $form_id = 0 ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0 AND status = '' AND form_id = %d", $form_id ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0 AND status = ''" ); // phpcs:ignore
	}

	public static function count_for_form( $form_id ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status = ''", $form_id ) ); // phpcs:ignore
	}

	public static function count_spam( $form_id = 0 ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'spam' AND form_id = %d", $form_id ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'spam'" ); // phpcs:ignore
	}

	public static function set_status( $id, $status ) {
		global $wpdb;
		return $wpdb->update( Spiral_Forms_Install::table(), array( 'status' => (string) $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Daily cron: delete entries older than each form's retention setting,
	 * and spam older than 30 days.
	 */
	public static function purge_expired() {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		self::delete_where( "status = 'spam' AND created_at < %s", array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		foreach ( Spiral_Forms_Forms::all() as $p ) {
			$form = Spiral_Forms_Forms::get( $p->ID );
			$days = $form ? (int) $form['settings']['retention_days'] : 0;
			if ( $days > 0 ) {
				self::delete_where( 'form_id = %d AND created_at < %s', array( $p->ID, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
			}
		}
	}

	public static function mark_read( $id, $read = true ) {
		global $wpdb;
		return $wpdb->update( Spiral_Forms_Install::table(), array( 'is_read' => $read ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	public static function delete( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$table = Spiral_Forms_Install::table();
		foreach ( self::query( array( 'ids' => $ids, 'per_page' => 0, 'status' => 'any' ) )['items'] as $entry ) {
			Spiral_Forms_Uploads::delete_entry_files( $entry );
		}
		$ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) ); // phpcs:ignore
	}

	public static function delete_for_form( $form_id ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE form_id = %d LIMIT 500", $form_id ) ); // phpcs:ignore
			self::delete( $ids );
		} while ( $ids );
		return true;
	}

	/** Delete matching rows in batches (so their files are removed too). */
	private static function delete_where( $where_sql, array $vals ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$where_sql} LIMIT 500", $vals ) ); // phpcs:ignore
			self::delete( $ids );
		} while ( $ids );
	}

	private static function hydrate( array $row ) {
		$fields        = json_decode( $row['fields'], true );
		$row['fields'] = is_array( $fields ) ? $fields : array();
		$row['id']     = (int) $row['id'];
		$row['form_id'] = (int) $row['form_id'];
		return $row;
	}

	/**
	 * Value as a display string (arrays joined).
	 */
	public static function value_to_string( $value, $sep = ', ' ) {
		if ( Spiral_Forms_Uploads::is_file_value( $value ) ) {
			return implode( $sep, array_map( static function ( $r ) { return (string) ( $r['name'] ?? '' ); }, $value ) );
		}
		return is_array( $value ) ? implode( $sep, array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value;
	}
}
