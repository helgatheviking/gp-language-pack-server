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
	 * Registers the admin menu item under Tools.
	 */
	public function register_admin_menu(): void {
		add_management_page(
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
			.gp-lp-wrap {
				margin-top: 20px;
			}
			.gp-lp-content-panel {
				display: none;
				margin-top: 20px;
			}
			.gp-lp-content-panel.active {
				display: block;
			}
			/* Grid metrics */
			.gp-lp-metrics {
				display: flex;
				flex-wrap: wrap;
				gap: 20px;
				margin: 20px 0 30px;
			}
			.gp-lp-card {
				background: #fff;
				border: 1px solid #c3c4c7;
				padding: 15px 20px;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				min-width: 180px;
				flex: 1;
				max-width: 250px;
			}
			.gp-lp-card-title {
				font-size: 11px;
				color: #64748b;
				display: block;
				margin-bottom: 5px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.05em;
			}
			.gp-lp-card-value {
				font-size: 24px;
				font-weight: 600;
				color: #1d2327;
			}
			/* Table Styling & Interactions */
			.gp-lp-project-name {
				font-weight: 600;
				display: inline-flex;
				align-items: center;
				gap: 8px;
			}
			.gp-lp-project-arrow {
				transition: transform 0.15s ease-in-out;
				color: #8c8f94;
			}
			.gp-lp-project-row.expanded .gp-lp-project-arrow {
				transform: rotate(90deg);
			}
			.gp-lp-sets-container {
				padding: 10px 20px 20px 40px;
			}
			/* Badges */
			.gp-lp-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 500;
			}
			.gp-lp-badge-active {
				background-color: #f0f0f1;
				color: #2c3338;
				border: 1px solid #dcdcde;
			}
			.gp-lp-badge-generated {
				background-color: #d1e7dd;
				color: #0f5132;
				border: 1px solid #badbcc;
			}
			.gp-lp-badge-needs-update {
				background-color: #fff3cd;
				color: #664d03;
				border: 1px solid #ffecb5;
			}
			.gp-lp-badge-missing {
				background-color: #f8d7da;
				color: #842029;
				border: 1px solid #f5c2c7;
			}
			/* Progress bar */
			.gp-lp-progress-container {
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.gp-lp-progress-bar {
				flex: 1;
				background-color: #dcdcde;
				height: 8px;
				border-radius: 4px;
				overflow: hidden;
				max-width: 120px;
			}
			.gp-lp-progress-fill {
				background-color: #2271b1;
				height: 100%;
				transition: width 0.3s ease;
			}
			/* Delete button standard style */
			.btn-delete {
				color: #b32d2e !important;
				border-color: #b32d2e !important;
			}
			.btn-delete:hover {
				background: #fcf0f1 !important;
				color: #b32d2e !important;
			}
			.btn-delete:focus {
				box-shadow: 0 0 0 1px #b32d2e !important;
			}
			.btn-delete:disabled {
				color: #a7aaad !important;
				border-color: #dcdcde !important;
				background: #f6f7f7 !important;
			}
		</style>

		<div class="wrap gp-lp-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Language Pack Server for GlotPress', 'gp-language-pack-server' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Tabs -->
			<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom: 20px;">
				<a href="#dashboard" class="nav-tab nav-tab-active" data-target="panel-dashboard"><?php esc_html_e( 'Dashboard', 'gp-language-pack-server' ); ?></a>
				<a href="#settings" class="nav-tab" data-target="panel-settings"><?php esc_html_e( 'Settings', 'gp-language-pack-server' ); ?></a>
			</nav>

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
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'No active GlotPress projects found. Make sure you create and activate projects in GlotPress first.', 'gp-language-pack-server' ); ?></p>
					</div>
				<?php else : ?>
					<table class="widefat striped">
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
								<tr class="gp-lp-project-row" data-project-id="<?php echo esc_attr( $project->id ); ?>" style="cursor: pointer;">
									<td>
										<span class="gp-lp-project-name">
											<span class="dashicons dashicons-arrow-right-alt2 gp-lp-project-arrow"></span>
											<strong><?php echo esc_html( $project->name ); ?></strong>
										</span>
									</td>
									<td><code><?php echo esc_html( $project->path ); ?></code></td>
									<td><span class="gp-lp-badge gp-lp-badge-active"><?php echo count( $sets ); ?> Sets</span></td>
								</tr>
								<tr class="gp-lp-sets-row" id="sets-row-<?php echo esc_attr( $project->id ); ?>" style="display: none;">
									<td colspan="3">
										<div class="gp-lp-sets-container">
											<?php if ( empty( $sets ) ) : ?>
												<p class="description"><?php esc_html_e( 'No translation sets exist for this project yet.', 'gp-language-pack-server' ); ?></p>
											<?php else : ?>
												<table class="widefat" style="border: 1px solid #c3c4c7;">
													<thead>
														<tr>
															<th><?php esc_html_e( 'Locale (WP)', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'Progress', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'Pack status', 'gp-language-pack-server' ); ?></th>
															<th><?php esc_html_e( 'Pack Metadata', 'gp-language-pack-server' ); ?></th>
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
																	<button class="button button-primary btn-generate" 
																			data-project-id="<?php echo esc_attr( $project->id ); ?>" 
																			data-set-id="<?php echo esc_attr( $set->id ); ?>">
																		<?php esc_html_e( 'Regenerate language pack', 'gp-language-pack-server' ); ?>
																	</button>
																	<button class="button btn-delete" 
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

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="gp_language_pack_threshold"><?php esc_html_e( 'Translation Progress Threshold', 'gp-language-pack-server' ); ?></label>
								</th>
								<td>
									<input type="number" id="gp_language_pack_threshold" name="gp_language_pack_threshold" 
										   value="<?php echo esc_attr( $threshold ); ?>" min="0" max="100" class="small-text" />
									<p class="description">
										<?php esc_html_e( 'Minimum translation percentage required for a language pack to be generated and served. Packs below this threshold will not be sent to clients.', 'gp-language-pack-server' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Save Control Settings', 'gp-language-pack-server' ) ); ?>
				</form>
			</div>

		</div>

		<!-- Script Logic -->
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				// Tabs functionality
				const tabs = document.querySelectorAll('.nav-tab');
				const panels = document.querySelectorAll('.gp-lp-content-panel');

				tabs.forEach(tab => {
					tab.addEventListener('click', function(e) {
						e.preventDefault();
						tabs.forEach(t => t.classList.remove('nav-tab-active'));
						panels.forEach(p => p.classList.remove('active'));

						this.classList.add('nav-tab-active');
						const targetId = this.dataset.target;
						const targetPanel = document.getElementById(targetId);
						if (targetPanel) {
							targetPanel.classList.add('active');
						}
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

				// Helper to generate a single pack via AJAX and return a Promise
				function generatePack(btn) {
					return new Promise((resolve, reject) => {
						const row = btn.closest('tr');
						const projId = btn.dataset.projectId;
						const setId = btn.dataset.setId;

						btn.disabled = true;
						const oldText = btn.innerText;
						btn.innerText = '<?php esc_attr_e( 'Generating...', 'gp-language-pack-server' ); ?>';

						fetch(ajaxUrl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: `action=gp_language_pack_ajax_generate&project_id=${projId}&set_id=${setId}&nonce=${nonce}`
						})
						.then(res => res.json())
						.then(res => {
							btn.disabled = false;
							btn.innerText = oldText;

							if (res.success) {
								// Update status badge
								row.querySelector('.col-status').innerHTML = '<span class="gp-lp-badge gp-lp-badge-generated"><?php esc_attr_e( 'Generated', 'gp-language-pack-server' ); ?></span>';
								
								// Update metadata size and date
								row.querySelector('.col-meta .meta-size').innerText = res.data.size;
								row.querySelector('.col-meta .meta-date').innerText = res.data.date;

								// Enable delete button
								const deleteBtn = row.querySelector('.btn-delete');
								if (deleteBtn) {
									deleteBtn.removeAttribute('disabled');
								}
								resolve(res.data);
							} else {
								resolve({ error: res.data.message });
							}
						})
						.catch(err => {
							btn.disabled = false;
							btn.innerText = oldText;
							resolve({ error: 'An unexpected network error occurred.' });
						});
					});
				}

				// Manual ZIP generation click
				document.querySelectorAll('.btn-generate').forEach(btn => {
					btn.addEventListener('click', function() {
						generatePack(btn).then(result => {
							if (result.error) {
								alert('Error: ' + result.error);
							}
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

