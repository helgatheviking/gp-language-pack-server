<?php
/**
 * Admin controller for GP Language Packs.
 *
 * @package GP_Language_Pack
 */

namespace HelgaTheViking\GPLanguagePack\Server;

defined( 'ABSPATH' ) || exit;

use GP;
use GP_Locales;
use WP_Error;

/**
 * Handles the admin settings screen, AJAX actions, and asset loading.
 */
class AdminController {

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
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_gp_language_pack_ajax_generate', array( $this, 'ajax_generate_pack' ) );
		add_action( 'wp_ajax_gp_language_pack_ajax_delete', array( $this, 'ajax_delete_pack' ) );
	}

	/**
	 * Registers the admin menu item under Settings.
	 */
	public function register_admin_menu(): void {
		add_options_page(
			__( 'Language Pack Server for GlotPress', 'gp-language-pack-server' ),
			__( 'Language Pack Server', 'gp-language-pack-server' ),
			'manage_options',
			'gp-language-pack-server',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Registers the plugin settings.
	 */
	public function register_settings(): void {
		register_setting( 'gp_language_pack_settings', 'gp_language_pack_threshold', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 90,
		) );
	}

	/**
	 * Handles AJAX language pack generation.
	 */
	public function ajax_generate_pack(): void {
		check_ajax_referer( 'gp_language_pack_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-language-pack-server' ) ) );
		}

		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$set_id     = isset( $_POST['set_id'] ) ? (int) $_POST['set_id'] : 0;

		$project = GP::$project->get( $project_id );
		$set     = GP::$translation_set->get( $set_id );

		if ( ! $project || ! $set ) {
			wp_send_json_error( array( 'message' => __( 'Project or Translation Set not found.', 'gp-language-pack-server' ) ) );
		}

		// Always force generation when manually clicked from admin dashboard.
		$result = LanguagePackGenerator::generate( $project, $set, true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Get updated size and modified timestamp.
		$locale    = GP_Locales::by_slug( $set->locale );
		$wp_locale = $locale->wp_locale;
		$filename  = RestController::get_pack_filename( $project->slug, $wp_locale );
		$file_path = trailingslashit( gp_language_pack_dir() ) . $project->slug . '/' . $filename;

		$size = '0 KB';
		$date = '';
		if ( file_exists( $file_path ) ) {
			$size = size_format( filesize( $file_path ) );
			$date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file_path ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Language pack generated successfully!', 'gp-language-pack-server' ),
			'size'    => $size,
			'date'    => $date,
		) );
	}

	/**
	 * Handles AJAX language pack deletion.
	 */
	public function ajax_delete_pack(): void {
		check_ajax_referer( 'gp_language_pack_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-language-pack-server' ) ) );
		}

		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;
		$set_id     = isset( $_POST['set_id'] ) ? (int) $_POST['set_id'] : 0;

		$project = GP::$project->get( $project_id );
		$set     = GP::$translation_set->get( $set_id );

		if ( ! $project || ! $set ) {
			wp_send_json_error( array( 'message' => __( 'Project or Translation Set not found.', 'gp-language-pack-server' ) ) );
		}

		$locale    = GP_Locales::by_slug( $set->locale );
		$wp_locale = $locale->wp_locale;
		$filename  = RestController::get_pack_filename( $project->slug, $wp_locale );
		$file_path = trailingslashit( gp_language_pack_dir() ) . $project->slug . '/' . $filename;

		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
			wp_send_json_success( array( 'message' => __( 'Language pack deleted.', 'gp-language-pack-server' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Language pack file not found on disk.', 'gp-language-pack-server' ) ) );
	}

	/**
	 * Renders the dashboard/settings screen.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$projects = GP::$project->find_many( array( 'active' => 1 ) );
		$threshold = LanguagePackGenerator::get_default_threshold();

		// Calculate stats.
		$total_packs_generated = 0;
		$total_file_size = 0;
		$dir = gp_language_pack_dir();

		if ( is_dir( $dir ) ) {
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $files as $file ) {
				if ( ! $file->isDir() && $file->getExtension() === 'zip' ) {
					$total_packs_generated++;
					$total_file_size += $file->getSize();
				}
			}
		}

		$formatted_total_size = size_format( $total_file_size );
		$site_url = esc_url( get_rest_url( null, 'gp-language-pack-server/v1' ) );
		$import_url = esc_url( get_rest_url( null, 'gp-language-pack-server/v1/projects/{project-slug-or-id}/import' ) );

		?>
		<style>
			@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

			.gp-lp-wrap {
				font-family: 'Outfit', sans-serif;
				margin: 20px 20px 0 0;
				max-width: 1200px;
			}
			.gp-lp-header {
				background: linear-gradient(135deg, hsl(250, 70%, 55%) 0%, hsl(280, 70%, 50%) 100%);
				color: #fff;
				padding: 30px 40px;
				border-radius: 12px 12px 0 0;
				box-shadow: 0 4px 15px rgba(0,0,0,0.05);
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.gp-lp-title h1 {
				color: #fff;
				font-size: 28px;
				font-weight: 700;
				margin: 0 0 5px 0;
				line-height: 1.2;
				text-shadow: 0 2px 4px rgba(0,0,0,0.1);
			}
			.gp-lp-title p {
				margin: 0;
				opacity: 0.85;
				font-size: 14px;
				font-weight: 300;
			}
			.gp-lp-tabs {
				background: #fff;
				border-bottom: 1px solid #e5e7eb;
				display: flex;
				padding: 0 20px;
				box-shadow: 0 2px 5px rgba(0,0,0,0.02);
			}
			.gp-lp-tab {
				padding: 18px 24px;
				font-size: 15px;
				font-weight: 500;
				color: #4b5563;
				text-decoration: none;
				border-bottom: 3px solid transparent;
				transition: all 0.2s ease;
				cursor: pointer;
			}
			.gp-lp-tab:hover {
				color: hsl(250, 70%, 55%);
			}
			.gp-lp-tab.active {
				color: hsl(250, 70%, 55%);
				border-bottom-color: hsl(250, 70%, 55%);
			}
			.gp-lp-content-panel {
				display: none;
				background: #fff;
				padding: 35px 40px;
				border-radius: 0 0 12px 12px;
				box-shadow: 0 4px 20px rgba(0,0,0,0.03);
			}
			.gp-lp-content-panel.active {
				display: block;
				animation: fadeIn 0.35s ease;
			}
			@keyframes fadeIn {
				from { opacity: 0; transform: translateY(5px); }
				to { opacity: 1; transform: translateY(0); }
			}
			/* Grid metrics */
			.gp-lp-metrics {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 20px;
				margin-bottom: 35px;
			}
			.gp-lp-card {
				background: linear-gradient(145deg, #ffffff, #fcfdff);
				border: 1px solid #eef2f7;
				padding: 24px;
				border-radius: 12px;
				box-shadow: 0 4px 6px rgba(0,0,0,0.01);
				display: flex;
				flex-direction: column;
			}
			.gp-lp-card-title {
				font-size: 13px;
				text-transform: uppercase;
				color: #6b7280;
				letter-spacing: 0.05em;
				margin-bottom: 8px;
				font-weight: 600;
			}
			.gp-lp-card-value {
				font-size: 26px;
				font-weight: 700;
				color: #111827;
			}
			/* Table Styling */
			.gp-lp-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 10px;
			}
			.gp-lp-table th {
				text-align: left;
				padding: 14px 18px;
				background: #f8fafc;
				color: #475569;
				font-weight: 600;
				font-size: 13px;
				border-bottom: 2px solid #e2e8f0;
			}
			.gp-lp-table td {
				padding: 16px 18px;
				border-bottom: 1px solid #f1f5f9;
				vertical-align: middle;
				font-size: 14px;
				color: #334155;
			}
			.gp-lp-project-row {
				background-color: #fbfcfe;
				cursor: pointer;
				transition: background-color 0.2s ease;
			}
			.gp-lp-project-row:hover {
				background-color: #f3f6fc;
			}
			.gp-lp-project-name {
				font-weight: 600;
				color: #1e293b;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.gp-lp-project-arrow {
				transition: transform 0.2s ease;
				display: inline-block;
				font-size: 11px;
				color: #94a3b8;
			}
			.gp-lp-project-row.expanded .gp-lp-project-arrow {
				transform: rotate(90deg);
			}
			.gp-lp-sets-row {
				display: none;
				background-color: #fff;
			}
			.gp-lp-sets-container {
				padding: 10px 20px 25px 40px;
			}
			/* Badges */
			.gp-lp-badge {
				display: inline-flex;
				align-items: center;
				padding: 4px 10px;
				border-radius: 9999px;
				font-size: 12px;
				font-weight: 500;
				gap: 5px;
			}
			.gp-lp-badge-active {
				background-color: #ecfdf5;
				color: #059669;
			}
			.gp-lp-badge-generated {
				background-color: #eff6ff;
				color: #2563eb;
			}
			.gp-lp-badge-needs-update {
				background-color: #fffbeb;
				color: #d97706;
			}
			.gp-lp-badge-missing {
				background-color: #f3f4f6;
				color: #6b7280;
			}
			/* Progress bar */
			.gp-lp-progress-container {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.gp-lp-progress-bar {
				flex: 1;
				background-color: #e2e8f0;
				height: 6px;
				border-radius: 3px;
				overflow: hidden;
				min-width: 100px;
			}
			.gp-lp-progress-fill {
				background: linear-gradient(90deg, hsl(250, 70%, 55%), hsl(280, 70%, 50%));
				height: 100%;
				border-radius: 3px;
				transition: width 0.3s ease;
			}
			/* Buttons */
			.gp-lp-btn {
				background-color: #fff;
				border: 1px solid #cbd5e1;
				color: #334155;
				padding: 6px 12px;
				border-radius: 6px;
				cursor: pointer;
				font-weight: 500;
				font-size: 12px;
				transition: all 0.2s ease;
				display: inline-flex;
				align-items: center;
				gap: 5px;
			}
			.gp-lp-btn:hover {
				border-color: hsl(250, 70%, 55%);
				color: hsl(250, 70%, 55%);
				box-shadow: 0 2px 4px rgba(0,0,0,0.03);
			}
			.gp-lp-btn-primary {
				background: hsl(250, 70%, 55%);
				border-color: hsl(250, 70%, 55%);
				color: #fff;
			}
			.gp-lp-btn-primary:hover {
				background: hsl(250, 70%, 50%);
				border-color: hsl(250, 70%, 50%);
				color: #fff;
			}
			.gp-lp-btn-danger {
				color: #dc2626;
			}
			.gp-lp-btn-danger:hover {
				background-color: #fef2f2;
				border-color: #fca5a5;
				color: #dc2626;
			}
			.gp-lp-btn:disabled {
				opacity: 0.5;
				cursor: not-allowed;
			}
			/* Forms */
			.gp-lp-form-row {
				margin-bottom: 25px;
			}
			.gp-lp-form-row label {
				display: block;
				font-weight: 600;
				color: #1e293b;
				margin-bottom: 8px;
				font-size: 15px;
			}
			.gp-lp-form-row input[type="number"] {
				padding: 10px 14px;
				border: 1px solid #cbd5e1;
				border-radius: 8px;
				font-size: 15px;
				width: 120px;
				outline: none;
				transition: border-color 0.2s;
			}
			.gp-lp-form-row input[type="number"]:focus {
				border-color: hsl(250, 70%, 55%);
				box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
			}
			.gp-lp-form-help {
				font-size: 13px;
				color: #64748b;
				margin-top: 6px;
				line-height: 1.4;
			}
			/* Code area */
			.gp-lp-code-container {
				position: relative;
				margin-top: 15px;
			}
			.gp-lp-code {
				background: #0f172a;
				color: #e2e8f0;
				padding: 24px;
				border-radius: 8px;
				font-family: 'Fira Code', 'Courier New', Courier, monospace;
				font-size: 13px;
				line-height: 1.5;
				overflow-x: auto;
				margin: 0;
			}
			.gp-lp-copy-btn {
				position: absolute;
				top: 12px;
				right: 12px;
				background: rgba(255,255,255,0.1);
				border: none;
				color: #fff;
				padding: 6px 12px;
				border-radius: 4px;
				font-size: 11px;
				cursor: pointer;
				transition: background 0.2s;
			}
			.gp-lp-copy-btn:hover {
				background: rgba(255,255,255,0.2);
			}
			.gp-lp-doc-block {
				margin-bottom: 35px;
				border-bottom: 1px solid #f1f5f9;
				padding-bottom: 25px;
			}
			.gp-lp-doc-block:last-child {
				border-bottom: none;
				padding-bottom: 0;
			}
			.gp-lp-doc-block h3 {
				font-size: 18px;
				font-weight: 600;
				color: #0f172a;
				margin-top: 0;
				margin-bottom: 10px;
			}
			.gp-lp-doc-block p {
				color: #475569;
				line-height: 1.5;
				margin: 0 0 15px 0;
			}
			.gp-lp-alert {
				background-color: #f8fafc;
				border-left: 4px solid hsl(250, 70%, 55%);
				padding: 15px 20px;
				border-radius: 0 8px 8px 0;
				margin-bottom: 20px;
				font-size: 14px;
				line-height: 1.5;
				color: #475569;
			}
		</style>

		<div class="gp-lp-wrap">
			<!-- Header -->
			<div class="gp-lp-header">
				<div class="gp-lp-title">
					<h1><?php esc_html_e( 'Language Pack Server for GlotPress', 'gp-language-pack-server' ); ?></h1>
					<p><?php esc_html_e( 'Manage language pack builds and client delivery', 'gp-language-pack-server' ); ?></p>
				</div>
			</div>

			<!-- Tabs -->
			<div class="gp-lp-tabs">
				<div class="gp-lp-tab active" data-target="panel-dashboard"><?php esc_html_e( 'Dashboard', 'gp-language-pack-server' ); ?></div>
				<div class="gp-lp-tab" data-target="panel-settings"><?php esc_html_e( 'Settings', 'gp-language-pack-server' ); ?></div>
			</div>

			<!-- Dashboard Panel -->
			<div id="panel-dashboard" class="gp-lp-content-panel active">
				<!-- Metrics -->
				<div class="gp-lp-metrics">
					<div class="gp-lp-card">
						<span class="gp-lp-card-title"><?php esc_html_e( 'Active Projects', 'gp-language-pack-server' ); ?></span>
						<span class="gp-lp-card-value"><?php echo count( $projects ); ?></span>
					</div>
					<div class="gp-lp-card">
						<span class="gp-lp-card-title"><?php esc_html_e( 'Packs on Disk', 'gp-language-pack-server' ); ?></span>
						<span class="gp-lp-card-value"><?php echo esc_html( $total_packs_generated ); ?></span>
					</div>
					<div class="gp-lp-card">
						<span class="gp-lp-card-title"><?php esc_html_e( 'Disk Usage', 'gp-language-pack-server' ); ?></span>
						<span class="gp-lp-card-value"><?php echo esc_html( $formatted_total_size ); ?></span>
					</div>
				</div>

				<?php if ( empty( $projects ) ) : ?>
					<div class="gp-lp-alert">
						<?php esc_html_e( 'No active GlotPress projects found. Make sure you create and activate projects in GlotPress first.', 'gp-language-pack-server' ); ?>
					</div>
				<?php else : ?>
					<table class="gp-lp-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Project Name', 'gp-language-pack-server' ); ?></th>
								<th><?php esc_html_e( 'Project Path', 'gp-language-pack-server' ); ?></th>
								<th><?php esc_html_e( 'Sets', 'gp-language-pack-server' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $projects as $project ) : ?>
								<?php
								$sets = GP::$translation_set->by_project_id( $project->id );
								?>
								<tr class="gp-lp-project-row" data-project-id="<?php echo esc_attr( $project->id ); ?>">
									<td>
										<span class="gp-lp-project-name">
											<span class="gp-lp-project-arrow">▶</span>
											<?php echo esc_html( $project->name ); ?>
										</span>
									</td>
									<td><code><?php echo esc_html( $project->path ); ?></code></td>
									<td><span class="gp-lp-badge gp-lp-badge-active"><?php echo count( $sets ); ?> Sets</span></td>
								</tr>
								<tr class="gp-lp-sets-row" id="sets-row-<?php echo esc_attr( $project->id ); ?>">
									<td colspan="3">
										<div class="gp-lp-sets-container">
											<?php if ( empty( $sets ) ) : ?>
												<p class="gp-lp-form-help"><?php esc_html_e( 'No translation sets exist for this project yet.', 'gp-language-pack-server' ); ?></p>
											<?php else : ?>
												<table class="gp-lp-table" style="background:#fff; border: 1px solid #f1f5f9;">
													<thead>
														<tr>
															<th><?php esc_html_e( 'Locale (WP)', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'Progress', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'ZIP Status', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'ZIP Metadata', 'gp-language-pack-server' ); ?></th>
															<th style="text-align: right;"><?php esc_html_e( 'Actions', 'gp-language-pack-server' ); ?></th>
														</tr>
													</thead>
													<tbody>
														<?php foreach ( $sets as $set ) : ?>
															<?php
															$locale = GP_Locales::by_slug( $set->locale );
															if ( ! $locale ) {
																continue;
															}
															$wp_locale = $locale->wp_locale;
															$percent = (int) $set->percent_translated();

															// File checks.
															$filename = RestController::get_pack_filename( $project->slug, $wp_locale );
															$file_path = trailingslashit( gp_language_pack_dir() ) . $project->slug . '/' . $filename;
															$exists = file_exists( $file_path );
															
															$status_badge = '<span class="gp-lp-badge gp-lp-badge-missing">' . esc_html__( 'Not Generated', 'gp-language-pack-server' ) . '</span>';
															$size_info = '—';
															$date_info = '—';

															if ( $exists ) {
																$zip_mtime = filemtime( $file_path );
																$last_mod = $set->last_modified();
																
																$size_info = size_format( filesize( $file_path ) );
																$date_info = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $zip_mtime );

																if ( $last_mod && strtotime( $last_mod ) > $zip_mtime ) {
																	$status_badge = '<span class="gp-lp-badge gp-lp-badge-needs-update">' . esc_html__( 'Needs Update', 'gp-language-pack-server' ) . '</span>';
																} else {
																	$status_badge = '<span class="gp-lp-badge gp-lp-badge-generated">' . esc_html__( 'Generated', 'gp-language-pack-server' ) . '</span>';
																}
															}
															?>
															<tr id="set-row-<?php echo esc_attr( $set->id ); ?>">
																<td>
																	<strong><?php echo esc_html( $locale->english_name ); ?></strong>
																	<br><code style="font-size:11px;"><?php echo esc_html( $wp_locale ); ?></code>
																</td>
																<td>
																	<div class="gp-lp-progress-container">
																		<div class="gp-lp-progress-bar">
																			<div class="gp-lp-progress-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
																		</div>
																		<span><?php echo esc_html( $percent ); ?>%</span>
																	</div>
																</td>
																<td class="col-status"><?php echo $status_badge; ?></td>
																<td class="col-meta">
																	<span class="meta-size"><?php echo esc_html( $size_info ); ?></span>
																	<br><span class="meta-date" style="font-size:11px; color:#64748b;"><?php echo esc_html( $date_info ); ?></span>
																</td>
																<td style="text-align: right;">
																	<button class="gp-lp-btn gp-lp-btn-primary btn-generate" 
																			data-project-id="<?php echo esc_attr( $project->id ); ?>" 
																			data-set-id="<?php echo esc_attr( $set->id ); ?>">
																		<?php esc_html_e( 'Build ZIP', 'gp-language-pack-server' ); ?>
																	</button>
																	<button class="gp-lp-btn gp-lp-btn-danger btn-delete" 
																			data-project-id="<?php echo esc_attr( $project->id ); ?>" 
																			data-set-id="<?php echo esc_attr( $set->id ); ?>"
																			<?php echo ! $exists ? 'disabled' : ''; ?>>
																		<?php esc_html_e( 'Delete', 'gp-language-pack-server' ); ?>
																	</button>
																</td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Settings Panel -->
			<div id="panel-settings" class="gp-lp-content-panel">
				<form method="post" action="options.php">
					<?php settings_fields( 'gp_language_pack_settings' ); ?>

					<div class="gp-lp-form-row">
						<label for="gp_language_pack_threshold"><?php esc_html_e( 'Translation Progress Threshold', 'gp-language-pack-server' ); ?></label>
						<input type="number" id="gp_language_pack_threshold" name="gp_language_pack_threshold" 
							   value="<?php echo esc_attr( $threshold ); ?>" min="0" max="100" />
						<p class="gp-lp-form-help">
							<?php esc_html_e( 'Minimum translation percentage required for a language pack to be generated and served. Packs below this threshold will not be sent to clients.', 'gp-language-pack-server' ); ?>
						</p>
					</div>

					<?php submit_button( __( 'Save Control Settings', 'gp-language-pack-server' ), 'primary gp-lp-btn gp-lp-btn-primary' ); ?>
				</form>
			</div>

			</div>

		<!-- Script Logic -->
		<script>
			function gpLPCopyCode(btn) {
				const container = btn.closest('.gp-lp-code-container');
				const code = container.querySelector('.gp-lp-code').innerText;
				navigator.clipboard.writeText(code).then(() => {
					const oldText = btn.innerText;
					btn.innerText = '<?php esc_attr_e( 'Copied!', 'gp-language-pack-server' ); ?>';
					setTimeout(() => btn.innerText = oldText, 2000);
				});
			}

			document.addEventListener('DOMContentLoaded', function() {
				// Tabs functionality
				const tabs = document.querySelectorAll('.gp-lp-tab');
				const panels = document.querySelectorAll('.gp-lp-content-panel');

				tabs.forEach(tab => {
					tab.addEventListener('click', function() {
						tabs.forEach(t => t.classList.remove('active'));
						panels.forEach(p => p.classList.remove('active'));

						this.classList.add('active');
						document.getElementById(this.dataset.target).classList.add('active');
					});
				});

				// Accordion functionality for Projects list
				const projectRows = document.querySelectorAll('.gp-lp-project-row');
				projectRows.forEach(row => {
					row.addEventListener('click', function(e) {
						if (e.target.closest('button') || e.target.closest('a')) {
							return; // Skip if action button clicked
						}
						
						this.classList.toggle('expanded');
						const id = this.dataset.projectId;
						const setsRow = document.getElementById('sets-row-' + id);
						if (setsRow) {
							setsRow.style.display = setsRow.style.display === 'table-row' ? 'none' : 'table-row';
						}
					});
				});

				// Nonce and AJAX URL
				const nonce = '<?php echo esc_js( wp_create_nonce( 'gp_language_pack_admin_nonce' ) ); ?>';
				const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

				// Manual ZIP generation click
				document.querySelectorAll('.btn-generate').forEach(btn => {
					btn.addEventListener('click', function() {
						const row = this.closest('tr');
						const projId = this.dataset.projectId;
						const setId = this.dataset.setId;

						this.disabled = true;
						const oldText = this.innerText;
						this.innerText = '<?php esc_attr_e( 'Building...', 'gp-language-pack-server' ); ?>';

						fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: `action=gp_language_pack_ajax_generate&project_id=${projId}&set_id=${setId}&nonce=${nonce}`
						})
						.then(res => res.json())
						.then(res => {
							this.disabled = false;
							this.innerText = oldText;

							if (res.success) {
								// Update status badge
								row.querySelector('.col-status').innerHTML = '<span class="gp-lp-badge gp-lp-badge-generated"><?php esc_attr_e( 'Generated', 'gp-language-pack-server' ); ?></span>';
								
								// Update metadata size and date
								row.querySelector('.col-meta .meta-size').innerText = res.data.size;
								row.querySelector('.col-meta .meta-date').innerText = res.data.date;

								// Enable delete button
								row.querySelector('.btn-delete').removeAttribute('disabled');
							} else {
								alert('Error: ' + res.data.message);
							}
						})
						.catch(err => {
							this.disabled = false;
							this.innerText = oldText;
							alert('An unexpected error occurred.');
						});
					});
				});

				// Manual ZIP deletion click
				document.querySelectorAll('.btn-delete').forEach(btn => {
					btn.addEventListener('click', function() {
						if (!confirm('<?php esc_js( esc_html_e( 'Are you sure you want to delete this language pack ZIP file from disk?', 'gp-language-pack-server' ) ); ?>')) {
							return;
						}

						const row = this.closest('tr');
						const projId = this.dataset.projectId;
						const setId = this.dataset.setId;

						this.disabled = true;
						const oldText = this.innerText;
						this.innerText = '<?php esc_attr_e( 'Deleting...', 'gp-language-pack-server' ); ?>';

						fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: `action=gp_language_pack_ajax_delete&project_id=${projId}&set_id=${setId}&nonce=${nonce}`
						})
						.then(res => res.json())
						.then(res => {
							this.disabled = false;
							this.innerText = oldText;

							if (res.success) {
								// Disable delete button
								this.disabled = true;

								// Update status badge
								row.querySelector('.col-status').innerHTML = '<span class="gp-lp-badge gp-lp-badge-missing"><?php esc_attr_e( 'Not Generated', 'gp-language-pack-server' ); ?></span>';
								
								// Clear metadata size and date
								row.querySelector('.col-meta .meta-size').innerText = '—';
								row.querySelector('.col-meta .meta-date').innerText = '—';
							} else {
								alert('Error: ' + res.data.message);
							}
						})
						.catch(err => {
							this.disabled = false;
							this.innerText = oldText;
							alert('An unexpected error occurred.');
						});
					});
				});
			});
		</script>
		<?php
	}
}
