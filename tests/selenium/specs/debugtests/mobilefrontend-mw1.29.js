/*
	This test reproduces the bug in Extension:MobileFrontend for MediaWiki 1.29,
	which interferes with the testsuite and is fixed by https://gerrit.wikimedia.org/r/#/c/363012/
*/

'use strict';

const MobileFrontend = require( '../../pageobjects/mobilefrontend.page' );


describe( 'MobileFrontend PageObject', function () {

	this.timeout( 123000000 );

	it( 'should perform sequential .edit() calls without errors', function () {
		var PageName = 'Test' + Math.random();

		MobileFrontend.edit( PageName, 0, "Before\n\n== Section 1 ==\nText1\n\n== Section 2 ==\nText2" );
		MobileFrontend.edit( PageName, 1, "== NewSection 1 ==\nNewText1", false );

		/*
			In MediaWiki 1.28 and earlier this works perfectly.
			In MediaWiki 1.29, the .edit() above will fail by timeout in submitAndWait(),
			because submitAndWait() waits for us to leave the edit form, which doesn't happen.

			Solution is to apply https://gerrit.wikimedia.org/r/#/c/363012/
			to Extension:MobileFrontend in 1.29.
		*/
	} );
} );


