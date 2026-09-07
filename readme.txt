=== GenPost Thumbnails – Smart Featured Images ===
Contributors: mrghozzi
Donate link: https://patreon.com/MrGhozzi
Tags: featured image, ai images, thumbnail, post thumbnail, artificial intelligence
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate smart, contextual AI featured images for your WordPress posts automatically or on-demand using Pollinations.ai.

== Description ==

**GenPost Thumbnails – Smart Featured Images** is a lightweight, intelligent plugin that generates eye-catching, contextual featured images (thumbnails) for your WordPress posts using advanced AI models via Pollinations.ai.

Whether you publish breaking news, tech blogs, personal journals, or WooCommerce products, GenPost Thumbnails analyzes your post titles, tags, and categories to construct visually stunning, contextual prompts and generate relevant featured images in seconds.

### Key Features:
* **Smart Prompt Formulation**: Intelligently creates descriptive English visual prompts from your post title, tags, and categories.
* **On-Demand Generation via Meta Box**: Test and preview images directly in the post editor (compatible with both Block Editor / Gutenberg and the Classic Editor) before applying them.
* **Automated Publishing Workflow**: Optionally generate and attach a featured image automatically whenever a post is published without a thumbnail.
* **Safe Sideloading**: Downloads images safely into your native WordPress Media Library, sets proper SEO alt tags, and establishes standard post thumbnails.
* **Customizable Styles & Dimensions**: Choose between presets like Photorealistic, Digital Art, Cinematic, Minimalist, 3D Render, and Anime. Configure custom dimensions (default 1200x630, perfect for Open Graph social shares).
* **Quality Enhancers & Negative Prompts**: Add custom prompt boosters to ensure crisp, clean, and safe visual results every time.
* **WordPress.org Compliant**: Built strictly following WordPress coding standards, sanitization, nonces, capabilities, and explicit 3rd-party opt-in consent.

== External Service ==

This plugin utilizes **Pollinations.ai** as a 3rd-party AI image generation service to synthesize images based on post keywords and prompts.

* **Service Name**: Pollinations.ai
* **Service Website**: https://pollinations.ai
* **Terms of Service**: https://pollinations.ai/terms
* **Privacy Policy**: https://pollinations.ai/privacy
* **What Data is Sent**: When generating an image, only the visual prompt text (e.g. keywords describing the subject) and requested image dimensions (width, height, model parameters) are transmitted via a secure HTTPS request to the Pollinations.ai image API (`https://image.pollinations.ai/`).
* **Privacy & User Data**: No personally identifiable information (PII), user credentials, emails, visitor IP addresses, or private site data are sent to or stored by the external service.
* **Opt-In Requirement**: In accordance with WordPress.org Guideline 6, this service is strictly **opt-in**. The plugin will not send any requests to Pollinations.ai until the site administrator explicitly opts in via the plugin settings page or the administrative prompt notice.

== Installation ==

1. Upload the `genpost-thumbnails` folder to your `/wp-content/plugins/` directory, or install the ZIP file via **Plugins -> Add New -> Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Review the Pollinations.ai disclosure and opt-in via **Settings -> GenPost Thumbnails** or the welcome notice.
4. Configure your preferred default styles, image dimensions, and automation rules.
5. Create or edit a post to start generating smart featured images!

== Frequently Asked Questions ==

= Do I need an API key to use Pollinations.ai? =
No API key is required. Pollinations.ai provides an open, accessible AI generation endpoint.

= Can I preview the image before setting it as the featured image? =
Yes! In the post editor meta box, click "Generate AI Image" to render a live preview. If you like the result, click "Set as Featured Image" to download and attach it to your post. If not, you can adjust the prompt and regenerate.

= Does it work with the WordPress Block Editor (Gutenberg)? =
Yes, GenPost Thumbnails integrates seamlessly with both the Block Editor (Gutenberg) and the Classic Editor. Setting a featured image dynamically updates the editor interface without requiring a page reload.

= Where are the generated images stored? =
Images are securely downloaded and stored directly in your local WordPress Media Library (`wp-content/uploads/`). They become standard WordPress media attachments and do not depend on external CDNs after saving.

= Will it overwrite existing featured images when auto-generating? =
No. The automatic generation feature only triggers if a post has no featured image attached at the time of publication.

= Can I disable the external service? =
Yes. You can revoke consent or disable the service at any time in **Settings -> GenPost Thumbnails**.

== Screenshots ==

1. Post Editor Meta Box with prompt generator, style presets, and live preview.
2. Settings screen for configuring dimensions, presets, automation, and Pollinations.ai consent.
3. Media Library showing AI-generated image with automatic metadata and alt tags.

== Changelog ==

= 1.0.0 =
* Initial release.
* Added Pollinations.ai integration with full opt-in compliance.
* Added smart prompt generation from post title, excerpt, and categories.
* Added Meta Box with live preview for Gutenberg and Classic Editor.
* Added automatic featured image generation on post publish.
* Added comprehensive settings page with dimension and style customization.
* Added secure sideloading and cleanup in WordPress Media Library.
* Added full internationalization (i18n) support.

== Upgrade Notice ==

= 1.0.0 =
Initial release of GenPost Thumbnails. Install and opt in to begin generating smart featured images.
