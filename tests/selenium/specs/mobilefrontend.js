'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	CreateAccountPage = require( '../pageobjects/createaccount.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random(),
	Summary = 'funny change #' + Math.random();

describe( 'MobileFrontend', function () {

	before( function() {
		if ( browser.options.is1_23 ) {
			/* MobileFrontend editor in 1.23 requires login */
			CreateAccountPage.createAccount( 'TestUser' + Math.random(), '123456' );
		}
	} );

	it( 'should save the new edit without errors', function () {
		MobileFrontend.edit( PageName, 0, Content, Summary );

		expect( MobileFrontend.error, 'MobileFrontend.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {
		PostEdit.init();

		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
		expect( PostEdit.editLink.query.action, 'editLink.query.action' )
			.to.equal( 'edit' );
	} );

	it( 'should show pending edit when opening the edit form', function () {
		browser.refresh(); /* Make sure old MobileFrontend form isn't still in the DOM */
		MobileFrontend.open( PageName, 0 );

		MobileFrontend.content.waitForValue();
		expect( MobileFrontend.content.getValue(), 'MobileFrontend.content' )
			.to.equal( Content );
	} );

	it( 'should suggest summary of the pending edit', function () {

		/* To see the summary, we need to open "How did you improve the page?" dialog */
		MobileFrontend.content.addValue( '+' );
		MobileFrontend.nextButton.click();

		expect( MobileFrontend.summary.getValue(), 'MobileFrontend.summary' )
			.to.equal( Summary );

		/* Avoid "[...] data you have entered may not be saved" dialog */
		MobileFrontend.disableMWOnUnload();
	} );

	it( 'should show pending edit when editing a section', function () {

		/* Prepare the page with several sections */
		var Sections = [
			'Beginning of the article ' + Date.now(),
			"== Header 1 ==\n" + Math.random(),
			"== Header 2 ==\n" + Math.random()
		];
		MobileFrontend.edit( PageName, 0, Sections.join( "\n\n" ) );

		browser.refresh(); /* Make sure old MobileFrontend form isn't still in the DOM */

		/* Test preloading of a single section into MobileFrontend */
		for ( var sectionIdx = 0; sectionIdx < Sections.length; sectionIdx ++ ) {
			MobileFrontend.open( PageName, sectionIdx );

			MobileFrontend.content.waitForValue();
			expect( MobileFrontend.content.getValue(), 'MobileFrontend.content[' + sectionIdx +']' )
					.to.equal( Sections[sectionIdx] );
		}
	} );
} );
