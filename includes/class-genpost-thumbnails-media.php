<?php
/**
 * GenPost Thumbnails Media Handler.
 *
 * Handles secure downloading, validating, sideloading images into the Media Library,
 * and setting them as featured images.
 *
 * @package Genpost_Thumbnails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Genpost_Thumbnails_Media
 */
class Genpost_Thumbnails_Media {

	/**
	 * Allowed image mime types for sideloading.
	 *
	 * @var array
	 */
	const ALLOWED_MIME_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);

	/**
	 * Download an image from an external URL, sideload into Media Library, and attach to a post.
	 *
	 * @param string $image_url The external image URL.
	 * @param int    $post_id   The target post ID.
	 * @param string $prompt    The prompt used (used for caption/alt text).
	 * @param string $title     Optional custom title for the media item.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function sideload_and_set_thumbnail( $image_url, $post_id = 0, $prompt = '', $title = '' ) {
		// Verify required WordPress administration files are loaded.
		if ( ! function_exists( 'download_url' ) || ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download the image to a temporary file (45 second timeout for AI generation).
		$tmp_file = download_url( $image_url, 45 );

		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error(
				'genpost_thumb_download_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to download image from AI service: %s', 'genpost-thumbnails' ),
					$tmp_file->get_error_message()
				)
			);
		}

		// Verify that the temporary file exists and is not empty.
		if ( ! file_exists( $tmp_file ) || 0 === filesize( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'genpost_thumb_empty_file',
				__( 'Downloaded image file is empty or missing.', 'genpost-thumbnails' )
			);
		}

		// Determine and validate the file type and extension.
		$file_check = wp_check_filetype_and_ext( $tmp_file, 'ai-generated-image.jpg', self::ALLOWED_MIME_TYPES );

		// Fallback check if extension was missing in temporary name.
		if ( empty( $file_check['ext'] ) || empty( $file_check['type'] ) ) {
			$image_size_info = @getimagesize( $tmp_file );
			if ( false !== $image_size_info && ! empty( $image_size_info['mime'] ) ) {
				$mime = $image_size_info['mime'];
				$ext  = '';
				switch ( $mime ) {
					case 'image/jpeg':
						$ext = 'jpg';
						break;
					case 'image/png':
						$ext = 'png';
						break;
					case 'image/webp':
						$ext = 'webp';
						break;
				}

				if ( ! empty( $ext ) ) {
					$file_check = array(
						'ext'  => $ext,
						'type' => $mime,
					);
				}
			}
		}

		// If still invalid, reject and cleanup.
		if ( empty( $file_check['ext'] ) || empty( $file_check['type'] ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'genpost_thumb_invalid_mime',
				__( 'The downloaded file is not a valid JPEG, PNG, or WebP image.', 'genpost-thumbnails' )
			);
		}

		// Determine a clean, descriptive filename.
		$post_obj = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post_obj && ! empty( $post_obj->post_title ) ) {
			$clean_slug = sanitize_title( $post_obj->post_title );
		} else {
			$clean_slug = 'ai-featured-image';
		}

		$filename = sprintf(
			'%s-%s.%s',
			substr( $clean_slug, 0, 40 ),
			wp_generate_password( 6, false ),
			$file_check['ext']
		);

		// Prepare the array for media_handle_sideload.
		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		// Prepare descriptive media metadata.
		if ( empty( $title ) ) {
			if ( $post_obj && ! empty( $post_obj->post_title ) ) {
				$title = sprintf(
					/* translators: %s: Post title */
					__( 'Featured Image for %s', 'genpost-thumbnails' ),
					$post_obj->post_title
				);
			} else {
				$title = __( 'AI Generated Featured Image', 'genpost-thumbnails' );
			}
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => ! empty( $prompt ) ? sanitize_textarea_field( $prompt ) : '',
			'post_status'  => 'inherit',
		);

		// Sideload into the media library.
		$attachment_id = media_handle_sideload( $file_array, $post_id, $title, $post_data );

		// Clean up temporary file in case sideload failed without unlinking.
		if ( file_exists( $tmp_file ) ) {
			wp_delete_file( $tmp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set SEO Alt Text on the attachment.
		$alt_text = $post_obj ? $post_obj->post_title : $title;
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		// Record origin prompt for reference.
		if ( ! empty( $prompt ) ) {
			update_post_meta( $attachment_id, '_genpost_thumb_prompt', sanitize_text_field( $prompt ) );
		}

		// Set as featured image for the post if post_id is provided.
		if ( $post_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}
}
