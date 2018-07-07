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
 * @brief Testsuite engine that simulates HTTP requests via internal invocation of MediaWiki.
 */

class ModerationTestsuiteInternalInvocationEngine extends ModerationTestsuiteEngine {

	public function __construct() {
		/*
			Enforce CACHE_DB for sessions, because sessions created
			by the child process must be available in the parent.
			In CLI mode, CACHE_ACCEL would not provide that.
		*/
		global $wgSessionCacheType;
		if ( $wgSessionCacheType != CACHE_DB ) {
			/* Unfortunately it's too late to override the variable.
				SessionManager is a singleton and has already been created,
				and it provides no methods to change the storage.
			*/
			throw new Exception( 'Moderation Testsuite: please set $wgSessionCacheType to CACHE_DB in LocalSettings.php.' );
		}
	}

	protected function getSession() {
		return MediaWiki\Session\SessionManager::getGlobalSession();
	}

	protected function getUser() {
		return $this->getSession()->getUser();
	}

	public function loginAs( User $user ) {
		$this->getSession()->setUser( $user );
		$user->setCookies( null, null, true );

		$_COOKIE = array_map( function ( $info ) {
			return $info['value'];
		}, $user->getRequest()->response()->getCookies() );

		return true;
	}

	public function logout() {
		$this->getSession()->clear();
	}

	/**
	 * @brief Perform API request and return the resulting structure.
	 * @note If $apiQuery contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	 */
	protected function doQuery( array $apiQuery ) {
		$wiki = new ModerationTestsuiteInternallyInvokedWiki(
			wfScript( 'api' ),
			$apiQuery,
			true, /* $isPosted */
			$this->getRequestHeaders()
		);
		return $wiki->execute();
	}

	public function executeHttpRequest( $url, $method = 'GET', array $postData = [] ) {
		$wiki = new ModerationTestsuiteInternallyInvokedWiki(
			$url,
			$postData,
			( $method == 'POST' ),
			$this->getRequestHeaders()
		);
		$result = $wiki->execute();

		return ModerationTestsuiteResponse::newFromFauxResponse(
			$result['FauxResponse'],
			$result['capturedContent']
		);
	}

	public function getEditToken( $updateCache = false ) {
		return $this->getUser()->getEditToken();
	}
}
