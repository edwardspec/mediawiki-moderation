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
 * Testsuite engine that runs index.php/api.php in PHP CLI.
 */

use MediaWiki\Shell\Shell;

class ModerationTestsuiteCliEngine extends ModerationTestsuiteEngine {

	/** @var array [ 'name' => 'value' ] */
	protected $cookies = [];

	/** @var array [ 'name' => 'value' ] - $wg* variables from setMwConfig() */
	protected $config = [];

	/**
	 * Forget the login cookies (if any), thus becoming an anonymous user.
	 */
	protected function logoutInternal() {
		$this->cookies = [];
	}

	/**
	 * Sets MediaWiki global variable.
	 * @param string $name Name of variable without the $wg prefix.
	 */
	public function setMwConfig( $name, $value ) {
		$this->config[$name] = $value;
	}

	/**
	 * Simulate HTTP request by running index.php/api.php of MediaWiki in a controlled environment.
	 * @param string $url
	 * @param string $method
	 * @param array $postData
	 * @return ModerationTestsuiteResponse
	 * @throws MWException
	 */
	public function httpRequestInternal( $url, $method, array $postData ) {
		global $wgServerName, $IP;
		$url = wfExpandURL( $url, PROTO_CANONICAL );

		/* Handle CURL uploads in $postData */
		$files = [];
		foreach ( $postData as $key => $val ) {
			if ( $val instanceof CURLFile ) {
				/* Create a temporary copy of this file,
					so that the original file won't be deleted after the upload */
				$tmpFilename = tempnam( sys_get_temp_dir(), 'testsuite.upload' ) .
					basename( $val->getFilename() );
				copy( $val->getFilename(), $tmpFilename );

				$files[$key] = $tmpFilename;
				unset( $postData[$key] ); // CURLFile is not serializable
			}
		}

		# Descriptor is the information to be passed to [cliInvoke.php].
		$descriptor = [
			'_COOKIE' => $this->cookies,
			'_GET' => [],
			'_POST' => $postData,
			'files' => $files,
			'httpHeaders' => $this->getRequestHeaders() + [
				'Host' => $wgServerName
			],
			'config' => $this->config,

			// For sanity checks only.
			'expectedUser' => [
				$this->getExpectedUser()->getId(),
				$this->getExpectedUser()->getName()
			]
		];

		/* We must parse $url here,
			because invokeFromScript() is called before reading LocalSettings.php
			and therefore won't know things like $wgServer or wfParseUrl() */
		$bits = wfParseUrl( $url );
		if ( $bits['host'] !== $wgServerName ) {
			throw new Exception( "CliEngine can only access the wiki itself " .
				"($wgServerName), not another host (${bits['host']})" );
		}

		if ( isset( $bits['query'] ) ) {
			$descriptor['_GET'] = wfCgiToArray( $bits['query'] );
		}

		list( $scriptName, $pathInfo ) = $this->safelyExtractPathInfo( $bits['path'] );

		$descriptor['isApi'] = ( $scriptName == wfScript( 'api' ) );

		$documentRoot = str_replace( dirname( wfScript() ), '', $IP );
		$scriptFilename = realpath( $IP . '/' . basename( $scriptName ) );

		/* Prepare the environment for calling cliInvoke.php.
			To be sure we have everything,
			we provide all variables that CGI/1.1 would provide
			(except CONTENT_TYPE/CONTENT_LENGTH,
			because the child process populates $_POST directly).
		*/
		$env = [
			'QUERY_STRING' => isset( $bits['query'] ) ? $bits['query'] : '',
			'REQUEST_METHOD' => $method,
			'SCRIPT_NAME' => $scriptName,
			'SCRIPT_FILENAME' => $scriptFilename,
			'REQUEST_URI' => $bits['path'] . ( isset( $bits['query'] ) ? '?' . $bits['query'] : '' ),
			'PATH_INFO' => $pathInfo,
			'DOCUMENT_ROOT' => $documentRoot,
			'SERVER_PROTOCOL' => 'HTTP/1.1',
			'GATEWAY_INTERFACE' => 'CGI/1.1',
			'REMOTE_ADDR' => '127.0.0.1',
			'REMOTE_PORT' => 12345, # Fake
			'SERVER_ADDR' => '127.0.0.1',
			'SERVER_PORT' => 80,
			'SERVER_NAME' => "$wgServerName",
			'SERVER_SOFTWARE' => 'FakeHttpServer/1.0.0',
			'REDIRECT_STATUS' => 200,
			'HTTP_HOST' => "$wgServerName"
		];

		// This variable is needed for parallel builds via Fastest
		// (it runs PHPUnit in multiple threads,
		// and each thread uses its own database)
		$env['ENV_TEST_CHANNEL'] = getenv( 'ENV_TEST_CHANNEL' );

		$limits = [ 'memory' => -1, 'filesize' => -1, 'time' => -1, 'walltime' => -1 ];

		$ret = Shell::command( [] )
			->params( [ PHP_BINARY, __DIR__ . '/cliInvoke.php' ] )
			->environment( $env )
			->limits( $limits )
			->restrict( Shell::NO_ROOT )
			->input( serialize( $descriptor ) )
			->execute();

		$output = $ret->getStdout();
		$errorOutput = $ret->getStderr();

		if ( $errorOutput ) {
			/* Allow PHPUnit to complain about this */
			print "\n" . $errorOutput;
		}

		$errorContext = "from $env[REQUEST_METHOD] [$url], postData=[" .
			wfArrayToCgi( $descriptor['_POST'] ) . ']';

		try {
			$result = unserialize( $output );
		} catch ( Exception $e ) {
			$this->getLogger()->error( '[CliEngine] Non-serialized text printed by cliInvoke.php', [
				'printedOutput' => $output
			] );
			throw new MWException( "Non-serialized text printed by cliInvoke.php $errorContext" );
		}

		if ( !empty( $result['exceptionText'] ) ) {
			$this->getLogger()->error( '[CliEngine] Exception detected', [
				'text' => $result['exceptionText']
			] );
		}

		if ( !isset( $result['FauxResponse'] ) ) {
			$this->getLogger()->error( '[CliEngine] No FauxResponse', [
				'capturedContent' => $result['capturedContent']
			] );
			throw new MWException( "no FauxResponse $errorContext" );
		}

		/* Remember the newly set cookies */
		$response = $result['FauxResponse'];
		$this->cookies = array_map( function ( $info ) {
			return $info['value'];
		}, $response->getCookies() ) + $this->cookies;

		return ModerationTestsuiteResponse::newFromFauxResponse(
			$response,
			$result['capturedContent']
		);
	}

	/**
	 * Safely split $bits['path'] into SCRIPT_NAME and PATH_INFO
	 * @return array that contains two strings: script name (e.g. "api.php") and pathinfo.
	 */
	protected function safelyExtractPathInfo( $relPath ) {
		$allowedScripts = array_map( 'wfScript', [ 'index', 'api' ] );

		list( $scriptName, ) = preg_split( '/(?<=\.php)/', $relPath );
		if ( !in_array( $scriptName, $allowedScripts ) ) {
			/* If the script isn't whitelisted,
				whole $relPath is treated as PATH_INFO */
			$scriptName = wfScript( 'index' );
			$pathInfo = $relPath;
		} else {
			$pathInfo = substr( $relPath, strlen( $scriptName ) );
		}

		return [ $scriptName, $pathInfo ];
	}

	/**
	 * Run subrequests in the DB sandbox imposed by MediaWikiTestCase.
	 */
	public function escapeDbSandbox() {
		global $argv;
		if ( array_search( '--use-normal-tables', $argv ) !== false ) {
			$dbw = wfGetDB( DB_MASTER );
			$this->setMwConfig( 'DBprefix', $dbw->tablePrefix() );

			// Ensure that ModerationVersionCheck doesn't have an old version number in cache,
			// otherwise Moderation will assume that DB schema is outdated.
			ModerationVersionCheck::invalidateCache();
		} else {
			// If temporary tables were used, then cliInvoked script can't access them.
			// Fallback to "break out of the sandbox" workaround.
			parent::escapeDbSandbox();
		}
	}
}
