<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	private $t; # ModerationTestsuite
	function __construct( ModerationTestsuite $t ) {
		$this->t = $t;

		$this->resetCookieJar();
	}

	private $cookie_jar; # Cookie storage (from login() and anonymous preloading)

	public $userAgent = 'MediaWiki Moderation Testsuite';
	private $followRedirects = false;

	public function followRedirectsInOneNextRequest()
	{
		$this->followRedirects = true;
	}

	public function makeRequest( $url, $method = 'POST' )
	{
		$options = array( 'method' => $method );
		if ( $this->followRedirects ) {
			$options['followRedirects'] = true;
			$this->followRedirects = false; # Reset the flag
		}

		$req = MWHttpRequest::factory( $url, $options );
		$req->setUserAgent( $this->userAgent );
		$req->setCookieJar( $this->cookie_jar );

		return $req;
	}

	public function resetCookieJar() {
		$this->cookie_jar = new CookieJar;
	}
}
