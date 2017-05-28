'use strict';

const expect = require( 'chai' ).expect,
	VisualEditor = require( '../pageobjects/visualeditor.page' ),
	PostEdit = require( '../pageobjects/postedit.page' ),
	EditPage = require( '../pageobjects/edit.page' ),
	UserLoginPage = require( '../pageobjects/userlogin.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random(),
	Summary = 'funny change #' + Math.random();

describe( 'VisualEditor', function () {

	before( function() {
		if ( browser.options.is1_23 ) {
			console.log( '[SKIP] Test skipped: MediaWiki 1.23 doesn\'t support VisualEditor.' );
			this.skip();
		}
	} );

	it( 'should save the new edit without errors', function () {
		VisualEditor.edit( PageName, Content, Summary );

		expect( VisualEditor.error, 'VisualEditor.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {
		PostEdit.init();

		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
		expect( PostEdit.editLink.query.veaction, 'editLink.query.veaction' )
			.to.equal( 'edit' );
	} );

	it( 'should show pending edit when opening the edit form', function () {
		browser.refresh(); /* Make sure old VisualEditor form isn't still in the DOM */
		VisualEditor.open( PageName );

		VisualEditor.content.waitForText();
		expect( VisualEditor.content.getText(), 'VisualEditor.content' )
			.to.equal( Content );
	} );

	it( 'should suggest summary of the pending edit', function () {

		/* To see the summary, we need to open "Describe what you changed" dialog */
		VisualEditor.content.addValue( '+' );
		VisualEditor.saveButton.click();

		expect( VisualEditor.summary.getValue(), 'VisualEditor.summary' )
			.to.equal( Summary );

		/* Avoid "[...] data you have entered may not be saved" dialog */
		VisualEditor.disableMWOnUnload();
	} );

	it( 'should show pending edit when switching from "Edit source"', function () {
		/* Emulate the switch from "Edit source" to VisualEditor */
		EditPage.open( PageName );
		VisualEditor.openSwitch();

		VisualEditor.content.waitForText();
		expect( VisualEditor.content.getText(), 'VisualEditor.content' )
			.to.equal( Content );
	} );


	/* TODO: we need to test .showParsed notification (see [ajaxhook.ve.js]),
		which is only used when editing an existing article.

		We must login into an automoderated account to create an article.
	*/

	it( 'shouldn\'t show empty page after editing the existing article', function () {
		/* First we need an existing article. Because of the moderation,
			such article can only be created by an automoderated user.
		*/
		UserLoginPage.loginAsModerator();
		EditPage.edit( PageName, 'Initial content: ' + Content );

		browser.deleteCookie(); /* Logout */

		/* Now that we have an existing page, edit it again as anonymous user */
		VisualEditor.edit( PageName, 'Suggested content: ' + Content );
		PostEdit.init();

		expect( PostEdit.pageContent.getText(), 'PostEdit.pageContent' )
			.to.not.equal( '' );
	} );

} );
