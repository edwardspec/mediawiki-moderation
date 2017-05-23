'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of VisualEditor.
*/

class VisualEditor extends Page {

	/** @brief Editable element in the editor */
	get content() { return this.getWhenVisible( '.ve-ce-documentNode' ); }

	/** @brief "Save page" button in the editor */
	get saveButton() {
		var $submit = this.getWhenVisible( 'a=Save page' );

		browser.waitUntil( function() {
			return ( $submit.getAttribute( 'aria-disabled' ) === 'false' );
		} );

		return $submit;
	}

	/** @brief "Save page" button in "Describe what you changed" dialog */
	get confirmButton() {
		return this.getWhenVisible( '//*[@class="oo-ui-processDialog-navigation"]//a[node() = "Save page"]' );
	}

	/**
		@brief Text in "Something went wrong" dialog.
	*/
	get errMsg() {
		return $( '.oo-ui-processDialog-error' );
	}

	/**
		@returns Displayed error (if any).
		@retval null No error.
	*/
	get error() {
		return this.errMsg.isVisible() ? this.errMsg.getText() : null;
	}

	open( name ) {
		super.open( name + '?veaction=edit&vehidebetadialog=true' );
	}

	/**
		@brief Edit the page in VisualEditor.
		@param name Page title, e.g. "List of Linux distributions".
		@param content Page content (arbitrary text).
	*/
	edit( name, content ) {
		this.open( name );

		/*
			FIXME: sometimes this .addValue() is executed before
			installation of the handler that enables saveButton.
			Need a better waiting criteria here.
		*/
		this.content.addValue( content );

		this.saveButton.click();
		this.confirmButton.click();

		/* After the edit: wait for
			(1) the page to be loaded
			OR
			(2) VisualEditor error to be shown
		*/
		var self = this;
		browser.waitUntil( function() {
			return (
				self.errMsg.isVisible()
				||
				( browser.getUrl().indexOf( 'veaction=edit' ) === -1 )
			);
		} );
	}
}

module.exports = new VisualEditor();
