<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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

$inputFilename = $argv[1];
$resultFilename = $argv[2];

if ( !$inputFilename || !$resultFilename ) {
	throw new MWException( "cliInvoke.php: input/output files must be specified." );
}

$wgModerationTestsuiteCliDescriptor = unserialize( file_get_contents( $inputFilename ) );

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

/* The point of [InvokedWikiSettings.php] is override $wgRequest
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
$stdoutFilename = tempnam( sys_get_temp_dir(), 'testsuite.stdout' );
fclose( STDOUT );
$STDOUT = fopen( $stdoutFilename, 'a' );

$exceptionText = '';

try {

/*--------------------------------------------------------------*/
include $wgModerationTestsuiteCliDescriptor[ 'isApi' ] ? 'api.php' : 'index.php';
/*--------------------------------------------------------------*/

}
catch ( Exception $e ) {
	ob_start();
	MWExceptionHandler::handleException( $e );
	$exceptionText = ob_get_flush();
}

// Capture all output
while ( ob_get_status() ) {
	ob_end_flush();
}
fclose( $STDOUT );
$capturedContent = file_get_contents( $stdoutFilename );

$result = [
	'FauxResponse' => RequestContext::getMain()->getRequest()->response(),
	'capturedContent' => $capturedContent,
	'exceptionText' => $exceptionText
];

/*--------------------------------------------------------------*/

file_put_contents( $resultFilename, serialize( $result ) );
