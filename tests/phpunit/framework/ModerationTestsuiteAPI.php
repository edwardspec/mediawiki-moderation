<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2017 Edward Chernenko.

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
	@brief Implements API methods for the automated testsuite.
*/

class ModerationTestsuiteAPI {
	private $t; # ModerationTestsuite
	function __construct( ModerationTestsuite $t ) {
		$this->t = $t;

		$this->apiUrl = wfScript( 'api' );
		$this->getEditToken();
	}

	private $apiUrl;
	public $editToken = false;

	private function getEditToken() {
		$ret = $this->query( [
			'action' => 'tokens',
			'type' => 'edit'
		] );

		$this->editToken = $ret['tokens']['edittoken'];
	}

	/**
		@brief Perform API request and return the resulting structure.
		@note If $query contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	*/
	public function query( $query ) {
		$query['format'] = 'json';

		if ( array_key_exists( 'token', $query )
			&& is_null( $query['token'] ) ) {
				$query['token'] = $this->editToken;
		}

		$req = $this->t->httpPost( $this->apiUrl, $query );
		return FormatJson::decode( $req->getContent(), true );
	}

	public function apiLogin( $username ) {
		# Step 1. Get the token.
		$q = [
			'action' => 'login',
			'lgname' => $username,
			'lgpassword' => $this->t->TEST_PASSWORD
		];
		$ret = $this->query( $q );

		# Step 2. Actual login.
		$q['lgtoken'] = $ret['login']['token'];
		$ret = $this->query( $q );

		if ( $ret['login']['result'] == 'Success' ) {
			$this->getEditToken(); # It's different for a logged-in user
			return true;
		}

		return false;
	}

	public function apiLogout() {
		$this->t->deleteAllCookies();
		$this->getEditToken();
	}

	public function apiLoggedInAs() {
		$ret = $this->query( [
			'action' => 'query',
			'meta' => 'userinfo'
		] );
		return $ret['query']['userinfo']['name'];
	}

	/**
		@brief Create account via API. Note: will not login.
	*/
	public function apiCreateAccount( $username ) {
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
			'password' => $this->t->TEST_PASSWORD,
			'retype' => $this->t->TEST_PASSWORD,
			'createtoken' => $token,
			'createreturnurl' => 'http://localhost/' /* Not really used */
		];
		$ret = $this->query( $q );
		return ( $ret['createaccount']['status'] == 'PASS' );
	}
}
