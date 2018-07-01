/**
	@file
	@brief Misc. utility functions used in our testsuite.
*/

'use strict';

/**
	@brief Runs from after() section of wdio.conf.js.
*/
module.exports.afterTestHook = function( browser ) {
	/* Latest Firefox displays "Do you really want to leave" dialog
		even when WebDriver is being closed. Suppress that.
	*/
	browser.execute( function() {
		window.onbeforeunload = null;
		if ( window.$ ) {
			$( window ).off( 'beforeunload pageshow' ); /* See [mediawiki.confirmCloseWindow.js] in MediaWiki core */
		}
	} );
};

/**
	@brief Runs from before() section of wdio.conf.js.
*/
module.exports.install = function( browser ) {

	/**
		@brief Make browser.url() ignore "Are you sure you want to leave this page?" alerts.
	*/
	var oldUrlFunc = browser.url.bind( browser );
	browser.url = function( url ) {
		/* Try to suppress beforeunload events.
			This doesn't work reliably in IE11, so there is a fallback alertAccept() below.
			We can't remove this browser.execute(), because Safari doesn't support alertAccept().
		*/
		browser.execute( function() {
			window.onbeforeunload = null;
			if ( window.$ ) {
				$( window ).off( 'beforeunload pageshow' ); /* See [mediawiki.confirmCloseWindow.js] in MediaWiki core */
			}
		} );

		var ret = oldUrlFunc( url );

		try {
			/* Fallback for IE11.
				Not supported by SafariDriver, see browser.execute() above. */
			browser.alertAccept();
		} catch( e ) {}

		return ret;
	};
};
