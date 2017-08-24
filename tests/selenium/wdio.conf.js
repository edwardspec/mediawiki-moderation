exports.config = {
	/* Custom variables specific to Moderation:
		name/password of MediaWiki user who is both moderator AND automoderated.
	*/
	moderatorUser: process.env.MEDIAWIKI_MODERATOR_USER || 'User 1',
	moderatorPassword: process.env.MEDIAWIKI_MODERATOR_PASSWORD || '123456',

	/*
		Determine version of MediaWiki, so that non-applicable tests may be skipped.
		For example, MediaWiki 1.23 doesn't really support VisualEditor.
	*/
	before: function() {
		var BlankPage = require( './pageobjects/blank.page' );
		BlankPage.open();
		browser.options.is1_23 = BlankPage.is1_23;

		/*
			Make browser.url() ignore "Are you sure you want to leave this page?" alerts.
		*/
		var oldUrlFunc = browser.url.bind( browser );
		browser.url = function( url ) {

			/* Try to suppress beforeunload events.
				This doesn't work reliably in IE11, so there is a fallback alertAccept() below.
				We can't remove this browser.execute(), because Safari doesn't support alertAccept().
			*/
			browser.execute( function() {
				window.onbeforeunload = null;
				$( window ).off( 'beforeunload pageshow' ); /* See [mediawiki.confirmCloseWindow.js] in MediaWiki core */
			} );

			var ret = oldUrlFunc( url );

			try {
				/* Fallback for IE11.
					Not supported by SafariDriver, see browser.execute() above. */
				browser.alertAccept();
			} catch( e ) {}

			return ret;
		};
	},

	after: function() {
		/* Latest Firefox displays "Do you really want to leave" dialog
			even when WebDriver is being closed. Suppress that.
		*/
		browser.execute( function() {
			window.onbeforeunload = null;
			$( window ).off( 'beforeunload pageshow' ); /* See [mediawiki.confirmCloseWindow.js] in MediaWiki core */
		} );
	},

	/* Common WebdriverIO options */
	specs: [
		'specs/*.js'
	],
	maxInstances: 1,
	capabilities: [
		{ browserName: 'firefox' },
		//{ browserName: 'chrome' },
	],
	sync: true,
	logLevel: 'silent',
	coloredLogs: true,
	bail: 0,
	screenshotPath: '/tmp/',
	baseUrl: 'http://127.0.0.1',
	waitforTimeout: 10000,
	connectionRetryTimeout: 90000,
	connectionRetryCount: 3,
	framework: 'mocha',
	reporters: ['spec'],
	mochaOpts: {
		ui: 'bdd',
		timeout: 50000
	}
}
