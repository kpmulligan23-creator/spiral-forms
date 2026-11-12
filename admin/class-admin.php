<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin menus and the forms list screen.
 */
class Spiral_Forms_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_spiral_forms_delete_form', array( __CLASS__, 'delete_form' ) );
		add_action( 'admin_post_spiral_forms_duplicate_form', array( __CLASS__, 'duplicate_form' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		Spiral_Forms_Builder::init();
		Spiral_Forms_Entries_Admin::init();
		Spiral_Forms_Settings_Page::init();
	}

	public static function menu() {
		$unread = Spiral_Forms_Entries::count_unread();
		$badge  = $unread ? ' <span class="awaiting-mod">' . (int) $unread . '</span>' : '';

		add_menu_page( __( 'Spiral Forms', 'spiral-forms' ), __( 'Spiral Forms', 'spiral-forms' ) . $badge, self::CAP, 'spiral-forms', array( __CLASS__, 'list_page' ), 'dashicons-feedback', 26 );
		add_submenu_page( 'spiral-forms', __( 'All Forms', 'spiral-forms' ), __( 'All Forms', 'spiral-forms' ), self::CAP, 'spiral-forms', array( __CLASS__, 'list_page' ) );
		add_submenu_page( 'spiral-forms', __( 'Add New Form', 'spiral-forms' ), __( 'Add New', 'spiral-forms' ), self::CAP, 'spiral-forms-edit', array( 'Spiral_Forms_Builder', 'page' ) );
		add_submenu_page( 'spiral-forms', __( 'Entries', 'spiral-forms' ), __( 'Entries', 'spiral-forms' ) . $badge, self::CAP, 'spiral-forms-entries', array( 'Spiral_Forms_Entries_Admin', 'page' ) );
		add_submenu_page( 'spiral-forms', __( 'Settings', 'spiral-forms' ), __( 'Settings', 'spiral-forms' ), self::CAP, 'spiral-forms-settings', array( 'Spiral_Forms_Settings_Page', 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'spiral-forms' ) ) {
			return;
		}
		wp_enqueue_style( 'spiral-forms-admin', SPIRAL_FORMS_URL . 'assets/css/admin.css', array(), SPIRAL_FORMS_VERSION );
	}

	public static function list_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$forms = Spiral_Forms_Forms::all();
		?>
		<div class="wrap sf-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Forms', 'spiral-forms' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=spiral-forms-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'spiral-forms' ); ?></a>
			<hr class="wp-header-end">
			<?php self::notices(); ?>

			<?php if ( ! $forms ) : ?>
				<div class="sf-empty">
					<p><?php esc_html_e( 'No forms yet.', 'spiral-forms' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=spiral-forms-edit' ) ); ?>"><?php esc_html_e( 'Create your first form', 'spiral-forms' ); ?></a></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th><?php esc_html_e( 'Name', 'spiral-forms' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'spiral-forms' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Modified', 'spiral-forms' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $forms as $p ) : ?>
						<?php
						$edit   = admin_url( 'admin.php?page=spiral-forms-edit&form_id=' . $p->ID );
						$ent    = admin_url( 'admin.php?page=spiral-forms-entries&form_id=' . $p->ID );
						$dup    = wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_duplicate_form&form_id=' . $p->ID ), 'sf_dup_' . $p->ID );
						$del    = wp_nonce_url( admin_url( 'admin-post.php?action=spiral_forms_delete_form&form_id=' . $p->ID ), 'sf_del_' . $p->ID );
						$count  = Spiral_Forms_Entries::count_for_form( $p->ID );
						$unread = Spiral_Forms_Entries::count_unread( $p->ID );
						?>
						<tr>
							<td>
								<strong><a class="row-title" href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $p->post_title ); ?></a></strong>
								<div class="row-actions">
									<span><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'spiral-forms' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( $ent ); ?>"><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></a> | </span>
									<span><a href="<?php echo esc_url( $dup ); ?>"><?php esc_html_e( 'Duplicate', 'spiral-forms' ); ?></a> | </span>
									<span class="trash"><a href="<?php echo esc_url( $del ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this form and all its entries?', 'spiral-forms' ) ); ?>');"><?php esc_html_e( 'Delete', 'spiral-forms' ); ?></a></span>
								</div>
							</td>
							<td><input type="text" readonly class="sf-shortcode" value="<?php echo esc_attr( '[spiral_form id="' . $p->ID . '"]' ); ?>" onclick="this.select()"></td>
							<td><a href="<?php echo esc_url( $ent ); ?>"><?php echo (int) $count; ?></a><?php echo $unread ? ' <span class="sf-badge">' . (int) $unread . ' ' . esc_html__( 'new', 'spiral-forms' ) . '</span>' : ''; ?></td>
							<td><?php echo esc_html( get_the_modified_date( '', $p ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function notices() {
		$msg = isset( $_GET['sf_msg'] ) ? sanitize_key( $_GET['sf_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'saved'      => __( 'Form saved.', 'spiral-forms' ),
			'deleted'    => __( 'Form deleted.', 'spiral-forms' ),
			'duplicated' => __( 'Form duplicated.', 'spiral-forms' ),
			'entries_deleted' => __( 'Entries deleted.', 'spiral-forms' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
		}
	}

	public static function delete_form() {
		$id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		check_admin_referer( 'sf_del_' . $id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		if ( Spiral_Forms_Forms::get( $id ) ) {
			Spiral_Forms_Entries::delete_for_form( $id );
			wp_delete_post( $id, true );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms&sf_msg=deleted' ) );
		exit;
	}

	public static function duplicate_form() {
		$id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		check_admin_referer( 'sf_dup_' . $id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		$form = Spiral_Forms_Forms::get( $id );
		if ( $form ) {
			/* translators: %s: form title */
			Spiral_Forms_Forms::save( 0, sprintf( __( '%s (copy)', 'spiral-forms' ), $form['title'] ), $form );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms&sf_msg=duplicated' ) );
		exit;
	}
}
