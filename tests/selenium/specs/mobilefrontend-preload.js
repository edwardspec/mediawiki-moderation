'use strict';

const expect = require( 'chai' ).expect,
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test ' + browser.getTestString(),
	Content = browser.getTestString(),
	Summary = 'funny change #' + browser.getTestString(),
	bot;

describe( 'When user opens MobileFrontend editor and has a pending edit', function () {
	before( function () {
		bot = browser.loginIntoNewAccount();
		return bot.getEditToken().then( () => {
			// Because our newly created test account is NOT automoderated,
			// this edit will be queued for moderation
			return bot.edit( PageName, Content, Summary ).catch( function ( error ) {
				expect( error.code, 'error.code' )
					.to.equal( 'moderation-edit-queued' );
			} );
		} );
	} );

	it( 'pending edit should be shown in the edit form', function () {
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
} );
