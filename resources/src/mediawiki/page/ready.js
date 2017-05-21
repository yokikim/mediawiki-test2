( function ( mw, $ ) {
	// Break out of framesets
	if ( mw.config.get( 'wgBreakFrames' ) ) {
		// Note: In IE < 9 strict comparison to window is non-standard (the standard didn't exist yet)
		// it works only comparing to window.self or window.window (http://stackoverflow.com/q/4850978/319266)
		if ( window.top !== window.self ) {
			// Un-trap us from framesets
			window.top.location.href = location.href;
		}
	}

	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		var $sortable, $collapsible;

		$collapsible = $content.find( '.mw-collapsible' );
		if ( $collapsible.length ) {
			// Preloaded by Skin::getDefaultModules()
			mw.loader.using( 'jquery.makeCollapsible', function () {
				$collapsible.makeCollapsible();
			} );
		}

		$sortable = $content.find( 'table.sortable' );
		if ( $sortable.length ) {
			// Preloaded by Skin::getDefaultModules()
			mw.loader.using( 'jquery.tablesorter', function () {
				$sortable.tablesorter();
			} );
		}

		// Run jquery.checkboxShiftClick
		$content.find( 'input[type="checkbox"]:not(.noshiftselect)' ).checkboxShiftClick();
	} );

	// Things outside the wikipage content
	$( function () {
		var $nodes;

		// Add accesskey hints to the tooltips
		$( '[accesskey]' ).updateTooltipAccessKeys();

		$nodes = $( '.catlinks[data-mw="interface"]' );
		if ( $nodes.length ) {
			/**
			 * Fired when categories are being added to the DOM
			 *
			 * It is encouraged to fire it before the main DOM is changed (when $content
			 * is still detached).  However, this order is not defined either way, so you
			 * should only rely on $content itself.
			 *
			 * This includes the ready event on a page load (including post-edit loads)
			 * and when content has been previewed with LivePreview.
			 *
			 * @event wikipage_categories
			 * @member mw.hook
			 * @param {jQuery} $content The most appropriate element containing the content,
			 *   such as .catlinks
			 */
			mw.hook( 'wikipage.categories' ).fire( $nodes );
		}

		$( '#t-print' ).click( function ( e ) {
			window.print();
			e.preventDefault();
		} );
	} );

}( mediaWiki, jQuery ) );
