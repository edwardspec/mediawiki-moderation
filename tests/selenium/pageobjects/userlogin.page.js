'use strict';
const Page = require( './page' );

class UserLoginPage extends Page {

	get username() { return browser.element( '#wpName1' ); }
	get password() { return browser.element( '#wpPassword1' ); }
	get loginButton() { return browser.element( '#wpLoginAttempt' ); }
	get userPage() { return browser.element( '#pt-userpage' ); }
	get errMsg() { return $( '.error' ); }

	open() {
		super.open( 'Special:UserLogin' );
		this.username.waitForVisible(); /* In Edge, browser.url() may return before DOM is ready */
	}

	login( username, password ) {
		this.open();
		this.username.setValue( username );
		this.password.setValue( password );
		this.submitAndWait( this.loginButton );
	}
}
module.exports = new UserLoginPage();
