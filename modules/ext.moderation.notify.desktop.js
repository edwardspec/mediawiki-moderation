/*
	Implements notifyQueued() for desktop view.
	See [ext.moderation.notify.js] for non-desktop-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	var containerClass = '.postedit-container';

	mw.moderation = mw.moderation || {};

	/*
		This callback is used by notifyQueued().
		Displays $div as postEdit notification.
	*/
	mw.moderation.notifyCb = function( $div, readyCallback ) {

		/* Don't remove $div when clicking on links */
		$div.find( 'a' ).click( function( e ) {
			e.stopPropagation();
		} );

		mw.loader.using( 'mediawiki.action.view.postEdit', function() {

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

			readyCallback();
		} );
	};

}( mediaWiki, jQuery ) );
