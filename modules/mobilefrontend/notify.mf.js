/*
	Implements notifyQueued() for MobileFrontend.
	See [ext.moderation.notify.js] for non-mobile-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	var M = mw.mobileFrontend;

	mw.moderation = mw.moderation || {};

	function removeNotif() {
		$( '.mw-notification-tag-modqueued' ).remove();
	}

	/*
		This callback is used by notifyQueued().
		1) displays $div as mw.notification.
		2) removes postedit notification from the MobileFrontend editor
		(e.g. "mobile-frontend-editor-success").
	*/
	mw.moderation.notifyCb = function( $div ) {
		/* Suppress postedit message from MobileFrontend */
		mw.util.addCSS(
			'.toast, .mw-notification-tag-toast { display: none ! important; }'
		);

		$( window ).off( 'hashchange', removeNotif ); /* Only needed for MW 1.23 workaround, see below */

		/* Mobile version */
		mw.notify( $div, {
			tag: 'modqueued',
			autoHide: false,
			type: 'info'
		} ).done( function() {
			/* Workaround the bug which affected mw.notify() in MediaWiki 1.23,
				when #content was replaced by MobileFrontend
				and #mw-notification-area became detached from DOM */
			var $notif = $div.parents( '#mw-notification-area' ),
				$body = $( 'body' );
			if ( !$.contains( $body[0], $notif[0] ) ) {
				$notif.appendTo( $body );
			}

			/* Remove on click */
			$notif.click( function() {
				this.remove();
			} );

			/* Remove when moving to another page */
			$( window ).one( 'hashchange', removeNotif );
		} );

		/* If MobileFrontend hasn't reloaded the page after edit,
			remove "mobile-frontend-editor-success" from the toast queue,
			so that it won't be shown after reload.
		*/
		try {
			mw.loader.using( 'mobile.toast', function() {
				var toast = M.require( 'mobile.toast/toast' );
				toast._showPending();
			} );
		} catch ( e ) {
			 /* Nothing to do - old MobileFrontend (e.g. for MediaWiki 1.23)
				didn't have "show after reload" anyway.
			*/
		}
	}

	/* Call notifyQueued() after editing in MobileFrontend editor */
	mw.hook( 'moderation.ajaxhook.edit' ).add( function() {
		var router;
		try {
			try {
				/* Router from MediaWiki core (MediaWiki 1.28+) */
				router = mw.loader.require( 'mediawiki.router' );
			}
			catch ( e ) {
				/* MobileFrontend for MediaWiki 1.27 */
				router = M.require( 'mobile.startup/router' );
			}
		}
		catch ( e ) {
			/* Legacy MobileFrontend for MediaWiki 1.23 */
			M.one(  'page-loaded', function() {
				mw.moderation.notifyQueued();
			} );
		}

		if ( router ) {
			router.once( 'hashchange', function() {
				mw.moderation.notifyQueued();
			} );
		}
	} );

}( mediaWiki, jQuery ) );
