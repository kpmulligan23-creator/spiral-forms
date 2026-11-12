<?php
defined( 'ABSPATH' ) || exit;

/**
 * Front-end rendering: [spiral_form id="123"] shortcode (also used by the block).
 */
class Spiral_Forms_Renderer {

	public static function init() {
		add_shortcode( 'spiral_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'spiral-forms', SPIRAL_FORMS_URL . 'assets/css/forms.css', array(), SPIRAL_FORMS_VERSION );
		wp_register_script( 'spiral-forms', SPIRAL_FORMS_URL . 'assets/js/forms.js', array(), SPIRAL_FORMS_VERSION, true );
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'spiral_form' );
		return self::render( (int) $atts['id'] );
	}

	public static function render( $form_id ) {
		$form = Spiral_Forms_Forms::get( $form_id );
		if ( ! $form ) {
			return current_user_can( 'edit_posts' ) ? '<p class="sf-notice">' . esc_html__( 'Spiral Forms: form not found.', 'spiral-forms' ) . '</p>' : '';
		}

		if ( Spiral_Forms_Settings::get( 'load_css' ) ) {
			wp_enqueue_style( 'spiral-forms' );
		}
		wp_enqueue_script( 'spiral-forms' );

		$dom_id  = 'sf-form-' . $form['id'];
		$state   = Spiral_Forms_Submission::state( $form['id'] );
		$success = Spiral_Forms_Submission::success_message( $form );

		if ( null !== $success ) {
			return '<div id="' . esc_attr( $dom_id ) . '" class="sf-wrap"><div class="sf-confirmation" role="status" tabindex="-1">' . wp_kses_post( wpautop( $success ) ) . '</div></div>';
		}

		$errors = $state['errors'] ?? array();
		$values = $state['values'] ?? array();

		ob_start();
		?>
		<div id="<?php echo esc_attr( $dom_id ); ?>" class="sf-wrap <?php echo esc_attr( $form['settings']['css_class'] ); ?>">
			<?php if ( ! empty( $errors['_form'] ) ) : ?>
				<div class="sf-error-summary" role="alert"><?php echo esc_html( $errors['_form'] ); ?></div>
			<?php elseif ( $errors ) : ?>
				<div class="sf-error-summary" role="alert"><?php esc_html_e( 'Please correct the errors below.', 'spiral-forms' ); ?></div>
			<?php endif; ?>
			<form class="sf-form" method="post" action="<?php echo esc_url( '#' . $dom_id ); ?>" novalidate>
				<input type="hidden" name="spiral_form_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<?php
				foreach ( $form['fields'] as $field ) {
					$has   = array_key_exists( $field['id'], $values );
					$value = $has ? $values[ $field['id'] ] : self::default_value( $field );
					echo self::render_field( $form['id'], $field, $value, $errors[ $field['id'] ] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo Spiral_Forms_Spam::render_fields( $form['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
				<div class="sf-submit-row">
					<button type="submit" class="sf-submit"><?php echo esc_html( $form['settings']['submit_text'] ); ?></button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function default_value( array $field ) {
		if ( 'checkbox' === $field['type'] ) {
			return '' === $field['default'] ? array() : array_map( 'trim', explode( ',', $field['default'] ) );
		}
		return $field['default'];
	}

	private static function render_field( $form_id, array $f, $value, $error ) {
		$id       = 'sf-' . $form_id . '-' . $f['id'];
		$name     = 'sf_fields[' . $f['id'] . ']';
		$required = ! empty( $f['required'] );
		$desc_id  = $id . '-desc';
		$err_id   = $id . '-error';
		$describe = trim( ( $f['description'] ? $desc_id : '' ) . ' ' . ( $error ? $err_id : '' ) );
		$aria     = ( $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '' )
			. ( $error ? ' aria-invalid="true"' : '' )
			. ( $required ? ' required aria-required="true"' : '' );
		$ph       = $f['placeholder'] ? ' placeholder="' . esc_attr( $f['placeholder'] ) . '"' : '';
		$is_group = in_array( $f['type'], array( 'checkbox', 'radio' ), true );

		$classes = 'sf-field sf-field-' . $f['type'] . ( $error ? ' sf-has-error' : '' ) . ( $f['css_class'] ? ' ' . $f['css_class'] : '' );
		$star    = $required ? ' <span class="sf-required" aria-hidden="true">*</span>' : '';
		$label   = esc_html( $f['label'] ) . $star;

		$html = '<div class="' . esc_attr( $classes ) . '">';

		if ( $is_group ) {
			$html .= '<fieldset' . ( $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '' ) . '><legend class="sf-label">' . $label . '</legend>';
		} else {
			$html .= '<label class="sf-label" for="' . esc_attr( $id ) . '">' . $label . '</label>';
		}

		$str = is_array( $value ) ? '' : (string) $value;

		switch ( $f['type'] ) {
			case 'textarea':
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="5"' . $ph . $aria . '>' . esc_textarea( $str ) . '</textarea>';
				break;

			case 'select':
				$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $aria . '>';
				$html .= '<option value="">' . esc_html( $f['placeholder'] ? $f['placeholder'] : __( '— Select —', 'spiral-forms' ) ) . '</option>';
				foreach ( $f['options'] as $opt ) {
					$html .= '<option value="' . esc_attr( $opt ) . '"' . selected( $str, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
				}
				$html .= '</select>';
				break;

			case 'checkbox':
			case 'radio':
				$checked_vals = (array) $value;
				$input_name   = 'checkbox' === $f['type'] ? $name . '[]' : $name;
				$html        .= '<div class="sf-choices">';
				foreach ( $f['options'] as $i => $opt ) {
					$oid   = $id . '-' . $i;
					$html .= '<label class="sf-choice" for="' . esc_attr( $oid ) . '"><input type="' . esc_attr( $f['type'] ) . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $input_name ) . '" value="' . esc_attr( $opt ) . '"'
						. ( in_array( $opt, $checked_vals, true ) ? ' checked' : '' )
						. ( 'radio' === $f['type'] && $required ? ' required' : '' ) . '> ' . esc_html( $opt ) . '</label>';
				}
				$html .= '</div>';
				break;

			default:
				$type_map = array(
					'name'   => 'text',
					'email'  => 'email',
					'phone'  => 'tel',
					'text'   => 'text',
					'number' => 'number',
					'date'   => 'date',
				);
				$auto_map = array(
					'name'  => 'name',
					'email' => 'email',
					'phone' => 'tel',
				);
				$type     = $type_map[ $f['type'] ] ?? 'text';
				$extra    = isset( $auto_map[ $f['type'] ] ) ? ' autocomplete="' . $auto_map[ $f['type'] ] . '"' : '';
				if ( 'number' === $f['type'] || 'date' === $f['type'] ) {
					$extra .= '' !== $f['min'] && 'number' === $f['type'] ? ' min="' . esc_attr( $f['min'] ) . '"' : '';
					$extra .= '' !== $f['max'] && 'number' === $f['type'] ? ' max="' . esc_attr( $f['max'] ) . '"' : '';
					$extra .= 'number' === $f['type'] ? ' step="any"' : '';
				}
				$html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $str ) . '"' . $ph . $extra . $aria . '>';
		}

		if ( $is_group ) {
			$html .= '</fieldset>';
		}
		if ( $f['description'] ) {
			$html .= '<div class="sf-description" id="' . esc_attr( $desc_id ) . '">' . wp_kses_post( $f['description'] ) . '</div>';
		}
		if ( $error ) {
			$html .= '<div class="sf-error" id="' . esc_attr( $err_id ) . '">' . esc_html( $error ) . '</div>';
		}
		$html .= '</div>';

		return $html;
	}
}
