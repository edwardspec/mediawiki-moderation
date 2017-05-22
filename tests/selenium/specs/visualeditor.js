'use strict';

const expect = require( 'chai' ).expect,
	VisualEditor = require( '../pageobjects/VisualEditor' ),
	PostEdit = require( '../pageobjects/PostEdit' );

/*
	Title of MediaWiki page which should be edited during this test.
	FIXME: move this into pageobjects.
*/
var PageName = 'Test_' + Math.random();
PageName = PageName.replace( ' ', '_' ); /* Normalize */

describe( 'VisualEditor', function () {

	/* Temporarily increase Mocha timeout,
		so that we could debug this test with browser.debug() */
	this.timeout( 1230000 );

	it( 'should save the new edit without errors', function () {
		VisualEditor.edit( PageName,
			Date.now() + ' ' + Math.random() + "\n"
		);

		expect( VisualEditor.error, 'VisualEditor.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {

		/*
			FIXME: this test is not VisualEditor-specific
			(MediaWiki without VisualEditor also uses postedit notifications from Moderation).
			We should test this elsewhere.
		*/
		PostEdit.init();
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;

		expect( PostEdit.pendingIcon.isVisible(), 'pendingIcon.isVisible' ).to.be.true;
		expect( PostEdit.text )
			.to.contain( 'Success: your edit has been sent to moderation' );

		/* Notification contains the following links:
			- edit
			- signup (only for anonymous users)
		*/
		expect( PostEdit.editLink.isVisible(), 'editLink.isVisible' ).to.be.true;

		expect( PostEdit.editLinkQuery.title, 'editLink.query.title' )
			.to.equal( PageName );
		expect( PostEdit.editLinkQuery.veaction, 'editLink.query.veaction' )
			.to.equal( 'edit' );

		if ( !this.isLoggedIn ) {
			expect( PostEdit.signupLink.isVisible(), 'signupLink.isVisible' ).to.be.true;
			expect(
				PostEdit.signupLink.getAttribute( 'href' ).split( '/' ).pop(),
				'signupLink.title'
			).to.equal( 'Special:CreateAccount' );
		}

		/* Default postedit notification of MediaWiki is removed after 3.5 seconds
			(because it's not important whether the user reads it or not).

			Make sure that Moderation has countered this behavior,
			because its message contains links that user might want to follow,
			plus information about Moderation for first-time editors.
		*/
		PostEdit.waitUsualFadeTime();
		expect( PostEdit.notification.isVisible(), 'notification.isVisible' ).to.be.true;

		/* Clicking on notification should remove it */
		PostEdit.notification.click();
		PostEdit.notification.waitForExist( 500, true ); /* Wait for it to vanish */
	} );
} );
