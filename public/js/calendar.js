( function () {
	'use strict';

	var cfg  = window.gcCalendar || {};
	var i18n = cfg.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var el = document.getElementById( 'gc-calendar' );
		if ( ! el ) return;

		var activeTypes     = { gc_release: true, gc_event: true };
		var activePlatforms = {}; // family slug → true; empty = all platforms
		var tooltip         = document.getElementById( 'gc-event-tooltip' );
		var modal           = document.getElementById( 'gc-detail-modal' );
		var unscheduled     = document.getElementById( 'gc-unscheduled' );
		var platformBoxes   = document.querySelectorAll( '.gc-filter-platform' );
		var validPlatforms  = Array.prototype.map.call( platformBoxes, function ( box ) { return box.value; } );

		// ── URL state ───────────────────────────────────────────────────────────
		var validViews = [ 'dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listMonth' ];

		function readParams() {
			var params = new URLSearchParams( window.location.search );
			var out = {};

			// Phones get the agenda list by default: a 7-column month grid is
			// unreadable at that width.
			var v = params.get( 'gc_view' );
			var narrow = window.matchMedia && window.matchMedia( '(max-width: 767px)' ).matches;
			out.view = ( v && validViews.indexOf( v ) !== -1 ) ? v : ( narrow ? 'listMonth' : 'dayGridMonth' );

			var d = params.get( 'gc_date' );
			out.date = ( d && /^\d{4}-\d{2}-\d{2}$/.test( d ) ) ? d : null;

			var types = params.get( 'gc_types' );
			if ( types ) {
				var allowed = [ 'gc_release', 'gc_event' ];
				Object.keys( activeTypes ).forEach( function ( k ) { activeTypes[ k ] = false; } );
				types.split( ',' ).forEach( function ( type ) {
					if ( allowed.indexOf( type ) !== -1 ) activeTypes[ type ] = true;
				} );
			}

			var platforms = params.get( 'gc_platforms' );
			if ( platforms ) {
				platforms.split( ',' ).forEach( function ( slug ) {
					if ( validPlatforms.indexOf( slug ) !== -1 ) activePlatforms[ slug ] = true;
				} );
			}

			return out;
		}

		function activeTypeList() {
			return Object.keys( activeTypes ).filter( function ( k ) { return activeTypes[ k ]; } );
		}

		function activePlatformList() {
			return Object.keys( activePlatforms ).filter( function ( k ) { return activePlatforms[ k ]; } );
		}

		function filterParams() {
			var out = { type: activeTypeList().join( ',' ) };
			var platforms = activePlatformList();
			if ( platforms.length ) out.platform = platforms.join( ',' );
			return out;
		}

		function syncUrl( calendar ) {
			var params   = new URLSearchParams( window.location.search );
			var d        = calendar.view.currentStart;
			var dateStr  = d.getFullYear() + '-' +
				String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' +
				String( d.getDate() ).padStart( 2, '0' );

			params.set( 'gc_view', calendar.view.type );
			params.set( 'gc_date', dateStr );

			var activeList = activeTypeList();
			if ( activeList.length === Object.keys( activeTypes ).length ) {
				params.delete( 'gc_types' );
			} else {
				params.set( 'gc_types', activeList.join( ',' ) );
			}

			var platforms = activePlatformList();
			if ( platforms.length ) {
				params.set( 'gc_platforms', platforms.join( ',' ) );
			} else {
				params.delete( 'gc_platforms' );
			}

			var search = params.toString();
			history.replaceState(
				null,
				'',
				window.location.pathname + ( search ? '?' + search : '' ) + window.location.hash
			);
		}

		// ── Read initial state from URL ─────────────────────────────────────────
		var urlState = readParams();

		document.querySelectorAll( '.gc-filter-type' ).forEach( function ( checkbox ) {
			checkbox.checked = !! activeTypes[ checkbox.value ];
		} );
		platformBoxes.forEach( function ( checkbox ) {
			checkbox.checked = !! activePlatforms[ checkbox.value ];
		} );

		// ── Detail modal helpers ─────────────────────────────────────────────
		// Accepts a FullCalendar event or a plain object with the same shape
		// (title, url, startStr, extendedProps) from the "no exact date" list.
		function openDetailModal( event ) {
			if ( ! modal ) return;
			var props = event.extendedProps || {};

			var coverWrap = document.getElementById( 'gc-detail-cover-wrap' );
			var coverImg  = document.getElementById( 'gc-detail-cover' );
			if ( props.cover ) {
				coverImg.src     = props.cover;
				coverImg.alt     = event.title;
				coverWrap.hidden = false;
			} else {
				coverWrap.hidden = true;
				coverImg.src     = '';
			}

			document.getElementById( 'gc-detail-type' ).textContent  = typeLabel( props.type );
			document.getElementById( 'gc-detail-title' ).textContent = event.title;

			var rows = [];
			if ( props.dateLabel ) {
				rows.push( [ t( 'expected', 'Expected' ), props.dateLabel ] );
			} else if ( event.startStr ) {
				rows.push( [ t( 'date', 'Date' ), formatDate( event.startStr ) ] );
			}
			if ( props.developer ) rows.push( [ t( 'developer', 'Developer' ), props.developer ] );
			if ( props.publisher ) rows.push( [ t( 'publisher', 'Publisher' ), props.publisher ] );
			if ( props.genre )     rows.push( [ t( 'genre', 'Genre' ), props.genre ] );
			if ( props.address )   rows.push( [ t( 'address', 'Address' ), props.address ] );
			if ( props.platforms && props.platforms.length ) {
				rows.push( [ t( 'platforms', 'Platforms' ), props.platforms.join( ', ' ) ] );
			}
			document.getElementById( 'gc-detail-meta' ).innerHTML = rows.map( function ( row ) {
				return '<div class="gc-detail-row"><span class="gc-detail-label">' + escHtml( row[ 0 ] ) +
					'</span><span class="gc-detail-value">' + escHtml( row[ 1 ] ) + '</span></div>';
			} ).join( '' );

			var linkEl = document.getElementById( 'gc-detail-link' );
			if ( event.url ) {
				linkEl.href   = event.url;
				linkEl.hidden = false;
			} else {
				linkEl.href   = '#';
				linkEl.hidden = true;
			}

			// Review of this game (set through the gc_format_event filter).
			var reviewEl = document.getElementById( 'gc-detail-review' );
			if ( reviewEl ) {
				if ( props.review && props.review.url ) {
					reviewEl.href        = props.review.url;
					reviewEl.textContent = reviewLabel( props.review );
					reviewEl.hidden      = false;
				} else {
					reviewEl.href   = '#';
					reviewEl.hidden = true;
				}
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
			if ( e.key === 'Escape' ) {
				closeDetailModal();
				closeSubscribeMenu();
			}
		} );

		// Keep tooltip pinned near the cursor wherever the mouse moves.
		document.addEventListener( 'mousemove', function ( e ) {
			if ( ! tooltip || tooltip.hidden ) return;
			positionTooltip( e.clientX, e.clientY );
		} );

		// ── Event rendering ──────────────────────────────────────────────────

		// "Read our review · 8,5/10"
		function reviewLabel( review ) {
			return t( 'readReview', 'Read our review' ) + ( review.score ? ' · ' + review.score + '/' + ( review.max || 10 ) : '' );
		}

		// Score badge for entries with a review, or null.
		function reviewBadge( props ) {
			if ( ! props.review || ! props.review.url ) return null;
			var badge = document.createElement( 'span' );
			badge.className   = 'gc-review-badge';
			badge.textContent = props.review.score || t( 'review', 'Review' );
			badge.title       = reviewLabel( props.review );
			return badge;
		}

		function coverCard( arg ) {
			var props    = arg.event.extendedProps || {};
			var hasCover = !! props.cover;
			var color    = arg.event.backgroundColor;

			var wrap = document.createElement( 'div' );
			wrap.className = hasCover ? 'gc-event-card gc-event-card--cover' : 'gc-event-card';

			if ( hasCover ) {
				wrap.style.borderBottomColor = color;

				var img = document.createElement( 'img' );
				img.src       = props.cover;
				img.alt       = '';
				img.loading   = 'lazy';
				img.className = 'gc-event-cover';
				wrap.appendChild( img );

				var overlay = document.createElement( 'div' );
				overlay.className = 'gc-event-overlay';
				wrap.appendChild( overlay );
			} else {
				wrap.style.backgroundColor = color;
			}

			var label = document.createElement( 'span' );
			label.className   = 'gc-event-label';
			label.textContent = arg.event.title;
			wrap.appendChild( label );

			var badge = reviewBadge( props );
			if ( badge ) wrap.appendChild( badge );

			return wrap;
		}

		// Agenda (list) row: small cover, title and platforms / type.
		function listRow( arg ) {
			var props = arg.event.extendedProps || {};
			var row   = document.createElement( 'div' );
			row.className = 'gc-list-row';

			var thumb = document.createElement( 'span' );
			thumb.className = 'gc-list-thumb';
			thumb.style.borderColor = arg.event.backgroundColor;
			if ( props.cover ) {
				var img = document.createElement( 'img' );
				img.src     = props.cover;
				img.alt     = '';
				img.loading = 'lazy';
				thumb.appendChild( img );
			}
			row.appendChild( thumb );

			var text = document.createElement( 'span' );
			text.className = 'gc-list-text';
			var title = document.createElement( 'span' );
			title.className   = 'gc-list-title';
			title.textContent = arg.event.title;
			var listBadge = reviewBadge( props );
			if ( listBadge ) title.appendChild( listBadge );
			var meta = document.createElement( 'span' );
			meta.className   = 'gc-list-meta';
			var platforms = ( props.platformFamilies && props.platformFamilies.length ) ? props.platformFamilies : props.platforms;
			var metaText  = ( platforms && platforms.length )
				? platforms.join( ' · ' )
				: ( props.address || typeLabel( props.type ) );
			// The list's time column is hidden (releases are all-day), so timed
			// events show their start time here.
			if ( ! arg.event.allDay && arg.event.start ) {
				metaText = formatTime( arg.event.start ) + ' · ' + metaText;
			}
			meta.textContent = metaText;
			text.appendChild( title );
			text.appendChild( meta );
			row.appendChild( text );

			return row;
		}

		function showTooltip( event, x, y ) {
			if ( ! tooltip ) return;
			var props = event.extendedProps || {};
			var html  = '';

			if ( props.cover ) {
				html += '<img src="' + escHtml( props.cover ) + '" alt="" class="gc-tip-cover" />';
			}
			html += '<div class="gc-tip-body">';
			html += '<span class="gc-tip-type">' + escHtml( typeLabel( props.type ) ) + '</span>';
			html += '<strong class="gc-tip-title">' + escHtml( event.title ) + '</strong>';
			if ( props.dateLabel ) {
				html += '<span class="gc-tip-date">' + escHtml( props.dateLabel ) + '</span>';
			} else if ( event.startStr ) {
				html += '<span class="gc-tip-date">' + escHtml( formatDate( event.startStr ) ) + '</span>';
			}
			if ( props.developer ) {
				html += '<span class="gc-tip-row">' + escHtml( props.developer ) + '</span>';
			}
			if ( props.platforms && props.platforms.length ) {
				html += '<span class="gc-tip-row gc-tip-platforms">' + escHtml( props.platforms.join( ' · ' ) ) + '</span>';
			}
			if ( props.address ) {
				html += '<span class="gc-tip-row gc-tip-address">📍 ' + escHtml( props.address ) + '</span>';
			}
			if ( props.review && props.review.url ) {
				html += '<span class="gc-tip-row gc-tip-review">★ ' + escHtml( reviewLabel( props.review ) ) + '</span>';
			}
			if ( event.url ) {
				html += '<span class="gc-tip-link">' + escHtml( t( 'clickToOpen', 'Click to open ↗' ) ) + '</span>';
			}
			html += '</div>';

			tooltip.innerHTML = html;
			tooltip.hidden    = false;
			positionTooltip( x, y );
		}

		var calOptions = {
			initialView:         urlState.view,
			height:              'auto',
			showNonCurrentDates: false,
			nowIndicator:        true,
			firstDay:            1,
			locale:              cfg.locale || 'en-gb',
			headerToolbar: {
				left:   'prev,next today',
				center: 'title',
				right:  'dayGridMonth,timeGridWeek,timeGridDay,listMonth',
			},
			views: {
				// One busy day (e.g. a big release week) shouldn't stretch the
				// whole month: show 3 and a "+N more" popover.
				dayGridMonth: { dayMaxEvents: 3 },
				// Releases are all-day: no "all day" label eating the row; times of
				// timed events still show.
				listMonth:    { buttonText: t( 'agenda', 'Agenda' ), allDayText: '' },
			},
			moreLinkText: t( 'more', 'more' ),
			noEventsText: t( 'noEvents', 'No releases or events in this period.' ),
			eventSources: [ {
				url: cfg.restUrl,
				extraParams: filterParams,
				failure: function () {
					console.warn( 'Game Calendar: failed to load events.' );
				},
			} ],
			datesSet: function () {
				syncUrl( calendar );
			},
			eventContent: function ( arg ) {
				var node = ( arg.view.type.indexOf( 'list' ) === 0 ) ? listRow( arg ) : coverCard( arg );
				return { domNodes: [ node ] };
			},
			eventClick: function ( info ) {
				info.jsEvent.preventDefault();
				if ( tooltip ) tooltip.hidden = true;
				openDetailModal( info.event );
			},
			eventMouseEnter: function ( info ) {
				showTooltip( info.event, info.jsEvent.clientX, info.jsEvent.clientY );
			},
			eventMouseLeave: function () {
				if ( tooltip ) tooltip.hidden = true;
			},
		};

		if ( urlState.date ) {
			calOptions.initialDate = urlState.date;
		}

		var calendar = new FullCalendar.Calendar( el, calOptions );
		calendar.render();

		// ── Releases without an exact date ───────────────────────────────────
		var unscheduledRequest = 0;

		function loadUnscheduled() {
			if ( ! unscheduled || ! cfg.unscheduledUrl ) return;
			var request = ++unscheduledRequest;
			var url     = new URL( cfg.unscheduledUrl, window.location.href );
			var params  = filterParams();
			Object.keys( params ).forEach( function ( k ) { url.searchParams.set( k, params[ k ] ); } );

			fetch( url.toString(), { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
				.then( function ( r ) { return r.ok ? r.json() : []; } )
				.then( function ( items ) {
					if ( request !== unscheduledRequest ) return; // a newer filter change won
					renderUnscheduled( Array.isArray( items ) ? items : [] );
				} )
				.catch( function () {
					unscheduled.hidden = true;
				} );
		}

		function renderUnscheduled( items ) {
			var groups = [];
			var byLabel = {};
			items.forEach( function ( item ) {
				var label = ( item.extendedProps && item.extendedProps.dateLabel ) || '';
				if ( ! byLabel[ label ] ) {
					byLabel[ label ] = { label: label, items: [] };
					groups.push( byLabel[ label ] );
				}
				byLabel[ label ].items.push( item );
			} );

			var container = unscheduled.querySelector( '.gc-unscheduled-groups' );
			container.innerHTML = '';

			groups.forEach( function ( group ) {
				var section = document.createElement( 'div' );
				section.className = 'gc-unscheduled-group';

				var heading = document.createElement( 'h4' );
				heading.className   = 'gc-unscheduled-label';
				heading.textContent = group.label;
				section.appendChild( heading );

				var list = document.createElement( 'div' );
				list.className = 'gc-unscheduled-list';
				group.items.forEach( function ( item ) {
					var props = item.extendedProps || {};
					var btn   = document.createElement( 'button' );
					btn.type      = 'button';
					btn.className = 'gc-unscheduled-item';

					var cover = document.createElement( 'span' );
					cover.className = 'gc-unscheduled-cover';
					if ( props.cover ) {
						var img = document.createElement( 'img' );
						img.src     = props.cover;
						img.alt     = '';
						img.loading = 'lazy';
						cover.appendChild( img );
					}
					btn.appendChild( cover );

					var badge = reviewBadge( props );
					if ( badge ) cover.appendChild( badge );

					var title = document.createElement( 'span' );
					title.className   = 'gc-unscheduled-name';
					title.textContent = item.title;
					btn.appendChild( title );

					var families = ( props.platformFamilies && props.platformFamilies.length ) ? props.platformFamilies : props.platforms;
					if ( families && families.length ) {
						var meta = document.createElement( 'span' );
						meta.className   = 'gc-unscheduled-meta';
						meta.textContent = families.join( ' · ' );
						btn.appendChild( meta );
					}

					btn.addEventListener( 'click', function () {
						openDetailModal( { title: item.title, url: item.url, startStr: item.start, extendedProps: props } );
					} );
					list.appendChild( btn );
				} );
				section.appendChild( list );
				container.appendChild( section );
			} );

			unscheduled.hidden = groups.length === 0;
		}

		loadUnscheduled();

		// ── Filters ──────────────────────────────────────────────────────────
		function filtersChanged() {
			calendar.refetchEvents();
			syncUrl( calendar );
			loadUnscheduled();
			updateSubscribeLinks();
		}

		document.querySelectorAll( '.gc-filter-type' ).forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				activeTypes[ this.value ] = this.checked;
				filtersChanged();
			} );
		} );

		platformBoxes.forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				activePlatforms[ this.value ] = this.checked;
				filtersChanged();
			} );
		} );

		// ── Share: copy the current URL ──────────────────────────────────────
		function flashCopied( button, text ) {
			var span     = button.querySelector( 'span' ) || button;
			var original = span.textContent;
			span.textContent = text;
			button.classList.add( 'gc-share-btn--copied' );
			setTimeout( function () {
				span.textContent = original;
				button.classList.remove( 'gc-share-btn--copied' );
			}, 2000 );
		}

		var shareBtn = document.getElementById( 'gc-share-btn' );
		if ( shareBtn ) {
			shareBtn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( window.location.href ).then( function () {
					flashCopied( shareBtn, t( 'copied', 'Copied!' ) );
				} );
			} );
		}

		// ── Subscribe: iCalendar feed with the current filters ────────────────
		var subscribeBtn  = document.getElementById( 'gc-subscribe-btn' );
		var subscribeMenu = document.getElementById( 'gc-subscribe-menu' );

		function feedUrl() {
			var url    = new URL( cfg.icalUrl, window.location.href );
			var params = filterParams();
			if ( activeTypeList().length !== Object.keys( activeTypes ).length ) url.searchParams.set( 'type', params.type );
			if ( params.platform ) url.searchParams.set( 'platform', params.platform );
			return url.toString();
		}

		function updateSubscribeLinks() {
			if ( ! subscribeMenu || ! cfg.icalUrl ) return;
			var https  = feedUrl();
			var webcal = https.replace( /^https?:/, 'webcal:' );
			subscribeMenu.querySelector( '[data-gc-subscribe="google"]' ).href =
				'https://calendar.google.com/calendar/render?cid=' + encodeURIComponent( webcal );
			subscribeMenu.querySelector( '[data-gc-subscribe="webcal"]' ).href = webcal;
		}

		function closeSubscribeMenu() {
			if ( ! subscribeMenu || subscribeMenu.hidden ) return;
			subscribeMenu.hidden = true;
			subscribeBtn.setAttribute( 'aria-expanded', 'false' );
		}

		if ( subscribeBtn && subscribeMenu ) {
			updateSubscribeLinks();

			subscribeBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = subscribeMenu.hidden;
				subscribeMenu.hidden = ! open;
				subscribeBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
			subscribeMenu.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );
			document.addEventListener( 'click', closeSubscribeMenu );

			var copyBtn = subscribeMenu.querySelector( '[data-gc-subscribe="copy"]' );
			copyBtn.addEventListener( 'click', function () {
				navigator.clipboard.writeText( feedUrl() ).then( function () {
					flashCopied( copyBtn, t( 'linkCopied', 'Link copied!' ) );
				} );
			} );
		}

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
		return ( type === 'gc_event' ) ? t( 'gamingEvent', 'Gaming event' ) : t( 'gameRelease', 'Game release' );
	}

	function formatDate( dateStr ) {
		if ( ! dateStr ) return '';
		var d = new Date( dateStr );
		if ( isNaN( d.getTime() ) ) return dateStr;
		var opts = { day: 'numeric', month: 'long', year: 'numeric' };
		if ( dateStr.indexOf( 'T' ) !== -1 ) {
			opts.hour   = '2-digit';
			opts.minute = '2-digit';
		}
		try {
			return d.toLocaleDateString( cfg.dateLocale || undefined, opts );
		} catch ( e ) {
			return d.toLocaleDateString( undefined, opts );
		}
	}

	function formatTime( date ) {
		try {
			return date.toLocaleTimeString( cfg.dateLocale || undefined, { hour: '2-digit', minute: '2-digit' } );
		} catch ( e ) {
			return date.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
		}
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
