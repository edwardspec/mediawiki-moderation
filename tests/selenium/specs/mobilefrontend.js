'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	CreateAccountPage = require( '../pageobjects/createaccount.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test ' + browser.getTestString(),
	Content = browser.getTestString(),
	Summary = 'funny change #' + browser.getTestString(),
	Sections = [
		'Beginning of the article ' + browser.getTestString(),
		"== Header 1 ==\n" + browser.getTestString(),
		"== Header 2 ==\n" + browser.getTestString()
	];

describe( 'MobileFrontend', function () {

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
	} );

	it( 'should show pending edit when editing a section', function () {

		/* Prepare the page with several sections */
		MobileFrontend.edit( PageName, 0, Sections.join( "\n\n" ) );

		PostEdit.init(); /* Make sure the page has loaded before we go doing refresh() and MobileFrontend.open() */
		browser.refresh(); /* Make sure old MobileFrontend form isn't still in the DOM */

		/* Test preloading of a single section into MobileFrontend */
		for ( var sectionIdx = 0; sectionIdx < Sections.length; sectionIdx ++ ) {
			MobileFrontend.open( PageName, sectionIdx );

			MobileFrontend.content.waitForValue();
			expect( MobileFrontend.content.getValue(), 'MobileFrontend.content[' + sectionIdx +']' )
					.to.equal( Sections[sectionIdx] );
		}
	} );

	it( 'shouldn\'t mention several edited sections in the summary', function () {

		// When saving the edit, MobileFrontend adds /* Section name */
		// to the edit summary. However, Moderation preloads the previous
		// summary when subsequently editing the same page.
		//
		// Here we test that this doesn't create ugly summaries
		// like "/* Section 1 */ /* Section 3 */ /* Section 1 */ fix typo".

		var sectionIdx = 1;

		/* Edit the section without touching MobileFrontend.summary field
			(whatever was preloaded into it stays unchanged) */
		MobileFrontend.edit( PageName, sectionIdx, Sections[sectionIdx], false );
		PostEdit.init(); /* Wait until complete */

		MobileFrontend.open( PageName, 0 );

		/* To see the summary, we need to open "How did you improve the page?" dialog */
		MobileFrontend.content.addValue( '+' );
		MobileFrontend.nextButton.click();

		expect( MobileFrontend.summary.getValue(), 'MobileFrontend.summary' )
			.to.not.match( /\/\*.*\*\// );
	} );

} );
