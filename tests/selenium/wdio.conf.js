exports.config = {
	/* Custom variables specific to Moderation:
		name/password of MediaWiki user who is both moderator AND automoderated.
	*/
	moderatorUser: 'User 1',
	moderatorPassword: '123456',

	/*
		Determine version of MediaWiki, so that non-applicable tests may be skipped.
		For example, MediaWiki 1.23 doesn't really support VisualEditor.
	*/
	before: function() {
		var BlankPage = require( './pageobjects/blank.page' );
		BlankPage.open();
		browser.options.is1_23 = BlankPage.is1_23;
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
