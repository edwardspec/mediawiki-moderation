'use strict';

const expect = require( 'chai' ).expect,
	EditPage = require( '../pageobjects/edit.page' ),
	MobileFrontend = require( '../pageobjects/mobilefrontend.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	CreateAccountPage = require( '../pageobjects/createaccount.page' ),
	LogoutPage = require( '../pageobjects/logout.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	subtests = [
		'desktop',
		'MobileFrontend'
	];

/* Run the same tests for desktop and mobile view */
subtests.forEach( function( subTest ) {

describe( 'Postedit notification (' + subTest + ')', function () {

	var doTestEdit;
	switch ( subTest ) {
		case 'desktop':
			doTestEdit = function() {
				EditPage.edit(
					PageName,
					Date.now() + ' ' + Math.random() + "\n"
				);
			};
			break;

		case 'MobileFrontend':
			doTestEdit = function() {
				MobileFrontend.edit(
					PageName,
					0,
					Date.now() + ' ' + Math.random() + "\n"
				);
			};
	}

	before( function() {
		/* Make sure we are logged in */
		CreateAccountPage.createAccount( 'TestUser' + Math.random(), '123456' );

		doTestEdit();
		PostEdit.init();
	} );

	after( function() {
		LogoutPage.logout();
	} );

	it( 'should be visible', function () {
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
	} );

	it( 'shouldn\'t contain default text (for wikis without Moderation)', function () {
		/* i18n messages from MediaWiki core:
			postedit-confirmation-created
			postedit-confirmation-saved
		*/
		expect( PostEdit.text ).to.not.contain( 'The page has been created' );
		expect( PostEdit.text ).to.not.contain( 'Your edit was saved' );
	} );

	it ( 'should contain "Pending Review" icon', function() {
		expect( PostEdit.pendingIcon.isVisible(), 'pendingIcon.isVisible' ).to.be.true;
	} );

	it ( 'should say "your edit has been sent to moderation"', function() {
		expect( PostEdit.text )
			.to.contain( 'Success: your edit has been sent to moderation' );
	} );

	it ( 'should contain "continue editing" link', function() {

		expect( PostEdit.editLink.isVisible(), 'editLink.isVisible' ).to.be.true;

		expect( PostEdit.editLink.query.title, 'editLink.query.title' )
			.to.equal( PageName );
		expect( PostEdit.editLink.query.action, 'editLink.query.action' )
			.to.equal( 'edit' );
	} );

	it ( 'shouldn\'t contain "sign up" link if the user is logged in', function() {

		expect( PostEdit.signupLink.isVisible(), 'signupLink.isVisible' ).to.be.false;
	} );

	it ( 'shouldn\'t disappear after 3.5 seconds', function() {
		/* Default postedit notification of MediaWiki is removed after 3.5 seconds
			(because it's not important whether the user reads it or not).

			Make sure that Moderation has countered this behavior,
			because its message contains links that user might want to follow,
			plus information about Moderation for first-time editors.
		*/
		PostEdit.waitUsualFadeTime();
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
	} );

	it ( 'should be removed when you click on it', function() {
		/* Clicking on notification should remove it */
		PostEdit.notification.click();
		PostEdit.notification.waitForVisible( 500, true ); /* Wait for it to vanish */
	} );

	it ( 'should contain "sign up" link if the user is anonymous', function() {

		if ( browser.options.is1_23 && subTest == 'MobileFrontend' ) {
			console.log( '[SKIP] Test skipped: MobileFrontend in MediaWiki 1.23 requires login to edit.' );
			this.skip();
		}

		LogoutPage.logout();

		doTestEdit();
		PostEdit.init();

		expect( PostEdit.signupLink.isVisible(), 'signupLink.isVisible' ).to.be.true;
		expect(
			PostEdit.signupLink.query.title,
			'signupLink.query.title'
		).to.equal( 'Special:CreateAccount' );
	} );

} ); /* describe( ..., function { */


} ); /* .forEach( function( subTest ) { */
