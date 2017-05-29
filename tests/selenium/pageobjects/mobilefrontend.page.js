'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of MobileFrontend.
*/

class MobileFrontend extends Page {

	/** @brief Editable element in the editor */
	get content() { return $( '#wikitext-editor,.wikitext-editor' ); }

	/** @brief Button to close "You are not logged in" dialog */
	get editAnonymouslyButton() { return $( 'a=Edit without logging in' ); }

	/** "Next" button (navigates from textarea screen to "Enter edit summary" screen) */
	get nextButton() { return $( '.continue' ); }

	/** "Save" button on the "Enter edit summary" screen */
	get saveButton() { return $( '.submit' ); }

	/** @brief "Summary" field in "Describe what you changed" dialog */
	get summary() { return this.getWhenVisible( '.summary' ); }

	/**
		@brief Text in "Something went wrong" dialog.
	*/
	get errMsg() {
		return $( '.mw-notification-type-error' );
	}

	/**
		@returns Displayed error (if any).
		@retval null No error.
	*/
	get error() {
		return this.errMsg.isVisible() ? this.errMsg.getText() : null;
	}

	/**
		@brief Open MobileFrontend editor for article "name".
	*/
	open( name, section = 0 ) {
		super.open( name + '?mobileaction=toggle_view_mobile&action=edit&section=' + section );
		this.content.waitForExist();

		if ( this.editAnonymouslyButton.isExisting() ) {
			this.editAnonymouslyButton.click();
		}

		this.content.waitForVisible();
	}

	/**
		@brief Edit the page in MobileFrontend.
		@param name Page title, e.g. "List of Linux distributions".
		@param section Section number, e.g. 0.
		@param content Page content (arbitrary text).
		@param summary Edit comment (e.g. "fixed typo").
	*/
	edit( name, section, content, summary = '' ) {
		this.open( name, section );
		this.content.setValue( content );
		this.nextButton.click();

		if ( summary !== false ) {
			this.summary.setValue( summary );
		}

		var expectingAlert = !this.isExistingPage;
		this.saveButton.click();

		/* Close "Are you sure you want to create a new page?" dialog */
		if ( expectingAlert ) {
			browser.waitUntil( function() {
				try {
					return browser.alertText() !== '';
				} catch ( e ) {
					return false;
				}
			}, 1000 );
			browser.alertAccept();
		}

		/* After the edit: wait for
			(1) the page to be loaded
			OR
			(2) MobileFrontend error to be shown
		*/
		var self = this;
		browser.waitUntil( function() {
			return (
				self.errMsg.isVisible()
				||
				( browser.getUrl().indexOf( '#/editor/' ) === -1 )
			);
		} );
	}
}

module.exports = new MobileFrontend();
