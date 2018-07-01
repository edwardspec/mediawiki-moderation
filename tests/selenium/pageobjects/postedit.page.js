'use strict';
const Page = require( './page' );

/**
	@brief Represents the page visited immediately after editing.
	Detects postedit notifications (created by mw.notify from MediaWiki core).
*/

class PostEdit extends Page {
	get notification() { return $( '.postedit, .mw-notification' ); }
	get pendingIcon() { return $( '#pending-review' ); }
	get editLink() { return browser.getLink( 'a=your version of this page' ); }
	get signupLink() { return browser.getLink( 'a=sign up' ); }

	get pageContent() { return $( '#mw-content-text' ); }

	get text() { return this.notification.getText(); }

	/** Default time (in ms.) until the postedit notification is usually removed.
		Must be the same as in [mediawiki.action.view.postEdit.js] of MediaWiki core. */
	get usualFadeTime() { return 3500; }

	/** @brief Wait for postedit notification to appear */
	init() {
		this.notification.waitForExist();
		this.inittime = new Date().getTime(); /* Used in waitUsualFadeTime() */
	}

	/**
		@brief Pause until the time when MediaWiki should have removed this notification.
	*/
	waitUsualFadeTime() {
		browser.pause( this.usualFadeTime - ( new Date().getTime() - this.inittime ) );
	}
}

module.exports = new PostEdit();
