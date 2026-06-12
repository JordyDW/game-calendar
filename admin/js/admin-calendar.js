( function ( $ ) {
	'use strict';

	var calendar;
	var activeType    = 'gc_release';
	var editingPostId = null;
	var igdbTimer;

	document.addEventListener( 'DOMContentLoaded', function () {
		initCalendar();
		initModal();
		initIGDB();
		initCoverPickers();
	} );

	// ── Calendar ────────────────────────────────────────────────────────────

	function initCalendar() {
		var el = document.getElementById( 'gc-admin-calendar' );
		if ( ! el ) return;

		calendar = new FullCalendar.Calendar( el, {
			initialView:    'dayGridMonth',
			height:         'auto',
			nowIndicator:   true,
			firstDay:       1,
			locale:         'en-GB',
			headerToolbar: {
				left:   'prev,next today',
				center: 'title',
				right:  'dayGridMonth,timeGridWeek,timeGridDay,listMonth',
			},
			buttonText: {
				today:  'Today',
				month:  'Month',
				week:   'Week',
				day:    'Day',
				list:   'List',
			},
			eventSources: [ {
				url:     gcAdminCal.restUrl,
				failure: function () { console.warn( 'Game Calendar: failed to load events.' ); },
			} ],
			dateClick: function ( info ) {
				hidePopover();
				openModal( info.dateStr, info.allDay );
			},
			eventClick: function ( info ) {
				info.jsEvent.preventDefault();
				info.jsEvent.stopPropagation();
				showPopover( info.event, info.el );
			},
		} );

		calendar.render();
	}

	// ── Quick-add modal ──────────────────────────────────────────────────────

	function initModal() {
		var modal = document.getElementById( 'gc-modal' );
		if ( ! modal ) return;

		var addBtn = document.getElementById( 'gc-add-entry-btn' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				hidePopover();
				openModal( null, true );
			} );
		}

		modal.querySelectorAll( '.gc-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				if ( editingPostId ) return;
				modal.querySelectorAll( '.gc-tab' ).forEach( function ( t ) { t.classList.remove( 'gc-tab--active' ); } );
				tab.classList.add( 'gc-tab--active' );
				activeType = tab.dataset.type;
				updateTypeRows();
			} );
		} );

		modal.querySelectorAll( '.gc-modal-close' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', closeModal );
		} );

		modal.querySelector( '.gc-modal-backdrop' ).addEventListener( 'click', closeModal );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! modal.hidden ) closeModal();
		} );

		document.getElementById( 'gc-modal-save' ).addEventListener( 'click', submitQuickAdd );
	}

	function openModal( dateStr, allDay ) {
		var modal = document.getElementById( 'gc-modal' );
		resetModal();

		if ( dateStr ) {
			var d = dateStr.split( 'T' )[ 0 ];
			document.getElementById( 'gc-modal-date' ).value           = d;
			document.getElementById( 'gc-modal-start-date' ).value     = d;
			if ( ! allDay && dateStr.indexOf( 'T' ) !== -1 ) {
				document.getElementById( 'gc-modal-start-time' ).value = dateStr.substring( 11, 16 );
			}
		}

		modal.hidden = false;
		document.body.classList.add( 'gc-modal-open' );
		updateTypeRows();

		setTimeout( function () {
			var focus = activeType === 'gc_release'
				? document.getElementById( 'gc-modal-igdb' )
				: document.getElementById( 'gc-modal-title' );
			if ( focus ) focus.focus();
		}, 60 );
	}

	function closeModal() {
		document.getElementById( 'gc-modal' ).hidden = true;
		document.body.classList.remove( 'gc-modal-open' );
	}

	function resetModal() {
		activeType = 'gc_release';
		var modal  = document.getElementById( 'gc-modal' );

		modal.querySelectorAll( '.gc-tab' ).forEach( function ( t ) { t.classList.remove( 'gc-tab--active' ); } );
		modal.querySelector( '[data-type="gc_release"]' ).classList.add( 'gc-tab--active' );

		[ 'gc-modal-igdb', 'gc-modal-title', 'gc-modal-date',
		  'gc-modal-start-date', 'gc-modal-start-time', 'gc-modal-end-date', 'gc-modal-end-time',
		  'gc-modal-event-url', 'gc-modal-event-address', 'gc-modal-developer', 'gc-modal-publisher', 'gc-modal-genre',
		  'gc-modal-platforms', 'gc-modal-url', 'gc-modal-igdb-id',
		  'gc-modal-cover-url', 'gc-modal-event-cover-url' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) el.value = '';
		} );

		[ 'gc-modal-cover-img', 'gc-modal-event-cover-img' ].forEach( function ( id ) {
			var img = document.getElementById( id );
			if ( img ) { img.src = ''; img.style.display = 'none'; }
		} );

		var err = document.getElementById( 'gc-modal-error' );
		err.hidden      = true;
		err.textContent = '';

		var igdbResults = document.getElementById( 'gc-modal-igdb-results' );
		if ( igdbResults ) { igdbResults.hidden = true; igdbResults.innerHTML = ''; }

		var saveBtn = document.getElementById( 'gc-modal-save' );
		saveBtn.textContent = 'Save';
		saveBtn.disabled    = false;

		editingPostId = null;
		var tabsEl = modal.querySelector( '.gc-type-tabs' );
		if ( tabsEl ) tabsEl.classList.remove( 'gc-tabs--locked' );
		document.getElementById( 'gc-modal-heading' ).textContent = 'Add Entry';
	}

	function updateTypeRows() {
		var modal   = document.getElementById( 'gc-modal' );
		var typeKey = activeType.replace( 'gc_', '' ); // 'release', 'event', or 'dlc'
		modal.querySelectorAll( '.gc-field' ).forEach( function ( row ) {
			if ( row.classList.contains( 'gc-field--all' ) ) {
				return; // always visible
			}
			row.style.display = row.classList.contains( 'gc-field--' + typeKey ) ? '' : 'none';
		} );
	}

	function submitQuickAdd() {
		var titleInput = document.getElementById( 'gc-modal-title' );
		var title      = titleInput.value.trim();
		if ( ! title ) {
			titleInput.focus();
			showModalError( 'Title is required.' );
			return;
		}

		var saveBtn         = document.getElementById( 'gc-modal-save' );
		saveBtn.textContent = 'Saving…';
		saveBtn.disabled    = true;

		var data = {
			action:    'gc_quick_add',
			nonce:     gcAdminCal.nonce,
			post_type: activeType,
			title:     title,
		};

		if ( editingPostId ) {
			data.post_id = editingPostId;
		}

		if ( 'gc_release' === activeType ) {
			data.gc_release_date = document.getElementById( 'gc-modal-date' ).value;
			data.gc_developer    = document.getElementById( 'gc-modal-developer' ).value;
			data.gc_publisher    = document.getElementById( 'gc-modal-publisher' ).value;
			data.gc_genre        = document.getElementById( 'gc-modal-genre' ).value;
			data.gc_cover_url    = document.getElementById( 'gc-modal-cover-url' ).value;
			data.gc_igdb_id      = document.getElementById( 'gc-modal-igdb-id' ).value;
			data.gc_platforms    = document.getElementById( 'gc-modal-platforms' ).value;
			data.gc_url          = document.getElementById( 'gc-modal-url' ).value;
		} else if ( 'gc_event' === activeType ) {
			var startDateInput = document.getElementById( 'gc-modal-start-date' );
			if ( ! startDateInput.value ) {
				startDateInput.focus();
				showModalError( 'Start date is required.' );
				saveBtn.textContent = 'Save';
				saveBtn.disabled    = false;
				return;
			}
			var startTime = document.getElementById( 'gc-modal-start-time' ).value;
			data.gc_event_start = startTime ? startDateInput.value + 'T' + startTime : startDateInput.value;

			var endDateVal = document.getElementById( 'gc-modal-end-date' ).value;
			var endTimeVal = document.getElementById( 'gc-modal-end-time' ).value;
			if ( endDateVal ) {
				data.gc_event_end = endTimeVal ? endDateVal + 'T' + endTimeVal : endDateVal;
			}
			data.gc_event_url       = document.getElementById( 'gc-modal-event-url' ).value;
			data.gc_event_address   = document.getElementById( 'gc-modal-event-address' ).value;
			data.gc_event_cover_url = document.getElementById( 'gc-modal-event-cover-url' ).value;
		}

		$.post( gcAdminCal.ajaxUrl, data )
			.done( function ( res ) {
				if ( ! res.success ) {
					showModalError( ( res.data && res.data.message ) || 'Failed to save.' );
					saveBtn.textContent = 'Save';
					saveBtn.disabled    = false;
					return;
				}
				closeModal();
				calendar.refetchEvents();
			} )
			.fail( function () {
				showModalError( 'Request failed.' );
				saveBtn.textContent = 'Save';
				saveBtn.disabled    = false;
			} );
	}

	function showModalError( msg ) {
		var err         = document.getElementById( 'gc-modal-error' );
		err.textContent = msg;
		err.hidden      = false;
	}

	// ── IGDB search inside modal ─────────────────────────────────────────────

	function initIGDB() {
		var input = document.getElementById( 'gc-modal-igdb' );
		if ( ! input ) return;

		input.addEventListener( 'input', function () {
			clearTimeout( igdbTimer );
			var q           = this.value.trim();
			var resultsEl   = document.getElementById( 'gc-modal-igdb-results' );

			if ( q.length < 2 ) {
				resultsEl.hidden    = true;
				resultsEl.innerHTML = '';
				return;
			}

			igdbTimer = setTimeout( function () {
				resultsEl.innerHTML = '<div class="gc-igdb-status">Searching IGDB…</div>';
				resultsEl.hidden    = false;

				$.post( gcAdminCal.ajaxUrl, {
					action: 'gc_igdb_search',
					nonce:  gcAdminCal.igdbNonce,
					query:  q,
				} )
				.done( function ( res ) {
					resultsEl.innerHTML = '';
					if ( ! res.success || ! res.data.length ) {
						resultsEl.innerHTML = '<div class="gc-igdb-status">No results found.</div>';
						return;
					}
					var ul = document.createElement( 'ul' );
					ul.className = 'gc-igdb-list';
					res.data.forEach( function ( game ) {
						var li        = document.createElement( 'li' );
						li.className  = 'gc-igdb-item';
						li.innerHTML  =
							( game.cover
								? '<img src="' + esc( game.cover ) + '" class="gc-igdb-thumb" alt="" />'
								: '<span class="gc-igdb-no-thumb"></span>' ) +
							'<span class="gc-igdb-info">' +
								'<strong>' + esc( game.name ) + '</strong>' +
								( game.release_date ? '<br><small>' + esc( game.release_date ) + '</small>' : '' ) +
								( game.developer    ? '<br><small>' + esc( game.developer )    + '</small>' : '' ) +
							'</span>';
						li.addEventListener( 'click', function () {
							fillFromIGDB( game );
							resultsEl.hidden    = true;
							resultsEl.innerHTML = '';
							input.value         = '';
						} );
						ul.appendChild( li );
					} );
					resultsEl.appendChild( ul );
				} )
				.fail( function () {
					resultsEl.innerHTML = '<div class="gc-igdb-status">IGDB request failed.</div>';
				} );
			}, 350 );
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '#gc-modal-igdb, #gc-modal-igdb-results' ) ) {
				var r = document.getElementById( 'gc-modal-igdb-results' );
				if ( r ) r.hidden = true;
			}
		} );
	}

	function fillFromIGDB( game ) {
		setVal( 'gc-modal-title',     game.name );
		setVal( 'gc-modal-date',      game.release_date );
		setVal( 'gc-modal-developer', game.developer );
		setVal( 'gc-modal-publisher', game.publisher );
		setVal( 'gc-modal-genre',     game.genre );
		setVal( 'gc-modal-cover-url', game.cover );
		setVal( 'gc-modal-igdb-id',   game.id );
		setVal( 'gc-modal-platforms', game.platforms ? game.platforms.join( ', ' ) : '' );
		setVal( 'gc-modal-url',       game.url );

		if ( game.cover ) {
			var img = document.getElementById( 'gc-modal-cover-img' );
			if ( img ) { img.src = game.cover; img.style.display = ''; }
		}

		// Flash every visible text input that received a value.
		[ 'gc-modal-title', 'gc-modal-date', 'gc-modal-developer',
		  'gc-modal-publisher', 'gc-modal-genre', 'gc-modal-platforms' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el && el.value ) {
				el.classList.remove( 'gc-input--filled' );
				void el.offsetWidth; // restart animation if already running
				el.classList.add( 'gc-input--filled' );
				el.addEventListener( 'animationend', function () {
					el.classList.remove( 'gc-input--filled' );
				}, { once: true } );
			}
		} );
	}

	function setVal( id, val ) {
		var el = document.getElementById( id );
		if ( el && val ) el.value = val;
	}

	// ── Cover image pickers ──────────────────────────────────────────────────

	var coverPickerMap = {
		'gc-modal-cover-pick':       { input: 'gc-modal-cover-url',       preview: 'gc-modal-cover-img' },
		'gc-modal-event-cover-pick': { input: 'gc-modal-event-cover-url', preview: 'gc-modal-event-cover-img' },
	};

	function initCoverPickers() {
		// Event delegation so it works regardless of when the modal renders.
		$( document ).on( 'click', '#gc-modal-cover-pick, #gc-modal-event-cover-pick', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var cfg = coverPickerMap[ this.id ];
			if ( ! cfg ) return;

			if ( typeof wp === 'undefined' || typeof wp.media !== 'function' ) {
				console.warn( 'Game Calendar: wp.media is not available.' );
				return;
			}

			var frame = wp.media( {
				title:    'Choose Cover Image',
				button:   { text: 'Use this image' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var url = ( att.sizes && att.sizes.large ) ? att.sizes.large.url : att.url;
				$( '#' + cfg.input ).val( url ).trigger( 'input' );
			} );

			frame.open();
		} );

		// Live preview when a URL is typed manually.
		$( document ).on( 'input', '#gc-modal-cover-url, #gc-modal-event-cover-url', function () {
			var url     = $( this ).val().trim();
			var prevId  = this.id === 'gc-modal-cover-url' ? 'gc-modal-cover-img' : 'gc-modal-event-cover-img';
			var preview = $( '#' + prevId );
			if ( url ) {
				preview.attr( 'src', url ).show();
			} else {
				preview.attr( 'src', '' ).hide();
			}
		} );
	}

	// ── Edit existing entry in modal ─────────────────────────────────────────

	function openEditModal( event ) {
		hidePopover();
		$.post( gcAdminCal.ajaxUrl, {
			action:  'gc_get_entry',
			nonce:   gcAdminCal.nonce,
			post_id: event.id,
		} ).done( function ( res ) {
			if ( ! res.success ) return;
			var d = res.data;

			resetModal();

			// Switch to the correct tab.
			var modal = document.getElementById( 'gc-modal' );
			activeType = d.post_type;
			modal.querySelectorAll( '.gc-tab' ).forEach( function ( t ) { t.classList.remove( 'gc-tab--active' ); } );
			var activeTab = modal.querySelector( '[data-type="' + d.post_type + '"]' );
			if ( activeTab ) activeTab.classList.add( 'gc-tab--active' );

			// Fill fields.
			setVal( 'gc-modal-title', d.title );

			if ( 'gc_release' === d.post_type ) {
				setVal( 'gc-modal-date',      d.gc_release_date );
				setVal( 'gc-modal-developer', d.gc_developer );
				setVal( 'gc-modal-publisher', d.gc_publisher );
				setVal( 'gc-modal-genre',     d.gc_genre );
				setVal( 'gc-modal-platforms', d.gc_platforms );
				setVal( 'gc-modal-url',       d.gc_url );
				setVal( 'gc-modal-igdb-id',   d.gc_igdb_id );
				setVal( 'gc-modal-cover-url', d.gc_cover_url );
				if ( d.gc_cover_url ) {
					var img = document.getElementById( 'gc-modal-cover-img' );
					if ( img ) { img.src = d.gc_cover_url; img.style.display = ''; }
				}
			} else {
				var startRaw = d.gc_event_start || '';
				if ( startRaw.indexOf( 'T' ) !== -1 ) {
					var sp = startRaw.split( 'T' );
					setVal( 'gc-modal-start-date', sp[ 0 ] );
					setVal( 'gc-modal-start-time', sp[ 1 ] );
				} else {
					setVal( 'gc-modal-start-date', startRaw );
				}
				var endRaw = d.gc_event_end || '';
				if ( endRaw.indexOf( 'T' ) !== -1 ) {
					var ep = endRaw.split( 'T' );
					setVal( 'gc-modal-end-date', ep[ 0 ] );
					setVal( 'gc-modal-end-time', ep[ 1 ] );
				} else {
					setVal( 'gc-modal-end-date', endRaw );
				}
				setVal( 'gc-modal-event-url',       d.gc_event_url );
			setVal( 'gc-modal-event-address',   d.gc_event_address );
				setVal( 'gc-modal-event-cover-url', d.gc_event_cover_url );
				if ( d.gc_event_cover_url ) {
					var evtImg = document.getElementById( 'gc-modal-event-cover-img' );
					if ( evtImg ) { evtImg.src = d.gc_event_cover_url; evtImg.style.display = ''; }
				}
			}

			editingPostId = parseInt( d.post_id, 10 );
			document.getElementById( 'gc-modal-heading' ).textContent = 'Edit Entry';
			var tabsEl = modal.querySelector( '.gc-type-tabs' );
			if ( tabsEl ) tabsEl.classList.add( 'gc-tabs--locked' );
			document.getElementById( 'gc-modal-save' ).textContent    = 'Save Changes';

			modal.hidden = false;
			document.body.classList.add( 'gc-modal-open' );
			updateTypeRows();

			setTimeout( function () {
				var focus = document.getElementById( 'gc-modal-title' );
				if ( focus ) focus.focus();
			}, 60 );
		} );
	}

	// ── Event click popover ──────────────────────────────────────────────────

	function showPopover( event, triggerEl ) {
		var popover = document.getElementById( 'gc-popover' );
		var props   = event.extendedProps || {};

		var typeLabels = { gc_release: 'Game Release', gc_event: 'Gaming Event', gc_dlc: 'DLC / Update' };

		var html = '<button class="gc-pop-close" aria-label="Close">✕</button>';

		if ( props.cover ) {
			html += '<img src="' + esc( props.cover ) + '" class="gc-pop-cover" alt="" />';
		}

		html += '<div class="gc-pop-body">';
		html += '<span class="gc-pop-type">' + esc( typeLabels[ props.type ] || '' ) + '</span>';
		html += '<strong class="gc-pop-title">' + esc( event.title ) + '</strong>';

		if ( event.startStr ) {
			html += '<span class="gc-pop-date">' + formatDate( event.startStr ) + '</span>';
		}
		if ( props.address ) {
			html += '<span class="gc-pop-meta">📍 ' + esc( props.address ) + '</span>';
		}
		if ( props.developer ) {
			html += '<span class="gc-pop-meta">' + esc( props.developer ) + '</span>';
		}
		html += '</div>';

		html += '<div class="gc-pop-actions">';
		html += '<button class="button button-small gc-pop-edit" data-id="' + event.id + '">Edit</button> ';
		html += '<button class="button button-small gc-pop-delete" data-id="' + event.id + '">Delete</button>';
		html += '</div>';

		popover.innerHTML = html;
		popover.hidden    = false;

		var rect  = triggerEl.getBoundingClientRect();
		var sY    = window.scrollY;
		var sX    = window.scrollX;
		popover.style.top  = ( sY + rect.bottom + 8 ) + 'px';
		popover.style.left = ( sX + rect.left )       + 'px';

		requestAnimationFrame( function () {
			var pr = popover.getBoundingClientRect();
			if ( pr.right  > window.innerWidth  - 16 ) popover.style.left = ( sX + rect.right - pr.width ) + 'px';
			if ( pr.bottom > window.innerHeight - 16 ) popover.style.top  = ( sY + rect.top - pr.height - 8 ) + 'px';
		} );

		popover.querySelector( '.gc-pop-close' ).addEventListener( 'click', hidePopover );

		var editBtn = popover.querySelector( '.gc-pop-edit' );
		if ( editBtn ) {
			editBtn.addEventListener( 'click', function () {
				openEditModal( event );
			} );
		}

		var delBtn = popover.querySelector( '.gc-pop-delete' );
		if ( delBtn ) {
			delBtn.addEventListener( 'click', function () {
				if ( ! confirm( 'Delete this entry?' ) ) return;
				$.post( gcAdminCal.ajaxUrl, {
					action:  'gc_delete_entry',
					nonce:   gcAdminCal.nonce,
					post_id: parseInt( this.dataset.id, 10 ),
				} ).done( function ( res ) {
					if ( res.success ) {
						hidePopover();
						calendar.refetchEvents();
					}
				} );
			} );
		}
	}

	function hidePopover() {
		var p = document.getElementById( 'gc-popover' );
		if ( p ) p.hidden = true;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '#gc-popover' ) && ! e.target.closest( '.fc-event' ) ) {
			hidePopover();
		}
	} );

	// ── Utilities ────────────────────────────────────────────────────────────

	function esc( str ) {
		if ( str === null || str === undefined ) return '';
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function formatDate( dateStr ) {
		if ( ! dateStr ) return '';
		try {
			return new Date( dateStr ).toLocaleDateString( 'nl-BE', { year: 'numeric', month: 'long', day: 'numeric' } );
		} catch ( e ) {
			return dateStr;
		}
	}

} )( jQuery );
