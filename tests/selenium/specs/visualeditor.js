'use strict';

const expect = require( 'chai' ).expect,
	VisualEditor = require( '../pageobjects/VisualEditor' ),
	PostEdit = require( '../pageobjects/PostEdit' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random();

describe( 'VisualEditor', function () {

	it( 'should save the new edit without errors', function () {
		VisualEditor.edit( PageName, Content );

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
} );
