'use strict';

const expect = require( 'chai' ).expect,
	VisualEditor = require( '../pageobjects/VisualEditor' ),
	PostEdit = require( '../pageobjects/PostEdit' ),
	EditPage = require( '../pageobjects/edit.page' );

/*
	Title of MediaWiki page which should be edited during this test.
*/
var PageName = 'Test' + Math.random(),
	Content = Date.now() + ' ' + Math.random(),
	Summary = 'funny change #' + Math.random();

describe( 'VisualEditor', function () {

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
	} );

	it.skip( 'should show pending edit when editing a section', function () {

		/* Prepare the page with several sections */
		var Sections = [
			Date.now(),
			"== Header 1 ==\n" + Math.random(),
			"== Header 2 ==\n" + Math.random(),
			"== Header 3 ==\n" + Math.random()
		];
		EditPage.edit( PageName, Sections.join( "\n\n" ) );

		/* Test preloading of a single section into VisualEditor */
		var sectionIdx = 1;
		VisualEditor.open( PageName, sectionIdx );

		VisualEditor.content.waitForText();

		/* TODO
			expect( VisualEditor.content.getText(), 'VisualEditor.content' )
				.to.equal( Sections[sectionIdx] );
		*/
	} );
} );
