/*
	This code handles Preload for MobileFrontend.

	When JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.
*/

( function ( mw, $ ) {
	'use strict';

	/* Supply MobileFrontend with preloaded pending edit, if there is one */
	mw.loader.using( 'mobile.editor.common', function() {

		var api = new mw.Api();

		/* TODO */
	});

}( mediaWiki, jQuery ) );

