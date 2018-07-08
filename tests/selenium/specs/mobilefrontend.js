'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	BlankPage = require( 'wdio-mediawiki/BlankPage' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test ' + browser.getTestString(),
	Sections = [
		'Beginning of the article ' + browser.getTestString(),
		"== Header 1 ==\n" + browser.getTestString(),
		"== Header 2 ==\n" + browser.getTestString()
	];

describe( 'MobileFrontend', function () {

	before( function () {
		/* Prepare the page with several sections */
		MobileFrontend.edit( PageName, 0, Sections.join( "\n\n" ) );

		PostEdit.init(); /* Wait for the page to be loaded before we go with MobileFrontend.open() */
		BlankPage.open(); /* Make sure old MobileFrontend form isn't still in the DOM */
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
