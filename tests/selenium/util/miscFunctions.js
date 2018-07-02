/**
	@file
	@brief Misc. utility functions used in our testsuite.
*/

'use strict';

var nodeUrl = require( 'url' );

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

	/** @brief Select $link by selector. Adds $link.query field to the returned $link */
	browser.getLink = function( selector ) {
		var $link = $( selector );

		Object.defineProperty( $link, 'query', {
			get: function() {
				var url = nodeUrl.parse( $link.getAttribute( 'href' ), true, true ),
					query = url.query;

				if ( !query.title ) {
					/* URL like "/wiki/Cat?action=edit" */
					var title = url.pathname.split( '/' ).pop();
					if ( title != 'index.php' ) {
						query.title = title;
					}
				}

				return query;
			}
		} );

		return $link;
	};

	/**
		@brief Enable mobile skin (from Extension:MobileFrontend) for further requests.
		@note This preference is saved as a cookie. If the cookies are deleted, skin will revert to desktop.
	*/
	browser.switchToMobileSkin = function() {
		browser.setCookie( {
			name: 'mf_useformat',
			value: 'true',

			/* domain/path are required by PhantomJS */
			domain: browser.getCookieDomain(),
			path: '/'
		} );
	};

	/** @brief Returns correct cookie domain (required by PhantomJS) */
	browser.getCookieDomain = function() {
		return browser.options.baseUrl
			.replace( 'http://', '' )
			.split('/')[0]
			.replace( /^[^.]+\./, '.' );
	};
};
