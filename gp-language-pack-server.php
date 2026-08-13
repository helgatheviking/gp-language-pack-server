<?php
/**
 * Plugin Name: Language Pack Server for GlotPress
 * Plugin URI:  https://github.com/helgatheviking/gp-language-pack-server
 * Description: Generates and serves language pack downloads from GlotPress translations via the WordPress REST API.
 * Version:     1.0.0
 * Author:      helgatheviking
 * Author URI:  https://www.kathyisawesome.com
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * License:     GPL-3.0-or-later
 * Text Domain: gp-language-pack-server
 * 
 * Update URI: https://www.backcourt.io
 * 
 * Requires PHP: 8.0
 *
 * Requires at least: 6.9.0
 * Tested up to: 7.0.0
 *
 * @package GP_Language_Pack
 */

defined( 'ABSPATH' ) || exit;

use HelgaTheViking\GPLanguagePack\Server\RestApi;
use HelgaTheViking\GPLanguagePack\Server\LanguagePackGenerator;
use HelgaTheViking\GPLanguagePack\Server\AdminController;

// ---------------------------------------------------------------------------
// Update server
// ---------------------------------------------------------------------------

use HelgaTheViking\GPLanguagePack\Server\Vendor\Fragen;

/**
  * Add Git Updater Lite
  */
if ( file_exists( __DIR__ . '/packages/autoload.php' ) ) {
	require_once __DIR__ . '/packages/autoload.php';

	if ( class_exists( Fragen\Git_Updater\Lite::class ) ) {
		( new Fragen\Git_Updater\Lite( __FILE__ ) )->run();
	}
}

// ---------------------------------------------------------------------------
// Activation
// ---------------------------------------------------------------------------

/**
 * Runs on plugin activation.
 *
 * Creates the upload directory and writes security files before any pack is
 * ever generated or served.
 */
function gp_language_pack_activate(): void {
	gp_language_pack_create_directory();

	if ( ! wp_next_scheduled( 'gp_language_pack_daily_generate' ) ) {
		wp_schedule_event( time(), 'daily', 'gp_language_pack_daily_generate' );
	}
}
register_activation_hook( __FILE__, 'gp_language_pack_activate' );

/**
 * Runs on plugin deactivation.
 *
 * Clears the daily Cron generation event.
 */
function gp_language_pack_deactivate(): void {
	wp_clear_scheduled_hook( 'gp_language_pack_daily_generate' );
}
register_deactivation_hook( __FILE__, 'gp_language_pack_deactivate' );

/**
 * Creates the language-packs upload directory and writes two security files:
 *
 *   .htaccess  — Apache: blocks POST/PUT uploads, PHP execution, directory listing.
 *   index.php  — All servers: silent index prevents directory listing where
 *                .htaccess is ignored (e.g. Nginx with autoindex on).
 *
 * Safe to call multiple times; both files are only written if absent.
 */
function gp_language_pack_create_directory(): void {
	$dir = gp_language_pack_dir();

	if ( ! wp_mkdir_p( $dir ) ) {
		return;
	}

	// .htaccess (Apache).
	$htaccess = $dir . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules  = "# Block any attempt to upload files to this directory.\n";
		$rules .= "<LimitExcept GET HEAD>\n";
		$rules .= "\tDeny from all\n";
		$rules .= "</LimitExcept>\n\n";
		$rules .= "# Prevent PHP execution.\n";
		$rules .= "<FilesMatch \"\\.php\$\">\n";
		$rules .= "\tDeny from all\n";
		$rules .= "</FilesMatch>\n\n";
		$rules .= "# Disable directory listing.\n";
		$rules .= "Options -Indexes\n\n";
		$rules .= "# Serve zips as downloads rather than inline.\n";
		$rules .= "<FilesMatch \"\\.zip\$\">\n";
		$rules .= "\tHeader set Content-Disposition attachment\n";
		$rules .= "</FilesMatch>\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, $rules );
	}

	// index.php (Nginx / any server).
	$index = $dir . '/index.php';
	if ( ! file_exists( $index ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Bootstraps the plugin. Does nothing if GlotPress is not active.
 */
function gp_language_pack_init(): void {
	if ( ! class_exists( 'GP' ) ) {
		return;
	}

	define( 'gp_language_pack_VERSION', '1.0.0' );
	define( 'gp_language_pack_PATH', plugin_dir_path( __FILE__ ) );
	define( 'gp_language_pack_URL', plugin_dir_url( __FILE__ ) );

	if ( ! file_exists( gp_language_pack_PATH . 'vendor/autoload.php' ) ) {
		add_action( 'admin_notices', 'gp_language_pack_missing_autoloader_notice' );
		return;
	}

	require_once gp_language_pack_PATH . 'vendor/autoload.php';

	RestApi::instance();
	LanguagePackGenerator::instance();

	if ( is_admin() ) {
		AdminController::instance();
	}
}
add_action( 'plugins_loaded', 'gp_language_pack_init', 20 );

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Returns the filesystem directory where language pack zips are stored.
 *
 * Filterable so site owners can redirect to e.g. an S3-mounted path.
 *
 * @return string Absolute path, no trailing slash.
 */
function gp_language_pack_dir(): string {
	$upload_dir = wp_upload_dir();
	$dir        = $upload_dir['basedir'] . '/language-packs';

	/**
	 * Filters the directory used to store language pack zip files.
	 *
	 * @param string $dir Absolute path, no trailing slash.
	 */
	return apply_filters( 'gp_language_pack_dir', $dir );
}

/**
 * Returns the base URL from which language pack zips are served.
 *
 * Filterable so site owners can point to a CDN.
 *
 * @return string URL, no trailing slash.
 */
function gp_language_pack_url(): string {
	$upload_dir = wp_upload_dir();
	$url        = $upload_dir['baseurl'] . '/language-packs';

	/**
	 * Filters the base URL used to build language pack download links.
	 *
	 * @param string $url URL, no trailing slash.
	 */
	return apply_filters( 'gp_language_pack_url', $url );
}

/**
 * Admin notice shown when vendor/autoload.php is missing.
 *
 * This means `composer install` has not been run in the plugin directory.
 * The plugin cannot function without it.
 */
function gp_language_pack_missing_autoloader_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%s</strong> %s <code>%s</code></p></div>',
		esc_html__( 'Language Pack Server for GlotPress:', 'gp-language-pack-server' ),
		esc_html__( 'Composer dependencies are missing. Please run', 'gp-language-pack-server' ),
		'composer install --no-dev'
	);
}