'use strict';
var assert = require( 'assert' );

describe( 'VisualEditor', function () {

	/* Temporarily increase Mocha timeout,
		so that we could debug this test with browser.debug() */
	this.timeout( 1230000 );

	/* TODO: use Page Object pattern
		to move the methods "how to use VisualEditor" into a separate file.
		See http://webdriver.io/guide/testrunner/pageobjects.html for details.
	*/

	it( 'should save the new edit without errors', function () {

		browser.url( '/wiki/Test?veaction=edit' );

		/* Wait for VisualEditor to be completely rendered */
		browser.waitForExist( '.ve-ce-branchNode' );
		browser.waitForExist( 'a=Start editing' );

		/* Close "Switch to the source editor/Start editing" dialog */
		browser.click( 'a=Start editing' );

		/* Type something random in the edit form of VisualEditor */
		browser.element( '.ve-ce-branchNode' ).addValue( Date.now() + ' ' + Math.random() + "\n" );

		/* Save the page */
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

		$submit.click();

		/* Click "Save page" in "Describe what you changed" dialog */
		browser.waitForExist( '.oo-ui-processDialog-navigation' );
		$submit = browser.element( '.oo-ui-processDialog-navigation' ).element( 'a=Save page' );
		browser.waitUntil( function() { return $submit.isVisible(); } );
		$submit.click();

		/* After the edit: wait for the page to be loaded */
		browser.waitForExist( '.postedit' );

		/* Analyze the postedit message */

		/* FIXME: use an assert library with more detailed error output, e.g. Chai */
		assert( browser.isVisible( '.postedit' ) );
		assert( browser.getText( '.postedit' ).match( /Success: your edit has been sent to moderation/ ) );
	} );
} );

