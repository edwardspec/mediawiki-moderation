'use strict';

const expect = require( 'chai' ).expect,
	VisualEditor = require( '../pageobjects/VisualEditor' ),
	PostEdit = require( '../pageobjects/PostEdit' );


describe( 'VisualEditor', function () {

	it( 'should save the new edit without errors', function () {
		VisualEditor.edit(
			'Test_' + Math.random(),
			Date.now() + ' ' + Math.random() + "\n"
		);

		expect( VisualEditor.error, 'VisualEditor.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {

		PostEdit.init();
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;
		expect( PostEdit.editLinkQuery.veaction, 'editLink.query.veaction' )
			.to.equal( 'edit' );
	} );
} );
