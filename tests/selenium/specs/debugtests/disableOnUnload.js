/**
	@brief Test of the testsuite itself: checks disableMWOnUnload() method in [pageobjects/page.js].
	Makes sure that VisualEditor.edit() works reliably (correctly waits for needed UI elements, etc.).

	This file doesn't test the Moderation.
		See [specs/*.js] for Moderation-related tests.
*/

'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../../pageobjects/mobilefrontend.page' );

var PageName = 'Test' + Math.random();

describe( 'Page.disableMWOnUnload()', function () {

	this.timeout( 120000 );

	for ( var i = 1; i <= 10; i ++ ) {
		it( 'should suppress "Do you really want to leave" alert: attempt ' + i, function () {

			MobileFrontend.open( PageName, 0 );
			MobileFrontend.content.addValue( '+' );

			MobileFrontend.disableMWOnUnload();

			browser.refresh();

			/* There shouldn't be an alert "Do you really want to leave this page" */
			expect( function() {
				browser.alertText(); /* Throws exception when there is no alert */
			} ).to.throw();
		} );
	}
} );
