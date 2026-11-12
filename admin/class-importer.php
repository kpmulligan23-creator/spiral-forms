<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPForms → Spiral Forms importer (Spiral Forms → Import).
 *
 * Workflow per site:
 *   1. Import a form (creates a Spiral form + copies its entries). WPForms is untouched.
 *   2. Test the new form on a draft page.
 *   3. "Swap embeds" replaces [wpforms id=X] / the WPForms block in posts with the Spiral form.
 *   4. Re-run "Import new entries" any time to pick up entries that arrived in WPForms meanwhile.
 */
class Spiral_Forms_Importer {

	const META_SOURCE    = '_spiral_imported_from_wpforms';
	const META_LAST_ENTRY = '_spiral_wpforms_last_entry';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_spiral_forms_import', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_submenu_page( 'spiral-forms', __( 'Import', 'spiral-forms' ), __( 'Import', 'spiral-forms' ), Spiral_Forms_Admin::CAP, 'spiral-forms-import', array( __CLASS__, 'page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Discovery                                                           */
	/* ------------------------------------------------------------------ */

	public static function wpforms_forms() {
		return get_posts(
			array(
				'post_type'      => 'wpforms',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	public static function wpforms_data( $post ) {
		$data = json_decode( (string) $post->post_content, true );
		return is_array( $data ) ? $data : array();
	}

	private static function entries_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforms_entries';
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : '';
	}

	public static function count_wpforms_entries( $wpforms_id, $after = 0 ) {
		global $wpdb;
		$table = self::entries_table();
		if ( ! $table ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND entry_id > %d", $wpforms_id, $after ) ); // phpcs:ignore
	}

	public static function imported_form_for( $wpforms_id ) {
		$found = get_posts(
			array(
				'post_type'      => Spiral_Forms_Forms::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => self::META_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => (int) $wpforms_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
			)
		);
		return $found ? (int) $found[0] : 0;
	}

	/** Posts that embed a given WPForms form (shortcode or block). */
	public static function find_embeds( $wpforms_id ) {
		global $wpdb;
		$id   = (int) $wpforms_id;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
				WHERE post_status IN ('publish','draft','private','future','pending')
				AND post_type NOT IN ('revision','wpforms')
				AND (post_content LIKE %s OR post_content LIKE %s)",
				'%[wpforms%',
				'%wp:wpforms/form-selector%'
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			if ( self::replace_embeds( $r->post_content, $id, 0, true ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Replace (or, with $dry, just detect) embeds of a WPForms form in content.
	 *
	 * @return string|bool New content, or bool when $dry.
	 */
	public static function replace_embeds( $content, $wpforms_id, $spiral_id, $dry = false ) {
		$id        = (int) $wpforms_id;
		$shortcode = '/\[wpforms\s[^\]]*\bid\s*=\s*(["\']?)' . $id . '\1[^\]]*\]/i';
		$block     = '/<!--\s*wp:wpforms\/form-selector\s+(\{[^}]*"formId"\s*:\s*"?' . $id . '"?[^}]*\})\s*\/-->/i';

		if ( $dry ) {
			return (bool) ( preg_match( $shortcode, $content ) || preg_match( $block, $content ) );
		}
		$content = preg_replace( $shortcode, '[spiral_form id="' . (int) $spiral_id . '"]', $content );
		$content = preg_replace( $block, '<!-- wp:spiral-forms/form {"formId":' . (int) $spiral_id . '} /-->', $content );
		return $content;
	}

	/* ------------------------------------------------------------------ */
	/* Conversion                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Convert a WPForms definition to a Spiral definition.
	 *
	 * @return array { title, data: {fields, settings}, map: wpf_id => spiral_id, warnings: string[] }
	 */
	public static function convert( array $wpf ) {
		$warnings = array();
		$fields   = array();
		$map      = array();
		$used     = array();
		$choices  = array(); // wpf field id => [ choice key => label ].

		$type_map = array(
			'name'          => 'name',
			'email'         => 'email',
			'phone'         => 'phone',
			'text'          => 'text',
			'textarea'      => 'textarea',
			'select'        => 'select',
			'checkbox'      => 'checkbox',
			'radio'         => 'radio',
			'number'        => 'number',
			'number-slider' => 'number',
			'date-time'     => 'date',
			'address'       => 'address',
			'url'           => 'text',
			'gdpr-checkbox' => 'checkbox',
		);
		$silent_skip = array( 'divider', 'html', 'content', 'pagebreak', 'captcha', 'internal-information', 'entry-preview' );

		foreach ( (array) ( $wpf['fields'] ?? array() ) as $wf ) {
			$wf   = (array) $wf;
			$type = (string) ( $wf['type'] ?? '' );
			$wid  = (string) ( $wf['id'] ?? '' );

			if ( in_array( $type, $silent_skip, true ) ) {
				continue;
			}
			if ( ! isset( $type_map[ $type ] ) ) {
				/* translators: 1: field label, 2: field type */
				$warnings[] = sprintf( __( 'Skipped field "%1$s" — type "%2$s" is not supported yet.', 'spiral-forms' ), $wf['label'] ?? $wid, $type );
				continue;
			}

			$label = (string) ( $wf['label'] ?? '' );
			$base  = sanitize_key( trim( preg_replace( '/[^A-Za-z0-9_\-]+/', '_', $label ? $label : $type ), '_' ) );
			$base  = substr( $base ? $base : 'field', 0, 40 );
			$sid   = $base;
			$n     = 2;
			while ( isset( $used[ $sid ] ) ) {
				$sid = $base . '_' . $n++;
			}
			$used[ $sid ] = true;
			$map[ $wid ]  = $sid;

			$opts = array();
			if ( ! empty( $wf['choices'] ) && is_array( $wf['choices'] ) ) {
				foreach ( $wf['choices'] as $key => $c ) {
					$c_label = is_array( $c ) ? (string) ( $c['label'] ?? '' ) : (string) $c;
					if ( '' !== $c_label ) {
						$opts[]                  = $c_label;
						$choices[ $wid ][ $key ] = $c_label;
					}
				}
			}
			if ( 'gdpr-checkbox' === $type && ! $opts ) {
				$opts = array( $label );
			}

			$f = array(
				'id'          => $sid,
				'type'        => $type_map[ $type ],
				'label'       => $label,
				'description' => (string) ( $wf['description'] ?? '' ),
				'placeholder' => (string) ( $wf['placeholder'] ?? ( $wf['simple_placeholder'] ?? '' ) ),
				'default'     => is_scalar( $wf['default_value'] ?? '' ) ? (string) ( $wf['default_value'] ?? '' ) : '',
				'required'    => ! empty( $wf['required'] ) ? 1 : 0,
				'options'     => $opts,
				'css_class'   => (string) ( $wf['css'] ?? '' ),
				'min'         => $wf['min'] ?? '',
				'max'         => $wf['max'] ?? '',
				'format'      => 'name' === $type && 'simple' !== ( $wf['format'] ?? 'first-last' ) ? 'first-last' : '',
				'_wpf'        => $wf,
			);
			if ( 'name' === $type && 'first-middle-last' === ( $wf['format'] ?? '' ) ) {
				/* translators: %s: field label */
				$warnings[] = sprintf( __( '"%s": middle name box dropped (first + last kept).', 'spiral-forms' ), $label );
			}
			if ( 'date-time' === $type && 'date' !== ( $wf['format'] ?? 'date' ) ) {
				/* translators: %s: field label */
				$warnings[] = sprintf( __( '"%s": time part dropped (date only).', 'spiral-forms' ), $label );
			}
			if ( 'address' === $type && 'us' !== ( $wf['scheme'] ?? 'us' ) ) {
				/* translators: %s: field label */
				$warnings[] = sprintf( __( '"%s": converted to a US-style address.', 'spiral-forms' ), $label );
			}
			$fields[] = $f;
		}

		// Conditional logic (needs the full id map, so a second pass).
		$op_map = array(
			'=='  => 'is',
			'!='  => 'is_not',
			'e'   => 'empty',
			'!e'  => 'not_empty',
			'c'   => 'contains',
		);
		foreach ( $fields as &$f ) {
			$wf = $f['_wpf'];
			unset( $f['_wpf'] );
			if ( empty( $wf['conditional_logic'] ) || empty( $wf['conditionals'] ) ) {
				continue;
			}
			$groups = array_values( array_filter( (array) $wf['conditionals'] ) );
			$rules  = array();
			$lossy  = false;
			$match  = 'all';
			if ( count( $groups ) > 1 ) {
				$single = array_filter(
					$groups,
					static function ( $g ) {
						return 1 === count( (array) $g );
					}
				);
				if ( count( $single ) === count( $groups ) ) {
					$match = 'any';
					$flat  = array();
					foreach ( $groups as $g ) {
						$flat[] = reset( $g );
					}
					$groups = array( $flat );
				} else {
					$lossy  = true;
					$groups = array( $groups[0] );
				}
			}
			foreach ( (array) $groups[0] as $r ) {
				$r   = (array) $r;
				$wid = (string) ( $r['field'] ?? '' );
				$op  = (string) ( $r['operator'] ?? '==' );
				if ( ! isset( $map[ $wid ], $op_map[ $op ] ) ) {
					$lossy = true;
					continue;
				}
				$val = (string) ( $r['value'] ?? '' );
				if ( isset( $choices[ $wid ][ $val ] ) ) {
					$val = $choices[ $wid ][ $val ]; // WPForms stores the choice key; we compare labels.
				}
				$rules[] = array(
					'field'    => $map[ $wid ],
					'operator' => $op_map[ $op ],
					'value'    => $val,
				);
			}
			if ( $rules ) {
				$f['conditional'] = array(
					'enabled' => 1,
					'action'  => 'hide' === ( $wf['conditional_type'] ?? 'show' ) ? 'hide' : 'show',
					'match'   => $match,
					'rules'   => $rules,
				);
			}
			if ( $lossy || ! $rules ) {
				/* translators: %s: field label */
				$warnings[] = sprintf( __( '"%s": some show/hide rules could not be converted — please check them.', 'spiral-forms' ), $f['label'] );
			}
		}
		unset( $f );

		$ws       = (array) ( $wpf['settings'] ?? array() );
		$tags     = static function ( $text ) use ( $map ) {
			return self::convert_tags( (string) $text, $map );
		};
		$notifs   = array();
		$enabled  = ! isset( $ws['notification_enable'] ) || ! empty( $ws['notification_enable'] );
		foreach ( (array) ( $ws['notifications'] ?? array() ) as $n ) {
			$n        = (array) $n;
			$notifs[] = array(
				'enabled'    => $enabled && ( ! isset( $n['enable'] ) || ! empty( $n['enable'] ) ) ? 1 : 0,
				'label'      => (string) ( $n['notification_name'] ?? __( 'Notification', 'spiral-forms' ) ),
				'to'         => $tags( $n['email'] ?? '{admin_email}' ),
				'subject'    => $tags( $n['subject'] ?? '' ),
				'message'    => $tags( $n['message'] ?? '{all_fields}' ),
				'from_name'  => $tags( $n['sender_name'] ?? '' ),
				'from_email' => $tags( $n['sender_address'] ?? '' ),
				'reply_to'   => $tags( $n['replyto'] ?? '' ),
			);
			if ( ! empty( $n['file_upload_attachment_fields'] ) || ! empty( $n['entry_csv_attachment_enable'] ) ) {
				$warnings[] = __( 'Notification attachments are not supported yet and were left out.', 'spiral-forms' );
			}
			if ( ! empty( $n['conditional_logic'] ) ) {
				/* translators: %s: notification name */
				$warnings[] = sprintf( __( 'Notification "%s" had conditions; it will now always send.', 'spiral-forms' ), $n['notification_name'] ?? '' );
			}
		}

		$confs    = array_values( (array) ( $ws['confirmations'] ?? array() ) );
		$conf     = $confs ? (array) $confs[0] : array();
		if ( count( $confs ) > 1 ) {
			$warnings[] = __( 'Only the first confirmation was imported.', 'spiral-forms' );
		}
		$ctype    = (string) ( $conf['type'] ?? 'message' );
		$redirect = '';
		if ( 'redirect' === $ctype ) {
			$redirect = (string) ( $conf['redirect'] ?? '' );
		} elseif ( 'page' === $ctype && ! empty( $conf['page'] ) && is_numeric( $conf['page'] ) ) {
			$redirect = (string) get_permalink( (int) $conf['page'] );
		}

		$settings = array(
			'submit_text'          => (string) ( $ws['submit_text'] ?? __( 'Submit', 'spiral-forms' ) ),
			'confirmation_type'    => $redirect ? 'redirect' : 'message',
			'confirmation_message' => $tags( $conf['message'] ?? '' ),
			'redirect_url'         => $redirect,
			'store_entries'        => empty( $ws['disable_entries'] ) ? 1 : 0,
			'store_spam'           => isset( $ws['store_spam_entries'] ) ? ( empty( $ws['store_spam_entries'] ) ? 0 : 1 ) : 1,
			'retention_days'       => (int) ( $ws['purge_entries_days'] ?? 0 ),
			'css_class'            => (string) ( $ws['form_class'] ?? '' ),
			'notifications'        => $notifs,
		);

		$providers = array_filter( (array) ( $wpf['providers'] ?? array() ) );
		if ( $providers ) {
			/* translators: %s: integration names */
			$warnings[] = sprintf( __( 'Integrations not imported: %s.', 'spiral-forms' ), implode( ', ', array_keys( $providers ) ) );
		}

		return array(
			'title'    => (string) ( $ws['form_title'] ?? '' ),
			'data'     => array(
				'fields'   => $fields,
				'settings' => $settings,
			),
			'map'      => $map,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}

	/** {field_id="3"} → {field:email}, {date format="…"} → {date}. */
	public static function convert_tags( $text, array $map ) {
		$text = preg_replace_callback(
			'/\{field_(?:id|value_id|html_id)=["\']?(\d+)["\']?\}/',
			static function ( $m ) use ( $map ) {
				return isset( $map[ $m[1] ] ) ? '{field:' . $map[ $m[1] ] . '}' : '';
			},
			$text
		);
		$text = preg_replace( '/\{date\s+format=[^}]*\}/', '{date}', $text );
		$text = str_replace( array( '{url_referer}', '{page_title}' ), array( '{page_url}', '{form_name}' ), $text );
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Copy WPForms entries newer than the last import into a Spiral form.
	 *
	 * @return int Number imported.
	 */
	public static function import_entries( $wpforms_id, $spiral_id, array $map, array $wpf_fields ) {
		global $wpdb;
		$table = self::entries_table();
		if ( ! $table ) {
			return 0;
		}
		$last  = (int) get_post_meta( $spiral_id, self::META_LAST_ENTRY, true );
		$count = 0;

		$labels = array();
		$types  = array();
		foreach ( $wpf_fields as $wf ) {
			$labels[ (string) $wf['id'] ] = (string) ( $wf['label'] ?? '' );
			$types[ (string) $wf['id'] ]  = (string) ( $wf['type'] ?? '' );
		}

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d AND entry_id > %d ORDER BY entry_id ASC LIMIT 500", $wpforms_id, $last ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $row ) {
				$wfields = json_decode( (string) $row['fields'], true );
				$fields  = array();
				foreach ( (array) $wfields as $wid => $v ) {
					$v    = (array) $v;
					$wid  = (string) ( $v['id'] ?? $wid );
					$sid  = $map[ $wid ] ?? 'wpf_' . $wid;
					$val  = $v['value'] ?? '';
					if ( 'checkbox' === ( $v['type'] ?? '' ) && is_string( $val ) && '' !== $val ) {
						$val = array_map( 'trim', explode( "\n", $val ) );
					}
					$fields[] = array(
						'id'    => $sid,
						'label' => (string) ( $labels[ $wid ] ?? ( $v['name'] ?? $sid ) ),
						'type'  => (string) ( $v['type'] ?? ( $types[ $wid ] ?? 'text' ) ),
						'value' => $val,
					);
				}
				$status = in_array( $row['status'], array( 'spam', 'trash' ), true ) ? 'spam' : '';
				if ( 'trash' === $row['status'] ) {
					$last = (int) $row['entry_id'];
					continue;
				}
				$new_id = Spiral_Forms_Entries::insert(
					$spiral_id,
					$fields,
					array(
						'ip'         => (string) ( $row['ip_address'] ?? '' ),
						'user_agent' => (string) ( $row['user_agent'] ?? '' ),
						'page_url'   => ! empty( $row['post_id'] ) ? (string) get_permalink( (int) $row['post_id'] ) : '',
						'user_id'    => (int) ( $row['user_id'] ?? 0 ),
						'status'     => $status,
						'created_at' => (string) $row['date'],
					)
				);
				if ( $new_id && ! empty( $row['viewed'] ) ) {
					Spiral_Forms_Entries::mark_read( $new_id );
				}
				$last = (int) $row['entry_id'];
				++$count;
			}
			update_post_meta( $spiral_id, self::META_LAST_ENTRY, $last );
		} while ( $rows && count( $rows ) === 500 );

		return $count;
	}

	/** Copy WPForms CAPTCHA keys if Spiral Forms has none configured. Returns a message or ''. */
	public static function copy_captcha() {
		$ours = Spiral_Forms_Settings::all();
		if ( 'none' !== $ours['captcha_provider'] ) {
			return '';
		}
		$w = (array) get_option( 'wpforms_settings', array() );
		$p = $w['captcha-provider'] ?? '';
		if ( 'recaptcha' === $p && ! empty( $w['recaptcha-site-key'] ) ) {
			$type = $w['recaptcha-type'] ?? 'v2';
			if ( 'invisible' === $type ) {
				return __( 'WPForms uses invisible reCAPTCHA v2, which Spiral Forms does not support — set up Turnstile or reCAPTCHA v3 in Settings.', 'spiral-forms' );
			}
			$ours['captcha_provider']     = 'recaptcha';
			$ours['recaptcha_type']       = 'v3' === $type ? 'v3' : 'v2';
			$ours['recaptcha_site_key']   = $w['recaptcha-site-key'];
			$ours['recaptcha_secret_key'] = $w['recaptcha-secret-key'] ?? '';
			if ( isset( $w['recaptcha-v3-threshold'] ) ) {
				$ours['recaptcha_threshold'] = (float) $w['recaptcha-v3-threshold'];
			}
		} elseif ( 'turnstile' === $p && ! empty( $w['turnstile-site-key'] ) ) {
			$ours['captcha_provider']     = 'turnstile';
			$ours['turnstile_site_key']   = $w['turnstile-site-key'];
			$ours['turnstile_secret_key'] = $w['turnstile-secret-key'] ?? '';
		} else {
			return '';
		}
		update_option( Spiral_Forms_Settings::OPTION, $ours );
		return __( 'Copied CAPTCHA settings from WPForms.', 'spiral-forms' );
	}

	public static function handle() {
		check_admin_referer( 'spiral_forms_import' );
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'spiral-forms' ) );
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$wid  = isset( $_POST['wpforms_id'] ) ? absint( $_POST['wpforms_id'] ) : 0;
		$task = isset( $_POST['task'] ) ? sanitize_key( $_POST['task'] ) : '';
		$post = get_post( $wid );
		if ( ! $post || 'wpforms' !== $post->post_type ) {
			wp_die( esc_html__( 'WPForms form not found.', 'spiral-forms' ) );
		}
		$wpf      = self::wpforms_data( $post );
		$conv     = self::convert( $wpf );
		$existing = self::imported_form_for( $wid );
		$messages = array();

		switch ( $task ) {
			case 'import':
				if ( $existing ) {
					$messages[] = __( 'This form was already imported. Use "Import new entries" or delete the Spiral copy first.', 'spiral-forms' );
					break;
				}
				$sid = Spiral_Forms_Forms::save( 0, $conv['title'] ? $conv['title'] : $post->post_title, $conv['data'] );
				if ( is_wp_error( $sid ) ) {
					wp_die( esc_html( $sid->get_error_message() ) );
				}
				update_post_meta( $sid, self::META_SOURCE, $wid );
				update_post_meta( $sid, '_spiral_wpforms_map', wp_json_encode( $conv['map'] ) );
				/* translators: %s: form title */
				$messages[] = sprintf( __( 'Imported form "%s".', 'spiral-forms' ), get_the_title( $sid ) );
				if ( ! empty( $_POST['with_entries'] ) ) {
					$n = self::import_entries( $wid, $sid, $conv['map'], array_values( (array) ( $wpf['fields'] ?? array() ) ) );
					/* translators: %d: count */
					$messages[] = sprintf( _n( 'Imported %d entry.', 'Imported %d entries.', $n, 'spiral-forms' ), $n );
				}
				$cap = self::copy_captcha();
				if ( $cap ) {
					$messages[] = $cap;
				}
				$messages = array_merge( $messages, $conv['warnings'] );
				break;

			case 'entries':
				if ( ! $existing ) {
					break;
				}
				$map = json_decode( (string) get_post_meta( $existing, '_spiral_wpforms_map', true ), true );
				$n   = self::import_entries( $wid, $existing, is_array( $map ) ? $map : $conv['map'], array_values( (array) ( $wpf['fields'] ?? array() ) ) );
				/* translators: %d: count */
				$messages[] = sprintf( _n( 'Imported %d new entry.', 'Imported %d new entries.', $n, 'spiral-forms' ), $n );
				break;

			case 'swap':
				if ( ! $existing ) {
					break;
				}
				$n = 0;
				foreach ( self::find_embeds( $wid ) as $row ) {
					$new = self::replace_embeds( $row->post_content, $wid, $existing );
					if ( $new !== $row->post_content ) {
						wp_update_post(
							array(
								'ID'           => (int) $row->ID,
								'post_content' => wp_slash( $new ),
							)
						);
						++$n;
					}
				}
				/* translators: %d: count */
				$messages[] = sprintf( _n( 'Swapped the form on %d post/page. (A revision was saved — restore it to undo.)', 'Swapped the form on %d posts/pages. (Revisions were saved — restore them to undo.)', $n, 'spiral-forms' ), $n );
				$messages[] = __( 'Clear your page cache (e.g. LiteSpeed) so visitors see the new form. Check widgets and theme options separately — they are not changed.', 'spiral-forms' );
				break;
		}

		set_transient( 'spiral_forms_import_msgs_' . get_current_user_id(), $messages, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=spiral-forms-import' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	private static function button( $wid, $task, $label, $primary = false, $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
		wp_nonce_field( 'spiral_forms_import' );
		echo '<input type="hidden" name="action" value="spiral_forms_import"><input type="hidden" name="wpforms_id" value="' . (int) $wid . '"><input type="hidden" name="task" value="' . esc_attr( $task ) . '">';
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
		$confirm = 'swap' === $task ? ' onclick="return confirm(\'' . esc_js( __( 'Replace the WPForms form with the Spiral form on these pages? Revisions are saved so you can undo.', 'spiral-forms' ) ) . '\');"' : '';
		echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '"' . $confirm . '>' . esc_html( $label ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function page() {
		if ( ! current_user_can( Spiral_Forms_Admin::CAP ) ) {
			return;
		}
		$msgs = get_transient( 'spiral_forms_import_msgs_' . get_current_user_id() );
		delete_transient( 'spiral_forms_import_msgs_' . get_current_user_id() );
		$forms = self::wpforms_forms();
		?>
		<div class="wrap sf-admin">
			<h1><?php esc_html_e( 'Import from WPForms', 'spiral-forms' ); ?></h1>
			<?php if ( $msgs ) : ?>
				<div class="notice notice-info"><ul style="list-style:disc;margin-left:20px"><?php foreach ( (array) $msgs as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Importing copies forms and entries — WPForms is not changed. Test the new form, then use "Swap embeds" to switch pages over. You can import new entries again right before deactivating WPForms.', 'spiral-forms' ); ?></p>

			<?php if ( ! $forms ) : ?>
				<p><em><?php esc_html_e( 'No WPForms forms found on this site.', 'spiral-forms' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'WPForms form', 'spiral-forms' ); ?></th>
						<th><?php esc_html_e( 'Entries', 'spiral-forms' ); ?></th>
						<th><?php esc_html_e( 'Used on', 'spiral-forms' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'spiral-forms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'spiral-forms' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $forms as $p ) : ?>
						<?php
						$conv     = self::convert( self::wpforms_data( $p ) );
						$existing = self::imported_form_for( $p->ID );
						$last     = $existing ? (int) get_post_meta( $existing, self::META_LAST_ENTRY, true ) : 0;
						$embeds   = self::find_embeds( $p->ID );
						?>
						<tr>
							<td><strong><?php echo esc_html( $p->post_title ); ?></strong> <span class="sf-muted">#<?php echo (int) $p->ID; ?></span>
								<?php if ( $existing ) : ?>
									<br>→ <a href="<?php echo esc_url( admin_url( 'admin.php?page=spiral-forms-edit&form_id=' . $existing ) ); ?>"><?php echo esc_html( get_the_title( $existing ) ); ?></a>
									<code>[spiral_form id="<?php echo (int) $existing; ?>"]</code>
								<?php endif; ?>
							</td>
							<td>
								<?php echo (int) self::count_wpforms_entries( $p->ID ); ?>
								<?php if ( $existing ) : ?>
									<?php $new = self::count_wpforms_entries( $p->ID, $last ); ?>
									<?php if ( $new ) : ?><br><span class="sf-badge"><?php echo (int) $new; ?> <?php esc_html_e( 'not yet imported', 'spiral-forms' ); ?></span><?php endif; ?>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( ! $embeds ) {
									echo '<span class="sf-muted">' . esc_html__( 'Not found in posts/pages', 'spiral-forms' ) . '</span>';
								}
								foreach ( $embeds as $e ) {
									echo '<a href="' . esc_url( get_edit_post_link( $e->ID ) ? get_edit_post_link( $e->ID ) : '#' ) . '">' . esc_html( $e->post_title ? $e->post_title : '#' . $e->ID ) . '</a> <span class="sf-muted">(' . esc_html( $e->post_type ) . ')</span><br>';
								}
								?>
							</td>
							<td><?php echo $conv['warnings'] ? '<ul style="margin:0">' . implode( '', array_map( static function ( $w ) { return '<li>• ' . esc_html( $w ) . '</li>'; }, $conv['warnings'] ) ) . '</ul>' : '<span class="sf-muted">—</span>'; // phpcs:ignore ?></td>
							<td>
								<?php
								if ( ! $existing ) {
									self::button( $p->ID, 'import', __( 'Import form + entries', 'spiral-forms' ), true, '<input type="hidden" name="with_entries" value="1">' );
								} else {
									self::button( $p->ID, 'entries', __( 'Import new entries', 'spiral-forms' ) );
									if ( $embeds ) {
										self::button( $p->ID, 'swap', __( 'Swap embeds', 'spiral-forms' ), true );
									}
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
