/**
 * SEV WC Product Sync — Admin JS
 *
 * Handles:
 *   - "Sync Now" button → init_batch AJAX → repeated process_batch AJAX loop
 *   - Live progress bar + stats counter updates
 *   - "Reset Sync Cache" button
 *
 * Depends on:  jQuery (WordPress built-in)
 * WP global:   window.sevSync = { ajaxUrl, nonce }
 */

/* global sevSync, jQuery */

jQuery( function ( $ ) {
	'use strict';

	// ── Element references ──────────────────────────────────────────────────────
	var $syncBtn      = $( '#sev-sync-now' );
	var $resetBtn     = $( '#sev-reset-cache' );
	var $trashAllBtn   = $( '#sev-trash-all' );
	var $emptyTrashBtn = $( '#sev-empty-trash' );
	var $progressWrap = $( '#sev-progress-section' );
	var $progressBar  = $( '#sev-progress-bar' );
	var $progressText = $( '#sev-progress-text' );
	var $statusMsg    = $( '#sev-status-message' );

	// Stat value elements
	var $stats = {
		created : $( '#sev-stat-created' ),
		updated : $( '#sev-stat-updated' ),
		skipped : $( '#sev-stat-skipped' ),
		deleted : $( '#sev-stat-deleted' ),
		errors  : $( '#sev-stat-errors' ),
	};

	// ── Sync Now ───────────────────────────────────────────────────────────────

	$syncBtn.on( 'click', function ( e ) {
		e.preventDefault();

		$syncBtn.prop( 'disabled', true ).text( 'Fetching products…' );
		$progressWrap.show();
		resetProgress();
		setStatus( 'Connecting to the Shopify API…' );

		$.ajax( {
			url     : sevSync.ajaxUrl,
			method  : 'POST',
			timeout : 0, // no JS timeout — server handles long requests via set_time_limit(0)
			data    : {
				action : 'sev_init_batch',
				nonce  : sevSync.nonce,
			},
			success : function ( response ) {
				if ( ! response.success ) {
					showError( ( response.data && response.data.message ) || 'Failed to start sync.' );
					return;
				}
				updateUI( response.data );
				if ( response.data.status === 'processing' ) {
					$syncBtn.text( 'Importing…' );
					processBatch();
				}
			},
			error   : function ( xhr, status, err ) {
				showError( 'Network error during init: ' + err );
			},
		} );
	} );

	// ── Batch processing loop ──────────────────────────────────────────────────

	/**
	 * Call sev_process_batch once, then schedule the next call unless done.
	 * Uses setTimeout(100) between calls so the browser can repaint the UI.
	 */
	function processBatch() {
		$.ajax( {
			url     : sevSync.ajaxUrl,
			method  : 'POST',
			timeout : 0,
			data    : {
				action : 'sev_process_batch',
				nonce  : sevSync.nonce,
			},
			success : function ( response ) {
				if ( ! response.success ) {
					showError( ( response.data && response.data.message ) || 'Batch failed.' );
					return;
				}

				var state = response.data;
				updateUI( state );

				if ( state.status === 'complete' ) {
					showComplete( state.stats );
				} else if ( state.status === 'error' ) {
					showError( state.message || 'Unknown error during import.' );
				} else {
					// Still processing — continue after a short pause.
					setTimeout( processBatch, 100 );
				}
			},
			error   : function ( xhr, status, err ) {
				showError( 'Network error during batch: ' + err );
			},
		} );
	}

	// ── Delete All Products to Trash ──────────────────────────────────────────

	$trashAllBtn.on( 'click', function ( e ) {
		e.preventDefault();

		if ( ! window.confirm( 'Move ALL synced products to trash?\n\nThis cannot be undone easily. You will need to run Sync Now to re-import everything.' ) ) {
			return;
		}

		$trashAllBtn.prop( 'disabled', true ).text( 'Deleting…' );
		$progressWrap.show();
		resetProgress();
		setStatus( 'Moving all products to trash…' );

		$.ajax( {
			url     : sevSync.ajaxUrl,
			method  : 'POST',
			timeout : 0,
			data    : {
				action : 'sev_trash_all',
				nonce  : sevSync.nonce,
			},
			success : function ( response ) {
				$trashAllBtn.prop( 'disabled', false ).text( 'Delete All Products to Trash' );
				if ( response.success ) {
					setStatus( response.data.message, 'success' );
				} else {
					setStatus( ( response.data && response.data.message ) || 'Failed.', 'error' );
				}
			},
			error   : function () {
				$trashAllBtn.prop( 'disabled', false ).text( 'Delete All Products to Trash' );
				setStatus( 'Network error. Please try again.', 'error' );
			},
		} );
	} );

	// ── Permanently Delete Trashed Products ───────────────────────────────────

	$emptyTrashBtn.on( 'click', function ( e ) {
		e.preventDefault();

		if ( ! window.confirm( 'PERMANENTLY DELETE all trashed synced products?\n\nThis action cannot be undone. Products will be gone forever.' ) ) {
			return;
		}

		$emptyTrashBtn.prop( 'disabled', true ).text( 'Deleting permanently…' );
		$progressWrap.show();
		resetProgress();
		setStatus( 'Permanently deleting trashed products…' );

		$.ajax( {
			url     : sevSync.ajaxUrl,
			method  : 'POST',
			timeout : 0,
			data    : {
				action : 'sev_empty_trash',
				nonce  : sevSync.nonce,
			},
			success : function ( response ) {
				$emptyTrashBtn.prop( 'disabled', false ).text( 'Permanently Delete Trashed Products' );
				if ( response.success ) {
					setStatus( response.data.message, 'success' );
				} else {
					setStatus( ( response.data && response.data.message ) || 'Failed.', 'error' );
				}
			},
			error   : function () {
				$emptyTrashBtn.prop( 'disabled', false ).text( 'Permanently Delete Trashed Products' );
				setStatus( 'Network error. Please try again.', 'error' );
			},
		} );
	} );

	// ── Reset Cache ────────────────────────────────────────────────────────────

	$resetBtn.on( 'click', function ( e ) {
		e.preventDefault();

		if ( ! window.confirm( 'Clear the sync cache?\n\nThe next sync will re-import every product from scratch instead of skipping unchanged ones.' ) ) {
			return;
		}

		$resetBtn.prop( 'disabled', true ).text( 'Clearing…' );

		$.ajax( {
			url     : sevSync.ajaxUrl,
			method  : 'POST',
			timeout : 30000,
			data    : {
				action : 'sev_reset_cache',
				nonce  : sevSync.nonce,
			},
			success : function ( response ) {
				$resetBtn.prop( 'disabled', false ).text( 'Reset Sync Cache' );
				if ( response.success ) {
					var msg = ( response.data && response.data.message ) || 'Cache cleared.';
					setStatus( msg, 'success' );
					$progressWrap.show();
				} else {
					setStatus( ( response.data && response.data.message ) || 'Failed to clear cache.', 'error' );
				}
			},
			error   : function () {
				$resetBtn.prop( 'disabled', false ).text( 'Reset Sync Cache' );
				setStatus( 'Network error. Please try again.', 'error' );
			},
		} );
	} );

	// ── UI helpers ─────────────────────────────────────────────────────────────

	/**
	 * Update progress bar, percentage label, status message and stat counters
	 * from a batch-state object.
	 *
	 * @param {Object} state  Batch state returned by the AJAX endpoint.
	 */
	function updateUI( state ) {
		var pct = state.progress || 0;
		$progressBar
			.css( 'width', pct + '%' )
			.attr( 'aria-valuenow', pct );
		$progressText.text( pct + '%' );

		if ( state.message ) {
			setStatus( state.message );
		}

		var s = state.stats || {};
		$stats.created.text( s.created || 0 );
		$stats.updated.text( s.updated || 0 );
		$stats.skipped.text( s.skipped || 0 );
		$stats.deleted.text( s.deleted || 0 );
		$stats.errors.text(  s.errors  || 0 );
	}

	function resetProgress() {
		$progressBar.css( 'width', '0%' ).attr( 'aria-valuenow', 0 );
		$progressText.text( '0%' );
		$statusMsg.text( '' ).removeClass( 'sev-error sev-success' );
		$.each( $stats, function ( _, $el ) { $el.text( 0 ); } );
	}

	/**
	 * Display a status/message line below the progress bar.
	 *
	 * @param {string} message
	 * @param {string} [type]  'success' | 'error' | '' (neutral)
	 */
	function setStatus( message, type ) {
		$statusMsg
			.text( message )
			.removeClass( 'sev-error sev-success' );
		if ( type ) {
			$statusMsg.addClass( 'sev-' + type );
		}
	}

	function showComplete( stats ) {
		$progressBar.css( 'width', '100%' ).attr( 'aria-valuenow', 100 );
		$progressText.text( '100%' );

		var parts = [
			'Sync complete',
			'Created: '  + ( stats.created || 0 ),
			'Updated: '  + ( stats.updated || 0 ),
			'Skipped: '  + ( stats.skipped || 0 ),
			'Deleted: '  + ( stats.deleted || 0 ),
		];
		if ( stats.errors > 0 ) {
			parts.push( 'Errors: ' + stats.errors );
		}
		setStatus( parts.join( ' — ' ), 'success' );
		$syncBtn.prop( 'disabled', false ).text( 'Sync Now' );
	}

	function showError( message ) {
		setStatus( 'Error: ' + message, 'error' );
		$syncBtn.prop( 'disabled', false ).text( 'Sync Now' );
	}

} );
