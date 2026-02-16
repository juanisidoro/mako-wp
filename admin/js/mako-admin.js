(function ($) {
	'use strict';

	var config = window.makoAdmin || {};
	var bulkState = {
		running: false,
		paused: false,
		processed: 0,
		total: 0,
		batchLimit: 0, // 0 = unlimited (all pending)
		timer: null
	};

	// --- Utility ---

	function timestamp() {
		return new Date().toLocaleTimeString();
	}

	function getSelectedPostTypes() {
		var types = [];
		$('.mako-generate-type:checked').each(function () {
			types.push($(this).val());
		});
		return types;
	}

	function getSelectedPostIds() {
		var ids = [];
		$('.mako-row-check:checked').each(function () {
			ids.push(parseInt($(this).val(), 10));
		});
		return ids;
	}

	function logMessage(msg, type) {
		var $log = $('#mako-log');
		var $container = $('#mako-log-container');
		$container.show();

		var cls = type === 'error' ? 'mako-log-error' :
		          type === 'skip'  ? 'mako-log-skip' :
		          type === 'done'  ? 'mako-log-done' : '';

		$log.append(
			'<div class="mako-log-entry ' + cls + '">' +
			'<span class="mako-log-time">[' + timestamp() + ']</span> ' +
			msg +
			'</div>'
		);

		$log.scrollTop($log[0].scrollHeight);
	}

	function updateProgress(processed, total) {
		var $container = $('#mako-progress-container');
		$container.show();

		var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
		$('#mako-progress-fill').css('width', pct + '%');
		$('#mako-progress-count').text(processed + ' / ' + total);
		$('#mako-progress-text').text(
			bulkState.paused ? config.i18n.paused :
			bulkState.running ? config.i18n.generating : ''
		);
	}

	function setControls(state) {
		$('#mako-test-one').prop('disabled', state !== 'idle');
		$('.mako-btn-batch').prop('disabled', state !== 'idle');
		$('#mako-pause-bulk').prop('disabled', state !== 'running').text(
			state === 'paused' ? config.i18n.resume : config.i18n.pause
		);
		$('#mako-stop-bulk').prop('disabled', state === 'idle');
	}

	// --- Single post generation (from meta box) ---

	function generateMako(postId, $button) {
		var originalText = $button.html();
		$button.prop('disabled', true).html('<span class="mako-spinner"></span>' + config.i18n.generating);

		$.post(config.ajaxUrl, {
			action: 'mako_generate',
			nonce: config.nonce,
			post_id: postId
		})
		.done(function (response) {
			if (response.success) {
				$button.html(originalText);
				// If the editor textarea exists, fetch preview and populate it.
				var $editor = $('#mako_custom_content');
				if ($editor.length) {
					$.post(config.ajaxUrl, {
						action: 'mako_preview',
						nonce: config.nonce,
						post_id: postId
					}).done(function (prev) {
						if (prev.success && prev.data.content) {
							$editor.val(prev.data.content);
						}
					});
					$('.mako-meta-metrics').show();
				} else {
					setTimeout(function () {
						window.location.reload();
					}, 500);
				}
			} else {
				$button.html('<span class="dashicons dashicons-warning"></span> ' + config.i18n.error);
				alert(response.data || 'Generation failed');
			}
		})
		.fail(function () {
			$button.html('<span class="dashicons dashicons-warning"></span> ' + config.i18n.error);
		})
		.always(function () {
			setTimeout(function () {
				$button.prop('disabled', false).html(originalText);
			}, 2000);
		});
	}

	// --- AI Enhance (BYOK) ---

	function aiEnhance(postId, $button) {
		var originalHtml = $button.html();
		$button.prop('disabled', true).html('<span class="mako-spinner"></span>' + config.i18n.aiGenerating);

		$('#mako-ai-feedback').remove();

		$.post(config.ajaxUrl, {
			action: 'mako_ai_generate',
			nonce: config.nonce,
			post_id: postId
		})
		.done(function (response) {
			if (response.success) {
				$('#mako_custom_content').val(response.data.content);
				$button.html(originalHtml);

				var d = response.data;
				var usage = d.usage || {};
				var feedback = '<div id="mako-ai-feedback" class="mako-ai-feedback">' +
					'<span class="dashicons dashicons-info-outline"></span> ' +
					'<strong>' + (d.provider || '') + '/' + (d.model || '') + '</strong>' +
					' — ' + (usage.input_tokens || 0).toLocaleString() + ' input + ' +
					(usage.output_tokens || 0).toLocaleString() + ' output = ' +
					(usage.total_tokens || 0).toLocaleString() + ' tokens' +
					'</div>';
				$('.mako-meta-actions').after(feedback);
			} else {
				alert((config.i18n.aiError || 'AI generation failed') + ': ' + (response.data || ''));
				$button.html(originalHtml);
			}
		})
		.fail(function () {
			alert(config.i18n.aiError || 'AI generation failed');
			$button.html(originalHtml);
		})
		.always(function () {
			$button.prop('disabled', false);
		});
	}

	// --- Cover Image Selector ---

	var coverFrame = null;

	function openCoverSelector() {
		if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
			alert('Media library not available. Please reload the page.');
			return;
		}

		if (coverFrame) {
			coverFrame.open();
			return;
		}

		coverFrame = wp.media({
			title: 'Select Cover Image',
			button: { text: 'Use as Cover' },
			multiple: false,
			library: { type: 'image' }
		});

		coverFrame.on('select', function () {
			var attachment = coverFrame.state().get('selection').first().toJSON();
			$('#mako_custom_cover').val(attachment.id);

			var imgUrl = attachment.sizes && attachment.sizes.thumbnail
				? attachment.sizes.thumbnail.url
				: attachment.url;

			var $thumb = $('#mako-cover-thumb');
			$thumb.find('.mako-cover-preview').attr('src', imgUrl);
			$thumb.show();

			$('.mako-btn-cover-select').text(config.i18n.change || 'Change');
			$('.mako-btn-cover-remove').show();
		});

		coverFrame.open();
	}

	function removeCover() {
		$('#mako_custom_cover').val('');
		$('#mako-cover-thumb').hide().find('.mako-cover-preview').attr('src', '');
		$('.mako-btn-cover-remove').hide();
		$('.mako-btn-cover-select').text(config.i18n.selectImage || 'Select Image');
	}

	// --- Preview ---

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

	// --- Delete MAKO ---

	function deleteMako(postId) {
		if (!confirm(config.i18n.confirmDelete || 'Delete MAKO content for this post?')) {
			return;
		}

		$.post(config.ajaxUrl, {
			action: 'mako_delete',
			nonce: config.nonce,
			post_id: postId
		})
		.done(function (response) {
			if (response.success) {
				$('#mako-row-' + postId).fadeOut(300, function () {
					$(this).remove();
				});
				logMessage(config.i18n.deleted || 'MAKO deleted for post #' + postId, 'done');
			} else {
				alert(response.data || 'Delete failed');
			}
		});
	}

	// --- Bulk Actions (delete/regenerate selected) ---

	function bulkDelete(postIds) {
		if (!confirm(config.i18n.confirmBulkDelete || 'Delete MAKO for selected posts?')) {
			return;
		}

		$.post(config.ajaxUrl, {
			action: 'mako_bulk_delete',
			nonce: config.nonce,
			post_ids: postIds
		})
		.done(function (response) {
			if (response.success) {
				postIds.forEach(function (id) {
					$('#mako-row-' + id).fadeOut(300, function () {
						$(this).remove();
					});
				});
				var msg = (config.i18n.bulkDeleted || 'Deleted MAKO for %d posts.').replace('%d', response.data.deleted);
				logMessage(msg, 'done');
			} else {
				alert(response.data || 'Bulk delete failed');
			}
		});
	}

	function bulkRegenerate(postIds) {
		if (!confirm(config.i18n.confirmBulkRegen || 'Regenerate MAKO for selected posts?')) {
			return;
		}

		bulkState.running = true;
		bulkState.paused = false;
		bulkState.processed = 0;
		bulkState.total = postIds.length;

		updateProgress(0, postIds.length);
		setControls('running');
		logMessage(config.i18n.batchStarting + ' ' + postIds.length + ' posts');

		function regenNext(index) {
			if (!bulkState.running || bulkState.paused) {
				return;
			}

			if (index >= postIds.length) {
				logMessage(config.i18n.bulkRegenDone, 'done');
				stopBulk();
				setTimeout(function () { window.location.reload(); }, 1500);
				return;
			}

			$.post(config.ajaxUrl, {
				action: 'mako_bulk_regenerate_next',
				nonce: config.nonce,
				post_ids: postIds,
				index: index
			})
			.done(function (response) {
				if (!response.success) {
					logMessage(config.i18n.error + ': ' + (response.data || ''), 'error');
					stopBulk();
					return;
				}

				var d = response.data;
				bulkState.processed = index + 1;

				if (d.skipped) {
					logMessage('<strong>' + d.title + '</strong> — ' + config.i18n.skipped + ' (' + d.reason + ')', 'skip');
				} else {
					logMessage(
						'<strong>' + d.title + '</strong> — ' +
						d.type + ' · ' + d.html_tokens + ' → ' + d.tokens + ' tokens · ' +
						d.savings + '% ' + config.i18n.savings
					);
				}

				updateProgress(bulkState.processed, bulkState.total);

				if (!bulkState.running || bulkState.paused) return;

				var delay = parseInt($('#mako-delay').val(), 10) || 3000;
				bulkState.timer = setTimeout(function () { regenNext(index + 1); }, delay);
			})
			.fail(function () {
				logMessage(config.i18n.error + ': Network error', 'error');
				stopBulk();
			});
		}

		regenNext(0);
	}

	function applyBulkAction(selectorId) {
		var action = $(selectorId).val();
		var postIds = getSelectedPostIds();

		if (!action) {
			alert(config.i18n.selectAction || 'Select a bulk action.');
			return;
		}
		if (postIds.length === 0) {
			alert(config.i18n.noSelection || 'No posts selected.');
			return;
		}

		if (action === 'delete') {
			bulkDelete(postIds);
		} else if (action === 'regenerate') {
			bulkRegenerate(postIds);
		}
	}

	// --- Batch generation (generate N pending posts) ---

	function startBatch(limit) {
		var types = getSelectedPostTypes();
		if (types.length === 0) {
			logMessage(config.i18n.noTypesSelected || 'Select at least one post type.', 'error');
			return;
		}

		// First, get the queue to know how many are pending.
		$.post(config.ajaxUrl, {
			action: 'mako_get_queue',
			nonce: config.nonce,
			post_types: types
		})
		.done(function (response) {
			if (!response.success) {
				logMessage(config.i18n.error, 'error');
				return;
			}

			var d = response.data;

			if (d.pending === 0) {
				logMessage(config.i18n.noPending, 'done');
				return;
			}

			// Determine how many to process.
			var toProcess = limit > 0 ? Math.min(limit, d.pending) : d.pending;

			bulkState.total = toProcess;
			bulkState.processed = 0;
			bulkState.batchLimit = toProcess;
			bulkState.running = true;
			bulkState.paused = false;

			updateProgress(0, toProcess);
			setControls('running');

			logMessage(
				(config.i18n.batchStarting || 'Starting batch:') + ' ' +
				toProcess + ' / ' + d.pending + ' ' + (config.i18n.pending || 'pending')
			);

			processBatchNext(types);
		});
	}

	function processBatchNext(types) {
		if (!bulkState.running || bulkState.paused) {
			return;
		}

		// Check if we've reached the batch limit.
		if (bulkState.batchLimit > 0 && bulkState.processed >= bulkState.batchLimit) {
			logMessage(config.i18n.batchDone || 'Batch complete!', 'done');
			stopBulk();
			setTimeout(function () { window.location.reload(); }, 1500);
			return;
		}

		var data = {
			action: 'mako_generate_batch_next',
			nonce: config.nonce
		};
		if (types && types.length > 0) {
			data.post_types = types;
		}

		$.post(config.ajaxUrl, data)
		.done(function (response) {
			if (!response.success) {
				logMessage(config.i18n.error + ': ' + (response.data || ''), 'error');
				stopBulk();
				return;
			}

			var d = response.data;

			if (d.done) {
				logMessage(config.i18n.bulkDone || 'All done!', 'done');
				stopBulk();
				updateProgress(bulkState.total, bulkState.total);
				setTimeout(function () { window.location.reload(); }, 1500);
				return;
			}

			bulkState.processed++;

			if (d.skipped) {
				logMessage(
					'<strong>' + d.title + '</strong> — ' + config.i18n.skipped +
					' (' + d.reason + ')',
					'skip'
				);
			} else {
				logMessage(
					'<strong>' + d.title + '</strong> — ' +
					d.type + ' · ' +
					d.html_tokens + ' → ' + d.tokens + ' tokens · ' +
					d.savings + '% ' + config.i18n.savings
				);
			}

			updateProgress(bulkState.processed, bulkState.total);

			if (!bulkState.running || bulkState.paused) return;

			var delay = parseInt($('#mako-delay').val(), 10) || 3000;
			bulkState.timer = setTimeout(function () { processBatchNext(types); }, delay);
		})
		.fail(function () {
			logMessage(config.i18n.error + ': Network error', 'error');
			stopBulk();
		});
	}

	// --- Controls ---

	function pauseBulk() {
		if (bulkState.paused) {
			bulkState.paused = false;
			setControls('running');
			logMessage(config.i18n.resumed);
			// Resume: we need to know what types were selected.
			var types = getSelectedPostTypes();
			processBatchNext(types);
		} else {
			bulkState.paused = true;
			if (bulkState.timer) {
				clearTimeout(bulkState.timer);
				bulkState.timer = null;
			}
			setControls('paused');
			logMessage(config.i18n.pausedMsg);
		}
	}

	function stopBulk() {
		bulkState.running = false;
		bulkState.paused = false;
		bulkState.batchLimit = 0;
		if (bulkState.timer) {
			clearTimeout(bulkState.timer);
			bulkState.timer = null;
		}
		setControls('idle');
	}

	function testOne() {
		var types = getSelectedPostTypes();
		if (types.length === 0) {
			logMessage(config.i18n.noTypesSelected || 'Select at least one post type.', 'error');
			return;
		}

		setControls('running');
		logMessage(config.i18n.testingOne);

		$.post(config.ajaxUrl, {
			action: 'mako_generate_next',
			nonce: config.nonce,
			post_types: types
		})
		.done(function (response) {
			if (!response.success) {
				logMessage(config.i18n.error + ': ' + (response.data || ''), 'error');
				setControls('idle');
				return;
			}

			var d = response.data;

			if (d.done) {
				logMessage(config.i18n.noPending, 'done');
				setControls('idle');
				return;
			}

			if (d.skipped) {
				logMessage(
					'<strong>' + d.title + '</strong> — ' + config.i18n.skipped +
					' (' + d.reason + ')',
					'skip'
				);
			} else {
				logMessage(
					'<strong>' + d.title + '</strong> — ' +
					d.type + ' · ' +
					d.html_tokens + ' → ' + d.tokens + ' tokens · ' +
					d.savings + '% ' + config.i18n.savings,
					'done'
				);
			}

			setControls('idle');
			setTimeout(function () { window.location.reload(); }, 2000);
		})
		.fail(function () {
			logMessage(config.i18n.error + ': Network error', 'error');
			setControls('idle');
		});
	}

	// --- Event handlers ---

	$(document).ready(function () {
		// Single generate (meta box + dashboard regen).
		$(document).on('click', '.mako-btn-generate, .mako-btn-regenerate', function (e) {
			e.preventDefault();
			generateMako($(this).data('post-id'), $(this));
		});

		// AI Enhance.
		$(document).on('click', '.mako-btn-ai-enhance', function (e) {
			e.preventDefault();
			aiEnhance($(this).data('post-id'), $(this));
		});

		// Cover image selector.
		$(document).on('click', '.mako-btn-cover-select', function (e) {
			e.preventDefault();
			openCoverSelector();
		});

		// Cover image remove.
		$(document).on('click', '.mako-btn-cover-remove', function (e) {
			e.preventDefault();
			removeCover();
		});

		// Preview.
		$(document).on('click', '.mako-btn-preview', function (e) {
			e.preventDefault();
			previewMako($(this).data('post-id'));
		});

		// Delete MAKO.
		$(document).on('click', '.mako-btn-delete-mako', function (e) {
			e.preventDefault();
			deleteMako($(this).data('post-id'));
		});

		// Select all checkbox.
		$('#mako-select-all').on('change', function () {
			$('.mako-row-check').prop('checked', $(this).prop('checked'));
		});

		// Update select-all state when individual checkboxes change.
		$(document).on('change', '.mako-row-check', function () {
			var total = $('.mako-row-check').length;
			var checked = $('.mako-row-check:checked').length;
			$('#mako-select-all').prop('checked', total === checked);
		});

		// Bulk action apply.
		$(document).on('click', '.mako-btn-apply-bulk', function (e) {
			e.preventDefault();
			var selectorId = $(this).data('selector');
			applyBulkAction(selectorId);
		});

		// Batch generation buttons.
		$(document).on('click', '.mako-btn-batch', function (e) {
			e.preventDefault();
			var limit = parseInt($(this).data('batch'), 10) || 0;
			startBatch(limit);
		});

		// Copy preview content.
		$('#mako-copy-preview').on('click', function () {
			var content = $('#mako-preview-content').text();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(content).then(function () {
					$('#mako-copy-preview').text(config.i18n.copied || 'Copied!');
					setTimeout(function () {
						$('#mako-copy-preview').text(config.i18n.copy || 'Copy');
					}, 2000);
				});
			}
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

		// Dashboard controls.
		$('#mako-test-one').on('click', function (e) {
			e.preventDefault();
			testOne();
		});

		$('#mako-pause-bulk').on('click', function (e) {
			e.preventDefault();
			pauseBulk();
		});

		$('#mako-stop-bulk').on('click', function (e) {
			e.preventDefault();
			logMessage(config.i18n.stopped);
			stopBulk();
		});

		$('#mako-flush-cache').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(config.ajaxUrl, {
				action: 'mako_flush_cache',
				nonce: config.nonce
			})
			.done(function () {
				logMessage(config.i18n.cacheFlushed);
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});

		$('#mako-clear-log').on('click', function (e) {
			e.preventDefault();
			$('#mako-log').empty();
		});
	});
})(jQuery);
