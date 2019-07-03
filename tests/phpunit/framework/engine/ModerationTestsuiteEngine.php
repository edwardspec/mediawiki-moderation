<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2018 Edward Chernenko.

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
 * Abstract parent class for sending requests (HTTP or API) to MediaWiki.

	Possible subclasses:
	1) send real HTTP requests via network (RealHttpEngine),
	2) invoke MediaWiki as a command-line script (CliEngine).
*/

abstract class ModerationTestsuiteEngine implements IModerationTestsuiteEngine {

	/** @var array Ignored non-OK HTTP codes, e.g. [ 302, 404 ] */
	protected $ignoredHttpErrors = [];

	/** @var array HTTP headers to add to all requests, e.g. [ 'User-Agent' => '...' ] */
	protected $reqHeaders = [];

	/** @var string|null Cached CSRF token, as obtained by getEditToken() */
	protected $editToken = null;

	/**
	 * Create engine object.
	 */
	public static function factory() {
		switch ( getenv( 'MODERATION_TEST_ENGINE' ) ) {
			case 'realhttp':
				return new ModerationTestsuiteRealHttpEngine;
		}

		/* Default */
		return new ModerationTestsuiteCliEngine;
	}

	/** Add an arbitrary HTTP header to all outgoing requests. */
	public function setHeader( $name, $value ) {
		$this->reqHeaders[$name] = $value;
	}

	/** Returns array of all HTTP headers. */
	protected function getRequestHeaders() {
		return $this->reqHeaders;
	}

	/**
	 * Perform GET request.
	 * @return ModerationTestsuiteResponse object.
	 */
	public function httpGet( $url ) {
		return $this->httpRequest( $url, 'GET', [] );
	}

	/**
	 * Perform POST request.
	 * @return ModerationTestsuiteResponse object.
	 */
	public function httpPost( $url, array $postData = [] ) {
		return $this->httpRequest( $url, 'POST', $postData );
	}

	/**
	 * Execute HTTP request and return a result.
	 * @param string $url
	 * @param string $method
	 * @param array $postData
	 * @return ModerationTestsuiteResponse
	 */
	private function httpRequest( $url, $method = 'GET', array $postData = [] ) {
		$logger = new ModerationTestsuiteLogger( 'ModerationTestsuite' );
		$logger->info( '[http] Sending {method} request to [{url}], postData={postData}',
			[
				'method' => $method,
				'url' => $url,
				'postData' => FormatJson::encode( $postData )
			]
		);

		$req = $this->httpRequestInternal( $url, $method, $postData );

		// Log results of the requests.
		$loggedContent = $req->getContent();
		$contentType = $req->getResponseHeader( 'Content-Type' );

		if ( $req->isRedirect() ) {
			$loggedContent = 'HTTP redirect to [' . $req->getResponseHeader( 'Location' ) . ']';
		} elseif ( strpos( $contentType, 'text/html' ) !== false ) {
			// Log will be too large for Travis if we dump the entire HTML,
			// so we only print main content and value of the <title> tag.
			$html = new ModerationTestsuiteHTML;
			$html->loadFromString( $loggedContent );

			if ( $html->getMainContent() ) {
				// MainContent element can be unavailable if this is some non-standard HTML page,
				// e.g. error 404 from showimg when simulating "missing-stash-image" error.
				$loggedContent = 'HTML page with title [' . $html->getTitle() . '] and main text [' .
					$html->getMainText() . ']';

				// Strip excessive newlines in MainContent
				$loggedContent = preg_replace( "/\n{3,}/", "\n\n", $loggedContent );
			}

		} elseif ( preg_match( '/^(image|application\/ogg)/', $contentType ) ) {
			$loggedContent = 'Omitted binary response of type [' . $contentType . '] and size ' .
				strlen( $loggedContent ) . ' bytes';
		}

		$logger->info( "[http] Received HTTP {code} response:\n" .
			"----------------- BEGIN CONTENT ---------------\n" .
			"{content}\n" .
			"----------------- END OF CONTENT --------------",
			[
				'code' => $req->getStatus(),
				'content' => $loggedContent
			]
		);

		return $req;
	}

	/**
	 * Engine-specific implementation of httpRequest().
	 */
	abstract public function httpRequestInternal( $url, $method, array $postData );

	/**
	 * Don't throw exception when HTTP request returns $code.
	 */
	public function ignoreHttpError( $code ) {
		$this->ignoredHttpErrors[$code] = true;
	}

	/**
	 * Re-enable throwing an exception when HTTP request returns $code.
	 */
	public function stopIgnoringHttpError( $code ) {
		unset( $this->ignoredHttpErrors[$code] );
	}

	/**
	 * Re-enable throwing an exception when HTTP request returns $code.
	 */
	protected function isHttpErrorIgnored( $code ) {
		return isset( $this->ignoredHttpErrors[$code] )
			&& $this->ignoredHttpErrors[$code];
	}

	/**
	 * Determine the current user.
	 * @return User
	 */
	public function loggedInAs() {
		$ret = $this->query( [
			'action' => 'query',
			'meta' => 'userinfo'
		] );
		$username = $ret['query']['userinfo']['name'];

		return User::newFromName( $username, false );
	}

	/**
	 * Perform API request and return the resulting structure.
	 * @param array $apiQuery
	 * @return array
	 * @note If $apiQuery contains 'token' => 'null', then 'token'
	 * will be set to the current value of $editToken.
	 */
	final public function query( array $apiQuery ) {
		$apiQuery['format'] = 'json';
		if ( array_key_exists( 'token', $apiQuery ) && $apiQuery['token'] === null ) {
			$apiQuery['token'] = $this->getEditToken();
		}

		return $this->queryInternal( $apiQuery );
	}

	/**
	 * Default implementation of query(). Can be overridden in the engine subclass.
	 * @param array $apiQuery
	 * @return array
	 */
	protected function queryInternal( array $apiQuery ) {
		$req = $this->httpPost( wfScript( 'api' ), $apiQuery );
		return FormatJson::decode( $req->getContent(), true );
	}

	/**
	 * Become a logged-in user. Can be overridden in the engine subclass.
	 * @param User $user
	 */
	final public function loginAs( User $user ) {
		$this->loginAsInternal( $user );
		$this->forgetEditToken(); # It's different for a logged-in user
	}

	/**
	 * Default implementation of loginAs(). Can be overridden in the engine subclass.
	 * @param User $user
	 */
	protected function loginAsInternal( User $user ) {
		# Step 1. Get the token.
		$ret = $this->query( [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'login'
		] );
		$loginToken = $ret['query']['tokens']['logintoken'];

		# Step 2. Actual login.
		$ret = $this->query( [
			'action' => 'clientlogin',
			'username' => $user->getName(),
			'password' => ModerationTestsuite::TEST_PASSWORD,
			'loginreturnurl' => 'http://localhost/not.really.used',
			'logintoken' => $loginToken
		] );

		if ( isset( $ret['error'] ) || $ret['clientlogin']['status'] != 'PASS' ) {
			throw new MWException( 'Failed to login as [' . $user->getName() . ']: ' .
				FormatJson::encode( $ret ) );
		}
	}

	/**
	 * Forget the login cookies, etc. and become an anonymous user.
	 */
	final public function logout() {
		$this->logoutInternal();
		$this->forgetEditToken(); # It's different for anonymous user
	}

	/**
	 * Engine-specific implementation of logout().
	 */
	abstract protected function logoutInternal();

	/**
	 * Obtain edit token. Can be overridden in the engine subclass.
	 */
	public function getEditToken() {
		if ( !$this->editToken ) {
			$ret = $this->query( [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'csrf'
			] );
			$this->editToken = $ret['query']['tokens']['csrftoken'];
		}

		return $this->editToken;
	}

	/**
	 * Invalidate the cache of getEditToken().
	 */
	protected function forgetEditToken() {
		$this->editToken = null;
	}

	/**
	 * Create an account and return User object.
	 * @note Will not login automatically (loginAs must be called).
	 */
	public function createAccount( $username ) {
		# Step 1. Get the token.
		$q = [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'createaccount'
		];
		$ret = $this->query( $q );
		$token = $ret['query']['tokens']['createaccounttoken'];

		# Step 2. Actually create an account.
		$q = [
			'action' => 'createaccount',
			'username' => $username,
			'password' => ModerationTestsuite::TEST_PASSWORD,
			'retype' => ModerationTestsuite::TEST_PASSWORD,
			'createtoken' => $token,
			'createreturnurl' => 'http://localhost/' /* Not really used */
		];
		$ret = $this->query( $q );

		if ( $ret['createaccount']['status'] != 'PASS' ) {
			return false;
		}

		return User::newFromName( $username, false );
	}

	/**
	 * Sets MediaWiki global variable.
	 * @param string $name Name of variable without the $wg prefix.
	 * @throws PHPUnit_Framework_SkippedTestError
	 */
	public function setMwConfig( $name, $value ) {
		/* Implementation depends on the engine.
			RealHttpEngine can't implement this at all.
		*/
		throw new PHPUnit_Framework_SkippedTestError(
			'Test skipped: ' . get_class( $this ) . ' doesn\'t support setMwConfig()' );
	}

	/**
	 * Handle the fact that MediaWikiTestCase tries to isolate us from the real database.

		MediaWiki 1.28+ started to agressively isolate tests from the real database,
		which means that executed HTTP requests must also be in the sandbox.

		RealHttp engine can't instruct the HTTP server to use another database prefix
		(which is how the sandbox is selected instead of the real database),
		so its only choice is to break out of the sandbox.
		Engine like CliEngine can handle this properly (by actually using the sandbox).
	 */
	public function escapeDbSandbox() {
		// FIXME: this approach below no longer works in MediaWiki 1.33+,
		// therefore only CliEngine with --use-normal-tables is usable.
		MediaWikiTestCase::teardownTestDB();
	}
}
