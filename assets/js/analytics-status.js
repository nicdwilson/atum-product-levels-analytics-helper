/**
 * ATUM Product Levels - Analytics Status Page Scripts
 *
 * @since 1.9.14
 */

(function($) {
	'use strict';

	const AtumPLAnalyticsStatus = {

		progressInterval: null,

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.checkIfBackfillRunning();
		},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			$('#atum-backfill-btn').on('click', this.handleBackfill.bind(this));
			$('#atum-clear-analytics-btn').on('click', this.handleClear.bind(this));
			$('#atum-test-sync-btn').on('click', this.handleTestSync.bind(this));
		},

		/**
		 * Check if backfill is currently running
		 */
		checkIfBackfillRunning: function() {
			const status = $('.atum-backfill-status').text().trim();
			if (status === 'In Progress') {
				this.startProgressPolling();
			}
		},

		/**
		 * Start polling for progress updates
		 */
		startProgressPolling: function() {
			// Clear any existing interval
			if (this.progressInterval) {
				clearInterval(this.progressInterval);
			}

			// Poll every 2 seconds
			this.progressInterval = setInterval(this.updateProgress.bind(this), 2000);

			// Update immediately
			this.updateProgress();
		},

		/**
		 * Stop polling for progress updates
		 */
		stopProgressPolling: function() {
			if (this.progressInterval) {
				clearInterval(this.progressInterval);
				this.progressInterval = null;
			}
		},

		/**
		 * Update progress from server
		 */
		updateProgress: function() {
			$.ajax({
				url: atumPLAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'atum_pl_analytics_progress',
					nonce: atumPLAnalytics.nonce
				},
				success: function(response) {
					if (response.success && response.data) {
						const progress = response.data.progress;
						const sync = response.data.sync;

						// Update progress text
						if ($('#backfill-progress-text').length) {
							const progressText = progress.processed + ' / ' + progress.total + ' orders (' + progress.percent + '%)';
							$('#backfill-progress-text').html(
								progressText +
								'<div class="atum-progress-bar" style="margin-top: 8px;"><div class="atum-progress-fill" id="backfill-progress-bar" style="width: ' + progress.percent + '%;"></div></div>'
							);
						}

						// Update progress bar
						$('#backfill-progress-bar').css('width', progress.percent + '%');

						// Update sync statistics
						if (sync) {
							$('.atum-pl-status-section').find('td').each(function() {
								const $td = $(this);
								if ($td.prev('th').text().includes('Synced to Analytics')) {
									$td.text(sync.synced_boms.toLocaleString());
								} else if ($td.prev('th').text().includes('Sync Coverage')) {
									const html = '<strong>' + sync.sync_percent + '%</strong>' +
										'<div class="atum-progress-bar">' +
										'<div class="atum-progress-fill" style="width: ' + sync.sync_percent + '%;"></div>' +
										'</div>';
									$td.html(html);
								}
							});
						}

						// Check if completed
						if (progress.status === 'completed') {
							this.stopProgressPolling();

							// Show completion message
							$('#atum-action-result')
								.removeClass('notice-error')
								.addClass('notice-success')
								.html('<p>Backfill completed! Processed ' + progress.processed + ' orders.</p>')
								.show();

							// Reload after 2 seconds to update all stats
							setTimeout(function() {
								location.reload();
							}, 2000);
						}
					}
				}.bind(this),
				error: function() {
					// Continue polling even on error
				}
			});
		},

		/**
		 * Handle backfill
		 */
		handleBackfill: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const $result = $('#atum-action-result');

			if (!confirm('This will sync all historical BOM data to WooCommerce Analytics.\n\nThis may take 10-30 seconds. Please wait...\n\nContinue?')) {
				return;
			}

			$button.prop('disabled', true).text('Processing (this may take 30 seconds)...');
			$result.hide();

			$.ajax({
				url: atumPLAnalytics.ajaxUrl,
				type: 'POST',
				timeout: 120000, // 2 minute timeout
				data: {
					action: 'atum_pl_analytics_backfill',
					nonce: atumPLAnalytics.nonce
				},
				success: function(response) {
					if (response.success) {
						$result
							.removeClass('notice-error')
							.addClass('notice-success')
							.html('<p>' + response.data.message + '</p>')
							.show();

						// Reload after 2 seconds
						setTimeout(function() {
							location.reload();
						}, 2000);
					} else {
						$result
							.removeClass('notice-success')
							.addClass('notice-error')
							.html('<p>' + (response.data.message || atumPLAnalytics.messages.error) + '</p>')
							.show();
					}
				}.bind(this),
				error: function() {
					$result
						.removeClass('notice-success')
						.addClass('notice-error')
						.html('<p>' + atumPLAnalytics.messages.error + '</p>')
						.show();
				},
				complete: function() {
					$button.prop('disabled', false).text('Run Historical Backfill');
				}
			});
		},

		/**
		 * Handle clear analytics
		 */
		handleClear: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const $result = $('#atum-action-result');

			if (!confirm('This will remove all BOM data from WooCommerce Analytics.\n\nYou can restore it by running the backfill again.\n\nContinue?')) {
				return;
			}

			$button.prop('disabled', true).text('Clearing...');
			$result.hide();

			$.ajax({
				url: atumPLAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'atum_pl_analytics_clear',
					nonce: atumPLAnalytics.nonce
				},
				success: function(response) {
					if (response.success) {
						$result
							.removeClass('notice-error')
							.addClass('notice-success')
							.html('<p>' + response.data.message + '</p>')
							.show();

						// Reload page after 1 second to show updated stats
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						$result
							.removeClass('notice-success')
							.addClass('notice-error')
							.html('<p>' + (response.data.message || atumPLAnalytics.messages.error) + '</p>')
							.show();
					}
				},
				error: function() {
					$result
						.removeClass('notice-success')
						.addClass('notice-error')
						.html('<p>' + atumPLAnalytics.messages.error + '</p>')
						.show();
				},
				complete: function() {
					$button.prop('disabled', false).text('Clear BOM Analytics');
				}
			});
		},

		/**
		 * Handle test sync
		 */
		handleTestSync: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const $result = $('#atum-action-result');

			$button.prop('disabled', true).text('Testing...');
			$result.hide();

			$.ajax({
				url: atumPLAnalytics.ajaxUrl,
				type: 'POST',
				data: {
					action: 'atum_pl_analytics_test_sync',
					nonce: atumPLAnalytics.nonce
				},
				success: function(response) {
					if (response.success) {
						$result
							.removeClass('notice-error')
							.addClass('notice-success')
							.html('<p>' + response.data.message + '</p>')
							.show();
					} else {
						$result
							.removeClass('notice-success')
							.addClass('notice-error')
							.html('<p>' + (response.data.message || atumPLAnalytics.messages.error) + '</p>')
							.show();
					}
				},
				error: function() {
					$result
						.removeClass('notice-success')
						.addClass('notice-error')
						.html('<p>' + atumPLAnalytics.messages.error + '</p>')
						.show();
				},
				complete: function() {
					$button.prop('disabled', false).text('Test Sync (Latest Order)');
				}
			});
		}

	};

	// Initialize when DOM is ready
	$(document).ready(function() {
		AtumPLAnalyticsStatus.init();
	});

})(jQuery);
