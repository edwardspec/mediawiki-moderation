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
	protected $headers; /**< Response headers, e.g. [ 'Content-Type' => 'text/html' ] */
	protected $httpCode; /**< HTTP return code, e.g. 200 */

	function __construct( $content, $headers, $httpCode ) {
		$this->content = $content;
		$this->headers = $headers;
		$this->httpCode = $httpCode;
	}

	public static function newFromMWHttpRequest( MWHttpRequest $httpRequest ) {
		return new self(
			$httpRequest->getContent(),
			$httpRequest->getResponseHeaders(),
			$httpRequest->getStatus()
		);
	}

	public function getResponseHeader( $headerName ) {
		$headerName = strtolower( $headerName );

		if ( isset( $this->headers[$headerName] ) ) {
			$lines = $this->headers[$headerName];
			return array_pop( $lines ); /* Return the last header with this name */
		}

		return null;
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

