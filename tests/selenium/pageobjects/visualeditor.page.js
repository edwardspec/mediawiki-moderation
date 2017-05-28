'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of VisualEditor.
*/

class VisualEditor extends Page {

	/** @brief Editable element in the editor */
	get content() {
		/* Until the Surface is focused, it won't accept addInput() properly */
		browser.waitForExist( '.ve-ce-surface-focused' );
		return this.getWhenVisible( '.ve-ce-documentNode' );
	}

	/** @brief "Save page" button in the editor */
	get saveButton() {
		var $submit = this.getWhenVisible( '.ve-ui-toolbar-saveButton a' );

		browser.waitUntil( function() {
			return ( $submit.getAttribute( 'aria-disabled' ) === 'false' );
		} );

		return $submit;
	}

	/** @brief "Save page" button in "Describe what you changed" dialog */
	get confirmButton() {
		return this.getWhenVisible( '.oo-ui-processDialog-navigation a[accesskey="s"]' );
	}

	/** @brief "Summary" field in "Describe what you changed" dialog */
	get summary() {
		return this.getWhenVisible( '.ve-ui-mwSaveDialog-summary textarea' );
	}

	get welcomeStartButton() {
		return this.getWhenVisible( 'a=Start editing' );
	}

	get editTab() {
		return this.getWhenVisible( '#ca-ve-edit a' );
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

	/**
		@brief Open VisualEditor for article "name".
	*/
	open( name ) {
		super.open( name + '?veaction=edit&vehidebetadialog=true' );
	}

	/**
		@brief Open VisualEditor for the already opened article via the UI.
	*/
	openSwitch() {
		this.editTab.click();
		this.welcomeStartButton.click();
	}

	/**
		@brief Edit the page in VisualEditor.
		@param name Page title, e.g. "List of Linux distributions".
		@param content Page content (arbitrary text).
		@param summary Edit comment (e.g. "fixed typo").
	*/
	edit( name, content, summary = '' ) {
		this.open( name );
		this.content.addValue( content );
		this.saveButton.click();

		this.summary.addValue( summary );
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
