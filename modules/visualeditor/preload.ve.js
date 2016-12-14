/*
	This code handles Preload for VisualEditor.

	When JavaScript-based editors load the main <textarea>
	(their analogue of #wpTextbox1 in index.php?action=edit),
	they ignore Preload hooks like onEditFormInitialText, so if this user
	has a pending change, it won't be preloaded at all.
*/

( function ( mw, $ ) {
	'use strict';

	/* Supply VisualEditor with preloaded pending edit, if there is one */
	mw.loader.using( 'ext.visualEditor.targetLoader', function() {

		var oldRequestPageData = mw.libs.ve.targetLoader.requestPageData,
			api = new mw.Api();

		/* Override requestPageData() method */
		mw.libs.ve.targetLoader.requestPageData = function( pageName, oldid, targetName, modified ) {

			/*
				useDefault() - call the original (unmodified) method from mw.libs.ve.
				Example: return useDefault( "no change is awaiting moderation, so nothing to preload!" );
			*/
			function useDefault( reason ) {
				console.log( "Moderation: not preloading: " + reason );

				return oldRequestPageData.apply( this, [
					pageName, oldid, targetName, modified
				] );
			}

			/* If user is editing some older revision,
				then preloading is not needed here */
			if ( oldid !== undefined && oldid != mw.config.get('wgCurRevisionId' ) ) {
				return useDefault( "user is editing an older revision" );
			}

			/* We need to get the following information:
				1) response from action=query&prop=moderationpreload
				(i.e. wikitext of pending change, if it exists)

				2) response from ?action=visualeditor&paction=metadata
				(i.e. everything except parsed HTML)

				3) response from ?action=visualeditor&paction=parsefragment,
				which will transform (1) into the parsed HTML.

				After that, we insert this parsed HTML into (2),
				and return it as the result of requestPageData().
			*/
			var qPreload = {
				action: 'query',
				prop: 'moderationpreload',
				mptitle: pageName,
				mpmode: 'wikitext'
			};
			return api.get( qPreload ).then( function( data ) {

				var wikitext = data.query.moderationpreload.wikitext;
				if ( !wikitext ) {
					/* Nothing to preload.
						Call the original requestPageData() from VisualEditor. */
					return useDefault( "no pending change found" );
				}

				/* Preload summary.
					If #wpSummary field exists, it will be used as "initialEditSummary"
					in ve.init.mw.DesktopArticleTarget() constructor. */
				if ( $( '#wpSummary' ).length == 0 ) { /* */
					$( '<input/>' )
						.attr( 'id', 'wpSummary' )
						.attr( 'style', 'display: none;' )
						.appendTo( $( 'body' ) );
				}
				$( '#wpSummary' ).val( data.query.moderationpreload.comment );

				/* (2) Get metadata */
				var qMetadata = {
					action: 'visualeditor',
					paction: 'metadata', // Ask for everything except parsed HTML
					page: pageName,
					uselang: mw.config.get( 'wgUserLanguage' )
				};
				var promiseMetadata = api.get( qMetadata ).then();

				/* (3) Get HTML */
				var qParseFragment = {
					action: 'visualeditor',
					paction: 'parsefragment', // Convert wikitext into HTML
					page: pageName,
					wikitext: wikitext // Just preloaded from moderationpreload API
				};
				var promiseParseFragment = api.get( qParseFragment ).then();

				/* When both (2) and (3) are completed, combine their results and return */
				return $.when( promiseMetadata, promiseParseFragment )
					.then( function ( metadata, parsefragment ) {

						var ret = metadata[0];
						var ret2 = parsefragment[0];

						if ( ret.visualeditor && ret2.visualeditor ) {
							ret.visualeditor.content = ret2.visualeditor.content;
						}

						/* Return metadata + HTML (like api.php?action=visualeditor&paction=parse) */
						return ret;

					} ).promise();

			}).promise();
		};
	});

}( mediaWiki, jQuery ) );

