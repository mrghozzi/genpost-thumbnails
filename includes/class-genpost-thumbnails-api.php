<?php
/**
 * GenPost Thumbnails API & Prompt Handler.
 *
 * Communicates with the external Pollinations.ai service and formulates smart prompts.
 *
 * @package Genpost_Thumbnails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Genpost_Thumbnails_API
 */
class Genpost_Thumbnails_API {

	/**
	 * Base API endpoint for Pollinations.ai image generation.
	 *
	 * @var string
	 */
	const API_BASE_URL = 'https://image.pollinations.ai/prompt/';

	/**
	 * Check if the user has explicitly opted into using the Pollinations.ai service.
	 *
	 * @return bool True if opted in, false otherwise.
	 */
	public function is_opted_in() {
		$settings = $this->get_settings();
		return isset( $settings['optin'] ) && 'yes' === $settings['optin'];
	}

	/**
	 * Retrieve sanitized plugin settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		$defaults = array(
			'optin'           => 'no',
			'auto_generate'   => 'no',
			'width'           => 1200,
			'height'          => 630,
			'model'           => 'flux',
			'style_preset'    => 'photorealistic',
			'prompt_prefix'   => '',
			'prompt_suffix'   => 'high quality, 8k resolution, clean composition, detailed',
			'negative_prompt' => 'blurry, low quality, distorted, watermark, signature, text, deformed',
			'post_types'      => array( 'post' ),
		);

		$saved = get_option( 'genpost_thumb_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get list of available visual style presets.
	 *
	 * @return array Associative array of style keys and descriptive labels/modifiers.
	 */
	public function get_style_presets() {
		return array(
			'photorealistic' => array(
				'label'    => __( 'Photorealistic (Default)', 'genpost-thumbnails' ),
				'modifier' => 'professional photography, realistic textures, natural lighting, sharp focus, 8k resolution',
			),
			'digital_art'    => array(
				'label'    => __( 'Digital Art', 'genpost-thumbnails' ),
				'modifier' => 'vibrant digital art, trending on ArtStation, dynamic lighting, expressive concept illustration',
			),
			'cinematic'      => array(
				'label'    => __( 'Cinematic Film', 'genpost-thumbnails' ),
				'modifier' => 'cinematic still, dramatic moody lighting, shallow depth of field, 35mm film grain, anamorphic',
			),
			'minimalist'     => array(
				'label'    => __( 'Minimalist & Clean', 'genpost-thumbnails' ),
				'modifier' => 'minimalist modern vector illustration, clean lines, balanced pastel colors, aesthetic composition',
			),
			'render_3d'      => array(
				'label'    => __( '3D Render', 'genpost-thumbnails' ),
				'modifier' => '3D Octane render, ray tracing, studio lighting, smooth materials, hyper-detailed, C4D',
			),
			'anime'          => array(
				'label'    => __( 'Anime & Manga Style', 'genpost-thumbnails' ),
				'modifier' => 'Japanese anime aesthetic, Makoto Shinkai style, vivid lighting, lush scenery, detailed drawing',
			),
		);
	}

	/**
	 * Get list of supported AI models.
	 *
	 * @return array
	 */
	public function get_available_models() {
		return array(
			'flux'  => __( 'Flux (Recommended – High Quality & Accuracy)', 'genpost-thumbnails' ),
			'turbo' => __( 'Turbo (Fastest Generation)', 'genpost-thumbnails' ),
		);
	}

	/**
	 * Build an intelligent, contextual prompt based on post content and metadata.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $custom_prompt Optional manual prompt from user.
	 * @param string $style_preset  Optional style preset key.
	 * @return string Formatted visual prompt.
	 */
	public function build_smart_prompt( $post_id = 0, $custom_prompt = '', $style_preset = '' ) {
		$settings = $this->get_settings();

		// If a custom prompt is provided, start with it.
		if ( ! empty( $custom_prompt ) ) {
			$base_prompt = sanitize_text_field( $custom_prompt );
		} elseif ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$base_prompt = $this->extract_keywords_from_post( $post );
			} else {
				$base_prompt = 'Modern editorial subject concept';
			}
		} else {
			$base_prompt = 'Creative modern conceptual scene';
		}

		// Determine style preset.
		if ( empty( $style_preset ) || ! array_key_exists( $style_preset, $this->get_style_presets() ) ) {
			$style_preset = ! empty( $settings['style_preset'] ) ? $settings['style_preset'] : 'photorealistic';
		}

		$presets  = $this->get_style_presets();
		$modifier = isset( $presets[ $style_preset ]['modifier'] ) ? $presets[ $style_preset ]['modifier'] : '';

		// Combine components.
		$parts = array();

		if ( ! empty( $settings['prompt_prefix'] ) ) {
			$parts[] = trim( $settings['prompt_prefix'] );
		}

		$parts[] = trim( $base_prompt );

		if ( ! empty( $modifier ) ) {
			$parts[] = $modifier;
		}

		if ( ! empty( $settings['prompt_suffix'] ) ) {
			$parts[] = trim( $settings['prompt_suffix'] );
		}

		$full_prompt = implode( ', ', array_filter( $parts ) );

		// Clean up repeated commas or spaces.
		$full_prompt = preg_replace( '/\s*,\s*/', ', ', $full_prompt );
		$full_prompt = preg_replace( '/,{2,}/', ',', $full_prompt );
		$full_prompt = trim( $full_prompt, " ,\t\n\r\0\x0B" );

		return $full_prompt;
	}

	/**
	 * Extract meaningful visual keywords from a post object.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public function extract_keywords_from_post( $post ) {
		$title = wp_strip_all_tags( $post->post_title );

		// Extract categories.
		$categories = get_the_category( $post->ID );
		$cat_names  = array();
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			foreach ( $categories as $cat ) {
				if ( strtolower( $cat->name ) !== 'uncategorized' ) {
					$cat_names[] = $cat->name;
				}
			}
		}

		// Extract post tags.
		$tags      = get_the_tags( $post->ID );
		$tag_names = array();
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_names[] = $tag->name;
			}
		}

		// Build thematic description.
		$elements = array();

		if ( ! empty( $title ) ) {
			$elements[] = $title;
		}

		if ( ! empty( $cat_names ) ) {
			$elements[] = implode( ' ', array_slice( $cat_names, 0, 3 ) );
		}

		if ( ! empty( $tag_names ) ) {
			$elements[] = implode( ' ', array_slice( $tag_names, 0, 4 ) );
		}

		// If title is empty, use excerpt or first words of content.
		if ( empty( $elements ) ) {
			$excerpt = ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 15 );
			$excerpt = wp_strip_all_tags( $excerpt );
			if ( ! empty( $excerpt ) ) {
				$elements[] = $excerpt;
			}
		}

		$combined = implode( ', ', $elements );
		return ! empty( $combined ) ? $combined : 'A visually compelling article thumbnail';
	}

	/**
	 * Construct the Pollinations.ai image URL for a given prompt and arguments.
	 *
	 * @param string $prompt Prompt string.
	 * @param array  $args   Optional parameters (width, height, model, seed, nologo).
	 * @return string|WP_Error Full image URL or WP_Error if not opted in or prompt empty.
	 */
	public function build_image_url( $prompt, $args = array() ) {
		if ( ! $this->is_opted_in() ) {
			return new WP_Error(
				'genpost_thumb_optin_required',
				__( 'You must opt-in to the Pollinations.ai service before generating images.', 'genpost-thumbnails' )
			);
		}

		$prompt = trim( $prompt );
		if ( empty( $prompt ) ) {
			return new WP_Error(
				'genpost_thumb_empty_prompt',
				__( 'Prompt cannot be empty.', 'genpost-thumbnails' )
			);
		}

		$settings = $this->get_settings();

		// Parse dimensions.
		$width  = isset( $args['width'] ) ? absint( $args['width'] ) : absint( $settings['width'] );
		$height = isset( $args['height'] ) ? absint( $args['height'] ) : absint( $settings['height'] );

		// Bound dimensions to safe API limits (between 256 and 2048).
		$width  = max( 256, min( 2048, $width ) );
		$height = max( 256, min( 2048, $height ) );

		// Model.
		$model = isset( $args['model'] ) ? sanitize_key( $args['model'] ) : sanitize_key( $settings['model'] );
		if ( ! in_array( $model, array( 'flux', 'turbo' ), true ) ) {
			$model = 'flux';
		}

		// Random seed for reproducibility or variation.
		$seed = isset( $args['seed'] ) ? absint( $args['seed'] ) : wp_rand( 100000, 999999999 );

		// Build query parameters.
		$query_params = array(
			'width'   => $width,
			'height'  => $height,
			'model'   => $model,
			'nologo'  => 'true',
			'enhance' => 'true',
			'seed'    => $seed,
		);

		// Encode the prompt for the URL path.
		$encoded_prompt = rawurlencode( $prompt );

		$url = self::API_BASE_URL . $encoded_prompt;
		$url = add_query_arg( $query_params, $url );

		return esc_url_raw( $url );
	}

	/**
	 * Test the connection to the Pollinations.ai API endpoint.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection() {
		if ( ! $this->is_opted_in() ) {
			return new WP_Error(
				'genpost_thumb_optin_required',
				__( 'You must opt-in to the Pollinations.ai service before testing connection.', 'genpost-thumbnails' )
			);
		}

		$test_url = $this->build_image_url( 'test ping simple abstract geometric icon', array(
			'width'  => 300,
			'height' => 200,
			'model'  => 'turbo',
		) );

		if ( is_wp_error( $test_url ) ) {
			return $test_url;
		}

		$response = wp_remote_get(
			$test_url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'genpost_thumb_api_http_error',
				sprintf(
					/* translators: %d: HTTP response code */
					__( 'Pollinations.ai returned an unexpected HTTP status code: %d', 'genpost-thumbnails' ),
					$status_code
				)
			);
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! empty( $content_type ) && false === strpos( $content_type, 'image/' ) ) {
			return new WP_Error(
				'genpost_thumb_api_invalid_content',
				__( 'The service response did not return an image stream.', 'genpost-thumbnails' )
			);
		}

		return true;
	}
}
