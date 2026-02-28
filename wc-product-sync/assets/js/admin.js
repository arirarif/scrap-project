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
            $('#wcps-trash-all').on('click', this.handleTrashAll.bind(this));
            $('#wcps-empty-trash').on('click', this.handleEmptyTrash.bind(this));
            $('#wcps-reset-cache').on('click', this.handleResetCache.bind(this));
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
         * Start sync process (batch mode)
         */
        startSync: function($button) {
            var self = this;

            // Store button reference for later
            this.$syncButton = $button;

            // Update UI
            $button.addClass('syncing').prop('disabled', true);
            $('#wcps-sync-status').html('<span class="wcps-spinner"></span> ' + wcps.strings.syncing);
            $('#wcps-progress').show();

            // Send initial AJAX request to start batch
            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                timeout: 60000, // 60 second timeout per batch
                data: {
                    action: 'wcps_manual_sync',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.handleBatchResponse(response.data);
                    } else {
                        self.syncError(response.data.message);
                        self.resetSyncButton();
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = error;
                    if (xhr.responseText) {
                        // Try to parse JSON error response
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                            }
                        } catch (e) {
                            // If not JSON, show raw response (truncated)
                            if (xhr.responseText.length > 200) {
                                errorMsg = xhr.responseText.substring(0, 200) + '...';
                            } else {
                                errorMsg = xhr.responseText;
                            }
                        }
                    }
                    errorMsg = status + ': ' + errorMsg;
                    console.error('WCPS Sync Error:', xhr.responseText);
                    self.syncError(errorMsg);
                    self.resetSyncButton();
                }
            });
        },

        /**
         * Handle batch response and continue if needed
         */
        handleBatchResponse: function(data) {
            var self = this;

            // Update progress display
            this.updateProgress({
                progress: data.progress || 0,
                message: data.message || ''
            });

            // Check status
            if (data.status === 'complete') {
                this.syncComplete(data);
                this.resetSyncButton();
            } else if (data.status === 'error') {
                this.syncError(data.message);
                this.resetSyncButton();
            } else if (data.status === 'running') {
                // Continue with next batch after small delay
                setTimeout(function() {
                    self.continueBatch();
                }, 500);
            } else {
                // Unknown status, treat as error
                this.syncError('Unknown sync status: ' + data.status);
                this.resetSyncButton();
            }
        },

        /**
         * Continue batch processing
         */
        continueBatch: function() {
            var self = this;

            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                timeout: 60000, // 60 second timeout per batch
                data: {
                    action: 'wcps_continue_sync',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.handleBatchResponse(response.data);
                    } else {
                        self.syncError(response.data.message);
                        self.resetSyncButton();
                    }
                },
                error: function(xhr, status, error) {
                    // On timeout/error, try to continue (may have been a temporary issue)
                    if (status === 'timeout') {
                        console.log('Batch timeout, retrying...');
                        setTimeout(function() {
                            self.continueBatch();
                        }, 1000);
                    } else {
                        var errorMsg = error;
                        if (xhr.responseText) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.data && response.data.message) {
                                    errorMsg = response.data.message;
                                }
                            } catch (e) {
                                if (xhr.responseText.length > 200) {
                                    errorMsg = xhr.responseText.substring(0, 200) + '...';
                                } else {
                                    errorMsg = xhr.responseText;
                                }
                            }
                        }
                        errorMsg = status + ': ' + errorMsg;
                        console.error('WCPS Continue Sync Error:', xhr.responseText);
                        self.syncError(errorMsg);
                        self.resetSyncButton();
                    }
                }
            });
        },

        /**
         * Reset sync button state
         */
        resetSyncButton: function() {
            if (this.$syncButton) {
                this.$syncButton.removeClass('syncing').prop('disabled', false);
            }
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
        },

        /**
         * Handle trash all button click
         */
        handleTrashAll: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);

            if ($button.hasClass('trashing')) {
                return;
            }

            if (!confirm(wcps.strings.confirm_trash)) {
                return;
            }

            var self = this;

            // Update UI
            $button.addClass('trashing').prop('disabled', true);
            $('#wcps-sync-status').html('<span class="wcps-spinner"></span> ' + wcps.strings.trashing);

            // Send AJAX request
            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcps_trash_all',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wcps-sync-status')
                            .removeClass('error')
                            .addClass('success')
                            .html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + response.data.message);

                        // Reload page after short delay
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#wcps-sync-status')
                            .removeClass('success')
                            .addClass('error')
                            .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.trash_error + ' ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#wcps-sync-status')
                        .removeClass('success')
                        .addClass('error')
                        .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.trash_error + ' ' + error);
                },
                complete: function() {
                    $button.removeClass('trashing').prop('disabled', false);
                }
            });
        },

        /**
         * Handle empty trash button click
         */
        handleEmptyTrash: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);

            if ($button.hasClass('emptying')) {
                return;
            }

            if (!confirm(wcps.strings.confirm_empty_trash)) {
                return;
            }

            var self = this;

            // Update UI
            $button.addClass('emptying').prop('disabled', true);
            $('#wcps-sync-status').html('<span class="wcps-spinner"></span> ' + wcps.strings.emptying_trash);

            // Send AJAX request
            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcps_empty_trash',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wcps-sync-status')
                            .removeClass('error')
                            .addClass('success')
                            .html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + response.data.message);

                        // Reload page after short delay
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $('#wcps-sync-status')
                            .removeClass('success')
                            .addClass('error')
                            .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.empty_trash_error + ' ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#wcps-sync-status')
                        .removeClass('success')
                        .addClass('error')
                        .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.empty_trash_error + ' ' + error);
                },
                complete: function() {
                    $button.removeClass('emptying').prop('disabled', false);
                }
            });
        },

        /**
         * Handle reset cache button click
         */
        handleResetCache: function(e) {
            e.preventDefault();

            var $button = $(e.currentTarget);

            if ($button.hasClass('resetting')) {
                return;
            }

            if (!confirm(wcps.strings.confirm_reset_cache)) {
                return;
            }

            var self = this;

            // Update UI
            $button.addClass('resetting').prop('disabled', true);
            $('#wcps-sync-status').html('<span class="wcps-spinner"></span> ' + wcps.strings.resetting_cache);

            // Send AJAX request
            $.ajax({
                url: wcps.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcps_reset_sync',
                    nonce: wcps.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wcps-sync-status')
                            .removeClass('error')
                            .addClass('success')
                            .html('<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ' + wcps.strings.cache_reset_complete);
                    } else {
                        $('#wcps-sync-status')
                            .removeClass('success')
                            .addClass('error')
                            .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.cache_reset_error + ' ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#wcps-sync-status')
                        .removeClass('success')
                        .addClass('error')
                        .html('<span class="dashicons dashicons-warning" style="color: red;"></span> ' + wcps.strings.cache_reset_error + ' ' + error);
                },
                complete: function() {
                    $button.removeClass('resetting').prop('disabled', false);
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WCPS.init();
    });

})(jQuery);
