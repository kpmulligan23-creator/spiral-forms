<?php
defined( 'ABSPATH' ) || exit;

/**
 * Form builder screen. The UI is rendered by assets/js/builder.js from a JSON
 * definition; on save, the JSON is posted back and sanitized server-side.
 */
class Spiral_Forms_Builder {

	public static function init() {
		add_action( 'admin_post_spiral_forms_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'spiral-forms-edit' ) ) {
			return;
		}
		wp_enqueue_script( 'spiral-forms-builder', SPIRAL_FORMS_URL . 'assets/js/builder.js', array( 'jquery', 'jquery-ui-sortable' ), SPIRAL_FORMS_VERSION, true );

		$types = array();
		foreach ( Spiral_Forms_Forms::field_types() as $key => $t ) {
			$types[] = array(
				'type'    => $key,
				'label'   => $t['label'],
				'options' => (bool) $t['options'],
			);
		}
		wp_localize_script(
			'spiral-forms-builder',
			'SpiralFormsBuilder',
			array(
				'types'     => $types,
				'smartTags' => Spiral_Forms_Smart_Tags::available(),
				'i18n'      => array(
					'label'        => __( 'Label', 'spiral-forms' ),
					'fieldId'      => __( 'Field ID (used in smart tags)', 'spiral-forms' ),
					'required'     => __( 'Required', 'spiral-forms' ),
					'placeholder'  => __( 'Placeholder', 'spiral-forms' ),
					'description'  => __( 'Description', 'spiral-forms' ),
					'default'      => __( 'Default value', 'spiral-forms' ),
					'options'      => __( 'Choices (one per line)', 'spiral-forms' ),
					'min'          => __( 'Minimum', 'spiral-forms' ),
					'max'          => __( 'Maximum', 'spiral-forms' ),
					'cssClass'     => __( 'CSS classes', 'spiral-forms' ),
					'remove'       => __( 'Remove', 'spiral-forms' ),
					'duplicate'    => __( 'Duplicate', 'spiral-forms' ),
					'moveUp'       => __( 'Move up', 'spiral-forms' ),
					'moveDown'     => __( 'Move down', 'spiral-forms' ),
					'confirmDel'   => __( 'Remove this field?', 'spiral-forms' ),
					'noFields'     => __( 'Add fields using the buttons on the left.', 'spiral-forms' ),
					'enabled'      => __( 'Enabled', 'spiral-forms' ),
					'name'         => __( 'Name', 'spiral-forms' ),
					'sendTo'       => __( 'Send to', 'spiral-forms' ),
					'subject'      => __( 'Subject', 'spiral-forms' ),
					'message'      => __( 'Message', 'spiral-forms' ),
					'fromName'     => __( 'From name', 'spiral-forms' ),
					'fromEmail'    => __( 'From email (blank = site default / SMTP plugin)', 'spiral-forms' ),
					'replyTo'      => __( 'Reply-to', 'spiral-forms' ),
					'notification' => __( 'Notification', 'spiral-forms' ),
					'newChoice'    => __( 'First choice', 'spiral-forms' ),
					'insertTag'    => __( 'Insert field tag…', 'spiral-forms' ),
					'nameFormat'   => __( 'Format', 'spiral-forms' ),
					'nameSimple'   => __( 'Single box', 'spiral-forms' ),
					'nameFirstLast' => __( 'First and last name', 'spiral-forms' ),
					'condEnable'   => __( 'Only show this field in some cases', 'spiral-forms' ),
					'condShow'     => __( 'Show', 'spiral-forms' ),
					'condHide'     => __( 'Hide', 'spiral-forms' ),
					'condWhen'     => __( 'this field when', 'spiral-forms' ),
					'condAll'      => __( 'all', 'spiral-forms' ),
					'condAny'      => __( 'any', 'spiral-forms' ),
					'condOfThese'  => __( 'of these match:', 'spiral-forms' ),
					'condAddRule'  => __( '+ Add rule', 'spiral-forms' ),
					'opIs'         => __( 'is', 'spiral-forms' ),
					'opIsNot'      => __( 'is not', 'spiral-forms' ),
					'opContains'   => __( 'contains', 'spiral-forms' ),
					'opEmpty'      => __( 'is empty', 'spiral-forms' ),
					'opNotEmpty'   => __( 'is not empty', 'spiral-forms' ),
				),
			)
		);
	}

	public static function page() {
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			return;
		}
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form    = $form_id ? Spiral_Forms_Forms::get( $form_id ) : null;

		if ( $form_id && ! $form ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Form not found.', 'spiral-forms' ) . '</p></div>';
			return;
		}
		if ( ! $form ) {
			$form = array(
				'id'       => 0,
				'title'    => '',
				'fields'   => Spiral_Forms_Forms::default_fields(),
				'settings' => Spiral_Forms_Forms::default_settings(),
			);
			$form['fields'] = array_map( array( 'Spiral_Forms_Forms', 'normalize_field' ), $form['fields'] );
		}
		$json = wp_json_encode(
			array(
				'fields'   => $form['fields'],
				'settings' => $form['settings'],
			)
		);
		?>
		<div class="wrap sf-admin sf-builder-wrap">
			<h1 class="wp-heading-inline"><?php echo $form['id'] ? esc_html__( 'Edit Form', 'spiral-forms' ) : esc_html__( 'Add New Form', 'spiral-forms' ); ?></h1>
			<?php if ( $form['id'] ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=spiral-forms-entries&form_id=' . $form['id'] ) ); ?>"><?php esc_html_e( 'View Entries', 'spiral-forms' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php Spiral_Forms_Admin::notices(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sf-builder-form">
				<input type="hidden" name="action" value="spiral_forms_save">
				<input type="hidden" name="form_id" value="<?php echo (int) $form['id']; ?>">
				<?php wp_nonce_field( 'spiral_forms_save' ); ?>
				<textarea name="form_data" id="sf-form-data" hidden><?php echo esc_textarea( $json ); ?></textarea>

				<div class="sf-builder-top">
					<input type="text" name="form_title" class="sf-title" value="<?php echo esc_attr( $form['title'] ); ?>" placeholder="<?php esc_attr_e( 'Form name', 'spiral-forms' ); ?>" required>
					<?php if ( $form['id'] ) : ?>
						<input type="text" readonly class="sf-shortcode" value="<?php echo esc_attr( '[spiral_form id="' . $form['id'] . '"]' ); ?>" onclick="this.select()">
					<?php endif; ?>
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Form', 'spiral-forms' ); ?></button>
				</div>

				<nav class="nav-tab-wrapper sf-tabs">
					<a href="#fields" class="nav-tab nav-tab-active" data-tab="fields"><?php esc_html_e( 'Fields', 'spiral-forms' ); ?></a>
					<a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'spiral-forms' ); ?></a>
					<a href="#notifications" class="nav-tab" data-tab="notifications"><?php esc_html_e( 'Notifications', 'spiral-forms' ); ?></a>
				</nav>

				<div class="sf-tab-panel" data-panel="fields">
					<div class="sf-builder">
						<aside class="sf-palette">
							<h3><?php esc_html_e( 'Add Field', 'spiral-forms' ); ?></h3>
							<div id="sf-palette-buttons"></div>
						</aside>
						<div class="sf-canvas">
							<ul id="sf-fields"></ul>
						</div>
					</div>
				</div>

				<div class="sf-tab-panel" data-panel="settings" hidden>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="sf-s-submit_text"><?php esc_html_e( 'Submit button text', 'spiral-forms' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sf-s-submit_text" data-setting="submit_text"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'After submission', 'spiral-forms' ); ?></th>
							<td>
								<label><input type="radio" name="sf_confirm_type" value="message" data-setting="confirmation_type"> <?php esc_html_e( 'Show a message', 'spiral-forms' ); ?></label><br>
								<label><input type="radio" name="sf_confirm_type" value="redirect" data-setting="confirmation_type"> <?php esc_html_e( 'Redirect to a URL', 'spiral-forms' ); ?></label>
							</td>
						</tr>
						<tr class="sf-when-message">
							<th><label for="sf-s-confirmation_message"><?php esc_html_e( 'Confirmation message', 'spiral-forms' ); ?></label></th>
							<td><textarea class="large-text" rows="4" id="sf-s-confirmation_message" data-setting="confirmation_message"></textarea>
								<p class="description"><?php esc_html_e( 'Smart tags like {field:name} work here.', 'spiral-forms' ); ?></p></td>
						</tr>
						<tr class="sf-when-redirect">
							<th><label for="sf-s-redirect_url"><?php esc_html_e( 'Redirect URL', 'spiral-forms' ); ?></label></th>
							<td><input type="url" class="regular-text" id="sf-s-redirect_url" data-setting="redirect_url" placeholder="https://"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></th>
							<td>
								<label><input type="checkbox" data-setting="store_entries"> <?php esc_html_e( 'Save entries in the database', 'spiral-forms' ); ?></label><br>
								<label><input type="checkbox" data-setting="store_spam"> <?php esc_html_e( 'Keep blocked spam in Entries → Spam for 30 days (so a real lead flagged by mistake isn\'t lost)', 'spiral-forms' ); ?></label>
							</td>
						</tr>
						<tr>
							<th><label for="sf-s-retention_days"><?php esc_html_e( 'Delete entries after', 'spiral-forms' ); ?></label></th>
							<td><input type="number" min="0" step="1" class="small-text" id="sf-s-retention_days" data-setting="retention_days"> <?php esc_html_e( 'days (0 = keep forever)', 'spiral-forms' ); ?></td>
						</tr>
						<tr>
							<th><label for="sf-s-css_class"><?php esc_html_e( 'Form CSS classes', 'spiral-forms' ); ?></label></th>
							<td><input type="text" class="regular-text" id="sf-s-css_class" data-setting="css_class"></td>
						</tr>
					</table>
				</div>

				<div class="sf-tab-panel" data-panel="notifications" hidden>
					<div class="sf-tags-help">
						<strong><?php esc_html_e( 'Smart tags:', 'spiral-forms' ); ?></strong>
						<span id="sf-tag-list"></span>
					</div>
					<div id="sf-notifications"></div>
					<p><button type="button" class="button" id="sf-add-notification"><?php esc_html_e( '+ Add notification', 'spiral-forms' ); ?></button></p>
				</div>

				<p class="sf-builder-bottom"><button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Form', 'spiral-forms' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	public static function save() {
		check_admin_referer( 'spiral_forms_save' );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$title   = isset( $_POST['form_title'] ) ? sanitize_text_field( wp_unslash( $_POST['form_title'] ) ) : '';
		$data    = isset( $_POST['form_data'] ) ? json_decode( wp_unslash( $_POST['form_data'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Spiral_Forms_Forms::sanitize().

		if ( $form_id && ! Spiral_Forms_Forms::get( $form_id ) ) {
			wp_die( esc_html__( 'Form not found.', 'spiral-forms' ) );
		}

		$result = Spiral_Forms_Forms::save( $form_id, $title, is_array( $data ) ? $data : array() );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms-edit&form_id=' . $result . '&sf_msg=saved' ) );
		exit;
	}
}
