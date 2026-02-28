/**
 * WC Product Sync Admin JavaScript
 */
(function($) {
    'use strict';

    var WCPS = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            $('#wcps-manual-sync').on('click', this.handleManualSync.bind(this));
        },

        /**
         * Handle manual sync button click
         */
        handleManualSync: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);

            if ($button.hasClass('syncing')) {
                return;
            }

            if (!confirm(wcps.strings.confirm_sync)) {
                return;
            }

            this.startSync($button);
        },

        /**
         * Start sync process
         */
        startSync: function($button) {
            var self = this;

            // Update UI
            $button.addClass('syncing').prop('disabled', true);
            $('#wcps-sync-status').html('<span class="wcps-spinner"></span> ' + wcps.strings.syncing);
            $('#wcps-progress').show();

            // Start polling for progress
            this.pollInterval = setInterval(function() {
                self.checkProgress();
            }, 2000);

            // Send AJAX request
            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcps_manual_sync',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    clearInterval(self.pollInterval);

                    if (response.success) {
                        self.syncComplete(response.data);
                    } else {
                        self.syncError(response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    clearInterval(self.pollInterval);
                    self.syncError(error);
                },
                complete: function() {
                    $button.removeClass('syncing').prop('disabled', false);
                }
            });
        },

        /**
         * Check sync progress
         */
        checkProgress: function() {
            var self = this;

            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcps_get_sync_status',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success && response.data.progress) {
                        self.updateProgress(response.data.progress);
                    }
                }
            });
        },

        /**
         * Update progress bar
         */
        updateProgress: function(progress) {
            var percent = progress.progress || 0;
            var message = progress.message || '';

            $('.wcps-progress-fill').css('width', percent + '%');
            $('.wcps-progress-message').text(message);
        },

        /**
         * Handle sync completion
         */
        syncComplete: function(data) {
            var message = wcps.strings.sync_complete;

            if (data.stats) {
                message += ' Created: ' + (data.stats.created || 0) +
                          ', Updated: ' + (data.stats.updated || 0) +
                          ', Deleted: ' + (data.stats.deleted || 0) +
                          ', Errors: ' + (data.stats.errors || 0);
            }

            $('#wcps-sync-status')
                .removeClass('error')
                .addClass('success')
                .html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + message);

            this.updateProgress({progress: 100, message: message});

            // Reload page after short delay to show updated stats
            setTimeout(function() {
                location.reload();
            }, 3000);
        },

        /**
         * Handle sync error
         */
        syncError: function(message) {
            $('#wcps-sync-status')
                .removeClass('success')
                .addClass('error')
                .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.sync_error + ' ' + message);

            $('#wcps-progress').hide();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WCPS.init();
    });

})(jQuery);
