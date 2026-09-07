/**
 * GenPost Thumbnails Admin JavaScript
 *
 * @package Genpost_Thumbnails
 */

(function ($) {
	'use strict';

	$(document).ready(function () {
		const data = window.genpostThumbData || {};

		// Helper: Display status spinner with message
		function showStatus(message) {
			$('#genpost-thumb-status .genpost-thumb-status-text').text(message);
			$('#genpost-thumb-status').slideDown(150);
			$('#genpost-thumb-feedback').hide();
		}

		// Helper: Hide status spinner
		function hideStatus() {
			$('#genpost-thumb-status').slideUp(150);
		}

		// Helper: Display feedback alert
		function showFeedback(message, type) {
			const feedback = $('#genpost-thumb-feedback');
			feedback
				.removeClass('notice-success notice-error')
				.addClass(type === 'success' ? 'notice-success' : 'notice-error')
				.html(message)
				.slideDown(200);
		}

		// Helper: Get post title from editor (Gutenberg or Classic)
		function getEditorTitle() {
			if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
				const gTitle = wp.data.select('core/editor').getEditedPostAttribute('title');
				if (gTitle) {
					return gTitle;
				}
			}
			const classicTitle = $('#title').val();
			return classicTitle || '';
		}

		// Quick Opt-In Handler (Notice & Metabox alert)
		$(document).on('click', '.genpost-thumb-quick-optin-btn', function (e) {
			e.preventDefault();
			const $btn = $(this);
			$btn.prop('disabled', true).text('Enabling…');

			$.post(data.ajaxUrl, {
				action: 'genpost_thumb_optin',
				nonce: data.nonce,
				optin_action: 'agree'
			})
			.done(function (res) {
				if (res.success) {
					data.isOptedIn = true;
					$('#genpost-thumb-consent-notice').slideUp();
					$('.genpost-thumb-alert-warning').slideUp();
					showFeedback(res.data.message || 'Service enabled successfully!', 'success');
				} else {
					alert(res.data.message || 'Failed to enable service.');
				}
			})
			.fail(function () {
				alert(data.i18n.errorOccurred || 'Network error.');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});

		// Suggest Prompt Button
		$('#genpost-thumb-suggest-btn').on('click', function (e) {
			e.preventDefault();
			const $btn = $(this);
			$btn.prop('disabled', true);
			showStatus(data.i18n.suggesting || 'Analyzing content…');

			const stylePreset = $('#genpost-thumb-style-select').val();
			const customPrompt = $('#genpost-thumb-prompt-input').val();

			$.post(data.ajaxUrl, {
				action: 'genpost_thumb_suggest_prompt',
				nonce: data.nonce,
				post_id: data.postId,
				style_preset: stylePreset,
				custom_prompt: customPrompt
			})
			.done(function (res) {
				if (res.success && res.data.prompt) {
					$('#genpost-thumb-prompt-input').val(res.data.prompt);
					showFeedback('Prompt suggested based on post context!', 'success');
				} else {
					showFeedback(res.data.message || data.i18n.errorOccurred, 'error');
				}
			})
			.fail(function () {
				showFeedback(data.i18n.errorOccurred, 'error');
			})
			.always(function () {
				hideStatus();
				$btn.prop('disabled', false);
			});
		});

		// Generate AI Image Preview
		$('#genpost-thumb-generate-btn').on('click', function (e) {
			e.preventDefault();

			if (!data.isOptedIn) {
				showFeedback(data.i18n.optinRequired, 'error');
				return;
			}

			let prompt = $('#genpost-thumb-prompt-input').val().trim();
			const stylePreset = $('#genpost-thumb-style-select').val();

			const $btn = $(this);
			$btn.prop('disabled', true);
			showStatus(data.i18n.generating || 'Generating AI Image…');

			$.post(data.ajaxUrl, {
				action: 'genpost_thumb_generate_preview',
				nonce: data.nonce,
				post_id: data.postId,
				prompt: prompt,
				style_preset: stylePreset
			})
			.done(function (res) {
				if (res.success && res.data.image_url) {
					if (!prompt && res.data.prompt) {
						$('#genpost-thumb-prompt-input').val(res.data.prompt);
					}

					// Preload image before showing preview
					const img = new Image();
					img.onload = function () {
						$('#genpost-thumb-preview-img').attr('src', res.data.image_url);
						$('#genpost-thumb-preview-box').slideDown(250);
						hideStatus();
						$btn.prop('disabled', false);
					};
					img.onerror = function () {
						hideStatus();
						$btn.prop('disabled', false);
						showFeedback('Failed to render preview from service.', 'error');
					};
					img.src = res.data.image_url;
				} else {
					hideStatus();
					$btn.prop('disabled', false);
					showFeedback(res.data.message || data.i18n.errorOccurred, 'error');
				}
			})
			.fail(function (xhr) {
				hideStatus();
				$btn.prop('disabled', false);
				const err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: data.i18n.errorOccurred;
				showFeedback(err, 'error');
			});
		});

		// Regenerate Button
		$('#genpost-thumb-regenerate-btn').on('click', function (e) {
			e.preventDefault();
			$('#genpost-thumb-generate-btn').trigger('click');
		});

		// Set as Featured Image
		$('#genpost-thumb-apply-btn').on('click', function (e) {
			e.preventDefault();

			const imageUrl = $('#genpost-thumb-preview-img').attr('src');
			const prompt = $('#genpost-thumb-prompt-input').val().trim();

			if (!imageUrl) {
				showFeedback('No image preview found to apply.', 'error');
				return;
			}

			const $btn = $(this);
			$btn.prop('disabled', true);
			showStatus(data.i18n.applying || 'Setting Featured Image…');

			$.post(data.ajaxUrl, {
				action: 'genpost_thumb_apply_image',
				nonce: data.nonce,
				post_id: data.postId,
				image_url: imageUrl,
				prompt: prompt
			})
			.done(function (res) {
				if (res.success && res.data.attachment_id) {
					// 1. Sync Gutenberg Block Editor state if active
					if (window.wp && wp.data && wp.data.dispatch && wp.data.dispatch('core/editor')) {
						wp.data.dispatch('core/editor').editPost({
							featured_media: res.data.attachment_id
						});
					}

					// 2. Sync Classic Editor featured image box if active
					if ($('#postimagediv').length && res.data.thumb_html) {
						$('#postimagediv .inside').html(res.data.thumb_html);
					}

					showFeedback(res.data.message || data.i18n.successApplied, 'success');
				} else {
					showFeedback(res.data.message || data.i18n.errorOccurred, 'error');
				}
			})
			.fail(function (xhr) {
				const err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: data.i18n.errorOccurred;
				showFeedback(err, 'error');
			})
			.always(function () {
				hideStatus();
				$btn.prop('disabled', false);
			});
		});

		// Settings Page: API Connectivity Test
		$('#genpost-thumb-test-btn').on('click', function (e) {
			e.preventDefault();
			const $btn = $(this);
			const $result = $('#genpost-thumb-test-result');

			$btn.prop('disabled', true);
			$result.show().html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> ' + (data.i18n.testingConnection || 'Testing connection…'));

			$.post(data.ajaxUrl, {
				action: 'genpost_thumb_test_generate',
				nonce: data.nonce
			})
			.done(function (res) {
				if (res.success) {
					$result.html('<div class="notice notice-success inline" style="margin:5px 0; padding:8px;"><p>' + res.data.message + '</p></div>');
				} else {
					$result.html('<div class="notice notice-error inline" style="margin:5px 0; padding:8px;"><p>' + (res.data.message || 'Connection test failed.') + '</p></div>');
				}
			})
			.fail(function (xhr) {
				const err = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: 'Network test failed.';
				$result.html('<div class="notice notice-error inline" style="margin:5px 0; padding:8px;"><p>' + err + '</p></div>');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
