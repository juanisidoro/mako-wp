(function ($) {
	'use strict';

	var config = window.makoAdmin || {};

	// Generate MAKO for a single post.
	function generateMako(postId, $button) {
		var originalText = $button.text();
		$button.prop('disabled', true).html('<span class="mako-spinner"></span>' + config.i18n.generating);

		$.post(config.ajaxUrl, {
			action: 'mako_generate',
			nonce: config.nonce,
			post_id: postId
		})
		.done(function (response) {
			if (response.success) {
				$button.text(config.i18n.generated).addClass('button-primary');
				// Reload to show updated meta box.
				setTimeout(function () {
					window.location.reload();
				}, 500);
			} else {
				$button.text(config.i18n.error);
				alert(response.data || 'Generation failed');
			}
		})
		.fail(function () {
			$button.text(config.i18n.error);
		})
		.always(function () {
			setTimeout(function () {
				$button.prop('disabled', false).text(originalText);
			}, 2000);
		});
	}

	// Preview MAKO content.
	function previewMako(postId) {
		$.post(config.ajaxUrl, {
			action: 'mako_preview',
			nonce: config.nonce,
			post_id: postId
		})
		.done(function (response) {
			if (response.success) {
				$('#mako-preview-content').text(response.data.content);
				$('#mako-preview-modal').show();
			} else {
				alert(response.data || 'Preview failed');
			}
		});
	}

	// Bulk generate.
	function bulkGenerate($button) {
		if (!confirm(config.i18n.confirm)) {
			return;
		}

		var originalText = $button.text();
		$button.prop('disabled', true).html('<span class="mako-spinner"></span>' + config.i18n.generating);
		$('#mako-bulk-status').text('');

		$.post(config.ajaxUrl, {
			action: 'mako_bulk_generate',
			nonce: config.nonce
		})
		.done(function (response) {
			if (response.success) {
				var msg = response.data.generated + ' pages generated.';
				if (response.data.remaining > 0) {
					msg += ' ' + response.data.remaining + ' remaining - run again.';
				}
				$('#mako-bulk-status').text(msg);
				if (response.data.remaining > 0) {
					// Auto-continue.
					setTimeout(function () {
						bulkGenerate($button);
					}, 500);
					return;
				}
				$('#mako-bulk-status').text(config.i18n.bulkDone + ' ' + msg);
				setTimeout(function () {
					window.location.reload();
				}, 1000);
			} else {
				$('#mako-bulk-status').text(config.i18n.error + ': ' + (response.data || ''));
			}
		})
		.fail(function () {
			$('#mako-bulk-status').text(config.i18n.error);
		})
		.always(function () {
			$button.prop('disabled', false).text(originalText);
		});
	}

	// Event handlers.
	$(document).ready(function () {
		// Single generate.
		$(document).on('click', '.mako-btn-generate, .mako-btn-regenerate', function (e) {
			e.preventDefault();
			generateMako($(this).data('post-id'), $(this));
		});

		// Preview.
		$(document).on('click', '.mako-btn-preview', function (e) {
			e.preventDefault();
			previewMako($(this).data('post-id'));
		});

		// Close modal.
		$(document).on('click', '.mako-modal-close, .mako-modal-overlay', function () {
			$('#mako-preview-modal').hide();
		});

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				$('#mako-preview-modal').hide();
			}
		});

		// Bulk generate.
		$('#mako-bulk-generate').on('click', function (e) {
			e.preventDefault();
			bulkGenerate($(this));
		});

		// Flush cache.
		$('#mako-flush-cache').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(config.ajaxUrl, {
				action: 'mako_bulk_generate',
				nonce: config.nonce
			})
			.always(function () {
				$btn.prop('disabled', false);
				$('#mako-bulk-status').text('Cache flushed.');
			});
		});
	});
})(jQuery);
