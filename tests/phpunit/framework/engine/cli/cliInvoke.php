<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Helper script to run MediaWiki as a command line script.
 *
 * @see ModerationTestsuiteCliEngine::cliExecute()
 * @note this runs before Setup.php, configuration is unknown,
 * and we can't use any of the MediaWiki classes.
 * We just populate $_GET, $_POST, etc. and include "index.php".
 */

$wgModerationTestsuiteCliDescriptor = unserialize( stream_get_contents( STDIN ) );

/* Unpack all variables known from descriptor, e.g. $_POST */
foreach ( [ '_GET', '_POST', '_COOKIE' ] as $var ) {
	$GLOBALS[$var] = $wgModerationTestsuiteCliDescriptor[$var];
}

/* Unpack $_FILES */
foreach ( $wgModerationTestsuiteCliDescriptor['files'] as $uploadKey => $tmpFilename ) {
	$curlFile = new CURLFile( $tmpFilename );

	$_FILES[$uploadKey] = [
		'name' => 'whatever', # Not used anywhere
		'type' => $curlFile->getMimeType(),
		'tmp_name' => $curlFile->getFilename(),
		'size' => filesize( $curlFile->getFilename() ),
		'error' => 0
	];
}

/* The point of [InvokedWikiSettings.php] is to override $wgRequest
	to be FauxRequest (not WebRequest),
	because we can only extract headers from FauxResponse.

	NOTE: this consumes $wgModerationTestsuiteCliDescriptor['httpHeaders']
*/
define( 'MW_CONFIG_FILE', __DIR__ . '/InvokedWikiSettings.php' );

# Turn off display_errors (enabled by DevelopmentSettings.php),
# we don't need PHP errors to be mixed with the response.
ini_set( 'display_errors', 0 );
ini_set( 'log_errors', 1 );

require_once __DIR__ . '/MockAutoLoader.php'; # Intercepts header() calls

// Redirect STDOUT into the file
$originalStdout = fopen( "php://stdout", "w" ); // Save it (will write the result here)

$tmpFilename = tempnam( sys_get_temp_dir(), 'testsuite.stdout' );
fclose( STDOUT );
$STDOUT = fopen( $tmpFilename, 'a' );

$exceptionText = '';

function wfCliInvokeOnCompletion() {
	// FIXME: this whole script should be wrapped in a class to avoid global variables, etc.
	// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName.allowedPrefix
	global $originalStdout, $tmpFilename, $exceptionText, $STDOUT;

	// Capture all output
	while ( ob_get_status() ) {
		ob_end_flush();
	}
	fclose( $STDOUT );

	$capturedContent = file_get_contents( $tmpFilename );
	unlink( $tmpFilename ); // No longer needed

	$result = [
		'capturedContent' => $capturedContent,
		'exceptionText' => $exceptionText
	];

	// If an exception happened before efModerationTestsuiteSetup(),
	// then $request wouldn't be a FauxResponse yet (and is therefore useless for CliEngine).
	$response = RequestContext::getMain()->getRequest()->response();
	if ( $response instanceof FauxResponse ) {
		$result['FauxResponse'] = $response;
	}

	fwrite( $originalStdout, serialize( $result ) );
}

// Because MWLBFactory calls exit() instead of throwing an exception.
register_shutdown_function( 'wfCliInvokeOnCompletion' );

try {

/*--------------------------------------------------------------*/
include $wgModerationTestsuiteCliDescriptor[ 'isApi' ] ? 'api.php' : 'index.php';
/*--------------------------------------------------------------*/

}
catch ( Exception $e ) {
	$exceptionText = (string)$e;
}
