'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random(),
	Summary = 'funny change #' + Math.random();

describe( 'MobileFrontend', function () {

	this.timeout( 12300000 );

	it( 'should save the new edit without errors', function () {

		var sectionIdx = 0;

		MobileFrontend.edit( PageName, sectionIdx, Content, Summary );
		expect( MobileFrontend.error, 'MobileFrontend.error' ).to.be.null;


		//browser.debug();
	} );

} );
