'use strict';

const assert = require( 'assert' ),
	VisualEditor = require( '../pageobjects/VisualEditor' );

describe( 'VisualEditor', function () {

	/* Temporarily increase Mocha timeout,
		so that we could debug this test with browser.debug() */
	this.timeout( 1230000 );

	it( 'should save the new edit without errors', function () {

		VisualEditor.edit( 'Test',
			Date.now() + ' ' + Math.random() + "\n"
		);

		/* Analyze the postedit message */

		/* FIXME: use an assert library with more detailed error output, e.g. Chai */
		assert( browser.isVisible( '.postedit' ) );
		assert( browser.getText( '.postedit' ).match( /Success: your edit has been sent to moderation/ ) );
	} );
} );

