/**
	@brief Most basic class for "Page Object" pattern.
	@see http://webdriver.io/guide/testrunner/pageobjects.html
*/

'use strict';

const nodeUrl = require( 'url' );

class Page {
	constructor() {
		this.title = 'My Page';
	}
	open( path ) {
		browser.url( '/wiki/' + path );
	}

	/**
		@brief Enable mobile skin (from Extension:MobileFrontend) for further requests.
		@note This preference is saved as a cookie. If the cookies are deleted, skin will revert to desktop.
	*/
	switchToMobileSkin() {
		browser.setCookie( { name: 'mf_useformat', value: 'true' } );
	}

	getWhenExists( selector ) {
		browser.waitForExist( selector );
		return $( selector );
	}

	getWhenVisible( selector ) {
		browser.waitForVisible( selector );
		return $( selector );
	}

	/**
		@brief Error message element.
		Override in the child class if needed.
	*/
	get errMsg() { return $( '.error' ); }

	/**
		@returns Displayed error (if any).
		@retval null No error.
	*/
	get error() {
		if ( !this.errMsg.isVisible() ) {
			return null;
		}

		return this.errMsg.getText();
	}

	/** @brief Check if current user is logged into MediaWiki */
	get isLoggedIn() {
		return browser.execute( function() {
			return mw.user.getId() !== 0;
		} ).value;
	}

	/**
		@brief Select $link by selector. Adds $link.query field to the returned $link.
	*/
	getLink( selector ) {
		var $link = $( selector );

		/* Note: we can't use browser.execute() to run mw.Uri(...).query,
			because in MediaWiki 1.23 it doesn't add 'title' parameter
			for URLs like "/wiki/Cat?action=edit" */

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
	}

	/**
		@brief Click Submit button and wait for the result to be shown.
	*/
	submitAndWait( $submitElem ) {
		var currentUrl = browser.getUrl(),
			self = this;

		$submitElem.click();

		browser.waitUntil( function() {
			if ( browser.getUrl() != currentUrl ) {
				return browser.execute( function() {
					return document.readyState === 'complete';
				} );
			}

			/* If URL hasn't changed, it's possible that an error was displayed.
				It's also possible this is a FormSpecialPage
				and it displayed "Success" message on the same URL. */
			return (
				self.errMsg.isVisible()
				||
				$( '#mw-returnto' ).isExisting()
			);
		} );
	}
}

module.exports = Page;
