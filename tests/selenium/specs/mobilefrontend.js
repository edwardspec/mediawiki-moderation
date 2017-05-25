'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random(),
	Summary = 'funny change #' + Math.random();

describe( 'MobileFrontend', function () {

	this.timeout( 12300000 );

	var sectionIdx = 0;

	it( 'should save the new edit without errors', function () {
		MobileFrontend.edit( PageName, sectionIdx, Content, Summary );

		expect( MobileFrontend.error, 'MobileFrontend.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {
		PostEdit.init();

		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
		expect( PostEdit.editLink.query.action, 'editLink.query.action' )
			.to.equal( 'edit' );
	} );


} );
