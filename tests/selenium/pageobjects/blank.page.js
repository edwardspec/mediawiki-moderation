/*
	Basic MediaWiki page.
	This is used to determine MediaWiki configuration via mw.config.get()
	(most importantly, version of MediaWiki) before the tests.
*/

'use strict';
const Page = require( './page' );

class BlankPage extends Page {

	open() {
		super.open( 'Special:BlankPage' );
	}

	/**
		@brief Get "wgSomething" variable, assuming it is available to JavaScript.
	*/
	get( variable ) {
		return browser.execute( function( name ) {
			return mw.config.get( name );
		}, variable ).value;
	}

	/**
		@brief Get MediaWiki version, e.g. "1.28.2".
	*/
	get version() { return this.get( 'wgVersion' ); }

	/**
		@brief Returns true if this is MediaWiki 1.23, false otherwise.
		This is important because 1.23 doesn't need certain tests.
		For example, MobileFrontend in 1.23 requires login,
		so we shouldn't test anonymous mobile postedit notification.
	*/
	get is1_23() { return this.version.match( /^1\.23/ ) ? true : false; }

}
module.exports = new BlankPage();
