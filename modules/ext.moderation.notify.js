/*
	Fire "postEdit" hook to show "moderation-edit-queued" to the user.
*/

( function ( mw, $ ) {
	'use strict';

	var M = mw.mobileFrontend,
		containerSel = '.postedit-container, .mw-notification-tag-modqueued';

	mw.moderation = mw.moderation || {};

	/* Display mobile/desktop version */
	function show( $div ) {
		var $d = $.Deferred(),
			module = ( M ? 'ext.moderation.notify.mobile' : 'ext.moderation.notify.desktop' ),
			fn = ( M ? 'notifyMobileCb' : 'notifyDesktopCb' );

		mw.loader.using( module, function() {
			mw.moderation[fn]( $div );
			$d.resolve();
		} );

		return $d;
	}

	/* Show "your edit was queued for moderation" to user.
		May be called from [ext.moderation.ajaxhook.js].
	*/
	mw.moderation.notifyQueued = function( options = [] ) {
		if ( $( containerSel ).length ) {
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
		show( $div ).done( function() {
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
		} );
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
