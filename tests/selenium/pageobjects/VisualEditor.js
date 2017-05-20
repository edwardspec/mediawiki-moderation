'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of VisualEditor.
*/

class VisualEditor extends Page {

	/** @brief Editable element in the editor */
	get content() { return $( '.ve-ce-documentNode' ); }

	/** @brief "Save page" button in the editor. */
	get saveButtonEvenDisabled() { return $( 'a=Save page' ); }

	/** @brief "Describe what you changed" dialog */
	get confirmDialog() { return $( '.oo-ui-processDialog-navigation' ); }

	/**
		@brief Returns "Save page" button when it becomes enabled.
	*/
	get saveButton() {
		var $submit = this.saveButtonEvenDisabled;

		browser.waitUntil( function() {
			return ( $submit.getAttribute( 'aria-disabled' ) === 'false' );
		} );

		return $submit;
	}

	/** @brief "Save page" button in "Describe what you changed" dialog */
	get confirmButton() {
		this.confirmDialog.waitForExist();

		var $submit = this.confirmDialog.element( 'a=Save page' );
		$submit.waitForVisible();
		return $submit;
	}

	open( name ) {
		super.open( name + '?veaction=edit&vehidebetadialog=true' );

		/* Wait for VisualEditor to be completely rendered */
		this.content.waitForExist();
		this.saveButtonEvenDisabled.waitForExist();
	}

	edit( name, content ) {
		this.open( name );
		this.content.addValue( content );
		this.saveButton.click();
		this.confirmButton.click();

		/* After the edit: wait for the page to be loaded */
		browser.waitForExist( '.postedit' );
	}
}

module.exports = new VisualEditor();
