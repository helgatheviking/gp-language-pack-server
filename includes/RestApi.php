<?php
/**
 * REST API bootstrapper for GP Language Packs.
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers our REST controllers on rest_api_init.
 *
 * Deliberately minimal and independent of any GP REST API patch.
 */
class RestApi {

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Instantiates and registers all REST controllers.
	 */
	public function register_routes(): void {
		( new RestController() )->register_routes();
		( new ImportController() )->register_routes();
	}
}
