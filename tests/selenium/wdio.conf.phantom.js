'use strict';

var merge = require( 'deepmerge' ),
	wdioConf = require( './wdio.conf' );

// Overwrite default settings
exports.config = merge( wdioConf.config, {
	maxInstances: 1,

	capabilities: [
		{
			browserName: 'phantomjs',
			exclude: [
				// Unclear whether VisualEditor itself works
				// under PhantomJS. Not supported yet.
				'specs/visualeditor.js'
			]},
	],

	services: ['phantomjs']
} );
