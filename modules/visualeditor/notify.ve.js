/*
	Calls notifyQueued() after editing in VisualEditor.
*/

( function ( mw, $ ) {
	'use strict';

	mw.moderation.notifyVE = function() {
		/*
			VisualEditor may choose not to reload the page,
			but instead to display content/categorieshtml without reload.

			We must detect the appearance of #moderation-ajaxhook,
			and then call mw.moderation.notifyQueued().
		*/
		mw.hook( 'wikipage.content' ).add( function( $content ) {
			if ( $content.find( '#moderation-ajaxhook' ).length != 0 ) {
				mw.moderation.notifyQueued( {
					/* Force re-rendering of #mw-content-text */
					showParsed: true
				} );
			}
		} );
	};

}( mediaWiki, jQuery ) );
