<?php
/**
 * Plugin Name: Game Calendar
 * Plugin URI:  https://github.com/JordyDW/game-calendar
 * Description: A gaming-focused calendar for tracking game releases, DLC, and gaming events with IGDB integration.
 * Version:     1.2.2
 * Author:      Jordy De Wilde
 * Text Domain: game-calendar
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GC_VERSION', '1.2.2' );
define( 'GC_GITHUB_REPO',  'https://github.com/JordyDW/game-calendar' );
define( 'GC_PLUGIN_FILE', __FILE__ );
define( 'GC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Auto-updater via public GitHub releases.
require GC_PLUGIN_DIR . 'includes/lib/plugin-update-checker/load-v5p4.php';
add_action( 'init', function () {
	$updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		GC_GITHUB_REPO,
		GC_PLUGIN_FILE,
		'game-calendar'
	);
	$token = GC_Settings::get( 'gc_github_token' );
	if ( $token ) {
		$updater->setAuthentication( $token );
	}
	$updater->getVcsApi()->enableReleaseAssets();
} );

spl_autoload_register( function ( $class ) {
	$prefix = 'GC_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$name = strtolower( str_replace( array( $prefix, '_' ), array( '', '-' ), $class ) );
	$file = GC_PLUGIN_DIR . 'includes/class-' . $name . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );

add_action( 'plugins_loaded', function () {
	new GC_Post_Types();
	new GC_Meta_Boxes();
	new GC_Settings();
	new GC_IGDB_API();
	new GC_Calendar_Query();
	new GC_Admin_Calendar();
	new GC_Discord_Notifier();
	new GC_IGDB_Importer();
} );

register_activation_hook( __FILE__, function () {
	( new GC_Post_Types() )->register();
	GC_Discord_Notifier::schedule_events();
	GC_IGDB_Importer::schedule_events();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
	GC_Discord_Notifier::clear_events();
	GC_IGDB_Importer::clear_events();
	flush_rewrite_rules();
} );
