'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../../pageobjects/mobilefrontend.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random();

describe( 'MobileFrontend PageObject', function () {

	this.timeout( 123000000 );

	it( 'should perform sequential .edit() calls without errors', function () {
		MobileFrontend.edit( PageName, 0, "Before\n\n== Section 1 ==\nText1\n\n== Section 2 ==\nText2" );

		//// The following doesn't help:
		// browser.refresh();
		// require( '../../pageobjects/blank.page' ).open();

		//browser.mf = MobileFrontend; browser.debug();

		/*
			FIXME:
			in MediaWiki 1.29, after the following .edit successfully saves the new change,
			MobileFrontend for some reason redirects back to the edit form.

			This happens both with or without the Moderation,
			both when logged in or anonymous,
			both when PageName exists before the test (with the same number of sections)
				or when PageName is created by the test.

			This is an odd behavior in MobileFrontend itself,
			what we need to find is a workaround, so that our tests would work in 1.29.
		*/

		MobileFrontend.edit( PageName, 1, "== NewSection 1 ==\nNewText1", false );

		/*
			In MediaWiki 1.28 and earlier this works perfectly.
			In MediaWiki 1.29, the .edit() above will fail by timeout in submitAndWait(),
			because submitAndWait() waits for us to leave the edit form, which doesn't happen.
		*/
	} );
} );


