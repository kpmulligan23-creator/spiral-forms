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
				'ip'         => substr( (string) ( $meta['ip'] ?? '' ), 0, 100 ),
				'user_agent' => substr( (string) ( $meta['user_agent'] ?? '' ), 0, 255 ),
				'page_url'   => substr( (string) ( $meta['page_url'] ?? '' ), 0, 2048 ),
				'user_id'    => (int) ( $meta['user_id'] ?? 0 ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
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
			)
		);
		$table = Spiral_Forms_Install::table();
		$where = array( '1=1' );
		$vals  = array();

		if ( $args['form_id'] ) {
			$where[] = 'form_id = %d';
			$vals[]  = (int) $args['form_id'];
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
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0 AND form_id = %d", $form_id ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" ); // phpcs:ignore
	}

	public static function count_for_form( $form_id ) {
		global $wpdb;
		$table = Spiral_Forms_Install::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore
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
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$ph})", $ids ) ); // phpcs:ignore
	}

	public static function delete_for_form( $form_id ) {
		global $wpdb;
		return $wpdb->delete( Spiral_Forms_Install::table(), array( 'form_id' => (int) $form_id ), array( '%d' ) );
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
		return is_array( $value ) ? implode( $sep, $value ) : (string) $value;
	}
}
