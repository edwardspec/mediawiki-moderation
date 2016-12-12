/*
	This code handles Preload for MobileFrontend.

	When JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.
*/

( function ( M, $ ) {
	'use strict';

	var EditorGateway = M.require( 'mobile.editor.api/EditorGateway' ),
		oldGetContent = EditorGateway.prototype.getContent;

	EditorGateway.prototype.getContent = function() {

		/*
			useDefault() - call the original (unmodified) method from EditorGateway.
			Example: return useDefault( "no change is awaiting moderation, so nothing to preload!" );
		*/
		var useDefault = function( reason ) {
			console.log( "Moderation: not preloading: " + reason );
			return oldGetContent.apply( this, arguments );
		};

		/* Only load once */
		if ( this.content !== undefined ) {
			return useDefault( "already loaded" );
		}

		/* If user is editing some older revision,
			then preloading is not needed here */
		if ( this.oldId ) {
			return useDefault( "user is editing an older revision" );
		}

		/* Get the wikitext of pending change, if it exists */
		var result = $.Deferred(),
			self = this;
		var qPreload = {
			action: 'query',
			prop: 'moderationpreload',
			mptitle: this.title,
			mpmode: 'wikitext',

			// MobileFrontend also needs block information for this user
			meta: 'userinfo',
			uiprop: 'blockinfo'
		};
		this.api.get( qPreload ).then( function( data ) {
			var wikitext = data.query.moderationpreload.wikitext;
			if ( !wikitext ) {
				/* Nothing to preload.
					Call the original getContent() from EditorGateway. */
				result.resolve( useDefault( "no pending change found" ) );
				return;
			}

			self.content = wikitext;
			self.timestamp = ""; /* Ok to leave empty */
			self.originalContent = self.content;

			result.resolve( self.content, data.query.userinfo );
		} );

		return result;
	};

}( mw.mobileFrontend, jQuery ) );
