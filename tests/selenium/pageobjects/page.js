/**
	@brief Most basic class for "Page Object" pattern.
	@see http://webdriver.io/guide/testrunner/pageobjects.html
*/

'use strict';

class Page {
	constructor() {
		this.title = 'My Page';
	}
	open( path ) {
		browser.url( '/wiki/' + path );
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
