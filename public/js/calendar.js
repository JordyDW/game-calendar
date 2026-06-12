( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var el = document.getElementById( 'gc-calendar' );
		if ( ! el ) return;

		var activeTypes = { gc_release: true, gc_event: true };
		var tooltip     = document.getElementById( 'gc-event-tooltip' );
		var modal       = document.getElementById( 'gc-detail-modal' );

		// ── Detail modal helpers ─────────────────────────────────────────────
		function openDetailModal( event ) {
			if ( ! modal ) return;
			var props = event.extendedProps || {};

			// Cover.
			var coverWrap = document.getElementById( 'gc-detail-cover-wrap' );
			var coverImg  = document.getElementById( 'gc-detail-cover' );
			if ( props.cover ) {
				coverImg.src        = props.cover;
				coverImg.alt        = event.title;
				coverWrap.hidden    = false;
			} else {
				coverWrap.hidden    = true;
				coverImg.src        = '';
			}

			// Type + title.
			document.getElementById( 'gc-detail-type' ).textContent  = typeLabel( props.type );
			document.getElementById( 'gc-detail-title' ).textContent = event.title;

			// Meta rows.
			var metaEl = document.getElementById( 'gc-detail-meta' );
			var metaHtml = '';
			if ( event.startStr ) {
				metaHtml += '<div class="gc-detail-row"><span class="gc-detail-label">Date</span><span class="gc-detail-value">' + escHtml( formatDate( event.startStr ) ) + '</span></div>';
			}
			if ( props.developer ) {
				metaHtml += '<div class="gc-detail-row"><span class="gc-detail-label">Developer</span><span class="gc-detail-value">' + escHtml( props.developer ) + '</span></div>';
			}
			if ( props.publisher ) {
				metaHtml += '<div class="gc-detail-row"><span class="gc-detail-label">Publisher</span><span class="gc-detail-value">' + escHtml( props.publisher ) + '</span></div>';
			}
			if ( props.genre ) {
				metaHtml += '<div class="gc-detail-row"><span class="gc-detail-label">Genre</span><span class="gc-detail-value">' + escHtml( props.genre ) + '</span></div>';
			}
			if ( props.platforms && props.platforms.length ) {
				metaHtml += '<div class="gc-detail-row"><span class="gc-detail-label">Platforms</span><span class="gc-detail-value">' + escHtml( props.platforms.join( ', ' ) ) + '</span></div>';
			}
			metaEl.innerHTML = metaHtml;

			// Link.
			var linkEl = document.getElementById( 'gc-detail-link' );
			if ( event.url ) {
				linkEl.href   = event.url;
				linkEl.hidden = false;
			} else {
				linkEl.href   = '#';
				linkEl.hidden = true;
			}

			modal.hidden = false;
			document.body.classList.add( 'gc-modal-open' );
		}

		function closeDetailModal() {
			if ( ! modal ) return;
			modal.hidden = true;
			document.body.classList.remove( 'gc-modal-open' );
		}

		if ( modal ) {
			modal.querySelector( '.gc-detail-backdrop' ).addEventListener( 'click', closeDetailModal );
			modal.querySelector( '.gc-detail-close' ).addEventListener( 'click', closeDetailModal );
		}

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) closeDetailModal();
		} );

		// Keep tooltip pinned near the cursor wherever the mouse moves.
		document.addEventListener( 'mousemove', function ( e ) {
			if ( ! tooltip || tooltip.hidden ) return;
			positionTooltip( e.clientX, e.clientY );
		} );

		var calendar = new FullCalendar.Calendar( el, {
			initialView:         'dayGridMonth',
			height:              'auto',
			showNonCurrentDates: false,
			nowIndicator:        true,
			firstDay:            1,
			locale:              'en-GB',
			headerToolbar: {
				left:   'prev,next today',
				center: 'title',
				right:  'dayGridMonth,timeGridWeek,timeGridDay,listMonth',
			},
			buttonText: {
				today: 'Today',
				month: 'Month',
				week:  'Week',
				day:   'Day',
				list:  'List',
			},
			views: {
				listMonth: { buttonText: 'Agenda' },
			},
			eventSources: [ {
				url: gcCalendar.restUrl,
				extraParams: function () {
					var types = Object.keys( activeTypes ).filter( function ( t ) {
						return activeTypes[ t ];
					} );
					return { type: types.join( ',' ) };
				},
				failure: function () {
					console.warn( 'Game Calendar: failed to load events.' );
				},
			} ],
			// Render event as a cover-art card when an image is available.
			eventContent: function ( arg ) {
				var props    = arg.event.extendedProps || {};
				var hasCover = !! props.cover;

				var wrap = document.createElement( 'div' );
				wrap.className = hasCover ? 'gc-event-card gc-event-card--cover' : 'gc-event-card';

				if ( hasCover ) {
					var img = document.createElement( 'img' );
					img.src       = props.cover;
					img.alt       = '';
					img.className = 'gc-event-cover';
					wrap.appendChild( img );

					var overlay = document.createElement( 'div' );
					overlay.className = 'gc-event-overlay';
					wrap.appendChild( overlay );
				}

				var label = document.createElement( 'span' );
				label.className   = 'gc-event-label';
				label.textContent = arg.event.title;
				wrap.appendChild( label );

				return { domNodes: [ wrap ] };
			},
			eventClick: function ( info ) {
				info.jsEvent.preventDefault();
				openDetailModal( info.event );
			},
			eventMouseEnter: function ( info ) {
				if ( ! tooltip ) return;
				var props = info.event.extendedProps || {};
				var html  = '';

				if ( props.cover ) {
					html += '<img src="' + escHtml( props.cover ) + '" alt="" class="gc-tip-cover" />';
				}

				html += '<div class="gc-tip-body">';
				html += '<span class="gc-tip-type">' + escHtml( typeLabel( props.type ) ) + '</span>';
				html += '<strong class="gc-tip-title">' + escHtml( info.event.title ) + '</strong>';

				if ( info.event.startStr ) {
					html += '<span class="gc-tip-date">' + formatDate( info.event.startStr ) + '</span>';
				}
				if ( props.developer ) {
					html += '<span class="gc-tip-row">' + escHtml( props.developer ) + '</span>';
				}
				if ( props.platforms && props.platforms.length ) {
					html += '<span class="gc-tip-row gc-tip-platforms">' + escHtml( props.platforms.join( ' · ' ) ) + '</span>';
				}
				if ( info.event.url ) {
					html += '<span class="gc-tip-link">Click to open ↗</span>';
				}

				html += '</div>';

				tooltip.innerHTML = html;
				tooltip.hidden    = false;
				positionTooltip( info.jsEvent.clientX, info.jsEvent.clientY );
			},
			eventMouseLeave: function () {
				if ( tooltip ) tooltip.hidden = true;
			},
		} );

		calendar.render();

		// Type filter checkboxes.
		document.querySelectorAll( '.gc-filter-type' ).forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				activeTypes[ this.value ] = this.checked;
				calendar.refetchEvents();
			} );
		} );

		function positionTooltip( x, y ) {
			var offset = 16;
			var tw = tooltip.offsetWidth;
			var th = tooltip.offsetHeight;
			var tx = ( x + offset + tw < window.innerWidth  - 8 ) ? x + offset : x - tw - offset;
			var ty = ( y + offset + th < window.innerHeight - 8 ) ? y + offset : y - th - offset;
			tooltip.style.left = tx + 'px';
			tooltip.style.top  = ty + 'px';
		}
	} );

	function typeLabel( type ) {
		var map = { gc_release: 'Game Release', gc_event: 'Gaming Event', gc_dlc: 'Game Release' };
		return map[ type ] || '';
	}

	function formatDate( dateStr ) {
		if ( ! dateStr ) return '';
		var d = new Date( dateStr );
		if ( isNaN( d.getTime() ) ) return dateStr;
		return d.toLocaleDateString( 'nl-BE', { day: '2-digit', month: '2-digit', year: 'numeric' } );
	}

	function escHtml( str ) {
		if ( str === null || str === undefined ) return '';
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} )();
