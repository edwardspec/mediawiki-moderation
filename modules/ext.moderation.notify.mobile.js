/*
	Implements notifyQueued() for MobileFrontend.
	See [ext.moderation.notify.js] for non-mobile-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	var M = mw.mobileFrontend;

	mw.moderation = mw.moderation || {};

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

		/* Workaround the bug which affected mw.notify() in MediaWiki 1.23 */
		var $oldContent = mw.util.$content;
		mw.util.$content = $( 'body' );

		/* Mobile version */
		mw.notify( $div, {
			tag: 'modqueued',
			autoHide: false,
			type: 'info'
		} ).done( function() {
			mw.util.$content = $oldContent; /* Restore mw.util.$content */
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
	mw.moderation.notifyMF = function() {
		try {
			/* Modern MobileFrontend */
			var router = M.require( 'mobile.startup/router' );
			router.once( 'hashchange', function() {
				mw.moderation.notifyQueued();
			} );
		}
		catch ( e ) {
			/* Legacy MobileFrontend for MediaWiki 1.23 */
			M.one(  'page-loaded', function() {
				mw.moderation.notifyQueued();
			} );
		}
	};

}( mediaWiki, jQuery ) );
