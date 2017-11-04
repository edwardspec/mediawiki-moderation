<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
	@brief Testsuite engine that simulates HTTP requests via internal invocation of MediaWiki.
*/

class ModerationTestsuiteInternalInvocationEngine extends ModerationTestsuiteEngine {

	private $userAgent = ''; /**< User-agent string. Used during simulated requests. */
	private $user = null; /**< User object. Used during simulated requests. */

	protected function getUser() {
		if ( !$this->user ) {
			$this->user = User::newFromName( '127.0.0.1', false );
		}

		return $this->user;
	}

	public function loginAs( User $user ) {
		$this->user = $user;
		return true;
	}

	public function logout() {
		$this->user = null;
	}

	public function setUserAgent( $ua ) {
		$this->userAgent = $ua;
	}

	/**
		@brief Create RequestContext for use in query() and executeHttpRequest().

		@note This function modifies the main RequestContext,
		because it may be used by the code we are testing,
		either explicitly or via $wgUser, etc.
	*/
	protected function makeRequestContext( $url, array $data, $isPosted, $isApi ) {

		$url = wfExpandUrl( $url, PROTO_CANONICAL );

		# var_dump( [ 'Sending internal request' => [ 'url' => $url, 'data' => $data ] ] );

		/* Prepare Request */
		$request = new FauxRequest( $data, $isPosted );
		$request->setRequestURL( $url );
		$request->setHeader( 'User-Agent', $this->userAgent );

		/* Add query string parameters (if any) to $request */
		$bits = wfParseUrl( $url );
		if ( isset( $bits['query'] ) ) {
			foreach ( explode( '&', $bits['query'] ) as $keyval ) {
				list( $key, $val ) = array_map( 'urldecode', explode( '=', $keyval ) );
				$request->setVal( $key, $val );
			}
		}

		/* Prepare Title (will be null for API) */
		if ( !$isApi ) {
			$_SERVER['REQUEST_URI'] = $url;
			$request->interpolateTitle();
			unset( $_SERVER['REQUEST_URI'] ); /* No longer needed */
		}

		$title = Title::newFromText( $request->getVal( 'title' ) );

		/* Prepare User */
		$user = $this->getUser();

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
		$user->setCookies( $request );

		/* Set legacy global variables */
		global $wgUser, $wgRequest, $wgOut;
		$wgUser = $user;
		$wgRequest = $request;
		$wgOut = $out;

		return $context;
	}

	/**
		@brief Perform API request and return the resulting structure.
		@note If $apiQuery contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	*/
	public function query( array $apiQuery ) {
		if ( array_key_exists( 'token', $apiQuery )
			&& is_null( $apiQuery['token'] ) ) {
				$apiQuery['token'] = $this->getEditToken();
		}

		$apiContext = $this->makeRequestContext(
			wfScript( 'api' ),
			$apiQuery,
			true, /* $isPosted */
			true /* $isApi */
		);

		$api = new ApiMain( $apiContext, true );
		$result = $api->getResult();
		try {
			$api->execute();
		}
		catch ( ApiUsageException $e ) {
			/* FIXME: code duplication, just subclass ApiMain and use their method */

			$messages = [];
			foreach ( $e->getStatusValue()->getErrorsByType( 'error' ) as $error ) {
				$messages[] = ApiMessage::create( $error );
			}

			$result->reset();

			$formatter = $api->getErrorFormatter();
			$formatter->addError( $e->getModulePath(), $messages );
		}

		return $result->getResultData();
	}


	public function deleteAllCookies() {
		$_COOKIE = [];
	}

	public function executeHttpRequest( $url, $method = 'GET', array $postData = [] ) {
		$httpContext = $this->makeRequestContext(
			$url,
			$postData,
			( $method == 'POST' ), /* $isPosted */
			false /* $isApi */
		);


		$mediaWiki = new MediaWiki( $httpContext );

		ob_start();
		$mediaWiki->run();
		$capturedContent = ob_get_clean();

		/* TODO:
			- handle uploads (CURLFile parameters in $postData)
			- follow redirects if necessary
			- get cookies from the response
		*/

		return ModerationTestsuiteResponse::newFromInternalInvocation(
			$httpContext,
			$capturedContent
		);
	}

	public function getEditToken( $updateCache = false ) {
		return $this->getUser()->getEditToken();
	}
}
