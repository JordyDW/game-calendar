( function ( $ ) {
	'use strict';

	var searchInput  = $( '#gc_igdb_search' );
	var resultsPanel = $( '#gc_igdb_results' );
	var debounceTimer;

	if ( ! searchInput.length ) return;

	searchInput.on( 'input', function () {
		clearTimeout( debounceTimer );
		var query = $( this ).val().trim();

		if ( query.length < 2 ) {
			resultsPanel.hide().empty();
			return;
		}

		debounceTimer = setTimeout( function () {
			resultsPanel.html( '<p class="gc-searching">Searching IGDB…</p>' ).show();

			$.post( gcAdmin.ajaxUrl, {
				action: 'gc_igdb_search',
				nonce:  gcAdmin.nonce,
				query:  query,
			} ).done( function ( response ) {
				resultsPanel.empty();

				if ( ! response.success || ! response.data.length ) {
					resultsPanel.html( '<p class="gc-no-results">No results found.</p>' );
					return;
				}

				var list = $( '<ul class="gc-igdb-list"></ul>' );

				response.data.forEach( function ( game ) {
					var item = $( '<li class="gc-igdb-item"></li>' );

					var thumb = game.cover
						? '<img src="' + $( '<div>' ).text( game.cover ).html() + '" alt="" class="gc-igdb-thumb" />'
						: '<span class="gc-igdb-no-thumb"></span>';

					item.html(
						thumb +
						'<span class="gc-igdb-info">' +
							'<strong>' + $( '<div>' ).text( game.name ).html() + '</strong>' +
							( game.release_date ? '<br><small>' + game.release_date + '</small>' : '' ) +
							( game.developer    ? '<br><small>' + $( '<div>' ).text( game.developer ).html() + '</small>' : '' ) +
						'</span>'
					);

					item.on( 'click', function () {
						populateFields( game );
						resultsPanel.hide().empty();
						searchInput.val( '' );
					} );

					list.append( item );
				} );

				resultsPanel.append( list );
			} ).fail( function () {
				resultsPanel.html( '<p class="gc-error">IGDB request failed.</p>' );
			} );
		}, 350 );
	} );

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.gc-igdb-search-wrap' ).length ) {
			resultsPanel.hide();
		}
	} );

	function populateFields( game ) {
		// Always overwrite with IGDB data.
		setValue( '#gc_release_date', game.release_date );
		setValue( '#gc_developer',    game.developer );
		setValue( '#gc_publisher',    game.publisher );
		setValue( '#gc_genre',        game.genre );
		setValue( '#gc_cover_url',    game.cover );
		setValue( '#gc_url',          game.url );
		setValue( '#gc_igdb_id',      game.id );

		// Store platform names as JSON for save_post to assign as taxonomy terms.
		if ( game.platforms && game.platforms.length ) {
			$( '#gc_igdb_platforms' ).val( JSON.stringify( game.platforms ) );
		}

		// Cover image preview.
		if ( game.cover ) {
			$( '#gc_cover_preview' ).attr( 'src', game.cover ).show();
		}

		// Always set the post title from IGDB.
		var titleField = $( '#title' );
		if ( titleField.length ) {
			titleField.val( game.name ).trigger( 'input' );
		}

		// Show the connection badge.
		var thumb = game.cover
			? '<img src="' + $( '<div>' ).text( game.cover ).html() + '" alt="" class="gc-igdb-connected-thumb" />'
			: '<span class="gc-igdb-connected-thumb gc-igdb-no-thumb"></span>';
		var link = game.url
			? ' <a href="' + $( '<div>' ).text( game.url ).html() + '" target="_blank" rel="noopener" class="gc-igdb-connected-link">View on IGDB ↗</a>'
			: '';
		$( '#gc-igdb-connected-badge' ).html(
			thumb +
			'<span class="gc-igdb-connected-info">' +
				'<span class="gc-igdb-connected-label"><span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> Connected to IGDB</span>' +
				link +
			'</span>' +
			'<button type="button" id="gc-igdb-disconnect" class="button button-small">Change</button>'
		).show();
		$( '#gc-igdb-search-area' ).hide();
	}

	// Handle "Change" button — show search without clearing the ID until a new game is chosen.
	$( document ).on( 'click', '#gc-igdb-disconnect', function () {
		$( '#gc-igdb-connected-badge' ).hide().empty();
		$( '#gc-igdb-search-area' ).show();
		searchInput.val( '' ).trigger( 'focus' );
	} );

	function setValue( selector, value ) {
		var el = $( selector );
		if ( el.length && value ) {
			el.val( value );
		}
	}

	// ── WP media library picker for cover image fields ──────────────────────
	$( document ).on( 'click', '.gc-media-btn', function ( e ) {
		e.preventDefault();
		var btn        = $( this );
		var targetSel  = btn.data( 'target' );
		var previewSel = btn.data( 'preview' );

		var frame = wp.media( {
			title:    btn.data( 'title' ) || 'Choose Cover Image',
			button:   { text: 'Use this image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var url = ( attachment.sizes && attachment.sizes.large )
				? attachment.sizes.large.url
				: attachment.url;

			$( targetSel ).val( url );

			var preview = $( previewSel );
			if ( preview.length ) {
				preview.attr( 'src', url ).show();
			} else {
				$( '<img style="max-height:80px;margin-top:6px;" />' )
					.attr( 'src', url )
					.insertAfter( targetSel );
			}
		} );

		frame.open();
	} );

} )( jQuery );
