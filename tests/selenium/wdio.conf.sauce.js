'use strict';

var merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {

	maxInstances: 5,

	capabilities: [
		{
			platform: 'Windows 10',
			browserName: 'MicrosoftEdge',
			version: '14.14393'
		},
		{
			platform: 'Windows 8.1',
			browserName: 'internet explorer',
			version: '11.0'
		},
		{
			platform: 'macOS 10.13',
			browserName: 'safari',
			version: '11.0',
			exclude: [
				// SafariDriver doesn't support sendKeys() to contenteditable,
				// so we can't test VisualEditor in it
				'specs/visualeditor.js'
			]
		},
		{ browserName: 'chrome', version: '60.0' }, // 61.0 has odd "Element is not clickable at point" issue when clicking Save in edit.page.js.
		{ browserName: 'firefox', version: 'latest' }
	],

	services: [ 'sauce' ],
	user: process.env.SAUCE_USERNAME || '',
	key: process.env.SAUCE_ACCESS_KEY || '',
	sauceConnect: true,

	waitforTimeout: 40000,
	mochaOpts: {
		timeout: 180000
	}

} );
