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
	@brief HTTP response to be analyzed by tests. Made from MWHttpRequest or OutputPage.

	This class mimics the methods of MWHttpRequest, even if it was created
	from OutputPage (as the result of internal invocation).
*/

class ModerationTestsuiteResponse {

	protected $content; /**< Response text */
	protected $httpCode; /**< HTTP return code, e.g. 200 */
	protected $getHeaderMethod; /**< callable, implementation-specific method used by getResponseHeader() */

	protected function __construct( $content, $httpCode, callable $getHeaderMethod ) {
		$this->content = $content;
		$this->httpCode = $httpCode;
		$this->getHeaderMethod = $getHeaderMethod;
	}

	/**
		@brief Create response from real MWHttpRequest.
		@returns ModerationTestsuiteResponse object.
	*/
	public static function newFromMWHttpRequest( MWHttpRequest $httpRequest ) {
		return new self(
			$httpRequest->getContent(),
			$httpRequest->getStatus(),
			[ $httpRequest, 'getResponseHeader' ]
		);
	}

	/**
		@brief Create response from OutputPage after internal invocation.
		@param $capturedContent Text printed by $mediaWiki->run(), as captured by ob_start()/ob_get_clean().
		@returns ModerationTestsuiteResponse object.
	*/
	public static function newFromOutput( OutputPage $out, $capturedContent ) {
		$mwResponse = $out->getRequest()->response(); /**< FauxResponse object */

		$req = new self(
			$capturedContent,
			$mwResponse->getStatusCode(),
			[ $mwResponse, 'getHeader' ]
		);

		if ( $req->isRedirect() ) {
			var_dump( "Got redirected to " . $req->getResponseHeader( 'location' ) );
		}

		return $req;
	}

	public function getResponseHeader( $headerName ) {
		return call_user_func( $this->getHeaderMethod, $headerName );
	}

	public function getStatus() {
		return $this->httpCode;
	}

	public function getContent() {
		return $this->content;
	}

	public function isRedirect() {
		return ( $this->httpCode >= 300 && $this->httpCode <= 303 );
	}
}

