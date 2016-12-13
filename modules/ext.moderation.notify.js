/*
	Fire "postEdit" hook to show "moderation-edit-queued" to the user.
*/

( function ( mw, $ ) {
	'use strict';

	var containerClass = '.postedit-container',
		M = mw.mobileFrontend;

	mw.moderation = mw.moderation || {};

	/* Show "your edit was queued for moderation" to user.
		May be called from [ext.moderation.ajaxhook.js].
	*/
	mw.moderation.notifyQueued = function( options = [] ) {
		if ( $( containerClass ).length ) {
			/* User quickly clicked Submit several times in VisualEditor, etc.
				Don't show the dialog twice.
			*/
			return;
		}

		var $div = $( '<div/>' );
		$div.append( $( '<p/>' ).append(
			mw.message(
				'moderation-edit-queued',
				mw.util.getUrl( null, { action: 'edit' } )
			).plain()
		));

		if ( mw.user.getId() == 0 ) {
			$div.append( $( '<p/>' ).append(
				mw.message( 'moderation-suggest-signup' ).parse()
			));
		}

		/* TODO: maybe move mobile/non-mobile versions into separate modules,
			load them only if needed.
		*/
		if ( M ) {
			/* Suppress postedit message from MobileFrontend */
			mw.util.addCSS(
				'.toast, .mw-notification-tag-toast { display: none ! important; }'
			);

			/* Mobile version */
			mw.notify( $div, {
				tag: 'modqueued',
				autoHide: false,
				type: 'info'
			} );

			/* If MobileFrontend hasn't reloaded the page after edit,
				remove "mobile-frontend-editor-success" from the toast queue,
				so that it won't be shown after reload.
			*/
			try {
				var toast = M.require( 'mobile.toast/toast' );
				toast._showPending();
			} catch ( e ) {
				 /* Nothing to do - old MobileFrontend (e.g. for MediaWiki 1.23)
					didn't have "show after reload" anyway.
				*/
			}
		}
		else {
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
		}

		/* Remove the cookie from [ext.moderation.ajaxhook.js] */
		$.cookie( 'modqueued', null, { path: '/' } );

		/* If requested, display HTML of this queued edit */
		if ( options.showParsed ) {
			var api = new mw.Api();
			api.get( {
				action: 'query',
				prop: 'moderationpreload',
				mptitle: mw.config.get( 'wgPageName' ),
				mpmode: 'parsed'
			} ).done( function( ret ) {
				var parsed = ret.query.moderationpreload.parsed;
				if ( parsed ) {
					var $div = $( '<div/>').html( parsed.text );
					mw.hook( 'wikipage.content' ).fire(
						$( '#mw-content-text' ).empty().append( $div )
					);

					$( '#catlinks' ).html( parsed.categorieshtml );
				}
			} );
		}
	}

	var justQueued = (
		/* 1. From the normal edit form: redirect contains ?modqueued=1 */
		mw.util.getParamValue('modqueued') == 1
		/* 2. From [ext.moderation.ajaxhook.js]: page was edited via API */
		|| $.cookie( 'modqueued' ) == 1
	);


	if ( justQueued && ( mw.config.get('wgAction') == 'view' ) ) {
		mw.moderation.notifyQueued();
	}

}( mediaWiki, jQuery ) );
