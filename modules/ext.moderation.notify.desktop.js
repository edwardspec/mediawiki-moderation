/*
	Implements notifyQueued() for MobileFrontend.
	See [ext.moderation.notify.js] for non-mobile-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	var containerClass = '.postedit-container';

	mw.moderation = mw.moderation || {};

	/*
		This callback is used by notifyQueued().
		Displays $div as postEdit notification.
	*/
	mw.moderation.notifyDesktopCb = function( $div ) {

		/* Desktop version */
		mw.hook( 'postEdit' ).fire( {
			message: $div
		} );

		/* Prevent the message from fading after 3 seconds
			(fading is done by mediawiki.action.view.postEdit.js),
			because both 'moderation-edit-queued' and 'moderation-suggest-signup'
			contain links (edit/signup) which the user might want to follow.
		*/
		var $cont = $( containerClass );
		var $newcont = $cont.clone();
		$cont.replaceWith( $newcont ); /* postEdit.js will remove $cont, but won't touch $newcont */

		/* Remove on click */
		$newcont.click( function() {
			$( containerClass ).remove();
		} );
	};

	/* Call notifyQueued() after editing in VisualEditor */
	mw.moderation.notifyDesktop = function() {
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
