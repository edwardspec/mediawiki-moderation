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
	@brief Implements HTTP client for the automated testsuite.
*/

class ModerationTestsuiteHTTP {
	function __construct() {
		$this->resetCookieJar();
	}

	private $cookie_jar; # Cookie storage (from login() and anonymous preloading)

	public $userAgent = 'MediaWiki Moderation Testsuite';

	public function makeRequest( $url, $method = 'POST' )
	{
		$req = MWHttpRequest::factory( $url, [ 'method' => $method ] );
		$req->setUserAgent( $this->userAgent );
		$req->setCookieJar( $this->cookie_jar );

		return $req;
	}

	public function resetCookieJar() {
		$this->cookie_jar = new CookieJar;
	}
}
