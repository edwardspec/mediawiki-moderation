/*
	The following is based on "mediawiki.action.view.postEdit.js"
	from the MediaWiki core.
*/

( function ( mw, $ ) {
	'use strict';

	var config = mw.config.get(['wgAction', 'wgScript']),
		$div;

	if ( config.wgAction == 'view' && window.location.search.match( /modqueued=1/ ) ) {
		$div = $(
			'<div class="postedit-container">' +
				'<div class="postedit">' +
					'<div class="postedit-icon postedit-icon-checkmark postedit-content"></div>' +
					'<a href="#" class="postedit-close">&times;</a>' +
				'</div>' +
			'</div>'
		);

		var text = mw.message( 'moderation-edit-queued', window.location + '&action=edit' ).plain();
		if ( mw.user.getId() == 0 ) {
			text += '<br>' + mw.message( 'moderation-suggest-signup', 'lol' ).parse();
		}

		$div.find( '.postedit-content' ).html( text );
		$div.find( '.postedit-close' ).click( fadeOutConfirmation );
		$div.prependTo( 'body' );
	}

	function fadeOutConfirmation() {
		$div.find( '.postedit' ).addClass( 'postedit-faded' );
		setTimeout( removeConfirmation, 500 );

		return false;
	}

	function removeConfirmation() {
		$div.remove();
	}
}( mediaWiki, jQuery ) );
