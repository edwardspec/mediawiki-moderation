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

	getWhenVisible( selector ) {
		browser.waitForVisible( selector );
		return $( selector );
	}
}
module.exports = Page;
