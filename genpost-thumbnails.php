<?php
/**
 * Plugin Name:       GenPost Thumbnails – Smart Featured Images
 * Plugin URI:        https://github.com/mrghozzi/genpost-thumbnails
 * Description:       Generate smart, contextual AI featured images for your WordPress posts automatically or on-demand using Pollinations.ai.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            mrghozzi
 * Author URI:        https://github.com/mrghozzi
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       genpost-thumbnails
 * Domain Path:       /languages
 *
 * @package Genpost_Thumbnails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'GENPOST_THUMB_VERSION', '1.0.0' );
define( 'GENPOST_THUMB_PLUGIN_FILE', __FILE__ );
define( 'GENPOST_THUMB_DIR', plugin_dir_path( __FILE__ ) );
define( 'GENPOST_THUMB_URL', plugin_dir_url( __FILE__ ) );
define( 'GENPOST_THUMB_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation hook callback.
 * Initializes default settings if not already defined.
 *
 * @return void
 */
function genpost_thumb_activate() {
	$defaults = array(
		'optin'            => 'no',
		'auto_generate'    => 'no',
		'width'            => 1200,
		'height'           => 630,
		'model'            => 'flux',
		'style_preset'     => 'photorealistic',
		'prompt_prefix'    => '',
		'prompt_suffix'    => 'high quality, 8k resolution, clean composition, detailed',
		'negative_prompt'  => 'blurry, low quality, distorted, watermark, signature, text, deformed',
		'post_types'       => array( 'post' ),
	);

	$existing = get_option( 'genpost_thumb_settings' );
	if ( false === $existing || ! is_array( $existing ) ) {
		update_option( 'genpost_thumb_settings', $defaults );
	}
}
register_activation_hook( __FILE__, 'genpost_thumb_activate' );

/**
 * Deactivation hook callback.
 *
 * @return void
 */
function genpost_thumb_deactivate() {
	// Clean up any temporary transients.
	delete_transient( 'genpost_thumb_api_status' );
}
register_deactivation_hook( __FILE__, 'genpost_thumb_deactivate' );

// Include required class files.
require_once GENPOST_THUMB_DIR . 'includes/class-genpost-thumbnails-api.php';
require_once GENPOST_THUMB_DIR . 'includes/class-genpost-thumbnails-media.php';
require_once GENPOST_THUMB_DIR . 'includes/class-genpost-thumbnails-admin.php';

/**
 * Initialize the plugin classes and hooks.
 *
 * @return void
 */
function genpost_thumb_init() {
	$api   = new Genpost_Thumbnails_API();
	$media = new Genpost_Thumbnails_Media();
	$admin = new Genpost_Thumbnails_Admin( $api, $media );
	$admin->init();
}
add_action( 'plugins_loaded', 'genpost_thumb_init' );

/**
 * Add settings link to plugins list.
 *
 * @param array $links Array of plugin action links.
 * @return array Modified array of plugin action links.
 */
function genpost_thumb_add_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=genpost-thumbnails' ) ),
		esc_html__( 'Settings', 'genpost-thumbnails' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . GENPOST_THUMB_BASENAME, 'genpost_thumb_add_action_links' );
