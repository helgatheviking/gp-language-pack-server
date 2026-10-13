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
use Translation_Entry;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Import REST controller.
 *
 * Extends WP_REST_Controller directly — zero dependency on any GP REST patch.
 *
 * @version 1.0.3
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
						'format'   => array(
							'description'       => __( 'File format. POT files are read with the "po" format.', 'gp-language-pack-server' ),
							'type'              => 'string',
							'default'           => 'po',
							'enum'              => array( 'po' ),
							'validate_callback' => 'rest_validate_request_arg',
						),
						'additive' => array(
							'description'       => __( 'Only add and update strings; never obsolete or fuzzy existing ones. Use for pre-release templates.', 'gp-language-pack-server' ),
							'type'              => 'boolean',
							'default'           => false,
							'validate_callback' => 'rest_validate_request_arg',
						),
						'force'    => array(
							'description'       => __( 'Skip the safety check that rejects imports which would obsolete most existing strings.', 'gp-language-pack-server' ),
							'type'              => 'boolean',
							'default'           => false,
							'validate_callback' => 'rest_validate_request_arg',
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
	 * Authentication is checked before the project is looked up, and an unknown
	 * project answers exactly like a forbidden one, so the endpoint can't be used
	 * to enumerate project slugs.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function import_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'gp_language_pack_not_authenticated',
				__( 'Authentication is required to import strings.', 'gp-language-pack-server' ),
				array( 'status' => 401 )
			);
		}

		$project = RestController::get_project_from_request( $request );

		if ( is_wp_error( $project ) || ! GP::$permission->current_user_can( 'write', 'project', $project->id ) ) {
			return new WP_Error(
				'gp_language_pack_cannot_import',
				__( 'Sorry, you are not allowed to import strings for this project.', 'gp-language-pack-server' ),
				array( 'status' => 403 )
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

		$file = $this->validate_upload( $request->get_file_params()['file'] ?? null );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$format = gp_get_import_file_format( 'po', $file['name'] );

		if ( ! $format ) {
			return new WP_Error(
				'gp_language_pack_unsupported_format',
				__( 'File format not supported.', 'gp-language-pack-server' ),
				array( 'status' => 400 )
			);
		}

		$translations = $format->read_originals_from_file( $file['tmp_name'] );

		if ( ! $translations || empty( $translations->entries ) || ! is_array( $translations->entries ) ) {
			return new WP_Error(
				'gp_language_pack_unreadable_file',
				__( 'Could not load any originals from the uploaded file.', 'gp-language-pack-server' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filters the maximum number of strings accepted in a single import.
		 *
		 * @param int        $max     Default 20000.
		 * @param GP_Project $project
		 */
		$max_entries = (int) apply_filters( 'gp_language_pack_max_import_entries', 20000, $project );

		if ( count( $translations->entries ) > $max_entries ) {
			return new WP_Error(
				'gp_language_pack_too_many_strings',
				__( 'The uploaded file contains too many strings.', 'gp-language-pack-server' ),
				array( 'status' => 413 )
			);
		}

		$additive = (bool) $request['additive'];
		$existing = GP::$original->by_project_id( $project->id ) ?: array();

		if ( $additive ) {
			// GlotPress obsoletes (or fuzzies a close match for) every active original missing
			// from the upload. Adding the current originals to the incoming set means nothing
			// is "missing", which makes the import purely additive.
			foreach ( $existing as $original ) {
				$entry = new Translation_Entry(
					array(
						'singular' => $original->singular,
						'plural'   => $original->plural,
						'context'  => $original->context,
					)
				);

				if ( ! isset( $translations->entries[ $entry->key() ] ) ) {
					$translations->entries[ $entry->key() ] = $entry;
				}
			}
		} elseif ( ! $request['force'] && $this->would_obsolete_too_many( $existing, $translations ) ) {
			return new WP_Error(
				'gp_language_pack_import_too_destructive',
				__( 'This import would obsolete most of the existing strings. Check that the right file was uploaded, or resend with force=1.', 'gp-language-pack-server' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->acquire_lock( $project ) ) {
			return new WP_Error(
				'gp_language_pack_import_in_progress',
				__( 'An import is already running for this project.', 'gp-language-pack-server' ),
				array( 'status' => 409 )
			);
		}

		try {
			$result = GP::$original->import_for_project( $project, $translations );
		} finally {
			$this->release_lock( $project );
		}

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
	 * Validates the uploaded file entry from $_FILES.
	 *
	 * @param mixed $file The 'file' entry of the request's file params.
	 * @return array|WP_Error The file entry, or an error.
	 */
	private function validate_upload( $file ) {
		$bad_request = static function ( string $code, string $message, int $status = 400 ): WP_Error {
			return new WP_Error( $code, $message, array( 'status' => $status ) );
		};

		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) || is_array( $file['tmp_name'] ) ) {
			return $bad_request( 'gp_language_pack_missing_file', __( 'No file uploaded.', 'gp-language-pack-server' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return $bad_request( 'gp_language_pack_upload_error', __( 'The file did not upload correctly.', 'gp-language-pack-server' ) );
		}

		// Only ever read a file PHP itself received in this request.
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return $bad_request( 'gp_language_pack_invalid_upload', __( 'Invalid upload.', 'gp-language-pack-server' ) );
		}

		if ( ! in_array( strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ), array( 'po', 'pot' ), true ) ) {
			return $bad_request( 'gp_language_pack_unsupported_format', __( 'Only .pot and .po files are accepted.', 'gp-language-pack-server' ) );
		}

		/**
		 * Filters the maximum size, in bytes, of an uploaded template.
		 *
		 * @param int $max Default 5 MB.
		 */
		$max_bytes = (int) apply_filters( 'gp_language_pack_max_import_bytes', 5 * MB_IN_BYTES );
		$size      = filesize( $file['tmp_name'] );

		if ( false === $size || 0 === $size || $size > $max_bytes ) {
			return $bad_request( 'gp_language_pack_invalid_file_size', __( 'The file is empty or too large.', 'gp-language-pack-server' ), 413 );
		}

		return $file;
	}

	/**
	 * Whether a full-sync import would obsolete more than half of the existing originals.
	 *
	 * Catches the usual accidents: a truncated POT, a POT generated from the wrong
	 * directory, or a build that excluded half the source. Skipped for small
	 * projects, where a big swing is normal.
	 *
	 * @param array  $existing     Active originals for the project.
	 * @param object $translations Parsed upload.
	 * @return bool
	 */
	private function would_obsolete_too_many( array $existing, $translations ): bool {
		if ( count( $existing ) < 20 ) {
			return false;
		}

		$dropped = 0;

		foreach ( $existing as $original ) {
			$entry = new Translation_Entry(
				array(
					'singular' => $original->singular,
					'plural'   => $original->plural,
					'context'  => $original->context,
				)
			);

			if ( ! isset( $translations->entries[ $entry->key() ] ) ) {
				++$dropped;
			}
		}

		return $dropped / count( $existing ) > 0.5;
	}

	/**
	 * Takes a per-project lock so two imports can't interleave.
	 *
	 * add_option() is atomic (unique option_name), unlike a get/set transient pair.
	 * A lock older than five minutes is treated as stale.
	 *
	 * @param GP_Project $project
	 * @return bool
	 */
	private function acquire_lock( GP_Project $project ): bool {
		$name = 'gp_lp_import_lock_' . (int) $project->id;

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( $name ) > 5 * MINUTE_IN_SECONDS ) {
			delete_option( $name );
			return add_option( $name, time(), '', false );
		}

		return false;
	}

	/**
	 * @param GP_Project $project
	 */
	private function release_lock( GP_Project $project ): void {
		delete_option( 'gp_lp_import_lock_' . (int) $project->id );
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
