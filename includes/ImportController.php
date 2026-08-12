<?php
/**
 * REST API controller: originals import endpoint.
 *
 * POST gp/v1/projects/{project}/import
 *
 * Accepts a PO/POT file upload and imports it as originals for the given
 * project using GlotPress's own import pipeline.
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

use GP;
use GP_Project;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Import REST controller.
 *
 * Extends WP_REST_Controller directly — zero dependency on any GP REST patch.
 */
class ImportController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'gp-language-pack-server/v1';

	/**
	 * @var string
	 */
	protected $rest_base = 'projects';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<project>[^/]+)/import',
			array(
				'args'   => array(
					'project' => array(
						'description' => __( 'Project slug or ID.', 'gp-language-pack-server' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'import_permissions_check' ),
					'args'                => array(
						'format' => array(
							'description' => __( 'File format. Auto-detected from filename when omitted, falling back to "po".', 'gp-language-pack-server' ),
							'type'        => 'string',
							'default'     => 'po',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Permission check — requires write access to the project.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function import_permissions_check( WP_REST_Request $request ) {
		$project = RestController::get_project_from_request( $request );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		if ( ! GP::$permission->current_user_can( 'write', 'project', $project->id ) ) {
			return new WP_Error(
				'gp_language_pack_cannot_import',
				__( 'Sorry, you are not allowed to import strings for this project.', 'gp-language-pack-server' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Handles POST gp/v1/projects/{project}/import.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$project = RestController::get_project_from_request( $request );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'gp_language_pack_missing_file',
				__( 'No file uploaded.', 'gp-language-pack-server' ),
				array( 'status' => 400 )
			);
		}

		// Prefer the explicit format param; fall back to auto-detect from filename.
		$format = gp_get_import_file_format( $request['format'], $files['file']['name'] );

		if ( ! $format ) {
			return new WP_Error(
				'gp_language_pack_unsupported_format',
				__( 'File format not supported.', 'gp-language-pack-server' ),
				array( 'status' => 400 )
			);
		}

		$translations = $format->read_originals_from_file( $files['file']['tmp_name'] );

		if ( ! $translations ) {
			return new WP_Error(
				'gp_language_pack_unreadable_file',
				__( 'Could not load originals from the uploaded file.', 'gp-language-pack-server' ),
				array( 'status' => 400 )
			);
		}

		$result = GP::$original->import_for_project( $project, $translations );

		/**
		 * Fires after originals are imported via the REST API.
		 *
		 * @param array           $result  [ added, existing, fuzzied, obsoleted, error ].
		 * @param GP_Project      $project
		 * @param WP_REST_Request $request
		 */
		do_action( 'gp_language_pack_originals_imported', $result, $project, $request );

		return rest_ensure_response( $this->prepare_item_for_response( $result, $request ) );
	}

	/**
	 * Formats the import result as a REST response.
	 *
	 * @param array           $result  [ added, existing, fuzzied, obsoleted, error ].
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $result, $request ): WP_REST_Response {
		list( $added, $existing, $fuzzied, $obsoleted, $error ) = $result;

		$data = array(
			'originals_added'     => (int) $added,
			'originals_existing'  => (int) $existing,
			'originals_fuzzied'   => (int) $fuzzied,
			'originals_obsoleted' => (int) $obsoleted,
			'originals_error'     => (int) $error,
		);

		/**
		 * Filters the import result before it is returned via the REST API.
		 *
		 * @param array           $data
		 * @param array           $result  Raw result from GP::$original->import_for_project().
		 * @param WP_REST_Request $request
		 */
		$data = apply_filters( 'gp_language_pack_prepare_import', $data, $result, $request );

		return rest_ensure_response( $data );
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
			'title'      => 'gp_import_result',
			'type'       => 'object',
			'properties' => array(
				'originals_added'     => array(
					'description' => __( 'Number of new original strings added.', 'gp-language-pack-server' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'originals_existing'  => array(
					'description' => __( 'Number of original strings that already existed.', 'gp-language-pack-server' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'originals_fuzzied'   => array(
					'description' => __( 'Number of existing translations marked fuzzy due to changed originals.', 'gp-language-pack-server' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'originals_obsoleted' => array(
					'description' => __( 'Number of originals marked obsolete.', 'gp-language-pack-server' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'originals_error'     => array(
					'description' => __( 'Number of originals that failed to import.', 'gp-language-pack-server' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
