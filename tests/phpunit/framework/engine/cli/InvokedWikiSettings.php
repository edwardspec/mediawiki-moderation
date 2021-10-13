<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2021 Edward Chernenko.

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
 * Replacement of LocalSettings.php loaded by [cliInvoke.php].
 *
 * @phan-file-suppress PhanUndeclaredGlobalVariable
 * @phan-file-suppress PhanUndeclaredVariableDim
 */

# Load the usual LocalSettings.php
require_once "$IP/LocalSettings.php";

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DatabaseDomain;

# Replace Memcached with our caching class. This is needed for Parallel PHPUnit testing,
# where "flush_all" Memcached command is not applicable (it would delete keys of another thread).
require_once __DIR__ . "/../../ModerationTestsuiteBagOStuff.php";
$wgObjectCaches[CACHE_MEMCACHED] = [
	'class' => ModerationTestsuiteBagOStuff::class,
	'loggroup' => 'memcached',
	'filename' => '/dev/shm/modtest.cache'
];

// Same as in [tests/common/TestSetup.php]. Makes tests faster.
$wgSessionPbkdf2Iterations = 1;

// Sanity check: disallow deprecated session management via session_id(), etc.
$wgPHPSessionHandling = 'disable';

/* Apply variables requested by ModerationTestsuiteCliEngine::setMwConfig() */
foreach ( $wgModerationTestsuiteCliDescriptor['config'] as $name => $value ) {
	if ( $name == 'DBprefix' && $wgDBtype == 'postgres' ) {
		// Setting $wgDBprefix with PostgreSQL is not allowed.
		// So we have to wait for database to be initialized from configs
		// (e.g. until SetupAfterCache hook, which is called after all configuration is read),
		// and then redefine the prefix via LoadBalancerFactory.

		$oldDomain = WikiMap::getCurrentWikiDbDomain();
		$newDomain = new DatabaseDomain(
			$oldDomain->getDatabase(),
			$oldDomain->getSchema(),
			$value // Typically "unittest_"
		);
		$GLOBALS['wgCachePrefix'] = $newDomain->getId();

		// Can't use Hooks::register(): MediaWiki 1.35+ prints a warning when it's called before boostrap,
		// but this must be called before boostrap.
		global $wgHooks;
		$wgHooks['SetupAfterCache'][] = static function () use ( $newDomain ) {
			$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
			$lbFactory->redefineLocalDomain( $newDomain );
		};

		// This approach causes a deprecation warning (which must be suppressed) in MediaWiki 1.36+.
		$reflection = new ReflectionProperty( 'MWDebug', 'deprecationFilters' );
		$reflection->setAccessible( true );
		$deprecationFilters = $reflection->getValue();

		$deprecationFilters[] = '/Deprecated cross-wiki access.*/';
		$reflection->setValue( $deprecationFilters );
	} else {
		$GLOBALS["wg$name"] = $value;
	}
}

function wfModerationTestsuiteMockedHeader( $string, $replace = true, $http_response_code = null ) {
	$response = RequestContext::getMain()->getRequest()->response();
	if ( !( $response instanceof FauxResponse ) ) {
		// This is WebRequest, meaning header() was called before wfModerationTestsuiteSetup(),
		// typically due to some early initialization error.
		return;
	}

	$response->header( $string, $replace, $http_response_code );
}

/**
 * Sanity check: log "which user is currently logged in",
 * and ensure that request is executed on behalf on an expected user.
 */
function wfModerationTestsuiteCliLogin() {
	global $wgModerationTestsuiteCliDescriptor;
	list( $expectedId, $expectedName ) = $wgModerationTestsuiteCliDescriptor['expectedUser'];

	$user = RequestContext::getMain()->getUser();
	if ( $user->getId() != $expectedId || $user->getName() != $expectedName ) {
		$user = User::newFromName( $expectedName, false );

		// Login as $user. If this user doesn't exist, it will be created.
		$manager = MediaWikiServices::getInstance()->getAuthManager();
		$status = $manager->autoCreateUser( $user, AuthManager::AUTOCREATE_SOURCE_SESSION, true );

		if ( !$status->isOK() ) {
			throw new MWException( "Failed to login as User:$expectedName (#$expectedId)." );
		}

		global $wgUser;
		if ( !( $wgUser instanceof StubGlobalUser ) ) {
			// MediaWiki 1.35-1.36. Not needed in MediaWiki 1.37+.
			$wgUser = $user;
		}
		RequestContext::getMain()->setUser( $user );
	}

	$request = RequestContext::getMain()->getRequest();
	foreach ( $request->getValues() as $key => $val ) {
		if ( $val === '{CliEngine:Token:CSRF}' ) {
			$request->setVal( $key, $user->getEditToken() );
		}
	}

	$event = array_merge(
		[
			'_entrypoint' => MW_ENTRY_POINT,
			'_LoggedInAs' => $user->getName() . ' (#' . $user->getId() .
				'), groups=[' . implode( ', ', $user->getGroups() ) . ']',
		],
		$request->getValues()
	);
	wfDebugLog( 'ModerationTestsuite', FormatJson::encode( $event, true, FormatJson::ALL_OK ) );
}

function wfModerationTestsuiteSetup() {
	global $wgModerationTestsuiteCliDescriptor, $wgModerationTestsuiteCliUploadData,
		$wgRequest, $wgHooks, $wgAutoloadClasses;

	$wgAutoloadClasses['ModerationTestsuiteCliApiMain'] =
		__DIR__ . '/ModerationTestsuiteCliApiMain.php';

	/*
		Override $wgRequest. It must be a FauxRequest
		(because you can't extract response headers from WebResponse,
		only from FauxResponse)
	*/
// phpcs:disable MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
	$request = new FauxRequest(
		$_POST + $_GET, // $data
		( $_SERVER['REQUEST_METHOD'] == 'POST' ) // $wasPosted
	);
	$request->setRequestURL( $_SERVER['REQUEST_URI'] );
// phpcs:enable
	$request->setHeaders( $wgModerationTestsuiteCliDescriptor['httpHeaders'] );
	$request->setCookies( $_COOKIE, '' );

	/* Use $request as the global WebRequest object */
	RequestContext::getMain()->setRequest( $request );
	$wgRequest = $request;

	if ( method_exists( $request, 'setUpload' ) ) {
		// MediaWiki 1.37+
		$request->setUploadData( $wgModerationTestsuiteCliUploadData );
	} else {
		// MediaWiki 1.35-1.36
		foreach ( $wgModerationTestsuiteCliUploadData as $uploadKey => $uploadData ) {
			// phpcs:ignore MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
			$_FILES[$uploadKey] = $uploadData;
		}
	}

	/* Some code in MediaWiki core, e.g. HTTPFileStreamer, calls header()
		directly (not via $wgRequest->response), but this function
		is a no-op in CLI mode, so the headers would be silently lost.

		We need to test these headers, so we use the following workaround:
		[MockAutoLoader.php] replaces header() calls with our function.
	*/
	ModerationTestsuiteMockAutoLoader::replaceFunction( 'header',
		'wfModerationTestsuiteMockedHeader'
	);

	/*
		Install hook to replace ApiMain class
			with ModerationTestsuiteCliApiMain (subclass of ApiMain)
			that always prints the result, even in "internal mode".
	*/
	$wgHooks['ApiBeforeMain'][] = static function ( ApiMain &$apiMain ) {
		$apiMain = new ModerationTestsuiteCliApiMain(
			$apiMain->getContext(),
			true
		);

		wfModerationTestsuiteCliLogin();
		return true;
	};

	$wgHooks['BeforeInitialize'] = static function ( &$unused1, &$unused2, &$unused3, &$user ) {
		wfModerationTestsuiteCliLogin();

		// Make sure that handlers of BeforeInitialize hook (if any) will run as this new user.
		$user = RequestContext::getMain()->getUser();

		return true;
	};

	/*
		Initialize the session from the session cookie (if such cookie exists).
		FIXME: determine why exactly didn't SessionManager do this automatically.
	*/
	$wgHooks['SetupAfterCache'][] = static function () {
		/* Earliest hook where $wgCookiePrefix (needed by getCookie())
			is available (when not set in LocalSettings.php)  */
		$request = RequestContext::getMain()->getRequest();
		$sessionId = $request->getCookie( '_session' );
		if ( $sessionId ) {
			$manager = MediaWiki\Session\SessionManager::singleton();
			$session = $manager->getSessionById( $sessionId, true )
				?: $manager->getEmptySession();
			$request->setSessionId( $session->getSessionId() );
		}
	};

	/*
		Track hooks, as requested by ModerationTestsuiteCliEngine::trackHook()
	*/
	$wgModerationTestsuiteCliDescriptor['capturedHooks'] = [];
	foreach ( $wgModerationTestsuiteCliDescriptor['trackedHooks'] as $hook ) {
		$wgModerationTestsuiteCliDescriptor['capturedHooks'][$hook] = [];

		$wgHooks[$hook][] = static function () use ( $hook ) {
			global $wgModerationTestsuiteCliDescriptor;

			// The testsuite would want to analyze types of received parameters,
			// and well as parameter values (assuming they can be serialized).
			$params = func_get_args();
			$paramTypes = array_map( static function ( $param ) {
				$type = gettype( $param );
				return $type == 'object' ? get_class( $param ) : $type;
			}, $params );
			$paramsJson = FormatJson::encode( $params );

			$wgModerationTestsuiteCliDescriptor['capturedHooks'][$hook][] = [
				$paramTypes,
				$paramsJson
			];

			return true;
		};
	}

	$wgHooks['AlternateUserMailer'][] = static function () {
		// Prevent any emails from actually being sent during the testsuite runs.
		return false;
	};
}

wfModerationTestsuiteSetup();
