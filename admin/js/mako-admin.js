(function ($) {
	'use strict';

	var config = window.makoAdmin || {};
	var bulkState = {
		running: false,
		paused: false,
		processed: 0,
		total: 0,
		timer: null
	};

	// --- Utility ---

	function timestamp() {
		return new Date().toLocaleTimeString();
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

		// Auto-scroll to bottom.
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
		// state: 'idle', 'running', 'paused'
		$('#mako-test-one').prop('disabled', state !== 'idle');
		$('#mako-start-bulk').prop('disabled', state !== 'idle');
		$('#mako-pause-bulk').prop('disabled', state !== 'running').text(
			state === 'paused' ? config.i18n.resume : config.i18n.pause
		);
		$('#mako-stop-bulk').prop('disabled', state === 'idle');
	}

	// --- Single post generation (from meta box) ---

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

	// --- Bulk generation (one at a time) ---

	function processNext() {
		if (!bulkState.running || bulkState.paused) {
			return;
		}

		$.post(config.ajaxUrl, {
			action: 'mako_generate_next',
			nonce: config.nonce
		})
		.done(function (response) {
			if (!response.success) {
				logMessage(config.i18n.error + ': ' + (response.data || ''), 'error');
				stopBulk();
				return;
			}

			var d = response.data;

			if (d.done) {
				logMessage(config.i18n.bulkDone, 'done');
				stopBulk();
				updateProgress(bulkState.total, bulkState.total);
				setTimeout(function () {
					window.location.reload();
				}, 1500);
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

			if (!bulkState.running || bulkState.paused) {
				return;
			}

			// Schedule next with delay.
			var delay = parseInt($('#mako-delay').val(), 10) || 3000;
			bulkState.timer = setTimeout(processNext, delay);
		})
		.fail(function () {
			logMessage(config.i18n.error + ': Network error', 'error');
			stopBulk();
		});
	}

	function startBulk() {
		// First, get the queue count.
		$.post(config.ajaxUrl, {
			action: 'mako_get_queue',
			nonce: config.nonce
		})
		.done(function (response) {
			if (!response.success) {
				logMessage(config.i18n.error, 'error');
				return;
			}

			var d = response.data;
			bulkState.total = d.pending + d.generated;
			bulkState.processed = d.generated;
			bulkState.running = true;
			bulkState.paused = false;

			updateProgress(bulkState.processed, bulkState.total);
			setControls('running');

			logMessage(
				config.i18n.starting + ' ' +
				d.pending + ' ' + config.i18n.pending + ' / ' +
				d.total + ' ' + config.i18n.totalPosts
			);

			processNext();
		});
	}

	function pauseBulk() {
		if (bulkState.paused) {
			// Resume.
			bulkState.paused = false;
			setControls('running');
			logMessage(config.i18n.resumed);
			processNext();
		} else {
			// Pause.
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
		if (bulkState.timer) {
			clearTimeout(bulkState.timer);
			bulkState.timer = null;
		}
		setControls('idle');
	}

	function testOne() {
		setControls('running');
		logMessage(config.i18n.testingOne);

		$.post(config.ajaxUrl, {
			action: 'mako_generate_next',
			nonce: config.nonce
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
			// Reload after a moment to update the table.
			setTimeout(function () {
				window.location.reload();
			}, 2000);
		})
		.fail(function () {
			logMessage(config.i18n.error + ': Network error', 'error');
			setControls('idle');
		});
	}

	// --- Event handlers ---

	$(document).ready(function () {
		// Single generate (meta box).
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

		// Dashboard controls.
		$('#mako-test-one').on('click', function (e) {
			e.preventDefault();
			testOne();
		});

		$('#mako-start-bulk').on('click', function (e) {
			e.preventDefault();
			startBulk();
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
