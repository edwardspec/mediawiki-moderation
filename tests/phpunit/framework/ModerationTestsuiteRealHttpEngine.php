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
	@brief Testsuite engine that sends real HTTP requests via the network, as users do.
*/

class ModerationTestsuiteRealHttpEngine extends ModerationTestsuiteEngine {

	protected $http; /**< ModerationTestsuiteHTTP object */
	protected $api; /**< ModerationTestsuiteAPI object */

	protected $apiUrl;
	protected $editToken = false;

	function __construct() {
		$this->http = new ModerationTestsuiteHTTP( $this );

		$this->apiUrl = wfScript( 'api' );
	}

	public function setUserAgent( $ua ) {
		$this->http->userAgent = $ua;
	}

	/**
		@brief Perform API request and return the resulting structure.
		@note If $apiQuery contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	*/
	public function query( array $apiQuery ) {
		$apiQuery['format'] = 'json';

		if ( array_key_exists( 'token', $apiQuery )
			&& is_null( $apiQuery['token'] ) ) {
				$apiQuery['token'] = $this->getEditToken();
		}

		$req = $this->httpPost( $this->apiUrl, $apiQuery );
		return FormatJson::decode( $req->getContent(), true );
	}


	public function deleteAllCookies() {
		$this->http->resetCookieJar();
	}

	public function executeHttpRequest( $url, $method = 'GET', array $postData = [] ) {
		$req = $this->http->makeRequest( $url, $method );
		$req->setData( $postData );

		if ( $method == 'POST' ) {
			/* Can be an upload */
			$req->setHeader( 'Content-Type', 'multipart/form-data' );
		}

		$status = $req->execute();

		if ( !$status->isOK()
			&& !$this->isHttpErrorIgnored( $req->getStatus() )
		) {
			throw new ModerationTestsuiteHttpError;
		}

		return $req;
	}

	public function logout() {
		$this->deleteAllCookies();
		$this->getEditToken( true );
	}


	public function getEditToken( $updateCache = false ) {
		if ( $updateCache || !$this->editToken ) {
			$ret = $this->query( [
				'action' => 'tokens',
				'type' => 'edit'
			] );

			$this->editToken = $ret['tokens']['edittoken'];
		}

		return $this->editToken;
	}
}
