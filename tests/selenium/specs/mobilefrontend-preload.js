'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test ' + browser.getTestString(),
	Sections = [
		'Beginning of the article ' + browser.getTestString(),
		"== Header 1 ==\n" + browser.getTestString(),
		"== Header 2 ==\n" + browser.getTestString()
	],
	Summary = 'funny change #' + browser.getTestString();

describe( 'When user opens MobileFrontend editor and has a pending edit', function () {
	before( function () {
		var bot = browser.loginIntoNewAccount();
		return bot.getEditToken().then( () => {
			// Because our newly created test account is NOT automoderated,
			// this edit will be queued for moderation
			var Content = Sections.join( "\n\n" );
			return bot.edit( PageName, Content, Summary ).catch( function ( error ) {
				expect( error.code, 'error.code' )
					.to.equal( 'moderation-edit-queued' );
			} );
		} );
	} );

	/* Test preloading of a single section into MobileFrontend */
	for ( var idx in Sections ) {
		it( 'section #' + idx + ' should be shown', function () {
			MobileFrontend.open( PageName, idx );

			MobileFrontend.content.waitForValue();
			return expect(
				MobileFrontend.content.getValue(),
				'MobileFrontend.content[' + idx +']' )
			.to.equal( Sections[idx] );
		} );
	}

	it( 'edit summary should be shown', function () {
		/* To see the summary, we need to open "How did you improve the page?" dialog */
		MobileFrontend.content.addValue( '+' );
		MobileFrontend.nextButton.click();

		expect( MobileFrontend.summary.getValue(), 'MobileFrontend.summary' )
			.to.equal( Summary );
	} );
} );
