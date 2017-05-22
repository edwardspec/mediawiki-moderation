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

	/** @brief Check if current user is logged into MediaWiki */
	get isLoggedIn() {
		return browser.execute( function() {
			return mw.user.getId() !== 0;
		} );
	}
}

module.exports = Page;
