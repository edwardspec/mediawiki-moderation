<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2020 Edward Chernenko.

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
 * Testsuite engine that sends real HTTP requests via the network, as users do.
 *
 * @deprecated
 * This engine is obsolete (it doesn't work in MediaWiki 1.33+), please use CliEngine.
 */

class ModerationTestsuiteRealHttpEngine extends ModerationTestsuiteEngine {
	/**
	 * @var CookieJar|null
	 * Cookie storage (for login() and anonymous preloading).
	 */
	private $cookieJar = null;

	/**
	 * Forget the login cookies (if any), thus becoming an anonymous user.
	 */
	protected function logoutInternal() {
		$this->cookieJar = null;
	}

	/**
	 * Execute HTTP request by sending it to the real HTTP server.
	 * @param string $url
	 * @param string $method
	 * @param array $postData
	 * @return ModerationTestsuiteResponse
	 * @throws MWException
	 */
	public function httpRequestInternal( $url, $method, array $postData ) {
		if ( !$this->cookieJar ) {
			$this->cookieJar = new CookieJar;
		}

		$req = MWHttpRequest::factory( $url, [
			'method' => $method
		] );
		foreach ( $this->getRequestHeaders() as $name => $value ) {
			$req->setHeader( $name, $value );
		}
		$req->setCookieJar( $this->cookieJar );
		$req->setData( $postData );

		if ( $method == 'POST' && function_exists( 'curl_init' ) ) {
			/* Can be an upload */
			$req->setHeader( 'Content-Type', 'multipart/form-data' );
		}

		$status = $req->execute();

		if ( !$status->isOK()
			&& !$this->isHttpErrorIgnored( $req->getStatus() )
		) {
			throw new MWException( __METHOD__ . ": request failed" );
		}

		return ModerationTestsuiteResponse::newFromMWHttpRequest( $req );
	}

	/**
	 * Sets MediaWiki global variable.
	 * @param string $name Name of variable without the $wg prefix.
	 * @throws PHPUnit\Framework\SkippedTestError
	 */
	public function setMwConfig( $name, $value ) {
		/* Implementation depends on the engine.
			RealHttpEngine can't implement this at all.
		*/
		throw new PHPUnit\Framework\SkippedTestError(
			'Test skipped: ' . get_class( $this ) . ' doesn\'t support setMwConfig()' );
	}
}
