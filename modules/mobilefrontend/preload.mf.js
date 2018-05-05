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

			oldGetContent.call( self ).then( function() {
				$deferred.resolve.apply( null, arguments );
			});
			return $deferred;
		};

		/* Only load once */
		if ( this.content !== undefined && this.content !== '' ) {
			return useDefault( "already loaded" );
		}

		/* If user is editing some older revision,
			then preloading is not needed here */
		if ( this.oldId ) {
			return useDefault( "user is editing an older revision" );
		}

		/* Get the wikitext of pending change, if it exists */
		var $result = $.Deferred();
		var qPreload = {
			action: 'query',
			prop: 'moderationpreload',
			mptitle: this.title,
			mpmode: 'wikitext',

			// MobileFrontend also needs block information for this user
			meta: 'userinfo',
			uiprop: 'blockinfo'
		};

		if ( $.isNumeric( this.sectionId ) ) {
			qPreload.mpsection = this.sectionId;
		}

		/* MediaWiki 1.31+ expects different format of return value,
			and also information on whether this user (if blocked)
			is allowed to edit his/her talkpage.
		*/
		var isMW31 = mw.config.get( 'wgVersion' ).split( '.' )[1] >= 31;
		if ( isMW31 ) {
			qPreload.list = 'blocks';
			qPreload.bkusers = mw.user.getName();
			qPreload.bkprop = 'flags';
		}

		this.api.post( qPreload ).then( function( data ) {
			var wikitext = data.query.moderationpreload.wikitext;
			if ( !wikitext ) {
				/* Nothing to preload.
					Call the original getContent() from EditorGateway. */
				return useDefault( "no pending change found", $result );
			}

			// Preload summary.
			// NOTE: MobileFrontend always adds /* SectionName */ when
			// saving an edit. This can result in ugly summaries,
			// e.g. "/* Section 1 */ /* Section 3 */ /* Section 6 */ fix typo".
			// To avoid that, we simply remove /* SectionName */
			// from the preloaded edit comment.
			var summary = data.query.moderationpreload.comment;
			summary = summary.replace( /\s*\/\*.*\*\/\s*/g, '' );
			$( '.summary' ).val( summary );

			self.content = wikitext;
			self.timestamp = ""; /* Ok to leave empty */
			self.originalContent = self.content;

			if ( isMW31 ) {
				/* MediaWiki 1.31+ */
				$result.resolve( {
					text: self.content,
					user: data.query.userinfo,
					block: data.query.blocks ? data.query.blocks[0] : {}
				} );
			}
			else {
				/* Legacy format, MediaWiki 1.27-1.30 */
				$result.resolve( self.content, data.query.userinfo );
			}
		} );

		return $result;
	};

}( mw.mobileFrontend, jQuery ) );
