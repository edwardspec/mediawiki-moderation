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
 * @brief Testsuite engine that sends real HTTP requests via the network, as users do.
 */

class ModerationTestsuiteRealHttpEngine extends ModerationTestsuiteEngine {

	const HTTP_REQUEST_CLASS = 'MWHttpRequest';

	protected $apiUrl;
	protected $editToken = false;

	private $cookieJar = null; # Cookie storage (from login() and anonymous preloading)

	function __construct() {
		$this->apiUrl = wfScript( 'api' );
	}

	protected function getCookieJar() {
		if ( !$this->cookieJar ) {
			$this->cookieJar = new CookieJar;
		}

		return $this->cookieJar;
	}

	/**
	 * @brief Perform API request and return the resulting structure.
	 * @note If $apiQuery contains 'token' => 'null', then 'token'
	 * will be set to the current value of $editToken.
	 */
	protected function doQuery( array $apiQuery ) {
		$req = $this->httpPost( $this->apiUrl, $apiQuery );
		return FormatJson::decode( $req->getContent(), true );
	}

	public function loginAs( User $user ) {
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

		$this->getEditToken( true ); # It's different for a logged-in user
	}

	public function executeHttpRequest( $url, $method = 'GET', array $postData = [] ) {
		$requestClass = static::HTTP_REQUEST_CLASS;

		$req = $requestClass::factory( $url, [
			'method' => $method
		] );
		foreach ( $this->getRequestHeaders() as $name => $value ) {
			$req->setHeader( $name, $value );
		}
		$req->setCookieJar( $this->getCookieJar() );
		$req->setData( $postData );

		if ( $method == 'POST' && function_exists( 'curl_init' ) ) {
			/* Can be an upload */
			$req->setHeader( 'Content-Type', 'multipart/form-data' );
		}

		$status = $req->execute();

		if ( !$status->isOK()
			&& !$this->isHttpErrorIgnored( $req->getStatus() )
		) {
			throw new ModerationTestsuiteHttpError;
		}

		return ModerationTestsuiteResponse::newFromMWHttpRequest( $req );
	}

	public function logout() {
		$this->cookieJar = null;
		$this->getEditToken( true );
	}

	public function getEditToken( $updateCache = false ) {
		if ( $updateCache || !$this->editToken ) {
			$ret = $this->query( [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'csrf'
			] );
			$this->editToken = $ret['query']['tokens']['csrftoken'];
		}

		return $this->editToken;
	}
}
