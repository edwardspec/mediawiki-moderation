<?php

# Load the usual LocalSettings.php
require_once "$IP/LocalSettings.php";

function efModerationTestsuiteSetup() {
	global $wgModerationTestsuiteCliDescriptor, $wgRequest, $wgHooks, $wgAutoloadClasses;

	$wgAutoloadClasses['ModerationTestsuiteCliApiMain'] = __DIR__ . '/ModerationTestsuiteCliApiMain.php';

	/*
		Override $wgRequest. It must be a FauxRequest
		(because you can't extract response headers from WebResponse,
		only from FauxResponse)
	*/
	$request = new FauxRequest(
		$_POST + $_GET, // $data
		( $_SERVER['REQUEST_METHOD'] == 'POST' ) // $wasPosted
	);
	$request->setRequestURL( $_SERVER['REQUEST_URI'] );
	$request->setHeaders( $wgModerationTestsuiteCliDescriptor['httpHeaders'] );
	$request->setCookies( $_COOKIE, '' );

	/* Use $request as the global WebRequest object */
	RequestContext::getMain()->setRequest( $request );
	$wgRequest = $request;

	/* Some code in MediaWiki core, e.g. HTTPFileStreamer, calls header()
		directly (not via $wgRequest->response), but this function
		is a no-op in CLI mode, so the headers would be silently lost.

		As a workaround (because we need to test those headers),
		we support 'uopz' PHP extension that can redefine a builtin.

		FIXME: this is terribly slow. Tests should opt-in to using this.
		(currently only testShowUpload() and testMissingStashedImage()
		would want to use it).
	*/
	if ( function_exists( 'uopz_set_return' ) ) {
		uopz_set_return( 'header', function( $string, $replace = true, $http_response_code = null ) {
			$response = RequestContext::getMain()->getRequest()->response();
			$response->header( $string, $replace, $http_response_code );
		}, true );
	}

	/*
		Install hook to replace ApiMain class
			with ModerationTestsuiteCliApiMain (subclass of ApiMain)
			that always prints the result, even in "internal mode".
	*/
	$wgHooks['ApiBeforeMain'][] = function( ApiMain &$apiMain ) {
		global $wgEnableWriteAPI;

		$apiMain = new ModerationTestsuiteCliApiMain(
			$apiMain->getContext(),
			$wgEnableWriteAPI
		);

		return true;
	};

	/*
		HACK: call session_id() on ID from the session cookie (if such cookie exists).
		FIXME: detemine why exactly didn't SessionManager do this automatically.
	*/
	$wgHooks['SetupAfterCache'][] = function() {
		/* Earliest hook where $wgCookiePrefix (needed by getCookie())
			is available (when not set in LocalSettings.php)  */
		$id = RequestContext::getMain()->getRequest()->getCookie( '_session' );
		if ( $id ) {
			session_id( $id );
		}
	};
}

efModerationTestsuiteSetup();

