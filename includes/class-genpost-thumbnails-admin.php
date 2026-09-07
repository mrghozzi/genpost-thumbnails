<?php
/**
 * GenPost Thumbnails Admin Interface.
 *
 * Handles admin menus, meta boxes, settings, AJAX endpoints, and publishing hooks.
 *
 * @package Genpost_Thumbnails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Genpost_Thumbnails_Admin
 */
class Genpost_Thumbnails_Admin {

	/**
	 * API handler instance.
	 *
	 * @var Genpost_Thumbnails_API
	 */
	private $api;

	/**
	 * Media handler instance.
	 *
	 * @var Genpost_Thumbnails_Media
	 */
	private $media;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'genpost-thumbnails';

	/**
	 * Constructor.
	 *
	 * @param Genpost_Thumbnails_API   $api   API handler instance.
	 * @param Genpost_Thumbnails_Media $media Media handler instance.
	 */
	public function __construct( Genpost_Thumbnails_API $api, Genpost_Thumbnails_Media $media ) {
		$this->api   = $api;
		$this->media = $media;
	}

	/**
	 * Register administrative hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_settings_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'admin_notices', array( $this, 'display_optin_notice' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_genpost_thumb_optin', array( $this, 'ajax_handle_optin' ) );
		add_action( 'wp_ajax_genpost_thumb_suggest_prompt', array( $this, 'ajax_suggest_prompt' ) );
		add_action( 'wp_ajax_genpost_thumb_generate_preview', array( $this, 'ajax_generate_preview' ) );
		add_action( 'wp_ajax_genpost_thumb_apply_image', array( $this, 'ajax_apply_image' ) );
		add_action( 'wp_ajax_genpost_thumb_test_generate', array( $this, 'ajax_test_generate' ) );

		// Automated generation on post publication.
		add_action( 'transition_post_status', array( $this, 'handle_auto_generate_on_publish' ), 10, 3 );
	}

	/**
	 * Register settings menu item under Settings.
	 *
	 * @return void
	 */
	public function register_settings_menu() {
		add_options_page(
			__( 'GenPost Thumbnails Settings', 'genpost-thumbnails' ),
			__( 'GenPost Thumbnails', 'genpost-thumbnails' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings with the WordPress Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'genpost_thumb_settings_group',
			'genpost_thumb_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize submitted settings array.
	 *
	 * @param array $input Raw input from form.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Opt-in consent.
		$sanitized['optin'] = ( isset( $input['optin'] ) && 'yes' === $input['optin'] ) ? 'yes' : 'no';

		// Auto generate on publish.
		$sanitized['auto_generate'] = ( isset( $input['auto_generate'] ) && 'yes' === $input['auto_generate'] ) ? 'yes' : 'no';

		// Dimensions.
		$sanitized['width']  = isset( $input['width'] ) ? max( 256, min( 2048, absint( $input['width'] ) ) ) : 1200;
		$sanitized['height'] = isset( $input['height'] ) ? max( 256, min( 2048, absint( $input['height'] ) ) ) : 630;

		// Model.
		$allowed_models     = array_keys( $this->api->get_available_models() );
		$sanitized['model'] = ( isset( $input['model'] ) && in_array( $input['model'], $allowed_models, true ) ) ? sanitize_key( $input['model'] ) : 'flux';

		// Style preset.
		$allowed_presets           = array_keys( $this->api->get_style_presets() );
		$sanitized['style_preset'] = ( isset( $input['style_preset'] ) && in_array( $input['style_preset'], $allowed_presets, true ) ) ? sanitize_key( $input['style_preset'] ) : 'photorealistic';

		// Prompts.
		$sanitized['prompt_prefix']   = isset( $input['prompt_prefix'] ) ? sanitize_text_field( $input['prompt_prefix'] ) : '';
		$sanitized['prompt_suffix']   = isset( $input['prompt_suffix'] ) ? sanitize_text_field( $input['prompt_suffix'] ) : '';
		$sanitized['negative_prompt'] = isset( $input['negative_prompt'] ) ? sanitize_text_field( $input['negative_prompt'] ) : '';

		// Post types.
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized['post_types'] = array_map( 'sanitize_key', $input['post_types'] );
		} else {
			$sanitized['post_types'] = array( 'post' );
		}

		return $sanitized;
	}

	/**
	 * Enqueue admin scripts and stylesheets.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_post_edit    = in_array( $screen->base, array( 'post', 'edit' ), true );
		$is_settings_pg  = ( 'settings_page_' . self::MENU_SLUG === $screen->id );

		if ( ! $is_post_edit && ! $is_settings_pg ) {
			return;
		}

		wp_enqueue_style(
			'genpost-thumbnails-admin-css',
			GENPOST_THUMB_URL . 'assets/css/admin.css',
			array(),
			GENPOST_THUMB_VERSION
		);

		wp_enqueue_script(
			'genpost-thumbnails-admin-js',
			GENPOST_THUMB_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GENPOST_THUMB_VERSION,
			true
		);

		global $post;
		$post_id = ( $post && isset( $post->ID ) ) ? $post->ID : 0;

		$localized_data = array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'genpost_thumb_nonce' ),
			'postId'     => $post_id,
			'isOptedIn'  => $this->api->is_opted_in(),
			'settingsUrl'=> admin_url( 'options-general.php?page=' . self::MENU_SLUG ),
			'i18n'       => array(
				'generating'         => __( 'Generating AI Image…', 'genpost-thumbnails' ),
				'suggesting'         => __( 'Analyzing content & creating prompt…', 'genpost-thumbnails' ),
				'applying'           => __( 'Downloading and setting as Featured Image…', 'genpost-thumbnails' ),
				'successApplied'     => __( 'Featured Image successfully updated!', 'genpost-thumbnails' ),
				'optinRequired'      => __( 'Pollinations.ai service is currently disabled. Please enable it in settings or the banner above.', 'genpost-thumbnails' ),
				'emptyPrompt'        => __( 'Please enter a prompt or click "Suggest from Content" first.', 'genpost-thumbnails' ),
				'errorOccurred'      => __( 'An error occurred. Please check console or try again.', 'genpost-thumbnails' ),
				'confirmRegenerate'  => __( 'Generate a new image? Current preview will be replaced.', 'genpost-thumbnails' ),
				'testSuccess'        => __( 'Connection successful! Test image generated.', 'genpost-thumbnails' ),
				'testingConnection'  => __( 'Testing connection to Pollinations.ai…', 'genpost-thumbnails' ),
			),
		);

		wp_localize_script( 'genpost-thumbnails-admin-js', 'genpostThumbData', $localized_data );
	}

	/**
	 * Register the Meta Box in post editing screens.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$settings   = $this->api->get_settings();
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'genpost_thumbnails_meta_box',
				__( 'GenPost Thumbnails – Smart Featured Image', 'genpost-thumbnails' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the Meta Box HTML.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$is_opted_in  = $this->api->is_opted_in();
		$presets      = $this->api->get_style_presets();
		$settings     = $this->api->get_settings();
		$selected_pst = ! empty( $settings['style_preset'] ) ? $settings['style_preset'] : 'photorealistic';

		wp_nonce_field( 'genpost_thumb_meta_box_nonce', 'genpost_thumb_meta_box_field' );
		?>
		<div id="genpost-thumb-metabox" class="genpost-thumb-wrap">
			<?php if ( ! $is_opted_in ) : ?>
				<div class="genpost-thumb-alert genpost-thumb-alert-warning">
					<p>
						<strong><?php esc_html_e( 'Service Disabled', 'genpost-thumbnails' ); ?></strong><br>
						<?php esc_html_e( 'Consent is required before sending requests to Pollinations.ai.', 'genpost-thumbnails' ); ?>
					</p>
					<button type="button" class="button button-secondary genpost-thumb-quick-optin-btn">
						<?php esc_html_e( 'Enable Service Now', 'genpost-thumbnails' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<div class="genpost-thumb-field-group">
				<label for="genpost-thumb-prompt-input" class="genpost-thumb-label">
					<?php esc_html_e( 'Image Prompt Description:', 'genpost-thumbnails' ); ?>
				</label>
				<textarea
					id="genpost-thumb-prompt-input"
					class="widefat genpost-thumb-textarea"
					rows="3"
					placeholder="<?php esc_attr_e( 'Click "Suggest from Content" or type custom visual prompt keywords…', 'genpost-thumbnails' ); ?>"
				></textarea>
			</div>

			<div class="genpost-thumb-field-group">
				<label for="genpost-thumb-style-select" class="genpost-thumb-label">
					<?php esc_html_e( 'Art & Visual Style:', 'genpost-thumbnails' ); ?>
				</label>
				<select id="genpost-thumb-style-select" class="widefat">
					<?php foreach ( $presets as $key => $preset ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_pst, $key ); ?>>
							<?php echo esc_html( $preset['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="genpost-thumb-actions">
				<button type="button" id="genpost-thumb-suggest-btn" class="button button-secondary" title="<?php esc_attr_e( 'Auto-extract relevant visual prompt based on title & categories', 'genpost-thumbnails' ); ?>">
					<span class="dashicons dashicons-lightbulb"></span>
					<?php esc_html_e( 'Suggest Prompt', 'genpost-thumbnails' ); ?>
				</button>

				<button type="button" id="genpost-thumb-generate-btn" class="button button-primary">
					<span class="dashicons dashicons-art"></span>
					<?php esc_html_e( 'Generate AI Image', 'genpost-thumbnails' ); ?>
				</button>
			</div>

			<!-- Status & Loader -->
			<div id="genpost-thumb-status" class="genpost-thumb-status" style="display:none;">
				<span class="spinner is-active"></span>
				<span class="genpost-thumb-status-text"></span>
			</div>

			<!-- Live Preview Area -->
			<div id="genpost-thumb-preview-box" class="genpost-thumb-preview-box" style="display:none;">
				<div class="genpost-thumb-preview-title">
					<?php esc_html_e( 'Preview Result', 'genpost-thumbnails' ); ?>
				</div>
				<div class="genpost-thumb-image-container">
					<img id="genpost-thumb-preview-img" src="" alt="<?php esc_attr_e( 'AI Generated Preview', 'genpost-thumbnails' ); ?>" />
				</div>
				<div class="genpost-thumb-preview-actions">
					<button type="button" id="genpost-thumb-apply-btn" class="button button-primary button-large">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Set as Featured Image', 'genpost-thumbnails' ); ?>
					</button>
					<button type="button" id="genpost-thumb-regenerate-btn" class="button button-secondary">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Regenerate', 'genpost-thumbnails' ); ?>
					</button>
				</div>
			</div>

			<div id="genpost-thumb-feedback" class="genpost-thumb-feedback" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Display an administrative notice if Pollinations.ai has not yet been opted-in.
	 *
	 * @return void
	 */
	public function display_optin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $this->api->is_opted_in() ) {
			return;
		}

		$dismissed = get_option( 'genpost_thumb_optin_dismissed', 'no' );
		if ( 'yes' === $dismissed ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . self::MENU_SLUG === $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible genpost-thumb-admin-notice" id="genpost-thumb-consent-notice">
			<p>
				<strong><?php esc_html_e( 'GenPost Thumbnails – 3rd Party Service Opt-In', 'genpost-thumbnails' ); ?></strong><br>
				<?php
				$genpost_thumb_optin_notice_text = sprintf(
					/* translators: 1: Service link, 2: Terms of service link, 3: Privacy policy link */
					__( 'To generate AI featured images, this plugin connects to <a href="%1$s" target="_blank" rel="noopener noreferrer">Pollinations.ai</a>. Only the generated visual prompt keywords and requested image dimensions are sent. No personal or site data is transmitted. Please review their <a href="%2$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> and <a href="%3$s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>.', 'genpost-thumbnails' ),
					'https://pollinations.ai',
					'https://pollinations.ai/terms',
					'https://pollinations.ai/privacy'
				);
				echo wp_kses(
					$genpost_thumb_optin_notice_text,
					array(
						'a' => array(
							'href'   => array(),
							'target' => array(),
							'rel'    => array(),
						),
					)
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary genpost-thumb-quick-optin-btn">
					<?php esc_html_e( 'Opt-In & Enable Pollinations.ai', 'genpost-thumbnails' ); ?>
				</button>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Configure Settings', 'genpost-thumbnails' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->api->get_settings();
		$presets    = $this->api->get_style_presets();
		$models     = $this->api->get_available_models();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap genpost-thumb-settings-wrap">
			<h1>
				<span class="dashicons dashicons-format-image"></span>
				<?php esc_html_e( 'GenPost Thumbnails – Smart Featured Images Settings', 'genpost-thumbnails' ); ?>
			</h1>

			<div class="genpost-thumb-settings-grid">
				<!-- Settings Form Column -->
				<div class="genpost-thumb-main-col">
					<form method="post" action="options.php">
						<?php
						settings_fields( 'genpost_thumb_settings_group' );
						?>

						<!-- Section 1: External Service Compliance (Guideline 6) -->
						<div class="genpost-thumb-card">
							<h2><?php esc_html_e( '1. External Service & Privacy (Guideline 6 Opt-In)', 'genpost-thumbnails' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'In compliance with WordPress.org guidelines, this plugin requires explicit consent before connecting to any 3rd-party service.', 'genpost-thumbnails' ); ?>
							</p>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<?php esc_html_e( 'Enable Pollinations.ai', 'genpost-thumbnails' ); ?>
									</th>
									<td>
										<label for="genpost_thumb_optin">
											<input
												type="checkbox"
												id="genpost_thumb_optin"
												name="genpost_thumb_settings[optin]"
												value="yes"
												<?php checked( 'yes', $settings['optin'] ); ?>
											/>
											<strong><?php esc_html_e( 'I agree to use the Pollinations.ai image generation service.', 'genpost-thumbnails' ); ?></strong>
										</label>
										<p class="description">
											<?php
											$genpost_thumb_service_desc = sprintf(
												/* translators: 1: Service website, 2: Terms of service, 3: Privacy policy */
												__( 'Service: <a href="%1$s" target="_blank" rel="noopener noreferrer">Pollinations.ai</a> | <a href="%2$s" target="_blank" rel="noopener noreferrer">Terms of Service</a> | <a href="%3$s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>. Only visual prompt text and dimensions are sent; no personal data or site visitor information is collected or sent.', 'genpost-thumbnails' ),
												'https://pollinations.ai',
												'https://pollinations.ai/terms',
												'https://pollinations.ai/privacy'
											);
											echo wp_kses(
												$genpost_thumb_service_desc,
												array(
													'a' => array(
														'href'   => array(),
														'target' => array(),
														'rel'    => array(),
													),
												)
											);
											?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<!-- Section 2: Automation Rules -->
						<div class="genpost-thumb-card">
							<h2><?php esc_html_e( '2. Publishing Automation', 'genpost-thumbnails' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Automatically generate and set featured images when new content is published.', 'genpost-thumbnails' ); ?>
							</p>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<?php esc_html_e( 'Auto-Generate on Publish', 'genpost-thumbnails' ); ?>
									</th>
									<td>
										<label for="genpost_thumb_auto_generate">
											<input
												type="checkbox"
												id="genpost_thumb_auto_generate"
												name="genpost_thumb_settings[auto_generate]"
												value="yes"
												<?php checked( 'yes', $settings['auto_generate'] ); ?>
											/>
											<?php esc_html_e( 'Automatically generate and attach a featured image if a post is published without one.', 'genpost-thumbnails' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<?php esc_html_e( 'Active Post Types', 'genpost-thumbnails' ); ?>
									</th>
									<td>
										<fieldset>
											<?php
											$active_types = (array) $settings['post_types'];
											foreach ( $post_types as $pt_key => $pt_obj ) :
												if ( 'attachment' === $pt_key ) {
													continue;
												}
												?>
												<label style="display:block; margin-bottom: 4px;">
													<input
														type="checkbox"
														name="genpost_thumb_settings[post_types][]"
														value="<?php echo esc_attr( $pt_key ); ?>"
														<?php checked( in_array( $pt_key, $active_types, true ) ); ?>
													/>
													<?php echo esc_html( $pt_obj->labels->singular_name . ' (' . $pt_key . ')' ); ?>
												</label>
											<?php endforeach; ?>
										</fieldset>
									</td>
								</tr>
							</table>
						</div>

						<!-- Section 3: Dimensions & Model Selection -->
						<div class="genpost-thumb-card">
							<h2><?php esc_html_e( '3. Image Dimensions & AI Model', 'genpost-thumbnails' ); ?></h2>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="genpost_thumb_width"><?php esc_html_e( 'Default Width (px)', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<input
											type="number"
											id="genpost_thumb_width"
											name="genpost_thumb_settings[width]"
											value="<?php echo esc_attr( $settings['width'] ); ?>"
											min="256"
											max="2048"
											step="8"
											class="regular-text"
										/>
										<p class="description"><?php esc_html_e( 'Recommended: 1200px for high-resolution Open Graph and featured images.', 'genpost-thumbnails' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="genpost_thumb_height"><?php esc_html_e( 'Default Height (px)', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<input
											type="number"
											id="genpost_thumb_height"
											name="genpost_thumb_settings[height]"
											value="<?php echo esc_attr( $settings['height'] ); ?>"
											min="256"
											max="2048"
											step="8"
											class="regular-text"
										/>
										<p class="description"><?php esc_html_e( 'Recommended: 630px (1200x630 gives optimal 1.91:1 social card ratio).', 'genpost-thumbnails' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="genpost_thumb_model"><?php esc_html_e( 'AI Model', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<select id="genpost_thumb_model" name="genpost_thumb_settings[model]" class="regular-text">
											<?php foreach ( $models as $m_key => $m_label ) : ?>
												<option value="<?php echo esc_attr( $m_key ); ?>" <?php selected( $settings['model'], $m_key ); ?>>
													<?php echo esc_html( $m_label ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="genpost_thumb_style_preset"><?php esc_html_e( 'Default Art Style', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<select id="genpost_thumb_style_preset" name="genpost_thumb_settings[style_preset]" class="regular-text">
											<?php foreach ( $presets as $p_key => $p_data ) : ?>
												<option value="<?php echo esc_attr( $p_key ); ?>" <?php selected( $settings['style_preset'], $p_key ); ?>>
													<?php echo esc_html( $p_data['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							</table>
						</div>

						<!-- Section 4: Prompt Engineering & Quality Boosters -->
						<div class="genpost-thumb-card">
							<h2><?php esc_html_e( '4. Prompt Engineering & Quality Boosters', 'genpost-thumbnails' ); ?></h2>

							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label for="genpost_thumb_prompt_prefix"><?php esc_html_e( 'Prompt Prefix (Optional)', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											id="genpost_thumb_prompt_prefix"
											name="genpost_thumb_settings[prompt_prefix]"
											value="<?php echo esc_attr( $settings['prompt_prefix'] ); ?>"
											class="large-text"
											placeholder="<?php esc_attr_e( 'e.g. A high-end editorial visual of', 'genpost-thumbnails' ); ?>"
										/>
										<p class="description"><?php esc_html_e( 'Prepend this text to every prompt.', 'genpost-thumbnails' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="genpost_thumb_prompt_suffix"><?php esc_html_e( 'Prompt Quality Suffix', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											id="genpost_thumb_prompt_suffix"
											name="genpost_thumb_settings[prompt_suffix]"
											value="<?php echo esc_attr( $settings['prompt_suffix'] ); ?>"
											class="large-text"
										/>
										<p class="description"><?php esc_html_e( 'Appended to prompts to enhance detail, composition, and visual fidelity.', 'genpost-thumbnails' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="genpost_thumb_negative_prompt"><?php esc_html_e( 'Negative Keywords', 'genpost-thumbnails' ); ?></label>
									</th>
									<td>
										<input
											type="text"
											id="genpost_thumb_negative_prompt"
											name="genpost_thumb_settings[negative_prompt]"
											value="<?php echo esc_attr( $settings['negative_prompt'] ); ?>"
											class="large-text"
										/>
										<p class="description"><?php esc_html_e( 'Undesirable elements to discourage during image synthesis.', 'genpost-thumbnails' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<?php submit_button( __( 'Save Changes', 'genpost-thumbnails' ) ); ?>
					</form>
				</div>

				<!-- Sidebar / Diagnostics Column -->
				<div class="genpost-thumb-side-col">
					<div class="genpost-thumb-card">
						<h3><?php esc_html_e( 'API Connection Test', 'genpost-thumbnails' ); ?></h3>
						<p class="description">
							<?php esc_html_e( 'Verify that your server can connect to Pollinations.ai and retrieve images.', 'genpost-thumbnails' ); ?>
						</p>
						<p>
							<button type="button" id="genpost-thumb-test-btn" class="button button-secondary">
								<span class="dashicons dashicons-networking"></span>
								<?php esc_html_e( 'Test Connection Now', 'genpost-thumbnails' ); ?>
							</button>
						</p>
						<div id="genpost-thumb-test-result" style="display:none; margin-top: 10px;"></div>
					</div>

					<div class="genpost-thumb-card">
						<h3><?php esc_html_e( 'About GenPost Thumbnails', 'genpost-thumbnails' ); ?></h3>
						<p>
							<?php esc_html_e( 'Version: 1.0.0', 'genpost-thumbnails' ); ?><br>
							<?php esc_html_e( 'License: GPLv2 or later', 'genpost-thumbnails' ); ?><br>
							<?php esc_html_e( 'AI Engine: Pollinations.ai', 'genpost-thumbnails' ); ?>
						</p>
						<p>
							<a href="https://pollinations.ai" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<?php esc_html_e( 'Visit Pollinations.ai', 'genpost-thumbnails' ); ?> &rarr;
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX Handler: Save quick opt-in consent or dismiss notice.
	 *
	 * @return void
	 */
	public function ajax_handle_optin() {
		check_ajax_referer( 'genpost_thumb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'genpost-thumbnails' ) ), 403 );
		}

		$action = isset( $_POST['optin_action'] ) ? sanitize_key( $_POST['optin_action'] ) : 'agree';

		if ( 'dismiss' === $action ) {
			update_option( 'genpost_thumb_optin_dismissed', 'yes' );
			wp_send_json_success( array( 'message' => __( 'Notice dismissed.', 'genpost-thumbnails' ) ) );
		}

		// Save opt-in consent in plugin settings.
		$settings          = $this->api->get_settings();
		$settings['optin'] = 'yes';
		update_option( 'genpost_thumb_settings', $settings );
		update_option( 'genpost_thumb_optin_dismissed', 'yes' );

		wp_send_json_success( array( 'message' => __( 'Pollinations.ai service enabled successfully.', 'genpost-thumbnails' ) ) );
	}

	/**
	 * AJAX Handler: Suggest intelligent prompt based on post content.
	 *
	 * @return void
	 */
	public function ajax_suggest_prompt() {
		check_ajax_referer( 'genpost_thumb_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this post.', 'genpost-thumbnails' ) ), 403 );
		}

		$style_preset  = isset( $_POST['style_preset'] ) ? sanitize_key( wp_unslash( $_POST['style_preset'] ) ) : '';
		$custom_prompt = isset( $_POST['custom_prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_prompt'] ) ) : '';

		$prompt = $this->api->build_smart_prompt( $post_id, $custom_prompt, $style_preset );

		wp_send_json_success( array(
			'prompt' => $prompt,
		) );
	}

	/**
	 * AJAX Handler: Generate image preview URL.
	 *
	 * @return void
	 */
	public function ajax_generate_preview() {
		check_ajax_referer( 'genpost_thumb_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this post.', 'genpost-thumbnails' ) ), 403 );
		}

		if ( ! $this->api->is_opted_in() ) {
			wp_send_json_error( array(
				'message'        => __( 'Please enable Pollinations.ai in the plugin settings before generating images.', 'genpost-thumbnails' ),
				'optin_required' => true,
			), 400 );
		}

		$prompt       = isset( $_POST['prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$style_preset = isset( $_POST['style_preset'] ) ? sanitize_key( wp_unslash( $_POST['style_preset'] ) ) : '';

		if ( empty( $prompt ) ) {
			$prompt = $this->api->build_smart_prompt( $post_id, '', $style_preset );
		}

		$image_url = $this->api->build_image_url( $prompt, array(
			'seed' => wp_rand( 100000, 999999999 ),
		) );

		if ( is_wp_error( $image_url ) ) {
			wp_send_json_error( array( 'message' => $image_url->get_error_message() ), 400 );
		}

		wp_send_json_success( array(
			'image_url' => $image_url,
			'prompt'    => $prompt,
		) );
	}

	/**
	 * AJAX Handler: Sideload previewed image into Media Library and apply as featured image.
	 *
	 * @return void
	 */
	public function ajax_apply_image() {
		check_ajax_referer( 'genpost_thumb_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied for this post.', 'genpost-thumbnails' ) ), 403 );
		}

		$image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
		$prompt    = isset( $_POST['prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['prompt'] ) ) : '';

		if ( empty( $image_url ) ) {
			wp_send_json_error( array( 'message' => __( 'No image URL provided to apply.', 'genpost-thumbnails' ) ), 400 );
		}

		// Download and sideload into media library.
		$attachment_id = $this->media->sideload_and_set_thumbnail( $image_url, $post_id, $prompt );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 500 );
		}

		// Prepare response with thumbnail preview HTML and attachment info.
		$thumb_html = _wp_post_thumbnail_html( $attachment_id, $post_id );

		wp_send_json_success( array(
			'attachment_id' => $attachment_id,
			'thumb_html'    => $thumb_html,
			'thumbnail_url' => wp_get_attachment_image_url( $attachment_id, 'medium' ),
			'message'       => __( 'Featured Image set successfully!', 'genpost-thumbnails' ),
		) );
	}

	/**
	 * AJAX Handler: Test API connection from settings page.
	 *
	 * @return void
	 */
	public function ajax_test_generate() {
		check_ajax_referer( 'genpost_thumb_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'genpost-thumbnails' ) ), 403 );
		}

		$result = $this->api->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Success! Connected to Pollinations.ai API and verified response.', 'genpost-thumbnails' ),
		) );
	}

	/**
	 * Automatically generate and attach a featured image when a post is published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function handle_auto_generate_on_publish( $new_status, $old_status, $post ) {
		// Only run when transitioning to 'publish' from another status.
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		// Ignore auto-drafts and revisions.
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		// Verify opt-in and auto-generate setting.
		$settings = $this->api->get_settings();
		if ( 'yes' !== $settings['optin'] || 'yes' !== $settings['auto_generate'] ) {
			return;
		}

		// Verify supported post type.
		$post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		// Check if post already has a featured image.
		if ( has_post_thumbnail( $post->ID ) ) {
			return;
		}

		// Check if already auto-generated previously to avoid duplication.
		$already_done = get_post_meta( $post->ID, '_genpost_thumb_autogenerated', true );
		if ( ! empty( $already_done ) ) {
			return;
		}

		// Build prompt.
		$prompt = $this->api->build_smart_prompt( $post->ID );
		if ( empty( $prompt ) ) {
			return;
		}

		// Build image URL.
		$image_url = $this->api->build_image_url( $prompt );
		if ( is_wp_error( $image_url ) ) {
			return;
		}

		// Download and sideload into media library.
		$attachment_id = $this->media->sideload_and_set_thumbnail( $image_url, $post->ID, $prompt );
		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $post->ID, '_genpost_thumb_autogenerated', 1 );
		}
	}
}
