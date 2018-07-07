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
 * @file
 * @brief Abstract parent class for sending requests (HTTP or API) to MediaWiki.

	Possible subclasses:
	1) "send real HTTP requests via network"
	2) "use internal invocation".
*/

abstract class ModerationTestsuiteEngine implements IModerationTestsuiteEngine {

	protected $ignoredHttpErrors = []; /**< Array of HTTP codes, e.g. [ 302, 404 ] */
	protected $reqHeaders = []; /**< Array of HTTP headers to add to all requests, e.g. [ 'User-Agent' => '...' ] */

	/**
	 * @brief Create engine object.
	 */
	public static function factory() {
		switch ( getenv( 'MODERATION_TEST_ENGINE' ) ) {
			case 'internal':
				/* Warning: incomplete (incorrect handling of sessions,
					compatibility issues with different versions of MediaWiki,
					etc.).
					Not calling wfDeprecate() because PHPUnit considers it a test failure.

					Don't use for real tests,
					only use for further development of this engine.
				*/
				return new ModerationTestsuiteInternalInvocationEngine;

			case 'cli':
				return new ModerationTestsuiteCliEngine;
		}

		/* Default */
		return new ModerationTestsuiteRealHttpEngine;
	}

	/** @brief Add an arbitrary HTTP header to all outgoing requests. */
	public function setHeader( $name, $value ) {
		$this->reqHeaders[$name] = $value;
	}

	/** @brief Returns array of all HTTP headers. */
	protected function getRequestHeaders() {
		return $this->reqHeaders;
	}

	/**
	 * @brief Perform GET request.
	 * @return ModerationTestsuiteResponse object.
	 */
	public function httpGet( $url ) {
		return $this->executeHttpRequest( $url, 'GET', [] );
	}

	/**
	 * @brief Perform POST request.
	 * @return ModerationTestsuiteResponse object.
	 */
	public function httpPost( $url, array $postData = [] ) {
		return $this->executeHttpRequest( $url, 'POST', $postData );
	}

	/**
	 * @brief Don't throw exception when HTTP request returns $code.
	 */
	public function ignoreHttpError( $code ) {
		$this->ignoredHttpErrors[$code] = true;
	}

	/**
	 * @brief Re-enable throwing an exception when HTTP request returns $code.
	 */
	public function stopIgnoringHttpError( $code ) {
		unset( $this->ignoredHttpErrors[$code] );
	}

	/**
	 * @brief Re-enable throwing an exception when HTTP request returns $code.
	 */
	protected function isHttpErrorIgnored( $code ) {
		return isset( $this->ignoredHttpErrors[$code] )
			&& $this->ignoredHttpErrors[$code];
	}

	public function loggedInAs() {
		$ret = $this->query( [
			'action' => 'query',
			'meta' => 'userinfo'
		] );
		$username = $ret['query']['userinfo']['name'];

		return User::newFromName( $username, false );
	}

	/**
	 * @brief Perform API request and return the resulting structure.
	 * @note If $apiQuery contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	 */
	final public function query( array $apiQuery ) {
		$apiQuery['format'] = 'json';
		if ( array_key_exists( 'token', $apiQuery )
			&& is_null( $apiQuery['token'] ) ) {
				$apiQuery['token'] = $this->getEditToken();
		}

		return $this->doQuery( $apiQuery );
	}

	/**
	 * @brief Engine-specific implementation of query().
	 */
	abstract protected function doQuery( array $apiQuery );

	/**
	 * @brief Create an account and return User object.
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
	 * @brief Sets MediaWiki global variable.
	 * @param name Name of variable without the " $wg prefix.
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
	 * @brief Handle the fact that MediaWikiTestCase tries to isolate us from the real database.

		MediaWiki 1.28+ started to agressively isolate tests from the real database,
		which means that executed HTTP requests must also be in the sandbox.

		RealHttp engine can't instruct the HTTP server to use another database prefix
		(which is how the sandbox is selected instead of the real database),
		so its only choice is to break out of the sandbox.
		Engine like CliEngine can handle this properly (by actually using the sandbox).
	 */
	public function escapeDbSandbox() {
		MediaWikiTestCase::teardownTestDB();
	}
}
