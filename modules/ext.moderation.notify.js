/*
	Fire "postEdit" hook to show "moderation-edit-queued" to the user.
*/

( function ( mw, $ ) {
	'use strict';

	if ( mw.config.get('wgAction') == 'view' && window.location.search.match( /modqueued=1/ ) ) {

		var $div = $('<div/>');
		$div.append($('<p/>').append(
			mw.message(
				'moderation-edit-queued',
				window.location + '&action=edit'
			).plain()
		));

		if ( mw.user.getId() == 0 ) {
			$div.append($('<p/>').append(
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
		var $cont = $('.postedit-container');
		var $newcont = $cont.clone();
		$cont.replaceWith( $newcont ); /* postEdit.js will remove $cont, but won't touch $newcont */

		/* Remove on click */
		$newcont.click(function() { this.remove(); });
	}

}( mediaWiki, jQuery ) );
