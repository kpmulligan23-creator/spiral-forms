<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gutenberg block (server-rendered, no build step required).
 */
class Spiral_Forms_Block {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		wp_register_script(
			'spiral-forms-block',
			SPIRAL_FORMS_URL . 'blocks/form/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			SPIRAL_FORMS_VERSION,
			true
		);

		register_block_type(
			SPIRAL_FORMS_DIR . 'blocks/form',
			array(
				'editor_script'   => 'spiral-forms-block',
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
	}

	public static function editor_data() {
		$forms = array();
		foreach ( Spiral_Forms_Forms::all() as $p ) {
			$forms[] = array(
				'value' => $p->ID,
				'label' => $p->post_title,
			);
		}
		wp_localize_script(
			'spiral-forms-block',
			'SpiralFormsBlock',
			array(
				'forms'   => $forms,
				'newUrl'  => admin_url( 'admin.php?page=spiral-forms-edit' ),
			)
		);
		wp_enqueue_style( 'spiral-forms', SPIRAL_FORMS_URL . 'assets/css/forms.css', array(), SPIRAL_FORMS_VERSION );
	}

	public static function render( $attributes ) {
		$id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		if ( ! $id ) {
			return '';
		}
		return Spiral_Forms_Renderer::render( $id );
	}
}
