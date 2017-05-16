/*
	This is only needed for legacy MobileFrontend (1.26 and lower),
	which uses ugly wrapper around mw.Api (with its own ajax() function()).

	See trackAjax() in [ext.moderation.ajaxhook.js] for details.
*/

( function ( M, $ ) {
	'use strict';

	var api;
	try {
		// Legacy module, won't be found in MediaWiki 1.27 and newer
		api = M.require( 'modules/editor/EditorApi' );
	} catch(e) {}

	if ( api ) {
		mw.moderation.trackAjax( api );
	}

}( mw.mobileFrontend, jQuery ) );
