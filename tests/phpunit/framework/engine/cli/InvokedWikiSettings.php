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
 * Replacement of LocalSettings.php loaded by [cliInvoke.php].
 */

# Load the usual LocalSettings.php
require_once "$IP/LocalSettings.php";

/* Apply variables requested by ModerationTestsuiteCliEngine::setMwConfig() */
foreach ( $wgModerationTestsuiteCliDescriptor['config'] as $name => $value ) {
	$GLOBALS["wg$name"] = $value;

	if ( $name == 'DBPrefix' ) {
		CloneDatabase::changePrefix( $value );
	}
}

function efModerationTestsuiteMockedHeader( $string, $replace = true, $http_response_code = null ) {
	$response = RequestContext::getMain()->getRequest()->response();
	$response->header( $string, $replace, $http_response_code );
}

function efModerationTestsuiteSetup() {
	global $wgModerationTestsuiteCliDescriptor, $wgRequest, $wgHooks, $wgAutoloadClasses;

	$wgAutoloadClasses['ModerationTestsuiteCliApiMain'] =
		__DIR__ . '/ModerationTestsuiteCliApiMain.php';

	/*
		Override $wgRequest. It must be a FauxRequest
		(because you can't extract response headers from WebResponse,
		only from FauxResponse)
	*/
// phpcs:disable MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
	$request = new FauxRequest(
		$_POST + $_GET, // $data
		( $_SERVER['REQUEST_METHOD'] == 'POST' ) // $wasPosted
	);
	$request->setRequestURL( $_SERVER['REQUEST_URI'] );
// phpcs:enable
	$request->setHeaders( $wgModerationTestsuiteCliDescriptor['httpHeaders'] );
	$request->setCookies( $_COOKIE, '' );

	/* Use $request as the global WebRequest object */
	RequestContext::getMain()->setRequest( $request );
	$wgRequest = $request;

	/* Some code in MediaWiki core, e.g. HTTPFileStreamer, calls header()
		directly (not via $wgRequest->response), but this function
		is a no-op in CLI mode, so the headers would be silently lost.

		We need to test these headers, so we use the following workaround:
		[MockAutoLoader.php] replaces header() calls with our function.
	*/
	ModerationTestsuiteMockAutoLoader::replaceFunction( 'header',
		'efModerationTestsuiteMockedHeader'
	);

	/*
		Install hook to replace ApiMain class
			with ModerationTestsuiteCliApiMain (subclass of ApiMain)
			that always prints the result, even in "internal mode".
	*/
	$wgHooks['ApiBeforeMain'][] = function ( ApiMain &$apiMain ) {
		$apiMain = new ModerationTestsuiteCliApiMain(
			$apiMain->getContext(),
			true
		);

		return true;
	};

	/*
		HACK: call session_id() on ID from the session cookie (if such cookie exists).
		FIXME: detemine why exactly didn't SessionManager do this automatically.
	*/
	$wgHooks['SetupAfterCache'][] = function () {
		/* Earliest hook where $wgCookiePrefix (needed by getCookie())
			is available (when not set in LocalSettings.php)  */
		$id = RequestContext::getMain()->getRequest()->getCookie( '_session' );
		if ( $id ) {
			session_id( $id );
		}
	};
}

efModerationTestsuiteSetup();
