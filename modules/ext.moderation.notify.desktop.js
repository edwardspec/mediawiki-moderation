/*
	Implements notifyQueued() for desktop view.
	See [ext.moderation.notify.js] for non-desktop-specific code.
*/

( function () {
	'use strict';

	mw.moderation = mw.moderation || {};

	/*
		This callback is used by notifyQueued().
		Displays $div as postEdit notification.
	*/
	mw.moderation.notifyCb = function ( $div, readyCallback ) {

		/* Don't remove $div when clicking on links */
		$div.find( 'a' ).on( 'click', function ( e ) {
			e.stopPropagation();
		} );

		mw.loader.using( 'mediawiki.action.view.postEdit', function () {

			/* Desktop version */
			var minorMwVersion = mw.config.get( 'wgVersion' ).split( '.' )[ 1 ];
			if ( minorMwVersion >= 38 ) {
				// In MediaWiki 1.38+, usual postedit notification is barely noticeable,
				// so we need to apply additional styles.
				mw.notify( $div, {
					autoHide: false
				} ).then( function () {
					$( '#mw-notification-area' ).addClass( 'mw-notification-area-modqueued' );
				} );
			} else {
				// MediaWiki 1.35-1.37
				mw.hook( 'postEdit' ).fire( {
					message: $div
				} );

				/* Prevent the message from fading after 3 seconds
					(fading is done by mediawiki.action.view.postEdit.js),
					because both 'moderation-edit-queued' and 'moderation-suggest-signup'
					contain links (edit/signup) which the user might want to follow.
				*/
				var containerClass = '.postedit-container',
					$cont = $( containerClass ),
					$newcont = $cont.clone();

				/* postEdit.js will remove $cont, but won't touch $newcont */
				$cont.replaceWith( $newcont );

				/* Remove on click */
				$newcont.on( 'click', function () {
					$( containerClass ).remove();
				} );
			}

			readyCallback();
		} );
	};

}() );
