'use strict';

var conf = require( './wdio.conf' ).config;

// Overwrite default settings
conf.maxInstances = 1;
conf.capabilities = [ {
	browserName: 'phantomjs',
	exclude: [
		// Unclear whether VisualEditor itself works under PhantomJS.
		// Not supported yet.
		'specs/visualeditor.js'
	]
} ];
conf.services = [ 'phantomjs' ];

exports.config = conf;
