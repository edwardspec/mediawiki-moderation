/*
	Implements notifyQueued() for MobileFrontend.
	See [ext.moderation.notify.js] for non-mobile-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	mw.moderation = mw.moderation || {};

	var M = mw.mobileFrontend;

	/**
		@brief Identify popups that should be overwritten.
	*/
	function shouldAllowMessage( msg ) {
		switch ( msg ) {
			case mw.msg( 'mobile-frontend-editor-success-new-page' ) :
			case mw.msg( 'mobile-frontend-editor-success-landmark-1' ) :
			case mw.msg( 'mobile-frontend-editor-success' ) :
				return false;
		}

		return true;
	}

	/* Override Toast class to suppress default notification ("edit saved"),
		because notifyQueued() already shows "edit queued for moderation" */
	var $d = $.Deferred();
	try {
		/* MediaWiki 1.29+ */
		mw.loader.using( 'mobile.startup', function() {
			$d.resolve( M.require( 'mobile.startup/toast' ) );
		} );
	}
	catch ( e ) {
		/* MediaWiki 1.27-1.28 */
		mw.loader.using( 'mobile.toast', function() {
			$d.resolve( M.require( 'mobile.toast/toast' ) );
		} );
	}

	$d.done( function( toast ) {
		var oldReload = toast.showOnPageReload;

		/*
			We must modify the message in showOnPageReload(),
			because _showPending() will be called before we have
			a chance to override show().
		*/
		toast.showOnPageReload = function( msg, cssClass ) {
			if ( shouldAllowMessage( msg ) ) {
				oldReload( msg, cssClass );
			}
		};
	} );

	/*
		This callback is used by notifyQueued().
		It displays $div as mw.notification.
	*/
	mw.moderation.notifyCb = function( $div, readyCallback ) {
		mw.notify( $div, {
			tag: 'modqueued',
			autoHide: false,
			type: 'info'
		} ).done( function() {
			var $notif = $( '.mw-notification-tag-modqueued' );

			/* Remove on click */
			$notif.click( function() {
				this.remove();
			} );

			/* Remove when moving to another page */
			$( window ).one( 'hashchange', function() {
				$notif.remove();
			} );

			readyCallback();
		} );
	}

	/* Workaround for Google Chrome issue.
		In Chrome, onSaveComplete() sometimes doesn't reload the page
		when changing window.location from /wiki/Something#/editor/0 to /wiki/Something
		(expected correct behavior: it should ALWAYS reload the page).
		The reason is unclear.

		As a workaround, we modify window.location explicitly to be sure.
		Note: we can't use window.location.reload() in onhashchange
		(it was causing flaky SauceLabs results in IE11 and Firefox).
	*/
	mw.hook( 'moderation.ajaxhook.edit' ).add( function() {
		$( window ).one( 'hashchange', function() {
			window.location.search = '?modqueued=1';
		} );
	} );

}( mediaWiki, jQuery ) );
