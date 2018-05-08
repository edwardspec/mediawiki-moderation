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
	@file
	@brief Testsuite engine that simulates HTTP requests via internal invocation of MediaWiki.
*/

use MediaWiki\Session\SessionManager;

class ModerationTestsuiteInternalInvocationEngine extends ModerationTestsuiteEngine {

	private $user = null; /**< User object. Used during simulated requests. */

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

	protected function getUser() {
		if ( !$this->user ) {
			$this->user = User::newFromName( '127.0.0.1', false );
		}

		return $this->user;
	}

	public function loginAs( User $user ) {
		$this->user = $user;
		return true;
	}

	public function logout() {
		$this->user = null;
	}

	/**
		@brief Create RequestContext for use in query() and executeHttpRequest().

		@note This function modifies the main RequestContext,
		because it may be used by the code we are testing,
		either explicitly or via $wgUser, etc.
	*/
	protected function makeRequestContext( $url, array $data, $isPosted, $isApi ) {

		$url = wfExpandUrl( $url, PROTO_CANONICAL );

		# var_dump( [ 'Sending internal request' => [ 'url' => $url, 'data' => $data ] ] );

		/* Handle CURL uploads in $data */
		$_FILES = [];
		foreach ( $data as $key => $val ) {
			if ( $val instanceof CURLFile ) {
				$_FILES[$key] = array(
					'name' => 'whatever', # Not used anywhere
					'type' => $val->getMimeType(),
					'tmp_name' => $val->getFilename(),
					'size' => filesize( $val->getFilename() ),
					'error' => 0
				);
				unset( $data[$key] );
			}
		}

		/* Prepare Request */
		$request = new FauxRequest( $data, $isPosted );
		$request->setRequestURL( $url );
		$request->setHeader( 'User-Agent', $this->getUserAgent() );

		/* Add query string parameters (if any) to $request */
		$bits = wfParseUrl( $url );
		if ( isset( $bits['query'] ) ) {
			foreach ( explode( '&', $bits['query'] ) as $keyval ) {
				list( $key, $val ) = array_map( 'urldecode', explode( '=', $keyval ) );

				if ( !array_key_exists( $key, $data ) ) { /* GET parameters don't override POST $data */
					$request->setVal( $key, $val );
				}
			}
		}

		/* Prepare Title (will be null for API) */
		if ( !$isApi ) {
			$_SERVER['REQUEST_URI'] = $url;
			$request->interpolateTitle();
			unset( $_SERVER['REQUEST_URI'] ); /* No longer needed */
		}

		$title = Title::newFromText( $request->getVal( 'title' ) );

		/* Prepare User */
		$user = $this->getUser();

		/* Get clean OutputPage */
		$unusedCleanContext = new RequestContext;
		$out = $unusedCleanContext->getOutput();

		/* Prepare Context */
		$context = RequestContext::getMain(); /* Tested code may use global context */
		$context->setUser( $user );
		$context->setRequest( $request );
		$context->setTitle( $title );
		$context->setOutput( $out );

		/* Bind OutputPage to the global context */
		$out->setContext( $context );

		/* Set cookies. CSRF token check in API assumes that $request has them. */
		$user->setCookies( $request );

		/* Set legacy global variables */
		global $wgUser, $wgRequest, $wgOut;
		$wgUser = $user;
		$wgRequest = $request;
		$wgOut = $out;

		return $context;
	}

	/**
		@brief Perform API request and return the resulting structure.
		@note If $apiQuery contains 'token' => 'null', then 'token'
			will be set to the current value of $editToken.
	*/
	protected function doQuery( array $apiQuery ) {
		$apiContext = $this->makeRequestContext(
			wfScript( 'api' ),
			$apiQuery,
			true, /* $isPosted */
			true /* $isApi */
		);

		return $this->forkAndRun( $apiContext, function( $childContext ) {
			return ModerationTestsuiteApiMain::invoke( $childContext );
		} );
	}


	public function deleteAllCookies() {
		$_COOKIE = [];
		SessionManager::singleton()->getGlobalSession()->clear();
	}

	/**
		@brief Reset all objects that can't be shared by forked processes.
		@see ForkController::prepareEnvironment()
	*/
	protected function prepareEnvironment() {
		wfGetLB()->closeAll();
		FileBackendGroup::destroySingleton();
		LockManagerGroup::destroySingletons();
		JobQueueGroup::destroySingletons();
		ObjectCache::clear();
		RedisConnectionPool::destroySingletons();

		global $wgMemc;
		$wgMemc = null;
	}

	/**
		@brief Fork PHP and run $function in the child process.
		@returns Value returned by $function.

		Note: this method will only return in parent process.
	*/
	public function forkAndRun( IContextSource $context, callable $function ) {
		/* Make child process reopen the SQL connection, etc. */
		$this->prepareEnvironment();

		/* Create a temporary file.
			Child will write the result into it. */
		$tmpFilename = tempnam( sys_get_temp_dir(), 'testsuite.result' );

		$pid = pcntl_fork();
		if ( $pid < 0 ) {
			throw new Exception( 'pcntl_fork() failed' );
		}

		if ( $pid == 0 ) {
			/* We are in the child process */
			$retval = call_user_func( $function, $context );

			/* Notify the parent of the results */
			$info = [
				'retval' => $retval,
				'FauxResponse' => $context->getRequest()->response(),
				'childSessionId' => $context->getRequest()->getSession()->getSessionId()
			];

			file_put_contents( $tmpFilename, serialize( $info ) );
			flush();

			/* Child process is no longer needed. Exit immediately.
				We can't use exit(0), because child process also has PHPUnit,
				and PHPUnit complains of exit() without tearDown(). */
			posix_kill( posix_getpid(), SIGKILL );
		}

		/* We are in the parent process. Wait for child process to exit. */
		$status = null;
		pcntl_waitpid( $pid, $status );

		/* Return the results provided by the child process. */
		$info = unserialize( file_get_contents( $tmpFilename ) );
		unlink( $tmpFilename ); /* No longer needed */

		/* Add newly added cookies into $_COOKIE */
		$cookies = $info['FauxResponse']->getCookies();
		foreach ( $cookies as $cookieName => $cookieInfo ) {
			if ( $cookieInfo['expire'] > time() ) {
				/* Cookie already expired, delete it */
				unset( $_COOKIE[$cookieName] );
			}
			else {
				/* New cookie */
				$_COOKIE[$cookieName] = $cookieInfo['value'];
			}
		}

		$context->getRequest()->setSessionId( $info['childSessionId'] );

		return $info['retval'];
	}

	public function executeHttpRequest( $url, $method = 'GET', array $postData = [] ) {
		$httpContext = $this->makeRequestContext(
			$url,
			$postData,
			( $method == 'POST' ), /* $isPosted */
			false /* $isApi */
		);

		$result = $this->forkAndRun( $httpContext, function( $childContext ) {
			ob_start();

			$mediaWiki = new MediaWiki( $childContext );
			$mediaWiki->run();

			$capturedContent = ob_get_clean();

			$childContext->getRequest()->getSession()->save();

			return [
				'FauxResponse' => $childContext->getRequest()->response(),
				'capturedContent' => $capturedContent
			];
		} );

		return ModerationTestsuiteResponse::newFromFauxResponse(
			$result['FauxResponse'],
			$result['capturedContent']
		);
	}

	public function getEditToken( $updateCache = false ) {
		return $this->getUser()->getEditToken();
	}
}
