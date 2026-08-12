<?php
/**
 * Language pack generator.
 *
 * Exports current translations from GlotPress into a WordPress-compatible
 * language pack: a zip containing {slug}-{wp_locale}.po and .mo files.
 *
 * Generation is triggered:
 *   - Automatically when a translation is approved (via `gp_translation_saved`).
 *     A 60-second deferred single event batches rapid successive approvals so
 *     a bulk import doesn't rebuild the zip on every individual string.
 *   - Via WP-CLI: `wp gp-language-packs generate [--project=<slug>] [--locale=<wp_locale>]`
 *   - Programmatically: LanguagePackGenerator::generate( $project, $set )
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

use GP;
use GP_Locale;
use GP_Locales;
use GP_Project;
use GP_Translation;
use GP_Translation_Set;
use MO;
use PO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_CLI;
use WP_Error;
use ZipArchive;

/**
 * GP Language Pack Generator.
 */
class LanguagePackGenerator {

	/**
	 * WP-Cron hook name for the deferred single-set rebuild.
	 */
	const SINGLE_EVENT_HOOK = 'gp_language_pack_generate_single';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'gp_translation_saved', array( $this, 'on_translation_saved' ) );
		add_action( self::SINGLE_EVENT_HOOK, array( $this, 'generate_by_ids' ), 10, 2 );
		add_action( 'gp_language_pack_daily_generate', array( $this, 'generate_all_packs' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'gp-language-packs generate', array( $this, 'cli_generate' ) );
		}
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/**
	 * Fires when a translation is saved.
	 *
	 * Only acts on approved (current) translations. Schedules a one-off event
	 * 60 seconds from now so rapid successive approvals during a bulk import
	 * are collapsed into a single rebuild.
	 *
	 * @param GP_Translation $translation
	 */
	public function on_translation_saved( GP_Translation $translation ): void {
		if ( 'current' !== $translation->status ) {
			return;
		}

		$set = GP::$translation_set->get( $translation->translation_set_id );
		if ( ! $set ) {
			return;
		}

		$project = GP::$project->get( $set->project_id );
		if ( ! $project ) {
			return;
		}

		$hook_args = array( (int) $project->id, (int) $set->id );

		if ( ! wp_next_scheduled( self::SINGLE_EVENT_HOOK, $hook_args ) ) {
			wp_schedule_single_event( time() + 60, self::SINGLE_EVENT_HOOK, $hook_args );
		}
	}

	/**
	 * WP-Cron callback: generate a pack for one project + set by ID.
	 *
	 * @param int $project_id
	 * @param int $set_id
	 */
	public function generate_by_ids( int $project_id, int $set_id ): void {
		$project = GP::$project->get( $project_id );
		$set     = GP::$translation_set->get( $set_id );

		if ( ! $project || ! $set ) {
			return;
		}

		$threshold = self::get_default_threshold();
		if ( $set->percent_translated() < $threshold ) {
			return;
		}

		$result = self::generate( $project, $set );

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[gp-language-packs] Failed to generate pack for %s / %s: %s',
				$project->slug,
				$set->locale,
				$result->get_error_message()
			) );
		}
	}

	// -------------------------------------------------------------------------
	// WP-CLI command
	// -------------------------------------------------------------------------

	/**
	 * Generates language packs from GlotPress translations.
	 *
	 * ## OPTIONS
	 *
	 * [--project=<slug>]
	 * : Limit generation to a single project slug. Omit to process all active projects.
	 *
	 * [--locale=<wp_locale>]
	 * : Limit generation to a single WordPress locale (e.g. es_ES). Requires --project.
	 *
	 * [--threshold=<percent>]
	 * : Minimum percent translated required to generate a pack. Default: 90.
	 *
	 * ## EXAMPLES
	 *
	 *   # Generate packs for all active projects at 90%+ translated.
	 *   wp gp-language-packs generate
	 *
	 *   # Generate packs only for the "my-plugin" project.
	 *   wp gp-language-packs generate --project=my-plugin
	 *
	 *   # Generate only the Spanish pack for "my-plugin".
	 *   wp gp-language-packs generate --project=my-plugin --locale=es_ES
	 *
	 *   # Lower the threshold to 70%.
	 *   wp gp-language-packs generate --threshold=70
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function cli_generate( array $args, array $assoc_args ): void {
		$project_slug = $assoc_args['project'] ?? null;
		$wp_locale    = $assoc_args['locale']  ?? null;
		$threshold    = isset( $assoc_args['threshold'] ) ? (int) $assoc_args['threshold'] : 90;

		if ( $wp_locale && ! $project_slug ) {
			WP_CLI::error( '--locale requires --project to be set.' );
			return;
		}

		if ( $project_slug ) {
			$project = GP::$project->by_path( $project_slug );
			if ( ! $project ) {
				WP_CLI::error( sprintf( 'No project found with slug "%s".', $project_slug ) );
				return;
			}
			$projects = array( $project );
		} else {
			// find_many() is core GP_Thing — not to be confused with find_some() which is patch-only.
			$projects = GP::$project->find_many( array( 'active' => 1 ) );
			if ( ! $projects ) {
				WP_CLI::warning( 'No active projects found.' );
				return;
			}
		}

		$generated = 0;
		$skipped   = 0;
		$failed    = 0;

		foreach ( $projects as $project ) {
			$sets = GP::$translation_set->by_project_id( $project->id );

			foreach ( $sets as $set ) {
				// Filter by locale if requested.
				if ( $wp_locale ) {
					$locale = GP_Locales::by_slug( $set->locale );
					if ( ! $locale || $locale->wp_locale !== $wp_locale ) {
						continue;
					}
				}

				$percent = $set->percent_translated();

				if ( $percent < $threshold ) {
					WP_CLI::debug(
						sprintf( 'Skipping %s / %s (%d%% < %d%%).', $project->slug, $set->locale, $percent, $threshold ),
						'gp-language-pack-server'
					);
					$skipped++;
					continue;
				}

				WP_CLI::log( sprintf( 'Generating: %s / %s (%d%%)…', $project->slug, $set->locale, $percent ) );

				$result = self::generate( $project, $set );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( '  Failed: %s', $result->get_error_message() ) );
					$failed++;
				} else {
					$generated++;
				}
			}
		}

		WP_CLI::success( sprintf(
			'Done. Generated: %d, Skipped (below threshold): %d, Failed: %d.',
			$generated,
			$skipped,
			$failed
		) );
	}

	// -------------------------------------------------------------------------
	// Core generation
	// -------------------------------------------------------------------------

	/**
	 * Generates a language pack zip for a single project + translation set.
	 *
	 * Steps:
	 *   1. Export current translations to a PO string via GlotPress.
	 *   2. Write the .po file to a temp directory.
	 *   3. Compile a .mo file (msgfmt if available, otherwise WP core MO class).
	 *   4. Zip both files.
	 *   5. Move the zip to the permanent upload directory.
	 *
	 * @param GP_Project         $project
	 * @param GP_Translation_Set $set
	 * @param bool               $force   Whether to bypass the change detection check.
	 * @return true|WP_Error
	 */
	public static function generate( GP_Project $project, GP_Translation_Set $set, bool $force = false ) {

		// Resolve locale.
		$locale = GP_Locales::by_slug( $set->locale );
		if ( ! $locale ) {
			return new WP_Error(
				'gp_language_pack_unknown_locale',
				sprintf( 'Unknown locale slug: %s', $set->locale )
			);
		}

		$wp_locale = $locale->wp_locale;
		if ( ! $wp_locale ) {
			return new WP_Error(
				'gp_language_pack_no_wp_locale',
				sprintf( 'No wp_locale for locale slug: %s', $set->locale )
			);
		}

		// Change detection.
		$dest_dir  = trailingslashit( gp_language_pack_dir() ) . sanitize_file_name( $project->slug );
		$dest_file = $dest_dir . '/' . RestController::get_pack_filename( $project->slug, $wp_locale );
		if ( ! $force && file_exists( $dest_file ) ) {
			$zip_mtime     = filemtime( $dest_file );
			$last_modified = $set->last_modified();
			if ( $last_modified && strtotime( $last_modified ) <= $zip_mtime ) {
				return true; // No changes since last run, skip.
			}
		}

		// 1. Export PO string.
		$po_format = GP::$formats['po'] ?? null;
		if ( ! $po_format ) {
			return new WP_Error(
				'gp_language_pack_no_po_format',
				'GlotPress PO format not available.'
			);
		}

		$translations = GP::$translation->for_translation(
			$project,
			$set,
			'no-limit',
			array( 'status' => 'current' )
		);

		if ( empty( $translations ) ) {
			return new WP_Error(
				'gp_language_pack_no_translations',
				sprintf( 'No current translations for %s / %s.', $project->slug, $wp_locale )
			);
		}

		$po_string = $po_format->print_exported_file( $project, $set, $locale, $translations );
		if ( ! $po_string ) {
			return new WP_Error(
				'gp_language_pack_export_failed',
				'GP PO export returned empty output.'
			);
		}

		// 2. Write .po to a temp directory.
		$tmp_dir = get_temp_dir() . 'gp-language-packs-' . uniqid( '', true );
		$base    = sanitize_file_name( $project->slug ) . '-' . sanitize_file_name( $wp_locale );
		$po_file = $tmp_dir . '/' . $base . '.po';
		$mo_file = $tmp_dir . '/' . $base . '.mo';
		$zip_tmp = $tmp_dir . '/' . $base . '.zip';

		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new WP_Error(
				'gp_language_pack_tmp_dir',
				sprintf( 'Could not create temp directory: %s', $tmp_dir )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $po_file, $po_string ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error( 'gp_language_pack_po_write', 'Could not write .po file.' );
		}

		// 3. Compile .mo.
		$mo_result = self::compile_mo( $po_file, $mo_file, $po_string );
		if ( is_wp_error( $mo_result ) ) {
			self::cleanup( $tmp_dir );
			return $mo_result;
		}

		// 4. Create zip.
		if ( ! class_exists( 'ZipArchive' ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error( 'gp_language_pack_no_zip', 'ZipArchive PHP extension is not available.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error( 'gp_language_pack_zip_open', 'Could not create zip archive.' );
		}

		$zip->addFile( $po_file, $base . '.po' );
		$zip->addFile( $mo_file, $base . '.mo' );

		// Add JS translations if any exist.
		self::add_js_translations_to_zip( $zip, $tmp_dir, $project, $set, $locale, $translations );

		/**
		 * Fires just before the zip is finalised.
		 *
		 * Use this hook to add extra files to the zip, e.g. .json files for
		 * the block editor / Jed format.
		 *
		 * @param ZipArchive         $zip     Open ZipArchive instance.
		 * @param string             $tmp_dir Temporary working directory.
		 * @param GP_Project         $project
		 * @param GP_Translation_Set $set
		 * @param GP_Locale          $locale
		 * @param string             $base    Base filename without extension.
		 */
		do_action( 'gp_language_pack_zip_before_close', $zip, $tmp_dir, $project, $set, $locale, $base );

		$zip->close();

		if ( ! file_exists( $zip_tmp ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error( 'gp_language_pack_zip_missing', 'Zip file was not created.' );
		}

		// 5. Move zip to permanent location.
		$dest_dir  = trailingslashit( gp_language_pack_dir() ) . sanitize_file_name( $project->slug );
		$dest_file = $dest_dir . '/' . RestController::get_pack_filename( $project->slug, $wp_locale );

		if ( ! wp_mkdir_p( $dest_dir ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error(
				'gp_language_pack_dest_dir',
				sprintf( 'Could not create destination directory: %s', $dest_dir )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem->copy( $zip_tmp, $dest_file, true ) ) {
			self::cleanup( $tmp_dir );
			return new WP_Error( 'gp_language_pack_copy', 'Could not copy zip to destination.' );
		}

		self::cleanup( $tmp_dir );

		/**
		 * Fires after a language pack is successfully generated.
		 *
		 * @param string             $dest_file Absolute path to the generated zip.
		 * @param GP_Project         $project
		 * @param GP_Translation_Set $set
		 * @param GP_Locale          $locale
		 */
		do_action( 'gp_language_pack_generated', $dest_file, $project, $set, $locale );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Compiles a .mo binary from a .po file.
	 *
	 * Tries the system msgfmt binary first (fast). Falls back to WordPress
	 * core's PO/MO classes which are always available.
	 *
	 * @param string $po_file   Absolute path to source .po file.
	 * @param string $mo_file   Absolute path to write .mo file.
	 * @param string $po_string Raw PO content (used for the PHP fallback).
	 * @return true|WP_Error
	 */
	private static function compile_mo( string $po_file, string $mo_file, string $po_string ) {
		if ( function_exists( 'exec' ) && self::has_msgfmt() ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec(
				sprintf( 'msgfmt %s -o %s 2>&1', escapeshellarg( $po_file ), escapeshellarg( $mo_file ) ),
				$output,
				$return_code
			);
			if ( 0 === $return_code && file_exists( $mo_file ) ) {
				return true;
			}
		}

		return self::compile_mo_with_wp_mo( $po_string, $mo_file );
	}

	/**
	 * Checks whether msgfmt is available on this server.
	 *
	 * Result is cached for the lifetime of the request.
	 *
	 * @return bool
	 */
	private static function has_msgfmt(): bool {
		static $available = null;
		if ( null === $available ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
			exec( 'msgfmt --version 2>&1', $out, $code );
			$available = ( 0 === $code );
		}
		return $available;
	}

	/**
	 * Compiles a .mo file using WordPress core's PO/MO classes.
	 *
	 * Always available — no external binary required.
	 *
	 * @param string $po_string PO file contents.
	 * @param string $mo_file   Destination .mo path.
	 * @return true|WP_Error
	 */
	private static function compile_mo_with_wp_mo( string $po_string, string $mo_file ) {
		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';

		$po  = new PO();
		$tmp = wp_tempnam( 'gp-lp-' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp, $po_string );
		$imported = $po->import_from_file( $tmp );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $imported ) {
			return new WP_Error( 'gp_language_pack_po_parse', 'Could not parse PO data.' );
		}

		$mo = new MO();
		$mo->set_headers( $po->headers );
		foreach ( $po->entries as $entry ) {
			$mo->add_entry( $entry );
		}

		if ( ! $mo->export_to_file( $mo_file ) ) {
			return new WP_Error( 'gp_language_pack_mo_write', 'Could not write .mo file.' );
		}

		return true;
	}

	/**
	 * Recursively removes a temporary working directory.
	 *
	 * @param string $dir Absolute path to temp directory.
	 */
	private static function cleanup( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			$file->isDir() ? rmdir( $file->getRealPath() ) : unlink( $file->getRealPath() );
		}
		rmdir( $dir );
	}

	/**
	 * Returns the default percentage translated threshold to generate / serve a pack.
	 *
	 * @return int 0-100.
	 */
	public static function get_default_threshold(): int {
		$threshold = get_option( 'gp_language_pack_threshold', 90 );
		return (int) apply_filters( 'gp_language_pack_default_threshold', $threshold );
	}

	/**
	 * Daily cron callback: generates language packs for all active projects and sets
	 * that meet the percentage threshold.
	 */
	public function generate_all_packs(): void {
		$projects = GP::$project->find_many( array( 'active' => 1 ) );
		if ( ! $projects ) {
			return;
		}

		$threshold = self::get_default_threshold();

		foreach ( $projects as $project ) {
			$sets = GP::$translation_set->by_project_id( $project->id );
			foreach ( $sets as $set ) {
				if ( $set->percent_translated() >= $threshold ) {
					self::generate( $project, $set );
				}
			}
		}
	}

	/**
	 * Extracts JS translations and adds JED JSON files to the zip.
	 *
	 * @param ZipArchive         $zip
	 * @param string             $tmp_dir
	 * @param GP_Project         $project
	 * @param GP_Translation_Set $set
	 * @param GP_Locale          $locale
	 * @param array              $translations
	 */
	private static function add_js_translations_to_zip( ZipArchive $zip, string $tmp_dir, GP_Project $project, GP_Translation_Set $set, GP_Locale $locale, array $translations ): void {
		$js_translations = array();

		foreach ( $translations as $translation ) {
			if ( empty( $translation->references ) ) {
				continue;
			}

			// References can be separated by spaces, newlines, or commas.
			$refs = preg_split( '/[\s,]+/', $translation->references );
			foreach ( $refs as $ref ) {
				$ref = trim( $ref );
				if ( empty( $ref ) ) {
					continue;
				}

				// Strip line number.
				$ref_parts = explode( ':', $ref );
				$file_path = $ref_parts[0];

				// Check if JS/TS file.
				$ext = pathinfo( $file_path, PATHINFO_EXTENSION );
				if ( in_array( $ext, array( 'js', 'jsx', 'ts', 'tsx' ), true ) ) {
					if ( ! isset( $js_translations[ $file_path ] ) ) {
						$js_translations[ $file_path ] = array();
					}
					$js_translations[ $file_path ][] = $translation;
				}
			}
		}

		if ( empty( $js_translations ) ) {
			return;
		}

		$textdomain = apply_filters( 'gp_language_pack_project_textdomain', $project->slug, $project );
		$wp_locale  = $locale->wp_locale;

		foreach ( $js_translations as $file_path => $file_trans ) {
			$locale_data = array(
				'' => array(
					'domain'       => 'messages',
					'plural-forms' => sprintf( 'nplurals=%d; plural=%s;', $locale->nplurals, $locale->plural ),
					'lang'         => $wp_locale,
				),
			);

			foreach ( $file_trans as $translation ) {
				$key = $translation->context ? $translation->context . "\x04" . $translation->singular : $translation->singular;
				
				// Build translations list.
				$translations_array = array();
				$nplurals = (int) $locale->nplurals;
				for ( $i = 0; $i < $nplurals; $i++ ) {
					$prop = "translation_$i";
					$translations_array[] = $translation->$prop ?? '';
				}

				$locale_data[ $key ] = $translations_array;
			}

			$updated = $set->last_modified();
			$json_data = array(
				'translation-revision-date' => $updated ? gmdate( 'Y-m-d H:i:s+00:00', strtotime( $updated ) ) : gmdate( 'Y-m-d H:i:s+00:00' ),
				'generator'                 => 'GP Language Packs/' . gp_language_pack_VERSION,
				'domain'                    => 'messages',
				'locale_data'               => array(
					'messages' => $locale_data,
				),
			);

			$json_string = wp_json_encode( $json_data );
			if ( false === $json_string ) {
				continue;
			}

			// Naming: {textdomain}-{locale}-{md5 of js relative path}.json
			$js_hash   = md5( $file_path );
			$json_name = sprintf( '%s-%s-%s.json', $textdomain, $wp_locale, $js_hash );
			$json_file = $tmp_dir . '/' . $json_name;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false !== file_put_contents( $json_file, $json_string ) ) {
				$zip->addFile( $json_file, $json_name );
			}
		}
	}
}
