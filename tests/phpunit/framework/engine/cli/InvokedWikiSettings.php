<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
 */

# Load the usual LocalSettings.php
require_once "$IP/LocalSettings.php";

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

/* Apply variables requested by ModerationTestsuiteCliEngine::setMwConfig() */
foreach ( $wgModerationTestsuiteCliDescriptor['config'] as $name => $value ) {
	if ( $name == 'DBprefix' && $wgDBtype == 'postgres' ) {
		// Setting $wgDBprefix with PostgreSQL is not allowed.
		// So we have to wait for database to be initialized from configs
		// (e.g. until SetupAfterCache hook, which is called after all configuration is read),
		// and then redefine the prefix via LoadBalancerFactory.

		if ( method_exists( 'WikiMap', 'getCurrentWikiDbDomain' ) ) {
			// MediaWiki 1.33+
			$oldDomain = WikiMap::getCurrentWikiDbDomain();
		} else {
			// MediaWiki 1.31-1.32
			global $wgDBname, $wgDBmwschema, $wgDBprefix;
			$oldDomain = new DatabaseDomain( $wgDBname, $wgDBmwschema, (string)$wgDBprefix );
		}

		$newDomain = new DatabaseDomain(
			$oldDomain->getDatabase(),
			$oldDomain->getSchema(),
			$value // Typically "unittest_"
		);
		$GLOBALS['wgCachePrefix'] = $newDomain->getId();

		Hooks::register( 'SetupAfterCache', function () use ( $newDomain, $value ) {
			$lbFactory = MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
			if ( method_exists( $lbFactory, 'redefineLocalDomain' ) ) {
				// MediaWiki 1.32+
				$lbFactory->redefineLocalDomain( $newDomain );
			} else {
				// MediaWiki 1.31
				$lbFactory->closeAll();
				$lbFactory->setDomainPrefix( $value );

				// HACK: in MediaWiki 1.31, RevisionStore object compared wfWikiId() with $db->getDomainID(),
				// however it fails, because wfWikiId() doesn't have prefix, and $db->getDomainID() does.
				// Normally $wgPrefix adds prefix to wfWikiId(), but $wgPrefix can't be used with PostgreSQL.
				// This is an unnecessary sanity check that makes running tests vs. PostgreSQL not possible.
				// Workaround is to provide $newDomain->getId() to RevisionStore when it is constructed.
				$services = MediaWiki\MediaWikiServices::getInstance();
				$services->redefineService( 'RevisionStore', function () use ( $services, $newDomain ) {
					// Based on [includes/ServiceWiring.php] in MediaWiki core.
					$store = new MediaWiki\Storage\RevisionStore(
						$services->getDBLoadBalancer(),
						$services->getService( '_SqlBlobStore' ),
						$services->getMainWANObjectCache(),
						$services->getCommentStore(),
						$services->getActorMigration(),
						$newDomain->getId() // <----- what ModerationTestsuite is adding
					);

					$store->setLogger( MediaWiki\Logger\LoggerFactory::getInstance( 'RevisionStore' ) );
					$config = $services->getMainConfig();
					$store->setContentHandlerUseDB( $config->get( 'ContentHandlerUseDB' ) );

					return $store;
				} );
			}
		} );
	} else {
		$GLOBALS["wg$name"] = $value;
	}
}

function efModerationTestsuiteMockedHeader( $string, $replace = true, $http_response_code = null ) {
	$response = RequestContext::getMain()->getRequest()->response();
	if ( !( $response instanceof FauxResponse ) ) {
		// This is WebRequest(), meaning header() was called before efModerationTestsuiteSetup(),
		// typically due to some early initialization error.
		return;
	}

	$response->header( $string, $replace, $http_response_code );
}

/**
 * Sanity check: log "what user is currently logged in",
 * and ensure that request is executed on behalf on an expected user.
 */
function efModerationTestsuiteLogSituation() {
	global $wgModerationTestsuiteCliDescriptor;

	$entrypoint = 'index';
	if ( defined( 'MW_ENTRY_POINT' ) ) {
		// MediaWiki 1.34+
		$entrypoint = MW_ENTRY_POINT;
	} else {
		// MediaWiki 1.31-1.33
		$entrypoint = defined( 'MW_API' ) ? 'api' : 'index';
	}

	$user = RequestContext::getMain()->getUser();
	$event = array_merge(
		[
			'_entrypoint' => $entrypoint,
			'_LoggedInAs' => $user->getName() . ' (#' . $user->getId() .
				'), groups=[' . implode( ', ', $user->getGroups() ) . ']',
		],
		RequestContext::getMain()->getRequest()->getValues()
	);
	wfDebugLog( 'ModerationTestsuite', FormatJson::encode( $event, true, FormatJson::ALL_OK ) );

	list( $expectedId, $expectedName ) = $wgModerationTestsuiteCliDescriptor['expectedUser'];
	$actualId = $user->getId();
	$actualName = $user->getName();
	if ( $expectedId != $actualId || $expectedName != $actualName ) {
		throw new MWException( "CliEngine: assertion failed: expected a request on behalf of " .
			"User:$expectedName (#$expectedId), but are (incorrectly) logged in as " .
			"User:$actualName (#$actualId)." );
	}
}

function efModerationTestsuiteSetup() {
	global $wgModerationTestsuiteCliDescriptor, $wgRequest, $wgHooks, $wgAutoloadClasses;

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

	/* Some code in MediaWiki core, e.g. HTTPFileStreamer, calls header()
		directly (not via $wgRequest->response), but this function
		is a no-op in CLI mode, so the headers would be silently lost.

		We need to test these headers, so we use the following workaround:
		[MockAutoLoader.php] replaces header() calls with our function.
	*/
	ModerationTestsuiteMockAutoLoader::replaceFunction( 'header',
		'efModerationTestsuiteMockedHeader'
	);

	/*
		Install hook to replace ApiMain class
			with ModerationTestsuiteCliApiMain (subclass of ApiMain)
			that always prints the result, even in "internal mode".
	*/
	$wgHooks['ApiBeforeMain'][] = function ( ApiMain &$apiMain ) {
		$apiMain = new ModerationTestsuiteCliApiMain(
			$apiMain->getContext(),
			true
		);

		efModerationTestsuiteLogSituation();
		return true;
	};

	/*
		Initialize the session from the session cookie (if such cookie exists).
		FIXME: determine why exactly didn't SessionManager do this automatically.
	*/
	$wgHooks['SetupAfterCache'][] = function () {
		/* Earliest hook where $wgCookiePrefix (needed by getCookie())
			is available (when not set in LocalSettings.php)  */
		$request = RequestContext::getMain()->getRequest();
		$sessionId = $request->getCookie( '_session' );
		if ( $sessionId ) {
			session_id( $sessionId );
		}
	};

	$wgHooks['BeforeInitialize'][] = function () {
		efModerationTestsuiteLogSituation();
	};
}

efModerationTestsuiteSetup();
