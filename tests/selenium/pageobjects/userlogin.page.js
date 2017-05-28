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
	}

	login( username, password ) {
		this.open();
		this.username.setValue( username );
		this.password.setValue( password );
		this.loginButton.click();

		/* After the login: wait for
			(1) the page to be loaded
			OR
			(2) error to be shown
		*/
		var self = this;
		browser.waitUntil( function() {
			return (
				self.errMsg.isVisible()
				||
				( browser.getUrl().indexOf( 'UserLogin' ) === -1 )
			);
		} );
	}

	loginAsModerator() {
		this.login(
			browser.options.moderatorUser,
			browser.options.moderatorPassword
		);
	}

}
module.exports = new UserLoginPage();
