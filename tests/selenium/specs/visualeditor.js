'use strict';

const expect = require('chai').expect,
	VisualEditor = require( '../pageobjects/VisualEditor' );

describe( 'VisualEditor', function () {

	/* Temporarily increase Mocha timeout,
		so that we could debug this test with browser.debug() */
	this.timeout( 1230000 );

	it( 'should save the new edit without errors', function () {
		VisualEditor.edit( 'Test',
			Date.now() + ' ' + Math.random() + "\n"
		);

		expect( VisualEditor.error, 'VisualEditor.error' ).to.be.null;
	} );

	it( 'should cause postedit notification "Success: your edit has been sent to moderation"', function () {
		browser.waitForVisible( '.postedit' );
		var $notif = $( '.postedit' );

		expect( $notif.isVisible(), 'notification.isVisible' ).to.be.true;
		expect( $notif.getText() ).to.match( /Success: your edit has been sent to moderation/ );
	} );
} );

