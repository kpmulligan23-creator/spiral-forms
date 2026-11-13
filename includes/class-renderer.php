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
		wp_localize_script(
			'spiral-forms',
			'SpiralForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'error'   => __( 'Something went wrong. Please try again.', 'spiral-forms' ),
					'sending' => __( 'Sending…', 'spiral-forms' ),
					'fix'     => __( 'Please correct the errors below.', 'spiral-forms' ),
					/* translators: %d: max number of files */
					'tooMany' => __( 'Please upload no more than %d file(s).', 'spiral-forms' ),
					/* translators: %s: file name */
					'tooBig'  => __( '"%s" is too large.', 'spiral-forms' ),
				),
			)
		);
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
			<?php $multipart = in_array( 'file', array_column( $form['fields'], 'type' ), true ); ?>
			<form class="sf-form" method="post" action="<?php echo esc_url( '#' . $dom_id ); ?>"<?php echo $multipart ? ' enctype="multipart/form-data"' : ''; ?> novalidate>
				<input type="hidden" name="spiral_form_id" value="<?php echo esc_attr( $form['id'] ); ?>">
				<?php
				$current = array();
				foreach ( $form['fields'] as $field ) {
					$has                     = array_key_exists( $field['id'], $values );
					$current[ $field['id'] ] = $has ? $values[ $field['id'] ] : self::default_value( $field );
				}
				$flat = array();
				foreach ( $current as $k => $v ) {
					$flat[ $k ] = is_array( $v ) && ! wp_is_numeric_array( $v ) ? trim( implode( ' ', array_filter( $v, 'strlen' ) ) ) : $v;
				}
				foreach ( $form['fields'] as $field ) {
					$visible = Spiral_Forms_Forms::is_visible( $field, $flat );
					echo self::render_field( $form['id'], $field, $current[ $field['id'] ], $errors[ $field['id'] ] ?? '', $visible ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		$parts = Spiral_Forms_Submission::parts( $field );
		if ( $parts ) {
			return array_fill_keys( $parts, '' );
		}
		if ( 'checkbox' === $field['type'] ) {
			return '' === $field['default'] ? array() : array_map( 'trim', explode( ',', $field['default'] ) );
		}
		return $field['default'];
	}

	private static function render_field( $form_id, array $f, $value, $error, $visible = true ) {
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
		$parts    = Spiral_Forms_Submission::parts( $f );
		$is_group = in_array( $f['type'], array( 'checkbox', 'radio' ), true ) || $parts;

		$classes = 'sf-field sf-field-' . $f['type'] . ( $error ? ' sf-has-error' : '' ) . ( $f['css_class'] ? ' ' . $f['css_class'] : '' );
		$star    = $required ? ' <span class="sf-required" aria-hidden="true">*</span>' : '';
		$label   = esc_html( $f['label'] ) . $star;

		$cond  = Spiral_Forms_Forms::normalize_conditional( $f['conditional'] );
		$attrs = ' data-sf-field="' . esc_attr( $f['id'] ) . '"';
		if ( $cond['enabled'] ) {
			$attrs .= ' data-sf-cond="' . esc_attr( wp_json_encode( $cond ) ) . '"';
		}
		if ( ! $visible ) {
			$attrs .= ' hidden';
		}
		$html = '<div class="' . esc_attr( $classes ) . '"' . $attrs . '>';

		if ( $is_group ) {
			$html .= '<fieldset' . ( $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '' ) . '><legend class="sf-label">' . $label . '</legend>';
		} else {
			$html .= '<label class="sf-label" for="' . esc_attr( $id ) . '">' . $label . '</label>';
		}

		$str = is_array( $value ) ? '' : (string) $value;

		if ( $parts ) {
			$value  = is_array( $value ) ? $value : array();
			$labels = array(
				'first'    => array( __( 'First', 'spiral-forms' ), 'given-name', 'sf-col-half' ),
				'last'     => array( __( 'Last', 'spiral-forms' ), 'family-name', 'sf-col-half' ),
				'address1' => array( __( 'Address Line 1', 'spiral-forms' ), 'address-line1', 'sf-col-full' ),
				'address2' => array( __( 'Address Line 2', 'spiral-forms' ), 'address-line2', 'sf-col-full' ),
				'city'     => array( __( 'City', 'spiral-forms' ), 'address-level2', 'sf-col-half' ),
				'state'    => array( __( 'State', 'spiral-forms' ), 'address-level1', 'sf-col-quarter' ),
				'postal'   => array( __( 'ZIP Code', 'spiral-forms' ), 'postal-code', 'sf-col-quarter' ),
			);
			$html .= '<div class="sf-parts">';
			foreach ( $parts as $p ) {
				$pid   = $id . '-' . $p;
				$req   = $required && 'address2' !== $p;
				$html .= '<div class="sf-part ' . esc_attr( $labels[ $p ][2] ) . '">'
					. '<input type="text" id="' . esc_attr( $pid ) . '" name="' . esc_attr( $name . '[' . $p . ']' ) . '" value="' . esc_attr( $value[ $p ] ?? '' ) . '" autocomplete="' . esc_attr( $labels[ $p ][1] ) . '"'
					. ( 'state' === $p ? ' maxlength="2"' : '' ) . ( 'postal' === $p ? ' inputmode="numeric"' : '' )
					. ( $req ? ' required aria-required="true"' : '' ) . ( $error ? ' aria-invalid="true"' : '' ) . '>'
					. '<label class="sf-sublabel" for="' . esc_attr( $pid ) . '">' . esc_html( $labels[ $p ][0] ) . '</label></div>';
			}
			$html .= '</div>';
		}

		switch ( $parts ? '_parts' : $f['type'] ) {
			case '_parts':
				break;

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

			case 'file':
				$exts  = Spiral_Forms_Uploads::extensions( $f );
				$max   = Spiral_Forms_Uploads::max_files( $f );
				$bytes = Spiral_Forms_Uploads::max_bytes( $f );
				$html .= '<input type="file" id="' . esc_attr( $id ) . '" name="' . esc_attr( 'sf_files[' . $f['id'] . '][]' ) . '"'
					. ( $max > 1 ? ' multiple' : '' )
					. ' accept="' . esc_attr( implode( ',', array_map( static function ( $e ) { return '.' . $e; }, $exts ) ) ) . '"'
					. ' data-max-files="' . (int) $max . '" data-max-bytes="' . (int) $bytes . '"' . $aria . '>';
				$html .= '<div class="sf-file-hint">' . esc_html(
					sprintf(
						/* translators: 1: file count, 2: size, 3: extensions */
						_n( 'Up to %1$d file, %2$s max. Allowed: %3$s', 'Up to %1$d files, %2$s each. Allowed: %3$s', $max, 'spiral-forms' ),
						$max,
						size_format( $bytes ),
						implode( ', ', $exts )
					)
				) . '</div>';
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
