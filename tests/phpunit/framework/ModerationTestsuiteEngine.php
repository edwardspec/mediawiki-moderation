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
	@brief Abstract parent class for sending requests (HTTP or API) to MediaWiki.

	Possible subclasses:
	1) "send real HTTP requests via network"
	2) "use internal invocation".
*/

abstract class ModerationTestsuiteEngine implements IModerationTestsuiteEngine {

	protected $ignoredHttpErrors = [];

	/**
		@brief Create engine object.
	*/
	public static function factory() {
		if ( getenv( 'MODERATION_TEST_INTERNAL' ) ) {
			return new ModerationTestsuiteInternalInvocationEngine;
		}

		return new ModerationTestsuiteRealHttpEngine;
	}

	/**
		@brief Perform GET request.
		@returns ModerationTestsuiteResponse object.
	*/
	public function httpGet( $url ) {
		return $this->executeHttpRequest( $url, 'GET', [] );
	}

	/**
		@brief Perform POST request.
		@returns ModerationTestsuiteResponse object.
	*/
	public function httpPost( $url, array $postData = [] ) {
		return $this->executeHttpRequest( $url, 'POST', $postData );
	}

	/**
		@brief Don't throw exception when HTTP request returns $code.
	*/
	public function ignoreHttpError( $code ) {
		$this->ignoredHttpErrors[$code] = true;
	}

	/**
		@brief Re-enable throwing an exception when HTTP request returns $code.
	*/
	public function stopIgnoringHttpError( $code ) {
		unset( $this->ignoredHttpErrors[$code] );
	}

	/**
		@brief Re-enable throwing an exception when HTTP request returns $code.
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
		@brief Create an account and return User object.
		@note Will not login automatically (loginAs must be called).
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
}
