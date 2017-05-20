'use strict';
const Page = require( './page' );

/**
	@brief Represents the editing form of VisualEditor.
*/

class VisualEditor extends Page {

	/** @brief Editable element in the editor */
	get content() { return browser.element( '.ve-ce-branchNode' ); }

	/**
		@brief "Save page" button in the editor.
		@note This function waits for "Save page" button to become enabled.
	*/
	get saveButton() {
		var $submit = browser.element( 'a=Save page' );

		browser.waitUntil( function() {
			return ( $submit.getAttribute( 'aria-disabled' ) === 'false' );
		} );

		/* FIXME: when using Firefox without this browser.pause(),
			we sometimes drop out of the above-mentioned waitUntil()
			before "Save page" is actually usable (so the .click() does nothing).
			It's probably because the internal state of OOUI widget ("is disabled?")
			is updated after the aria-disabled attribute.
			There is probably a way to get rid of this pause().
		*/
		browser.pause( 500 );

		return $submit;
	}

	/** @brief "Save page" button in "Describe what you changed" dialog */
	get confirmButton() {
		browser.waitForExist( '.oo-ui-processDialog-navigation' );
		var $submit = browser.element( '.oo-ui-processDialog-navigation' ).element( 'a=Save page' );
		browser.waitUntil( function() { return $submit.isVisible(); } );

		return $submit;
	}

	open( name ) {
		super.open( name + '?veaction=edit' );

		/* Wait for VisualEditor to be completely rendered */
		browser.waitForExist( '.ve-ce-branchNode' );
		browser.waitForExist( 'a=Start editing' );

		/* Close "Switch to the source editor/Start editing" dialog */
		browser.click( 'a=Start editing' );
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
