/*
	Fire "postEdit" hook to show "moderation-edit-queued" to the user.
*/

( function ( mw, $ ) {
	'use strict';

	if ( mw.config.get('wgAction') != 'view' ) {
		return; /* Nothing to do */
	}

	var containerClass = '.postedit-container';

	/* Show "your edit was queued for moderation" to user.
		May be called from [ext.moderation.ajaxhook.js].
	*/
	mw.moderationNotifyQueued = function( options = [] ) {
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
				window.location + '&action=edit'
			).plain()
		));

		if ( mw.user.getId() == 0 ) {
			$div.append( $( '<p/>' ).append(
				mw.message( 'moderation-suggest-signup' ).parse()
			));
		}

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
		window.location.search.match( /modqueued=1/ )
		/* 2. From [ext.moderation.ajaxhook.js]: page was edited via API */
		|| $.cookie( 'modqueued' ) == 1
	);

	if ( justQueued ) {
		mw.moderationNotifyQueued();
	}

}( mediaWiki, jQuery ) );
