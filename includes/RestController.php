<?php
/**
 * REST API controller: language pack updates endpoint.
 *
 * GET gp/v1/api/{project}
 *
 * Response shape matches what WordPress core expects when checking for
 * translation updates (mirrors api.wordpress.org/translations/…).
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

use GP;
use GP_Locale;
use GP_Locales;
use GP_Project;
use GP_Translation_Set;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Language packs REST controller.
 *
 * Extends WP_REST_Controller directly — zero dependency on any GP REST patch.
 */
class RestController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'gp-language-pack-server/v1';

	/**
	 * @var string
	 */
	protected $rest_base = 'api';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<project>[^/]+)',
			array(
				'args'   => array(
					'project' => array(
						'description' => __( 'Project slug or ID.', 'gp-language-pack-server' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'percent_translated' => array(
							'description'       => __( 'Minimum percent translated to include in results.', 'gp-language-pack-server' ),
							'type'              => 'integer',
							'default'           => LanguagePackGenerator::get_default_threshold(),
							'minimum'           => 0,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
							'validate_callback' => 'rest_validate_request_arg',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Handles GET gp/v1/api/{project}.
	 *
	 * Returns an object keyed by wp_locale. Each entry contains the fields
	 * WordPress uses to decide whether to offer / download a translation update.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$project = self::get_project_from_request( $request );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$threshold        = (int) $request['percent_translated'];
		$translation_sets = GP::$translation_set->by_project_id( $project->id );
		$items            = array();

		foreach ( $translation_sets as $set ) {
			$locale = GP_Locales::by_slug( $set->locale );

			if ( ! $locale ) {
				continue;
			}

			if ( $set->percent_translated() < $threshold ) {
				continue;
			}

			$wp_locale = $locale->wp_locale;

			// last_modified() returns a MySQL datetime string, or false when
			// there are no current translations.
			$last_modified = $set->current_count() ? $set->last_modified() : false;
			$updated       = $last_modified
				? gmdate( 'Y-m-d H:i:s', strtotime( $last_modified ) )
				: false;

			$data = array(
				'language'     => $wp_locale,
				'version'      => $updated ? gmdate( 'YmdHis', strtotime( $updated ) ) : false,
				'updated'      => $updated,
				'english_name' => $locale->english_name,
				'native_name'  => $locale->native_name,
				'package'      => $this->get_package_url( $project, $wp_locale ),
			);

			/**
			 * Filters the language pack data for a single translation set.
			 *
			 * @param array              $data    Response data for this locale.
			 * @param GP_Project         $project
			 * @param GP_Translation_Set $set
			 * @param GP_Locale          $locale
			 */
			$data = apply_filters( 'gp_language_pack_pack_data', $data, $project, $set, $locale );

			$items[ $wp_locale ] = $data;
		}

		/**
		 * Filters the complete language pack list before it is returned.
		 *
		 * @param array      $items   Map of wp_locale => pack data.
		 * @param GP_Project $project
		 */
		$items = apply_filters( 'gp_language_pack_items', $items, $project );

		return rest_ensure_response( $items );
	}

	/**
	 * @return array
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'gp_language_pack',
			'type'       => 'object',
			'properties' => array(
				'language'     => array(
					'description' => __( 'WordPress locale code.', 'gp-language-pack-server' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'version'      => array(
					'description' => __( 'Pack version derived from last translation update (YYYYMMDDHHmmss).', 'gp-language-pack-server' ),
					'type'        => array( 'string', 'boolean' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'updated'      => array(
					'description' => __( 'Date/time of last translation update (UTC).', 'gp-language-pack-server' ),
					'type'        => array( 'string', 'boolean' ),
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'english_name' => array(
					'description' => __( 'English name of the language.', 'gp-language-pack-server' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'native_name'  => array(
					'description' => __( 'Native name of the language.', 'gp-language-pack-server' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'package'      => array(
					'description' => __( 'URL to download the language pack zip, or false if not yet generated.', 'gp-language-pack-server' ),
					'type'        => array( 'string', 'boolean' ),
					'format'      => 'uri',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	// -------------------------------------------------------------------------
	// Shared helpers (also used by the import controller)
	// -------------------------------------------------------------------------

	/**
	 * Resolves a GP_Project from the {project} URL parameter.
	 *
	 * Accepts an integer ID or a URL-encoded project path. Results are cached
	 * per request to avoid repeated DB hits within the same endpoint handler.
	 *
	 * Public and static so the import controller can reuse it without coupling
	 * the two classes via inheritance.
	 *
	 * @param WP_REST_Request $request
	 * @return GP_Project|WP_Error
	 */
	public static function get_project_from_request( WP_REST_Request $request ) {
		static $cache = array();

		$raw = $request->get_url_params()['project'] ?? '';

		if ( isset( $cache[ $raw ] ) ) {
			return $cache[ $raw ];
		}

		// Normalise path: decode percent-encoding, preserve internal slashes,
		// strip leading/trailing slashes to match GP's stored path format.
		$path = rawurlencode( urldecode( $raw ) );
		$path = str_replace( '%2F', '/', $path );
		$path = str_replace( '%20', ' ', $path );
		$path = trim( $path, '/' );

		global $wpdb;
		$project = GP::$project->one(
			"SELECT * FROM {$wpdb->prefix}gp_projects WHERE path = %s OR id = %d",
			$path,
			(int) $raw
		);

		if ( ! $project instanceof GP_Project ) {
			$error = new WP_Error(
				'gp_language_pack_project_not_found',
				__( 'No project found with that slug or ID.', 'gp-language-pack-server' ),
				array( 'status' => 404 )
			);
			$cache[ $raw ] = $error;
			return $error;
		}

		$cache[ $raw ] = $project;
		return $project;
	}

	/**
	 * Returns the standard filename for a language pack zip.
	 *
	 * Public and static so the generator can call it without creating a
	 * controller instance.
	 *
	 * @param string $project_slug
	 * @param string $wp_locale
	 * @return string e.g. "my-plugin-es_ES.zip"
	 */
	public static function get_pack_filename( string $project_slug, string $wp_locale ): string {
		return sprintf(
			'%s-%s.zip',
			sanitize_file_name( $project_slug ),
			sanitize_file_name( $wp_locale )
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds the download URL for a language pack zip.
	 *
	 * The filename is intentionally versionless — WordPress uses the `updated`
	 * field in the response to decide whether a newer pack is available, not
	 * the filename.
	 *
	 * Returns false if the zip does not exist on disk yet, which tells
	 * WordPress there is nothing to download for this locale.
	 *
	 * @param GP_Project $project
	 * @param string     $wp_locale
	 * @return string|false
	 */
	private function get_package_url( GP_Project $project, string $wp_locale ) {
		$filename = self::get_pack_filename( $project->slug, $wp_locale );
		$file     = trailingslashit( gp_language_pack_dir() ) . $project->slug . '/' . $filename;

		if ( ! file_exists( $file ) ) {
			return false;
		}

		return trailingslashit( gp_language_pack_url() ) . $project->slug . '/' . $filename;
	}
}
