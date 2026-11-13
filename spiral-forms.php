<?php
/**
 * Plugin Name:       Spiral Forms
 * Description:       Lightweight forms builder: fields, validation, email notifications, entries, CSV export, spam protection.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Kevin Mulligan
 * License:           GPL-2.0-or-later
 * Text Domain:       spiral-forms
 */

defined( 'ABSPATH' ) || exit;

define( 'SPIRAL_FORMS_VERSION', '0.3.0' );
define( 'SPIRAL_FORMS_FILE', __FILE__ );
define( 'SPIRAL_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPIRAL_FORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'SPIRAL_FORMS_GITHUB_REPO', 'kpmulligan23-creator/spiral-forms' );

require_once SPIRAL_FORMS_DIR . 'includes/class-install.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-settings.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-forms.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-entries.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-smart-tags.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-spam.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-uploads.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-renderer.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-submission.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-block.php';
require_once SPIRAL_FORMS_DIR . 'includes/class-updater.php';

register_activation_hook( __FILE__, array( 'Spiral_Forms_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Spiral_Forms_Install', 'deactivate' ) );
add_action( 'spiral_forms_daily', array( 'Spiral_Forms_Entries', 'purge_expired' ) );

add_action(
	'plugins_loaded',
	static function () {
		Spiral_Forms_Install::maybe_upgrade();
		Spiral_Forms_Forms::init();
		Spiral_Forms_Renderer::init();
		Spiral_Forms_Submission::init();
		Spiral_Forms_Uploads::init();
		Spiral_Forms_Block::init();
		Spiral_Forms_Updater::init();

		if ( is_admin() ) {
			require_once SPIRAL_FORMS_DIR . 'admin/class-admin.php';
			require_once SPIRAL_FORMS_DIR . 'admin/class-builder.php';
			require_once SPIRAL_FORMS_DIR . 'admin/class-entries-admin.php';
			require_once SPIRAL_FORMS_DIR . 'admin/class-settings-page.php';
			require_once SPIRAL_FORMS_DIR . 'admin/class-importer.php';
			Spiral_Forms_Admin::init();
		}
	}
);
