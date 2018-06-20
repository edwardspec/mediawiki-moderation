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
	@file
	@brief Helper class to run MediaWiki as a command line script.
*/


class ModerationTestsuiteInternallyInvokedWiki {

	public $url;
	public $data; /**< array */
	public $isPosted; /**< True - POST request, false - GET request */
	public $extraHttpHeaders; /**< array */
	public $files; /**< array( requestParamName1 => filename1, ... )  */
	public $cookies; /**< array */

	public function __construct( $url, array $data, $isPosted, array $extraHttpHeaders = [] ) {
		$this->cookies = $_COOKIE;
		$this->url = $url;
		$this->isPosted = $isPosted;
		$this->extraHttpHeaders = $extraHttpHeaders;

		/* Handle CURL uploads in $data */
		$this->files = [];
		foreach ( $data as $key => $val ) {
			if ( $val instanceof CURLFile ) {
				/* Create a temporary copy of this file,
					so that the original file won't be deleted after the upload */
				$tmpFilename = tempnam( sys_get_temp_dir(), 'testsuite.upload' )
					. basename( $val->getFilename();
				copy( $val->getFilename(), $tmpFilename );

				$this->files[$key] = $tmpFilename;
				unset( $data[$key] ); // CURLFile is not serializable
			}
		}

		$this->data = $data;
	}

	public function __sleep() {
		return [ 'url', 'data', 'isPosted', 'extraHttpHeaders', 'files', 'cookies' ];
	}

	public function __wakeup() {
		foreach ( $this->files as $key => $filename ) {
			// Restore CURLFile objects from filename
			$this->data[$key] = curl_file_create( $filename );
		}
	}

	public static function newFromFile( $filename ) {
		return unserialize( file_get_contents( $filename ) );
	}

	/**
		@brief True if this is API request, false otherwise.
	*/
	protected function isApi() {
		return ( $this->url == wfScript( 'api' ) );
	}

	/**
		@brief Create RequestContext for use in invokeFromScript().
	*/
	protected function makeRequestContext() {
		$url = wfExpandUrl( $this->url, PROTO_CANONICAL );

		/* Handle CURL uploads in $data */
		$_FILES = [];
		foreach ( $this->data as $key => $val ) {
			if ( $val instanceof CURLFile ) {
				$_FILES[$key] = array(
					'name' => 'whatever', # Not used anywhere
					'type' => $val->getMimeType(),
					'tmp_name' => $val->getFilename(),
					'size' => filesize( $val->getFilename() ),
					'error' => 0
				);
				unset( $this->data[$key] );
			}
		}

		/* Prepare Request */
		$request = new FauxRequest( $this->data, $this->isPosted );
		$request->setRequestURL( $url );

		$request->setCookies(
			$this->cookies,
			"" /* No prefix needed (keys of $this->cookies already have the prefix) */
		);

		foreach( $this->extraHttpHeaders as $name => $val ) {
			$request->setHeader( $name, $val );
		}

		/* Prepare Session */
		$session = MediaWiki\Session\SessionManager::singleton()->getSessionForRequest( $request );
		$request->setSessionId( $session->getSessionId() );

		/* Add query string parameters (if any) to $request */
		$bits = wfParseUrl( $url );
		if ( isset( $bits['query'] ) ) {
			foreach ( explode( '&', $bits['query'] ) as $keyval ) {
				list( $key, $val ) = array_map( 'urldecode', explode( '=', $keyval ) );

				if ( !array_key_exists( $key, $this->data ) ) { /* GET parameters don't override POST $data */
					$request->setVal( $key, $val );
				}
			}
		}

		/* Prepare Title (will be null for API) */
		if ( !$this->isApi() ) {
			$_SERVER['REQUEST_URI'] = $url;
			$request->interpolateTitle();
			unset( $_SERVER['REQUEST_URI'] ); /* No longer needed */
		}

		$title = Title::newFromText( $request->getVal( 'title' ) );

		/* Prepare User */
		$user = User::newFromSession( $request );

		/* Get clean OutputPage */
		$unusedCleanContext = new RequestContext;
		$out = $unusedCleanContext->getOutput();

		/* Prepare Context */
		$context = RequestContext::getMain(); /* Tested code may use global context */
		$context->setUser( $user );
		$context->setRequest( $request );
		$context->setTitle( $title );
		$context->setOutput( $out );

		/* Bind OutputPage to the global context */
		$out->setContext( $context );

		/* Set cookies. CSRF token check in API assumes that $request has them. */
		$user->setCookies( $request, null, true );

		/* Set legacy global variables */
		global $wgUser, $wgRequest, $wgOut, $wgTitle;
		$wgUser = $user;
		$wgRequest = $request;
		$wgOut = $out;
		$wgTitle = $title;

		return $context;
	}

	/**
		@brief Execute the request. Called internally from the proc_open()ed script.
		@returns array
	*/
	public function invokeFromScript() {
		$context = $this->makeRequestContext();

		/*--------------------------------------------------------------*/
		ob_start();

		if ( $this->isApi() ) {
			ModerationTestsuiteApiMain::invoke( $context );
		}
		else { /* Non-API request */
			$mediaWiki = new MediaWiki( $context );
			$mediaWiki->run();
		}

		$capturedContent = ob_get_clean();

		/*--------------------------------------------------------------*/

		if ( $this->isApi() ) {
			return FormatJson::decode( $capturedContent, true );
		}

		return [
			'FauxResponse' => $context->getRequest()->response(),
			'capturedContent' => $capturedContent
		];
	}

	/**
		@brief Execute the request. Called from ModerationTestsuiteEngine.
	*/
	public function execute() {
		global $IP;

		/* Create temporary files to communicate with the script.
			Script will read $data from $inputFilename,
			then script will write $result into $outputFilename.
		*/
		$inputFilename = tempnam( sys_get_temp_dir(), 'testsuite.task' );
		$outputFilename = tempnam( sys_get_temp_dir(), 'testsuite.out' );

		file_put_contents( $inputFilename, serialize( $this ) );

		$cmd = wfEscapeShellArg(
			PHP_BINARY,
			"$IP/maintenance/runScript.php",
			__DIR__ . "/internallyInvoke.php",
			$inputFilename,
			$outputFilename
		);

		$retval = false;
		$unexpectedOutput = wfShellExecWithStderr( $cmd, $retval, [], [ 'memory' => -1 ] );

		if ( $unexpectedOutput ) {
			/* Allow PHPUnit to complain about this */
			echo $unexpectedOutput;
		}

		$info = unserialize( file_get_contents( $outputFilename ) );

		/* Delete the temporary files (no longer needed) */
		unlink( $inputFilename );
		unlink( $outputFilename );

		return $info;
	}
}
