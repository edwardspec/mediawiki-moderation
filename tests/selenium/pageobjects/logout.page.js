'use strict';
const Page = require( './page' );

class LogoutPage extends Page {

	logout() {
		if ( browser.desiredCapabilities.browserName == 'safari' ) {
			/* With SafariDriver, HttpOnly cookies can't be deleted by deleteCookie() */
			super.open( 'Special:UserLogout' );
		}
		else {
			/* Quick logout: forget the session cookie */
			browser.deleteCookie();
		}
	}

}
module.exports = new LogoutPage();
