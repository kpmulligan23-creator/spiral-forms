<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles POSTed submissions: validate → spam check → store → notify → confirm.
 *
 * Uses Post/Redirect/Get on success so refreshing doesn't resubmit. On errors the
 * page re-renders in the same request with values and messages preserved.
 */
class Spiral_Forms_Submission {

	/** @var array form_id => [ errors, values ] */
	private static $state = array();

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 5 );
		add_action( 'wp_ajax_spiral_forms_submit', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_spiral_forms_submit', array( __CLASS__, 'ajax' ) );
	}

	/**
	 * AJAX submit (used by assets/js/forms.js; the plain POST path is the no-JS fallback).
	 */
	public static function ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$form = Spiral_Forms_Forms::get( absint( $_POST['spiral_form_id'] ?? 0 ) );
		if ( ! $form ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'spiral-forms' ) ), 404 );
		}
		$post = wp_unslash( $_POST );
		// phpcs:enable

		$result = self::process( $form, $post );
		if ( ! $result['success'] ) {
			wp_send_json_error(
				array(
					'errors'  => $result['errors'],
					'message' => $result['errors']['_form'] ?? __( 'Please correct the errors below.', 'spiral-forms' ),
				)
			);
		}
		$s = $form['settings'];
		if ( 'redirect' === $s['confirmation_type'] && $s['redirect_url'] ) {
			wp_send_json_success( array( 'redirect' => $s['redirect_url'] ) );
		}
		wp_send_json_success( array( 'message' => wp_kses_post( wpautop( $result['message'] ) ) ) );
	}

	public static function state( $form_id ) {
		return self::$state[ $form_id ] ?? array();
	}

	public static function maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public forms on cached pages; protected by signed timestamp + honeypot + captcha.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['spiral_form_id'] ) ) {
			return;
		}
		$form = Spiral_Forms_Forms::get( absint( $_POST['spiral_form_id'] ) );
		if ( ! $form ) {
			return;
		}
		$post = wp_unslash( $_POST );
		// phpcs:enable

		$result = self::process( $form, $post );

		if ( true === $result['success'] ) {
			$s = $form['settings'];
			if ( 'redirect' === $s['confirmation_type'] && $s['redirect_url'] ) {
				wp_redirect( $s['redirect_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-configured, may be off-site.
				exit;
			}
			$token = wp_generate_password( 20, false );
			set_transient( 'sf_sent_' . $token, array( 'form_id' => $form['id'], 'message' => $result['message'] ), 10 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'sf_sent', $token, self::current_url() ) . '#sf-form-' . $form['id'] );
			exit;
		}

		self::$state[ $form['id'] ] = array(
			'errors' => $result['errors'],
			'values' => $result['values'],
		);
	}

	/**
	 * Confirmation message for the current request, or null.
	 */
	public static function success_message( array $form ) {
		$token = isset( $_GET['sf_sent'] ) ? sanitize_key( wp_unslash( $_GET['sf_sent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $token ) {
			return null;
		}
		$data = get_transient( 'sf_sent_' . $token );
		if ( ! is_array( $data ) || (int) $data['form_id'] !== (int) $form['id'] ) {
			return null;
		}
		return (string) $data['message'];
	}

	/**
	 * Core processing, separated from HTTP handling so it can be reused (AJAX, tests).
	 *
	 * @return array { success: bool, errors: array, values: array, message?: string, entry_id?: int }
	 */
	public static function process( array $form, array $post ) {
		$raw    = isset( $post['sf_fields'] ) && is_array( $post['sf_fields'] ) ? $post['sf_fields'] : array();
		$errors = array();
		$values = array();   // id => sanitized value (arrays for name/address parts), for re-rendering + conditions.
		$data   = array();   // id => [ id, label, type, value ] for storage/notifications (visible fields only).

		foreach ( $form['fields'] as $f ) {
			$values[ $f['id'] ] = self::sanitize_value( $f, $raw[ $f['id'] ] ?? null );
		}

		foreach ( $form['fields'] as $f ) {
			if ( ! Spiral_Forms_Forms::is_visible( $f, self::condition_values( $form, $values ) ) ) {
				continue; // Hidden by show/hide rules: not validated, not stored, not emailed.
			}
			$value = $values[ $f['id'] ];
			$error = self::validate_value( $f, $value );
			if ( $error ) {
				$errors[ $f['id'] ] = $error;
			}
			$data[ $f['id'] ] = array(
				'id'    => $f['id'],
				'label' => $f['label'],
				'type'  => $f['type'],
				'value' => self::storage_value( $f, $value ),
			);
		}

		$page_url = self::current_url();
		if ( wp_doing_ajax() && ! empty( $post['sf_page_url'] ) ) {
			$page_url = esc_url_raw( (string) $post['sf_page_url'] );
		}
		$meta = array(
			'ip'         => self::client_ip(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'page_url'   => $page_url,
			'user_id'    => get_current_user_id(),
		);

		$spam = Spiral_Forms_Spam::check( $post );
		if ( 'spam' === $spam ) {
			// Keep it for review (Entries → Spam), send nothing, and look successful to the bot.
			if ( ! empty( $form['settings']['store_entries'] ) && ! empty( $form['settings']['store_spam'] ) && $data ) {
				Spiral_Forms_Entries::insert( $form['id'], $data, $meta + array( 'status' => 'spam' ) );
			}
			return array(
				'success' => true,
				'errors'  => array(),
				'values'  => array(),
				'message' => $form['settings']['confirmation_message'],
			);
		}
		if ( true !== $spam ) {
			$errors['_form'] = $spam;
		}

		$errors = apply_filters( 'spiral_forms_validation_errors', $errors, $form, $data );
		if ( $errors ) {
			return array(
				'success' => false,
				'errors'  => $errors,
				'values'  => $values,
			);
		}

		$context = array(
			'form'     => $form,
			'values'   => $data,
			'entry_id' => 0,
			'page_url' => $meta['page_url'],
			'ip'       => $meta['ip'],
		);

		if ( ! empty( $form['settings']['store_entries'] ) ) {
			$context['entry_id'] = Spiral_Forms_Entries::insert( $form['id'], $data, $meta );
		}

		self::send_notifications( $form, $context );

		do_action( 'spiral_forms_submission_complete', $form, $data, $context['entry_id'], $context );

		return array(
			'success'  => true,
			'errors'   => array(),
			'values'   => array(),
			'entry_id' => $context['entry_id'],
			'message'  => Spiral_Forms_Smart_Tags::replace( $form['settings']['confirmation_message'], $context, 'html' ),
		);
	}

	/** Sub-inputs of composite fields. */
	public static function parts( array $f ) {
		if ( 'name' === $f['type'] && 'first-last' === $f['format'] ) {
			return array( 'first', 'last' );
		}
		if ( 'address' === $f['type'] ) {
			return array( 'address1', 'address2', 'city', 'state', 'postal' );
		}
		return array();
	}

	/** Values used by show/hide rules: composites flattened to one string. */
	private static function condition_values( array $form, array $values ) {
		$out = array();
		foreach ( $form['fields'] as $f ) {
			$v                = $values[ $f['id'] ] ?? '';
			$out[ $f['id'] ]  = self::parts( $f ) ? trim( implode( ' ', array_filter( (array) $v, 'strlen' ) ) ) : $v;
		}
		return $out;
	}

	/** Composite values become a readable string for storage/email/CSV. */
	private static function storage_value( array $f, $value ) {
		if ( 'name' === $f['type'] && is_array( $value ) ) {
			return trim( $value['first'] . ' ' . $value['last'] );
		}
		if ( 'address' === $f['type'] && is_array( $value ) ) {
			$city_line = trim( $value['city'] . ( $value['city'] && $value['state'] ? ', ' : ' ' ) . $value['state'] . ' ' . $value['postal'] );
			return implode( "\n", array_filter( array( $value['address1'], $value['address2'], $city_line ), 'strlen' ) );
		}
		return $value;
	}

	private static function sanitize_value( array $f, $raw ) {
		$parts = self::parts( $f );
		if ( $parts ) {
			$out = array();
			foreach ( $parts as $p ) {
				$v         = is_array( $raw ) && isset( $raw[ $p ] ) && is_scalar( $raw[ $p ] ) ? (string) $raw[ $p ] : '';
				$out[ $p ] = trim( sanitize_text_field( mb_substr( $v, 0, 500 ) ) );
			}
			return $out;
		}
		if ( 'checkbox' === $f['type'] ) {
			$vals = array();
			foreach ( (array) $raw as $v ) {
				if ( is_scalar( $v ) ) {
					$vals[] = sanitize_text_field( (string) $v );
				}
			}
			return array_values( array_filter( $vals, 'strlen' ) );
		}
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = mb_substr( (string) $raw, 0, 10000 );
		if ( 'textarea' === $f['type'] ) {
			return trim( sanitize_textarea_field( $raw ) );
		}
		if ( 'email' === $f['type'] ) {
			return trim( sanitize_email( $raw ) ) ? sanitize_email( $raw ) : trim( sanitize_text_field( $raw ) );
		}
		return trim( sanitize_text_field( $raw ) );
	}

	private static function validate_value( array $f, $value ) {
		if ( self::parts( $f ) ) {
			$required = 'address' === $f['type'] ? array( 'address1', 'city', 'state', 'postal' ) : array( 'first', 'last' );
			$any      = (bool) array_filter( $value, 'strlen' );
			if ( ! $any ) {
				return ! empty( $f['required'] ) ? __( 'This field is required.', 'spiral-forms' ) : '';
			}
			foreach ( $required as $p ) {
				if ( '' === $value[ $p ] && ( ! empty( $f['required'] ) || 'address' === $f['type'] ) ) {
					return 'address' === $f['type']
						? __( 'Please enter a complete address.', 'spiral-forms' )
						: __( 'Please enter your first and last name.', 'spiral-forms' );
				}
			}
			if ( 'address' === $f['type'] && ! preg_match( '/^\d{5}(-?\d{4})?$/', $value['postal'] ) ) {
				return __( 'Please enter a valid ZIP code.', 'spiral-forms' );
			}
			return '';
		}
		$empty = is_array( $value ) ? ! $value : '' === $value;
		if ( $empty ) {
			return ! empty( $f['required'] ) ? __( 'This field is required.', 'spiral-forms' ) : '';
		}

		switch ( $f['type'] ) {
			case 'email':
				return is_email( $value ) ? '' : __( 'Please enter a valid email address.', 'spiral-forms' );

			case 'phone':
				$digits = preg_replace( '/\D/', '', $value );
				return ( preg_match( '/^[0-9+().\-\s xextEXT]+$/', $value ) && strlen( $digits ) >= 7 && strlen( $digits ) <= 20 )
					? '' : __( 'Please enter a valid phone number.', 'spiral-forms' );

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return __( 'Please enter a number.', 'spiral-forms' );
				}
				if ( '' !== $f['min'] && $value < $f['min'] ) {
					/* translators: %s: minimum */
					return sprintf( __( 'Please enter a number of at least %s.', 'spiral-forms' ), $f['min'] );
				}
				if ( '' !== $f['max'] && $value > $f['max'] ) {
					/* translators: %s: maximum */
					return sprintf( __( 'Please enter a number no greater than %s.', 'spiral-forms' ), $f['max'] );
				}
				return '';

			case 'date':
				$d = DateTime::createFromFormat( 'Y-m-d', $value );
				return ( $d && $d->format( 'Y-m-d' ) === $value ) ? '' : __( 'Please enter a valid date.', 'spiral-forms' );

			case 'select':
			case 'radio':
				return in_array( $value, $f['options'], true ) ? '' : __( 'Please choose a valid option.', 'spiral-forms' );

			case 'checkbox':
				return array_diff( $value, $f['options'] ) ? __( 'Please choose valid options.', 'spiral-forms' ) : '';
		}
		return '';
	}

	private static function send_notifications( array $form, array $context ) {
		foreach ( $form['settings']['notifications'] as $n ) {
			if ( empty( $n['enabled'] ) ) {
				continue;
			}
			$to = self::emails_from( Spiral_Forms_Smart_Tags::replace( $n['to'], $context ) );
			if ( ! $to ) {
				continue;
			}

			$subject = wp_specialchars_decode( Spiral_Forms_Smart_Tags::replace( $n['subject'], $context ), ENT_QUOTES );
			$body    = Spiral_Forms_Smart_Tags::replace( $n['message'], $context, 'html' );
			$body    = self::email_template( wpautop( $body ), $form );

			$headers   = array( 'Content-Type: text/html; charset=UTF-8' );
			$from      = self::emails_from( Spiral_Forms_Smart_Tags::replace( $n['from_email'], $context ) );
			$from_name = trim( str_replace( array( "\r", "\n", '"' ), '', Spiral_Forms_Smart_Tags::replace( $n['from_name'], $context ) ) );
			if ( $from ) {
				$headers[] = 'From: ' . ( $from_name ? '"' . $from_name . '" ' : '' ) . '<' . $from[0] . '>';
			} elseif ( $from_name ) {
				$name_filter = static function () use ( $from_name ) {
					return $from_name;
				};
				add_filter( 'wp_mail_from_name', $name_filter );
			}
			$reply = self::emails_from( Spiral_Forms_Smart_Tags::replace( $n['reply_to'], $context ) );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . $reply[0];
			}

			$args = apply_filters(
				'spiral_forms_email_args',
				compact( 'to', 'subject', 'body', 'headers' ),
				$n,
				$form,
				$context
			);
			wp_mail( $args['to'], $args['subject'], $args['body'], $args['headers'] );

			if ( isset( $name_filter ) ) {
				remove_filter( 'wp_mail_from_name', $name_filter );
				unset( $name_filter );
			}
		}
	}

	private static function emails_from( $str ) {
		$out = array();
		foreach ( preg_split( '/[,;\s]+/', (string) $str ) as $e ) {
			$e = sanitize_email( $e );
			if ( is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function email_template( $content, array $form ) {
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f1f1f1;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;">'
			. '<div style="max-width:640px;margin:0 auto;background:#fff;padding:24px;border-radius:6px;">' . $content . '</div>'
			. '<p style="max-width:640px;margin:12px auto 0;color:#888;font-size:12px;text-align:center;">' . esc_html( $form['title'] ) . ' · ' . esc_html( get_bloginfo( 'name' ) ) . '</p>'
			. '</body></html>';
	}

	public static function current_url() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url  = ( is_ssl() ? 'https://' : 'http://' ) . $host . '/' . ltrim( $uri, '/' );
		return remove_query_arg( 'sf_sent', esc_url_raw( $url ) );
	}

	public static function client_ip() {
		$ip = '';
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$candidate = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
					break;
				}
			}
		}
		return apply_filters( 'spiral_forms_client_ip', $ip );
	}
}
