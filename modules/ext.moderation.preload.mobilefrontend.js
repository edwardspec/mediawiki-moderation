/*
	This code handles Preload for MobileFrontend.

	When JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.
*/

( function ( M, $ ) {
	'use strict';

	var EditorGateway,
		legacyMode = false;

	try {
		EditorGateway = M.require( 'mobile.editor.api/EditorGateway' );
	} catch(e) {
		// Legacy mode, e.g. in MediaWiki 1.23
		EditorGateway = M.require( 'modules/editor/EditorApi' );
		legacyMode = true;
	}

	var oldGetContent = EditorGateway.prototype.getContent;
	EditorGateway.prototype.getContent = function() {

		var self = this;

		/*
			useDefault() - call the original (unmodified) method from EditorGateway.
			Example: return useDefault( "no change is awaiting moderation, so nothing to preload!" );
		*/
		function useDefault( reason, $deferred ) {
			console.log( "Moderation: not preloading: " + reason );

			if ( $deferred === undefined ) {
				$deferred = $.Deferred();
			}

			oldGetContent.call( self ).then( function( content, userinfo ) {
				$deferred.resolve( content, userinfo );
			});
			return $deferred;
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
		var $result = $.Deferred(),
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

		var api = ( legacyMode ? this : this.api );
		api.get( qPreload ).then( function( data ) {
			var wikitext = data.query.moderationpreload.wikitext;
			if ( !wikitext ) {
				/* Nothing to preload.
					Call the original getContent() from EditorGateway. */
				return useDefault( "no pending change found", $result );
			}

			/* Preload summary */
			$( '.summary' ).val( data.query.moderationpreload.comment );

			self.content = wikitext;
			self.timestamp = ""; /* Ok to leave empty */
			self.originalContent = self.content;

			$result.resolve( self.content, data.query.userinfo );
		} );

		return $result;
	};

}( mw.mobileFrontend, jQuery ) );
