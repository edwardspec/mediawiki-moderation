'use strict';

var merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {

	capabilities: [
		/*
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
			platform: 'macOS 10.12',
			browserName: 'safari',
			version: '10.0'
		},
		{ browserName: 'chrome' },
		*/
		{ browserName: 'firefox' }
	],

	services: [ 'sauce' ],
	user: process.env.SAUCE_USER || '',
	key: process.env.SAUCE_KEY || '',
	sauceConnect: true,

	waitforTimeout: 20000,
	mochaOpts: {
		timeout: 180000
	}

} );
