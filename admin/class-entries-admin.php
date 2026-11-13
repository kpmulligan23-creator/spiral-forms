<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries list table.
 */
class Spiral_Forms_Entries_Table extends WP_List_Table {

	private $form_id;
	private $form;
	private $status;

	public function __construct( $form_id, $status = '' ) {
		$this->status = 'spam' === $status ? 'spam' : '';
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
		$this->form_id = (int) $form_id;
		$this->form    = $form_id ? Spiral_Forms_Forms::get( $form_id ) : null;
	}

	public function get_columns() {
		$cols = array( 'cb' => '<input type="checkbox">' );
		if ( $this->form ) {
			foreach ( array_slice( $this->form['fields'], 0, 3 ) as $f ) {
				$cols[ 'f_' . $f['id'] ] = esc_html( $f['label'] );
			}
		} else {
			$cols['summary'] = __( 'Entry', 'spiral-forms' );
			$cols['form']    = __( 'Form', 'spiral-forms' );
		}
		$cols['created_at'] = __( 'Date', 'spiral-forms' );
		return $cols;
	}

	protected function get_bulk_actions() {
		if ( 'spam' === $this->status ) {
			return array(
				'not_spam' => __( 'Not spam', 'spiral-forms' ),
				'delete'   => __( 'Delete permanently', 'spiral-forms' ),
			);
		}
		return array(
			'mark_spam'   => __( 'Mark as spam', 'spiral-forms' ),
			'mark_read'   => __( 'Mark read', 'spiral-forms' ),
			'mark_unread' => __( 'Mark unread', 'spiral-forms' ),
			'delete'      => __( 'Delete', 'spiral-forms' ),
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_REQUEST['order'] ) && 'asc' === $_REQUEST['order'] ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result   = Spiral_Forms_Entries::query(
			array(
				'form_id'  => $this->form_id,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
				'order'    => $order,
				'status'   => $this->status,
			)
		);
		$this->items = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), array( 'created_at' => array( 'created_at', true ) ) );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	public function column_cb( $item ) {
		return '<input type="checkbox" name="entry_ids[]" value="' . (int) $item['id'] . '">';
	}

	private function view_link( $item, $text ) {
		$url = admin_url( 'admin.php?page=spiral-forms-entries&view=' . $item['id'] );
		$out = '<a href="' . esc_url( $url ) . '">' . ( '' === $text ? '<em>' . esc_html__( '(empty)', 'spiral-forms' ) . '</em>' : esc_html( $text ) ) . '</a>';
		return $item['is_read'] ? $out : '<strong>' . $out . '</strong>';
	}

	private function entry_value( $item, $id ) {
		foreach ( $item['fields'] as $f ) {
			if ( ( $f['id'] ?? '' ) === $id ) {
				return Spiral_Forms_Entries::value_to_string( $f['value'] ?? '' );
			}
		}
		return '';
	}

	public function column_default( $item, $col ) {
		if ( 0 === strpos( $col, 'f_' ) ) {
			$val   = wp_html_excerpt( $this->entry_value( $item, substr( $col, 2 ) ), 80, '…' );
			$first = array_keys( $this->get_columns() )[1];
			return $col === $first ? $this->view_link( $item, $val ) . $this->row_actions( $this->actions( $item ) ) : esc_html( $val );
		}
		switch ( $col ) {
			case 'summary':
				$bits = array();
				foreach ( array_slice( $item['fields'], 0, 2 ) as $f ) {
					$bits[] = Spiral_Forms_Entries::value_to_string( $f['value'] ?? '' );
				}
				return $this->view_link( $item, wp_html_excerpt( implode( ' · ', array_filter( $bits, 'strlen' ) ), 80, '…' ) ) . $this->row_actions( $this->actions( $item ) );
			case 'form':
				$post = get_post( $item['form_id'] );
				return $post ? '<a href="' . esc_url( admin_url( 'admin.php?page=spiral-forms-entries&form_id=' . $item['form_id'] ) ) . '">' . esc_html( $post->post_title ) . '</a>' : '—';
			case 'created_at':
				return esc_html( get_date_from_gmt( $item['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
		}
		return '';
	}

	private function actions( $item ) {
		$del = wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_delete_entry&entry_id=' . $item['id'] ), 'sf_del_entry_' . $item['id'] );
		$base = array(
			'view'  => '<a href="' . esc_url( admin_url( 'admin.php?page=spiral-forms-entries&view=' . $item['id'] ) ) . '">' . esc_html__( 'View', 'spiral-forms' ) . '</a>',
			'trash' => '<a href="' . esc_url( $del ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this entry?', 'spiral-forms' ) ) . '\');">' . esc_html__( 'Delete', 'spiral-forms' ) . '</a>',
		);
		if ( 'spam' === $item['status'] ) {
			$ns           = wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_not_spam&entry_id=' . $item['id'] ), 'sf_not_spam_' . $item['id'] );
			$base['spam'] = '<a href="' . esc_url( $ns ) . '">' . esc_html__( 'Not spam', 'spiral-forms' ) . '</a>';
		}
		return $base;
	}

	public function no_items() {
		esc_html_e( 'No entries yet.', 'spiral-forms' );
	}
}

/**
 * Entries screens, bulk actions, and CSV export.
 */
class Spiral_Forms_Entries_Admin {

	public static function init() {
		add_action( 'admin_post_spiral_forms_delete_entry', array( __CLASS__, 'delete_entry' ) );
		add_action( 'admin_post_spiral_forms_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_spiral_forms_not_spam', array( __CLASS__, 'not_spam' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_bulk' ) );
	}

	public static function page() {
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['view'] ) ) {
			self::single( absint( $_GET['view'] ) );
			return;
		}
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$status  = isset( $_GET['status'] ) && 'spam' === $_GET['status'] ? 'spam' : '';
		// phpcs:enable
		$forms = Spiral_Forms_Forms::all();
		$table = new Spiral_Forms_Entries_Table( $form_id, $status );
		$table->prepare_items();
		?>
		<div class="wrap sf-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></h1>
			<?php if ( $form_id ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_export&form_id=' . $form_id ), 'sf_export' ) ); ?>"><?php esc_html_e( 'Export CSV', 'spiral-forms' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php Spiral_Forms_Admin::notices(); ?>

			<?php
			$base_url = admin_url( 'admin.php?page=spiral-forms-entries' . ( $form_id ? '&form_id=' . $form_id : '' ) );
			$n_spam   = Spiral_Forms_Entries::count_spam( $form_id );
			?>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo '' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></a> |</li>
				<li><a href="<?php echo esc_url( $base_url . '&status=spam' ); ?>" class="<?php echo 'spam' === $status ? 'current' : ''; ?>"><?php esc_html_e( 'Spam', 'spiral-forms' ); ?> <span class="sf-count">(<?php echo (int) $n_spam; ?>)</span></a></li>
			</ul>
			<br class="clear">
			<form method="get" class="sf-entries-filter">
				<input type="hidden" name="page" value="spiral-forms-entries">
				<?php if ( $status ) : ?><input type="hidden" name="status" value="spam"><?php endif; ?>
				<select name="form_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'All forms', 'spiral-forms' ); ?></option>
					<?php foreach ( $forms as $p ) : ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( $form_id, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $form_id ) : ?>
					<span class="description"><?php esc_html_e( 'Pick a form to see its columns and export CSV.', 'spiral-forms' ); ?></span>
				<?php endif; ?>
				<?php $table->search_box( __( 'Search entries', 'spiral-forms' ), 'sf-entries' ); ?>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'sf_bulk_entries', '_sf_bulk' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function single( $id ) {
		$entry = Spiral_Forms_Entries::get( $id );
		if ( ! $entry ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Entry not found.', 'spiral-forms' ) . '</p></div>';
			return;
		}
		if ( ! $entry['is_read'] ) {
			Spiral_Forms_Entries::mark_read( $id );
		}
		$form = get_post( $entry['form_id'] );
		$back = admin_url( 'admin.php?page=spiral-forms-entries&form_id=' . $entry['form_id'] );
		$del  = wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_delete_entry&entry_id=' . $id ), 'sf_del_entry_' . $id );
		?>
		<div class="wrap sf-admin">
			<h1>
				<?php
				/* translators: %d: entry ID */
				echo esc_html( sprintf( __( 'Entry #%d', 'spiral-forms' ), $id ) );
				?>
			</h1>
			<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to entries', 'spiral-forms' ); ?></a></p>
			<div class="sf-entry-layout">
				<div class="postbox sf-entry-fields">
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $entry['fields'] as $f ) : ?>
							<?php $val = Spiral_Forms_Entries::value_to_string( $f['value'] ?? '', "\n" ); ?>
							<tr>
								<th scope="row"><?php echo esc_html( $f['label'] ?? $f['id'] ); ?></th>
								<td>
									<?php
									if ( Spiral_Forms_Uploads::is_file_value( $f['value'] ?? null ) ) {
										foreach ( $f['value'] as $i => $rec ) {
											$rec = (array) $rec;
											echo '<a class="sf-file-link" href="' . esc_url( Spiral_Forms_Uploads::download_url( $id, $f['id'], $i, $rec ) ) . '"><span class="dashicons dashicons-download"></span> ' . esc_html( $rec['name'] ?? '' ) . '</a>'
												. ( ! empty( $rec['size'] ) ? ' <span class="sf-muted">' . esc_html( size_format( $rec['size'] ) ) . '</span>' : '' )
												. ( ! empty( $rec['file'] ) && ! Spiral_Forms_Uploads::path( $rec ) ? ' <em class="sf-muted">(' . esc_html__( 'file missing', 'spiral-forms' ) . ')</em>' : '' ) . '<br>';
										}
									} else {
										echo '' === $val ? '<em class="sf-muted">' . esc_html__( 'Empty', 'spiral-forms' ) . '</em>' : nl2br( esc_html( $val ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="postbox sf-entry-meta">
					<div class="inside">
						<p><strong><?php esc_html_e( 'Form', 'spiral-forms' ); ?>:</strong> <?php echo $form ? esc_html( $form->post_title ) : '—'; ?></p>
						<p><strong><?php esc_html_e( 'Submitted', 'spiral-forms' ); ?>:</strong> <?php echo esc_html( get_date_from_gmt( $entry['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
						<?php if ( $entry['page_url'] ) : ?>
							<p><strong><?php esc_html_e( 'Page', 'spiral-forms' ); ?>:</strong> <a href="<?php echo esc_url( $entry['page_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $entry['page_url'], PHP_URL_PATH ) ?: '/' ); ?></a></p>
						<?php endif; ?>
						<?php if ( 'spam' === $entry['status'] ) : ?>
							<p><span class="sf-badge"><?php esc_html_e( 'Spam', 'spiral-forms' ); ?></span>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_not_spam&entry_id=' . $id ), 'sf_not_spam_' . $id ) ); ?>"><?php esc_html_e( 'Not spam', 'spiral-forms' ); ?></a></p>
						<?php endif; ?>
						<p><strong><?php esc_html_e( 'IP', 'spiral-forms' ); ?>:</strong> <?php echo esc_html( $entry['ip'] ); ?></p>
						<?php if ( $entry['user_id'] ) : ?>
							<?php $u = get_userdata( $entry['user_id'] ); ?>
							<p><strong><?php esc_html_e( 'User', 'spiral-forms' ); ?>:</strong> <?php echo $u ? esc_html( $u->display_name ) : (int) $entry['user_id']; ?></p>
						<?php endif; ?>
						<p><a class="button-link-delete" href="<?php echo esc_url( $del ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this entry?', 'spiral-forms' ) ); ?>');"><?php esc_html_e( 'Delete entry', 'spiral-forms' ); ?></a></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_bulk() {
		if ( empty( $_POST['_sf_bulk'] ) || empty( $_POST['entry_ids'] ) ) {
			return;
		}
		check_admin_referer( 'sf_bulk_entries', '_sf_bulk' );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			return;
		}
		$action = isset( $_POST['action'] ) && '-1' !== $_POST['action'] ? sanitize_key( $_POST['action'] ) : ( isset( $_POST['action2'] ) ? sanitize_key( $_POST['action2'] ) : '' );
		$ids    = array_map( 'absint', (array) $_POST['entry_ids'] );

		switch ( $action ) {
			case 'delete':
				Spiral_Forms_Entries::delete( $ids );
				break;
			case 'mark_spam':
			case 'not_spam':
				foreach ( $ids as $id ) {
					Spiral_Forms_Entries::set_status( $id, 'mark_spam' === $action ? 'spam' : '' );
				}
				break;
			case 'mark_read':
			case 'mark_unread':
				foreach ( $ids as $id ) {
					Spiral_Forms_Entries::mark_read( $id, 'mark_read' === $action );
				}
				break;
			default:
				return;
		}
		wp_safe_redirect( remove_query_arg( array( 'paged' ), wp_get_referer() ) );
		exit;
	}

	public static function not_spam() {
		$id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		check_admin_referer( 'sf_not_spam_' . $id );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		Spiral_Forms_Entries::set_status( $id, '' );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=spiral-forms-entries' ) );
		exit;
	}

	public static function delete_entry() {
		$id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		check_admin_referer( 'sf_del_entry_' . $id );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		$entry = Spiral_Forms_Entries::get( $id );
		Spiral_Forms_Entries::delete( $id );
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms-entries&sf_msg=entries_deleted' . ( $entry ? '&form_id=' . $entry['form_id'] : '' ) ) );
		exit;
	}

	public static function export() {
		check_admin_referer( 'sf_export' );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$form    = Spiral_Forms_Forms::get( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Form not found.', 'spiral-forms' ) );
		}
		$entries = Spiral_Forms_Entries::query(
			array(
				'form_id'  => $form_id,
				'per_page' => 0,
				'order'    => 'ASC',
			)
		)['items'];

		// Columns: current form fields first, then any extra fields found in older entries.
		$columns = array();
		foreach ( $form['fields'] as $f ) {
			$columns[ $f['id'] ] = $f['label'];
		}
		foreach ( $entries as $e ) {
			foreach ( $e['fields'] as $f ) {
				if ( isset( $f['id'] ) && ! isset( $columns[ $f['id'] ] ) ) {
					$columns[ $f['id'] ] = $f['label'] ?? $f['id'];
				}
			}
		}

		$filename = sanitize_file_name( $form['title'] . '-entries-' . gmdate( 'Y-m-d' ) . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel reads accents correctly.
		fputcsv( $out, array_merge( array( 'Entry ID', 'Date' ), array_values( $columns ), array( 'Page URL', 'IP' ) ), ',', '"', '' );

		foreach ( $entries as $e ) {
			$by_id = array();
			foreach ( $e['fields'] as $f ) {
				if ( Spiral_Forms_Uploads::is_file_value( $f['value'] ?? null ) ) {
					$parts = array();
					foreach ( $f['value'] as $i => $rec ) {
						$parts[] = ( $rec['name'] ?? '' ) . ' (' . Spiral_Forms_Uploads::download_url( $e['id'], $f['id'], $i, (array) $rec ) . ')';
					}
					$by_id[ $f['id'] ?? '' ] = implode( "\n", $parts );
					continue;
				}
				$by_id[ $f['id'] ?? '' ] = Spiral_Forms_Entries::value_to_string( $f['value'] ?? '' );
			}
			$row = array( $e['id'], get_date_from_gmt( $e['created_at'], 'Y-m-d H:i:s' ) );
			foreach ( array_keys( $columns ) as $cid ) {
				$row[] = self::csv_safe( $by_id[ $cid ] ?? '' );
			}
			$row[] = $e['page_url'];
			$row[] = $e['ip'];
			fputcsv( $out, $row, ',', '"', '' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Prevent CSV/formula injection when opened in Excel/Sheets.
	 */
	private static function csv_safe( $value ) {
		return preg_match( '/^[=+\-@\t\r]/', (string) $value ) ? "'" . $value : $value;
	}
}
