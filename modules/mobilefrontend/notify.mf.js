/*
	Implements notifyQueued() for MobileFrontend.
	See [ext.moderation.notify.js] for non-mobile-specific code.
*/

( function ( mw, $ ) {
	'use strict';

	/*
		FIXME: in Google Chrome only, MobileFrontend for MediaWiki 1.29
		is inconsistent in whether it reloads the page after saving the edit
		in section #0 (Note: editing other sections always reloads the page).

		The cause for this should be investigated, because notifyQueued()
		is only called on page reload.

		Unlike the previous implementation (that worked in MediaWiki 1.28 and earlier),
		we can no longer use router.hashchange event,
		because [onSaveComplete] in [EditorOverlayBase.js] first sets window.location.href
		and then calls window.location.reload() for sectionIdx>=1.

		So if we just called notifyQueued() on every hashchange (as before),
		it wouldn't be shown properly for sectionIdx>=1 (it will be briefly shown,
		and then the page will reload, removing the notification instantly).

		Instead we should detect situation "saving the edit re-rendered page without reloading it"
		and call notifyQueued() only then.
	*/

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
	} catch ( e ) {
		$d.resolve( M.require( 'toast' ) );
	}

	$d.done( function( toast ) {
		var oldReload = toast.showOnPageReload;

		/*
			We must modify the message in showOnPageReload(),
			because _showPending() will be called before we have
			a chance to override show().
		*/
		if ( oldReload ) {
			toast.showOnPageReload = function( msg, cssClass ) {
				if ( shouldAllowMessage( msg ) ) {
					oldReload( msg, cssClass );
				}
			};
		}
		else {
			/* In MediaWiki 1.23-1.26, there was no showOnPageReload() */
			var oldShow = toast.show;
			toast.show = function( msg, cssClass ) {
				if ( shouldAllowMessage( msg ) ) {
					oldShow( msg, cssClass );
				}
			};

			/* In MediaWiki 1.23, page wasn't necessarily reloaded after edit
				(it could have been re-rendered without window.location.reload).
				If this happens, display the notification right away. */
			M.one(  'page-loaded', function() {
				mw.moderation.notifyQueued();
			} );
		}
	} );

	/*
		This callback is used by notifyQueued().
		It displays $div as mw.notification.
	*/
	mw.moderation.notifyCb = function( $div ) {
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

			$( window ).one( 'hashchange', function() {
				$( '.mw-notification-tag-modqueued' ).remove();
			} );
		} );
	}

}( mediaWiki, jQuery ) );
