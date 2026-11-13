<?php
defined( 'ABSPATH' ) || exit;

/**
 * Form storage (custom post type + JSON definition in post meta) and field type registry.
 */
class Spiral_Forms_Forms {

	const POST_TYPE = 'spiral_form';
	const META_KEY  = '_spiral_form_data';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Forms', 'spiral-forms' ),
					'singular_name' => __( 'Form', 'spiral-forms' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'rewrite'         => false,
			)
		);
	}

	/**
	 * Field types: key => [ label, has_options ].
	 */
	public static function field_types() {
		return apply_filters(
			'spiral_forms_field_types',
			array(
				'name'     => array( 'label' => __( 'Name', 'spiral-forms' ), 'options' => false ),
				'email'    => array( 'label' => __( 'Email', 'spiral-forms' ), 'options' => false ),
				'phone'    => array( 'label' => __( 'Phone', 'spiral-forms' ), 'options' => false ),
				'text'     => array( 'label' => __( 'Single Line Text', 'spiral-forms' ), 'options' => false ),
				'textarea' => array( 'label' => __( 'Paragraph Text', 'spiral-forms' ), 'options' => false ),
				'select'   => array( 'label' => __( 'Dropdown', 'spiral-forms' ), 'options' => true ),
				'checkbox' => array( 'label' => __( 'Checkboxes', 'spiral-forms' ), 'options' => true ),
				'radio'    => array( 'label' => __( 'Multiple Choice', 'spiral-forms' ), 'options' => true ),
				'number'   => array( 'label' => __( 'Number', 'spiral-forms' ), 'options' => false ),
				'date'     => array( 'label' => __( 'Date', 'spiral-forms' ), 'options' => false ),
				'address'  => array( 'label' => __( 'Address', 'spiral-forms' ), 'options' => false ),
				'file'     => array( 'label' => __( 'File Upload', 'spiral-forms' ), 'options' => false ),
			)
		);
	}

	public static function default_settings() {
		return array(
			'submit_text'          => __( 'Submit', 'spiral-forms' ),
			'confirmation_type'    => 'message', // message | redirect.
			'confirmation_message' => __( 'Thanks! Your message has been sent.', 'spiral-forms' ),
			'redirect_url'         => '',
			'store_entries'        => 1,
			'store_spam'           => 1,
			'retention_days'       => 0,
			'css_class'            => '',
			'notifications'        => array(
				array(
					'enabled'    => 1,
					'label'      => __( 'Admin Notification', 'spiral-forms' ),
					'to'         => '{admin_email}',
					'subject'    => __( 'New entry: {form_name}', 'spiral-forms' ),
					'message'    => '{all_fields}',
					'from_name'  => '{site_name}',
					'from_email' => '',
					'reply_to'   => '',
				),
				array(
					'enabled'    => 0,
					'label'      => __( 'Autoresponder', 'spiral-forms' ),
					'to'         => '',
					'subject'    => __( 'Thanks for contacting {site_name}', 'spiral-forms' ),
					'message'    => __( "Hi,\n\nThanks for reaching out — we received your message and will be in touch soon.\n\n{all_fields}", 'spiral-forms' ),
					'from_name'  => '{site_name}',
					'from_email' => '',
					'reply_to'   => '{admin_email}',
				),
			),
		);
	}

	public static function default_fields() {
		return array(
			array( 'id' => 'name', 'type' => 'name', 'label' => __( 'Name', 'spiral-forms' ), 'required' => 1 ),
			array( 'id' => 'email', 'type' => 'email', 'label' => __( 'Email', 'spiral-forms' ), 'required' => 1 ),
			array( 'id' => 'message', 'type' => 'textarea', 'label' => __( 'Message', 'spiral-forms' ), 'required' => 1 ),
		);
	}

	/**
	 * Return a normalized form array or null.
	 *
	 * @param int $form_id Form post ID.
	 * @return array|null { id, title, fields, settings }
	 */
	public static function get( $form_id ) {
		$post = get_post( (int) $form_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		$data = json_decode( (string) get_post_meta( $post->ID, self::META_KEY, true ), true );
		$data = is_array( $data ) ? $data : array();

		$fields   = isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array();
		$settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array();

		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'fields'   => array_map( array( __CLASS__, 'normalize_field' ), $fields ),
			'settings' => wp_parse_args( $settings, self::default_settings() ),
		);
	}

	public static function normalize_field( $field ) {
		return wp_parse_args(
			(array) $field,
			array(
				'id'          => '',
				'type'        => 'text',
				'label'       => '',
				'description' => '',
				'placeholder' => '',
				'default'     => '',
				'required'    => 0,
				'options'     => array(),
				'css_class'   => '',
				'min'         => '',
				'max'         => '',
				'format'      => '',
				'conditional' => array(),
				'extensions'  => '',
				'max_size'    => '',
				'max_files'   => 1,
			)
		);
	}

	/**
	 * Normalize a field's show/hide rules.
	 *
	 * @return array { enabled, action: show|hide, match: all|any, rules: [ { field, operator: is|is_not|empty|not_empty, value } ] }
	 */
	public static function normalize_conditional( $c ) {
		$c     = is_array( $c ) ? $c : array();
		$rules = array();
		foreach ( (array) ( $c['rules'] ?? array() ) as $r ) {
			$r  = (array) $r;
			$op = in_array( $r['operator'] ?? '', array( 'is', 'is_not', 'empty', 'not_empty', 'contains' ), true ) ? $r['operator'] : 'is';
			if ( empty( $r['field'] ) ) {
				continue;
			}
			$rules[] = array(
				'field'    => sanitize_key( $r['field'] ),
				'operator' => $op,
				'value'    => sanitize_text_field( (string) ( $r['value'] ?? '' ) ),
			);
		}
		return array(
			'enabled' => ! empty( $c['enabled'] ) && $rules ? 1 : 0,
			'action'  => ( $c['action'] ?? '' ) === 'hide' ? 'hide' : 'show',
			'match'   => ( $c['match'] ?? '' ) === 'any' ? 'any' : 'all',
			'rules'   => $rules,
		);
	}

	/**
	 * Is a field visible given the submitted values? Mirrors assets/js/forms.js.
	 *
	 * @param array $field  Normalized field.
	 * @param array $values field id => value (string|array).
	 */
	public static function is_visible( array $field, array $values ) {
		$c = self::normalize_conditional( $field['conditional'] );
		if ( ! $c['enabled'] ) {
			return true;
		}
		$results = array();
		foreach ( $c['rules'] as $r ) {
			$v    = $values[ $r['field'] ] ?? '';
			$list = is_array( $v ) ? array_map( 'strval', $v ) : ( '' === (string) $v ? array() : array( (string) $v ) );
			switch ( $r['operator'] ) {
				case 'empty':
					$ok = ! $list;
					break;
				case 'not_empty':
					$ok = (bool) $list;
					break;
				case 'is_not':
					$ok = ! in_array( $r['value'], $list, true );
					break;
				case 'contains':
					$ok = false !== stripos( implode( ' ', $list ), $r['value'] );
					break;
				default:
					$ok = in_array( $r['value'], $list, true );
			}
			$results[] = $ok;
		}
		$match = 'any' === $c['match'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
		return 'show' === $c['action'] ? $match : ! $match;
	}

	/**
	 * Sanitize a raw form definition coming from the builder.
	 */
	public static function sanitize( array $data ) {
		$types  = self::field_types();
		$fields = array();
		$seen   = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $raw ) {
			$f    = self::normalize_field( $raw );
			$type = isset( $types[ $f['type'] ] ) ? $f['type'] : 'text';
			$id   = sanitize_key( trim( preg_replace( '/[^A-Za-z0-9_\-]+/', '_', (string) ( $f['id'] ? $f['id'] : $f['label'] ) ), '_' ) );
			$id   = substr( $id, 0, 40 );
			$id   = $id ? $id : 'field';
			$base = $id;
			$i    = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = $base . '_' . $i++;
			}
			$seen[ $id ] = true;

			$options = array();
			if ( $types[ $type ]['options'] ) {
				foreach ( (array) $f['options'] as $opt ) {
					$opt = trim( sanitize_text_field( is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt ) );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
			}

			$fields[] = array(
				'id'          => $id,
				'type'        => $type,
				'label'       => sanitize_text_field( $f['label'] ),
				'description' => wp_kses_post( $f['description'] ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ),
				'default'     => sanitize_text_field( $f['default'] ),
				'required'    => empty( $f['required'] ) ? 0 : 1,
				'options'     => $options,
				'css_class'   => sanitize_html_class_list( $f['css_class'] ),
				'min'         => is_numeric( $f['min'] ) ? $f['min'] + 0 : '',
				'max'         => is_numeric( $f['max'] ) ? $f['max'] + 0 : '',
				'format'      => 'name' === $type && 'first-last' === $f['format'] ? 'first-last' : '',
				'conditional' => self::normalize_conditional( $f['conditional'] ),
				'extensions'  => 'file' === $type ? implode( ',', Spiral_Forms_Uploads::extensions( array( 'extensions' => $f['extensions'] ) ) ) : '',
				'max_size'    => 'file' === $type && is_numeric( $f['max_size'] ) && $f['max_size'] > 0 ? $f['max_size'] + 0 : '',
				'max_files'   => 'file' === $type ? max( 1, min( 20, (int) $f['max_files'] ) ) : 1,
			);
		}

		// Drop rules that point at missing fields or at the field itself.
		$ids = array_column( $fields, 'id' );
		foreach ( $fields as &$field ) {
			$field['conditional']['rules'] = array_values(
				array_filter(
					$field['conditional']['rules'],
					static function ( $r ) use ( $ids, $field ) {
						return in_array( $r['field'], $ids, true ) && $r['field'] !== $field['id'];
					}
				)
			);
			if ( ! $field['conditional']['rules'] ) {
				$field['conditional']['enabled'] = 0;
			}
		}
		unset( $field );

		$s        = wp_parse_args( (array) ( $data['settings'] ?? array() ), self::default_settings() );
		$notifs   = array();
		foreach ( (array) $s['notifications'] as $n ) {
			$n        = (array) $n;
			$notifs[] = array(
				'enabled'    => empty( $n['enabled'] ) ? 0 : 1,
				'label'      => sanitize_text_field( $n['label'] ?? '' ),
				'to'         => sanitize_text_field( $n['to'] ?? '' ),
				'subject'    => sanitize_text_field( $n['subject'] ?? '' ),
				'message'    => wp_kses_post( $n['message'] ?? '' ),
				'from_name'  => sanitize_text_field( $n['from_name'] ?? '' ),
				'from_email' => sanitize_text_field( $n['from_email'] ?? '' ),
				'reply_to'   => sanitize_text_field( $n['reply_to'] ?? '' ),
				'attach_files' => empty( $n['attach_files'] ) ? 0 : 1,
			);
		}

		$settings = array(
			'submit_text'          => sanitize_text_field( $s['submit_text'] ),
			'confirmation_type'    => in_array( $s['confirmation_type'], array( 'message', 'redirect' ), true ) ? $s['confirmation_type'] : 'message',
			'confirmation_message' => wp_kses_post( $s['confirmation_message'] ),
			'redirect_url'         => esc_url_raw( $s['redirect_url'] ),
			'store_entries'        => empty( $s['store_entries'] ) ? 0 : 1,
			'store_spam'           => empty( $s['store_spam'] ) ? 0 : 1,
			'retention_days'       => max( 0, (int) $s['retention_days'] ),
			'css_class'            => sanitize_html_class_list( $s['css_class'] ),
			'notifications'        => $notifs,
		);

		return array(
			'fields'   => $fields,
			'settings' => $settings,
		);
	}

	/**
	 * Create or update a form. Returns the post ID or WP_Error.
	 */
	public static function save( $form_id, $title, array $data ) {
		$clean = self::sanitize( $data );
		$args  = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => sanitize_text_field( $title ? $title : __( 'Untitled Form', 'spiral-forms' ) ),
			'post_status' => 'publish',
		);
		if ( $form_id ) {
			$args['ID'] = (int) $form_id;
			$result     = wp_update_post( $args, true );
		} else {
			$result = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( $result, self::META_KEY, wp_slash( wp_json_encode( $clean ) ) );
		return (int) $result;
	}

	public static function all() {
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}
}

if ( ! function_exists( 'sanitize_html_class_list' ) ) {
	/**
	 * Sanitize a space-separated list of CSS classes.
	 */
	function sanitize_html_class_list( $classes ) {
		$out = array();
		foreach ( preg_split( '/\s+/', (string) $classes ) as $c ) {
			$c = sanitize_html_class( $c );
			if ( $c ) {
				$out[] = $c;
			}
		}
		return implode( ' ', $out );
	}
}
