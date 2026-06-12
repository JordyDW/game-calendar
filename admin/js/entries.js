( function ( $ ) {
	'use strict';

	var gcE = window.gcEntries;

	// ── Quick Edit ──────────────────────────────────────────────────────────

	$( document ).on( 'click', '.gc-qe-trigger', function ( e ) {
		e.preventDefault();

		// Remove any already-open quick edit row.
		$( '.gc-qe-row' ).remove();

		var $row = $( this ).closest( 'tr' );
		var entry;
		try { entry = JSON.parse( $row.attr( 'data-entry' ) || '{}' ); } catch ( ex ) { entry = {}; }

		var $qeRow = buildQERow( entry );
		$row.after( $qeRow );
		$qeRow.find( '[name="qe-title"]' ).trigger( 'focus' );

		// Cancel.
		$qeRow.on( 'click', '.gc-qe-cancel', function () {
			$qeRow.remove();
		} );

		// Save.
		$qeRow.on( 'click', '.gc-qe-save', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Saving…' );

			var postData = {
				action:      'gc_quick_add',
				nonce:       gcE.nonce,
				post_id:     entry.id,
				title:       $qeRow.find( '[name="qe-title"]' ).val(),
				post_status: $qeRow.find( '[name="qe-status"]' ).val(),
			};

			if ( 'gc_event' === entry.type ) {
				var sd = $qeRow.find( '[name="qe-start-date"]' ).val();
				var st = $qeRow.find( '[name="qe-start-time"]' ).val();
				var ed = $qeRow.find( '[name="qe-end-date"]' ).val();
				var et = $qeRow.find( '[name="qe-end-time"]' ).val();
				postData.gc_event_start = sd ? ( st ? sd + 'T' + st : sd ) : '';
				if ( ed ) {
					postData.gc_event_end = et ? ed + 'T' + et : ed;
				}
			} else {
				postData.gc_release_date = $qeRow.find( '[name="qe-date"]' ).val();
			}

			$.post( gcE.ajaxUrl, postData )
				.done( function ( res ) {
					if ( ! res.success ) {
						$btn.prop( 'disabled', false ).text( 'Save' );
						$qeRow.find( '.gc-qe-error' ).text( ( res.data && res.data.message ) || 'Failed to save.' ).show();
						return;
					}

					var newTitle  = postData.title;
					var newDate   = res.data.start || '';
					var newStatus = res.data.post_status || postData.post_status;

					// Update the data attribute.
					var updated = $.extend( {}, entry, { title: newTitle, date: newDate, status: newStatus } );
					if ( 'gc_event' === entry.type ) {
						var newStart = newDate;
						var newSD = ''; var newST = '';
						if ( newStart && newStart.indexOf( 'T' ) !== -1 ) {
							var sp = newStart.split( 'T' );
							newSD = sp[ 0 ]; newST = sp[ 1 ];
						} else {
							newSD = newStart;
						}
						updated.startDate = newSD;
						updated.startTime = newST;
					}
					$row.attr( 'data-entry', JSON.stringify( updated ) );

					// Update visible cells.
					$row.find( '.column-title strong a' ).text( newTitle || '(no title)' );
					$row.find( '.column-date' ).text( newDate || '—' );

					var dotClass = 'publish' === newStatus ? 'gc-status-dot--published' : 'gc-status-dot--draft';
					var statusText = 'publish' === newStatus ? 'Published' : 'Draft';
					$row.find( '.column-status' ).html( '<span class="gc-status-dot ' + dotClass + '"></span>' + statusText );

					$qeRow.remove();

					// Flash the row briefly to confirm the update.
					$row.addClass( 'gc-row-updated' );
					setTimeout( function () { $row.removeClass( 'gc-row-updated' ); }, 1400 );
				} )
				.fail( function () {
					$btn.prop( 'disabled', false ).text( 'Save' );
					$qeRow.find( '.gc-qe-error' ).text( 'Request failed.' ).show();
				} );
		} );
	} );

	// Close quick edit when clicking outside.
	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.gc-qe-row, .gc-qe-trigger' ).length ) {
			$( '.gc-qe-row' ).remove();
		}
	} );

	function buildQERow( entry ) {
		var isEvent = 'gc_event' === entry.type;
		var dateFields;

		if ( isEvent ) {
			dateFields =
				'<div class="gc-qe-field">' +
					'<label>Start</label>' +
					'<div class="gc-qe-date-row">' +
						'<input type="date" name="qe-start-date" value="' + esc( entry.startDate || '' ) + '" />' +
						'<input type="time" name="qe-start-time" value="' + esc( entry.startTime || '' ) + '" />' +
					'</div>' +
				'</div>' +
				'<div class="gc-qe-field">' +
					'<label>End</label>' +
					'<div class="gc-qe-date-row">' +
						'<input type="date" name="qe-end-date" value="' + esc( entry.endDate || '' ) + '" />' +
						'<input type="time" name="qe-end-time" value="' + esc( entry.endTime || '' ) + '" />' +
					'</div>' +
				'</div>';
		} else {
			dateFields =
				'<div class="gc-qe-field">' +
					'<label>Release Date</label>' +
					'<input type="date" name="qe-date" value="' + esc( entry.date || '' ) + '" />' +
				'</div>';
		}

		var statusOpts =
			'<option value="publish"' + ( 'publish' === entry.status ? ' selected' : '' ) + '>Published</option>' +
			'<option value="draft"'   + ( 'draft'   === entry.status ? ' selected' : '' ) + '>Draft</option>';

		return $(
			'<tr class="gc-qe-row">' +
				'<td colspan="4">' +
					'<div class="gc-qe-form">' +
						'<div class="gc-qe-top">' +
							'<div class="gc-qe-field gc-qe-field--title">' +
								'<label>Title</label>' +
								'<input type="text" name="qe-title" value="' + esc( entry.title || '' ) + '" />' +
							'</div>' +
						'</div>' +
						'<div class="gc-qe-mid">' +
							dateFields +
							'<div class="gc-qe-field">' +
								'<label>Status</label>' +
								'<select name="qe-status">' + statusOpts + '</select>' +
							'</div>' +
						'</div>' +
						'<div class="gc-qe-footer">' +
							'<p class="gc-qe-error" style="display:none;color:#d63638;margin:0 0 8px;"></p>' +
							'<button class="button button-primary gc-qe-save">Save</button>' +
							'&nbsp;' +
							'<button class="button gc-qe-cancel">Cancel</button>' +
						'</div>' +
					'</div>' +
				'</td>' +
			'</tr>'
		);
	}

	function esc( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

} )( jQuery );
