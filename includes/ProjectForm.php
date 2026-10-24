<?php
/**
 * GlotPress project form integration.
 *
 * Adds a "Text domain" field to GlotPress's New/Edit project form. Files
 * inside a project's language packs are named after it.
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

use GP_Project;

/**
 * Adds the text domain field to the GlotPress project form and saves it.
 */
class ProjectForm {

	/**
	 * Form field name.
	 */
	const FIELD = 'gp_language_pack_textdomain';

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
		add_action( 'gp_after_project_form_fields', array( $this, 'render_field' ) );
		add_action( 'gp_project_created', array( $this, 'save_new' ) );
		add_action( 'gp_project_saved', array( $this, 'save_edited' ) );
	}

	/**
	 * Renders the text domain field.
	 *
	 * @param GP_Project $project Project being edited, or an empty one on the New form.
	 */
	public function render_field( GP_Project $project ): void {
		$value = $project->id ? LanguagePackGenerator::get_textdomain( $project ) : '';
		?>
		<dt><label for="<?php echo esc_attr( self::FIELD ); ?>"><?php esc_html_e( 'Text domain', 'gp-language-pack-server' ); ?></label></dt>
		<dd>
			<input type="text" name="<?php echo esc_attr( self::FIELD ); ?>" id="<?php echo esc_attr( self::FIELD ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<small><?php esc_html_e( 'The plugin or theme\'s text domain. Files inside the language packs are named after it, because WordPress loads translations by text domain. Leave empty to use the slug.', 'gp-language-pack-server' ); ?></small>
		</dd>
		<?php
	}

	/**
	 * Saves the text domain when a project is created from the New project form.
	 *
	 * @param GP_Project $project
	 */
	public function save_new( GP_Project $project ): void {
		$this->maybe_save( $project, 'add-project' );
	}

	/**
	 * Saves the text domain when a project is saved from the Edit project form.
	 *
	 * @param GP_Project $project
	 */
	public function save_edited( GP_Project $project ): void {
		$this->maybe_save( $project, 'edit-project_' . $project->id );
	}

	/**
	 * Saves the submitted text domain and rebuilds the project's packs if it changed.
	 *
	 * GlotPress has already checked the nonce and permissions before saving
	 * the project; the nonce is checked again here so projects saved in other
	 * ways (imports, code) are left alone.
	 *
	 * @param GP_Project $project
	 * @param string     $nonce_action GlotPress route nonce action for the form.
	 */
	private function maybe_save( GP_Project $project, string $nonce_action ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified below.
		$nonce = isset( $_POST['_gp_route_nonce'] ) ? wp_unslash( $_POST['_gp_route_nonce'] ) : '';
		if ( ! isset( $_POST[ self::FIELD ] ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return;
		}

		$before = LanguagePackGenerator::get_textdomain( $project );
		LanguagePackGenerator::set_textdomain( $project, sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) ) );

		if ( LanguagePackGenerator::get_textdomain( $project ) !== $before ) {
			LanguagePackGenerator::rebuild_project( $project );
		}
	}
}
