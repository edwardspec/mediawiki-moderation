/*
	This code handles Preload for MobileFrontend.

	When JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.
*/

( function () {
	'use strict';

	var M = mw.mobileFrontend,
		EditorGateway = M.require( 'mobile.editor.api/EditorGateway' ),
		oldGetContent = EditorGateway.prototype.getContent;

	EditorGateway.prototype.getContent = function () {

		var self = this;

		/*
			useDefault() - call the original (unmodified) method from EditorGateway.
			Example:
			return useDefault( "no change is awaiting moderation, so nothing to preload!" );
		*/
		function useDefault( reason, $deferred ) {
			console.log( 'Moderation: not preloading: ' + reason );

			if ( $deferred === undefined ) {
				$deferred = $.Deferred();
			}

			oldGetContent.call( self ).then( function () {
				$deferred.resolve.apply( null, arguments );
			} );
			return $deferred;
		}

		/* Only load once */
		if ( this.content !== undefined && this.content !== '' ) {
			return useDefault( 'already loaded' );
		}

		/* If user is editing some older revision,
			then preloading is not needed here */
		if ( this.oldId ) {
			return useDefault( 'user is editing an older revision' );
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
			uiprop: 'blockinfo',

			// MobileFrontend also needs to know whether this user (if blocked)
			// is allowed to edit his/her talkpage.
			list: 'blocks',
			bkusers: mw.user.getName(),
			bkprop: 'flags'
		};

		// eslint-disable-next-line no-jquery/no-is-numeric
		if ( $.isNumeric( this.sectionId ) ) {
			qPreload.mpsection = this.sectionId;
		}

		this.api.post( qPreload ).then( function ( data ) {
			var wikitext = data.query.moderationpreload.wikitext;
			if ( !wikitext ) {
				/* Nothing to preload.
					Call the original getContent() from EditorGateway. */
				return useDefault( 'no pending change found', $result );
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
			self.timestamp = ''; /* Ok to leave empty */
			self.originalContent = self.content;

			$result.resolve( {
				text: self.content,
				user: data.query.userinfo,
				block: data.query.blocks ? data.query.blocks[ 0 ] : {}
			} );
		} );

		return $result;
	};

}() );
