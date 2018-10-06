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
 * @file
 * Wrapper around the HTTP response of NonApiBot methods.
 *
 * Behaves like ModerationTestsuiteResponse, but also has ModerationTestsuiteBotResponse methods.
 */

class ModerationTestsuiteNonApiBotResponse implements IModerationTestsuiteResponse {
	use ModerationTestsuiteBotResponse;

	/** @var ModerationTestsuiteResponse */
	private $httpResp;

	protected function __construct( ModerationTestsuiteResponse $httpResp ) {
		$this->httpResp = $httpResp;
	}

	/** @return string|null */
	public function getResponseHeader( $headerName ) {
		return $this->httpResp->getResponseHeader( $headerName );
	}

	/** @return int */
	public function getStatus() {
		return $this->httpResp->getStatus();
	}

	/** @return string */
	public function getContent() {
		return $this->httpResp->getContent();
	}

	/** @return bool */
	public function isRedirect() {
		return $this->httpResp->isRedirect();
	}
}
