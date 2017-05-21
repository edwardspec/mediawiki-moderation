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

	open( name ) {
		super.open( name + '?veaction=edit&vehidebetadialog=true' );
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
