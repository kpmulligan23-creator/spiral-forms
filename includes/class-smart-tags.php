<?php
defined( 'ABSPATH' ) || exit;

/**
 * Smart tag replacement: {field:id}, {all_fields}, {form_name}, {site_name}, etc.
 */
class Spiral_Forms_Smart_Tags {

	public static function available() {
		return array(
			'{field:FIELD_ID}' => __( 'Value of a field', 'spiral-forms' ),
			'{all_fields}'     => __( 'All submitted fields', 'spiral-forms' ),
			'{form_name}'      => __( 'Form name', 'spiral-forms' ),
			'{site_name}'      => __( 'Site name', 'spiral-forms' ),
			'{site_url}'       => __( 'Site URL', 'spiral-forms' ),
			'{admin_email}'    => __( 'Site admin email', 'spiral-forms' ),
			'{page_url}'       => __( 'Page the form was submitted from', 'spiral-forms' ),
			'{entry_id}'       => __( 'Entry ID', 'spiral-forms' ),
			'{date}'           => __( 'Submission date', 'spiral-forms' ),
			'{user_ip}'        => __( 'Submitter IP address', 'spiral-forms' ),
		);
	}

	/**
	 * @param string $text    Text containing tags.
	 * @param array  $context { form, values (id => {label,type,value}), entry_id, page_url, ip }.
	 * @param string $mode    'text' or 'html' — controls escaping and {all_fields} format.
	 */
	public static function replace( $text, array $context, $mode = 'text' ) {
		$text   = (string) $text;
		$values = $context['values'] ?? array();
		$html   = 'html' === $mode;
		$esc    = static function ( $v ) use ( $html ) {
			return $html ? nl2br( esc_html( $v ) ) : $v;
		};

		$text = preg_replace_callback(
			'/\{field:([a-z0-9_\-]+)\}/i',
			static function ( $m ) use ( $values, $esc ) {
				$key = sanitize_key( $m[1] );
				return isset( $values[ $key ] ) ? $esc( Spiral_Forms_Entries::value_to_string( $values[ $key ]['value'] ) ) : '';
			},
			$text
		);

		if ( false !== strpos( $text, '{all_fields}' ) ) {
			$text = str_replace( '{all_fields}', self::all_fields( $values, $html, (int) ( $context['entry_id'] ?? 0 ) ), $text );
		}

		$map = array(
			'{form_name}'   => $context['form']['title'] ?? '',
			'{site_name}'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'    => home_url( '/' ),
			'{admin_email}' => get_option( 'admin_email' ),
			'{page_url}'    => $context['page_url'] ?? '',
			'{entry_id}'    => (string) ( $context['entry_id'] ?? '' ),
			'{date}'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'{user_ip}'     => $context['ip'] ?? '',
		);
		foreach ( $map as $tag => $val ) {
			$map[ $tag ] = $esc( $val );
		}

		return strtr( $text, $map );
	}

	private static function all_fields( array $values, $html, $entry_id = 0 ) {
		$out = '';
		foreach ( $values as $v ) {
			$val = Spiral_Forms_Entries::value_to_string( $v['value'] );
			if ( $html && Spiral_Forms_Uploads::is_file_value( $v['value'] ) ) {
				$links = array();
				foreach ( $v['value'] as $i => $rec ) {
					$url     = $entry_id || ! empty( $rec['url'] ) ? Spiral_Forms_Uploads::download_url( $entry_id, $v['id'], $i, (array) $rec ) : '';
					$label   = esc_html( $rec['name'] ) . ( ! empty( $rec['size'] ) ? ' <span style="color:#888">(' . esc_html( size_format( $rec['size'] ) ) . ')</span>' : '' );
					$links[] = $url ? '<a href="' . esc_url( $url ) . '">' . $label . '</a>' : $label;
				}
				$out .= '<tr><th align="left" valign="top" style="padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #e2e4e7;">' . esc_html( $v['label'] ) . '</th>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #e2e4e7;">' . implode( '<br>', $links ) . ( $entry_id ? '<br><span style="color:#888;font-size:12px">' . esc_html__( 'Log in to WordPress to download.', 'spiral-forms' ) . '</span>' : '' ) . '</td></tr>';
				continue;
			}
			if ( $html ) {
				$out .= '<tr><th align="left" valign="top" style="padding:8px 12px;background:#f6f7f7;border-bottom:1px solid #e2e4e7;">' . esc_html( $v['label'] ) . '</th>'
					. '<td style="padding:8px 12px;border-bottom:1px solid #e2e4e7;">' . ( '' === $val ? '<em style="color:#888">—</em>' : nl2br( esc_html( $val ) ) ) . '</td></tr>';
			} else {
				$out .= $v['label'] . ":\n" . ( '' === $val ? '—' : $val ) . "\n\n";
			}
		}
		if ( $html ) {
			return '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;border:1px solid #e2e4e7;">' . $out . '</table>';
		}
		return rtrim( $out );
	}
}
