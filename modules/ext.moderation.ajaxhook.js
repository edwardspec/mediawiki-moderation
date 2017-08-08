/*
	This code handles VisualEditor and other API-based JavaScript editors.

	A. Why is this needed?

	When Moderation aborts PageContentSave hook, api.php?action=edit returns error,
	so VisualEditor panics "The edit wasn't saved! Unknown error!".

	B. Why such an unusual implementation?

	It turns out, neither API (in MediaWiki core) nor VisualEditor provide
	any means of altering the API response via a PHP hook.

	C. How does this solution work?

	We intercept all AJAX responses (sic!), and if we determine that this
	response is "Unknown error: moderation-edit-queued" (see above),
	we FAKE THE AJAX RESPONSE by replacing it with "edit saved successfully!"

	NOTE: JavaScript editors also need "preloading" support.
	See [visualeditor/preload.ve.js], [mobilefrontend/preload.mf.js] for details.
*/

( function ( mw, $ ) {
	'use strict';

	mw.moderation = mw.moderation || {};
	mw.moderation.ajaxhook = mw.moderation.ajaxhook || {};

	/*
		Intercept all API calls made via mw.Api(), rewrite the response if needed.
	*/
	mw.moderation.trackAjax = function( apiObj ) {
		var oldFunc = apiObj.prototype.ajax;
		apiObj.prototype.ajax = function( parameters, ajaxOptions ) {
			var lastQuery = parameters;

			ajaxOptions.dataFilter = function( rawData, dataType ) {
				if ( dataType != 'json' ) {
					return rawData;
				}

				var newRet = rewriteAjaxResponse( lastQuery, JSON.parse( rawData ) );
				if ( !newRet ) {
					return rawData;
				}

				return JSON.stringify( newRet );
			};

			return oldFunc.apply( this, arguments );
		}
	};

	mw.loader.using( 'mediawiki.api', function() {
		mw.moderation.trackAjax( mw.Api );
	} );

	/* Make an API response for action=edit.
		This affects most API-based JavaScript editors, including MobileFrontend.
	*/
	mw.moderation.ajaxhook['edit'] = function() {
		var ret = {},
			timestamp = "2016-12-08T12:33:23Z"; /* TODO: recalculate */

		ret.edit = {
			"result": "Success", /* Uppercase */
			"pageid": mw.config.get( 'wgArticleId' ),
			"title": mw.config.get( 'wgTitle' ),
			"contentmodel": mw.config.get( 'wgPageContentModel' ),
			"oldrevid": mw.config.get( 'wgRevisionId' ),
			"newrevid": 0, /* NOTE: change if this causes problems in any API-based editors */
			"newtimestamp": timestamp
		};

		if ( ret.edit.pageid ) {
			ret.edit.new = "";
		}

		mw.hook( 'moderation.ajaxhook.edit' ).fire();
		return ret;
	}

	/**
		@brief Main logic of AJAX response rewriting.
		@param query API request, e.g. { action: "edit", "title": "Testpage1", ... }.
		@param ret API response, e.g. { edit: { result: "success", ... } }.
		@returns New API response (if overwrite is needed) or false (if no need to overwrite).
	*/
	function rewriteAjaxResponse( query, ret ) {

		/* Check whether we need to overwrite this AJAX response or not */
		if ( !ret.error ) {
			return false; /* Nothing to overwrite */
		}

		if (
			ret.error.code == 'moderation-edit-queued' /* MW 1.29+ */
			||
			ret.error.info.indexOf( 'moderation-edit-queued' ) != -1 /* MW 1.28 and older */
		) {
			/* Set cookie for [ext.moderation.notify.js].
				It means "edit was just queued for moderation".
			*/
			$.cookie( 'modqueued', '1', { path: '/' } );

			/*
				Error from api.php?action=edit: edit was queued for moderation.
				We must replace this response with "Edit saved successfully!".
			*/
			var func = mw.moderation.ajaxhook[query.action];
			if ( !func ) {
				/* Nothing to overwrite */
			}

			return func(); /* Fake a successful API response */
		}

		return false; /* Nothing to overwrite */
	};

}( mediaWiki, jQuery ) );
