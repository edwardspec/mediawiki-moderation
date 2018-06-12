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
	@file
	@brief Implementation of  MWHttpRequest that feeds CGI environment to PHP-CGI.
*/

class ModerationTestsuiteCGIHttpRequest extends MWHttpRequest {
	const SUPPORTS_FILE_POSTS = false; /**< TODO */

	public static function factory( $url, $options = null, $caller = __METHOD__ ) {
		return new self( $url, $options, $caller );
	}

	/**
	 * @see MWHttpRequest::execute
	 *
	 * @throws MWException
	 * @return Status
	 */
	public function execute() {
		global $IP, $wgServerName;

		$this->prepare();
		if ( !$this->status->isOK() ) {
			return Status::wrap( $this->status );
		}

		$bits = wfParseUrl( $this->url );
		if ( $bits['host'] !== $wgServerName ) {
			throw new Exception( "CGIHttpRequest can only be sent to the wiki itself ($wgServerName), not to another host (${bits['host']})" );
		}

		list( $scriptName, $pathInfo ) = $this->safelyExtractPathInfo( $bits['path'] );

		$documentRoot = str_replace( dirname( wfScript() ), '', $IP );
		$scriptFilename = realpath( $IP . '/' . basename( $scriptName ) );

		/* Encode $this->postData */
		/* FIXME: 1) postData is not understood, check the CGI standard for exact format.
			2) need multipart/form-data (for uploads)
		*/
		$requestBody = wfArrayToCgi( $this->postData ?: [] );

		$requestBodyTmpFile = tempnam( sys_get_temp_dir(), 'postdata' );
		file_put_contents( $requestBodyTmpFile, $requestBody );

		$env = [
			'QUERY_STRING' => isset( $bits['query'] ) ? $bits['query'] : '',
			'REQUEST_METHOD' => $this->method,
			'SCRIPT_NAME' => $scriptName,
			'REQUEST_URI' => $this->url,
			'SCRIPT_FILENAME' => $scriptFilename,
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
			'HTTP_HOST' => "$wgServerName",
			'CONTENT_TYPE' => 'x-www-form-urlencoded',
			'CONTENT_LENGTH' => strlen( $requestBody )
		];

		foreach ( $this->getHeaderList() as $header ) {
			list( $name, $value ) = explode( ': ', $header, 2 );
			$cgiHeader = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( $cgiHeader == 'HTTP_CONTENT_TYPE' ) {
				$cgiHeader = 'CONTENT_TYPE';
			}

			$env[$cgiHeader] = $value;
		}

		$cmd = wfEscapeShellArg(
			$this->getBinaryPath(),
			$scriptFilename
		);
		$cmd .= ' <'  . wfEscapeShellArg( $requestBodyTmpFile );

		$retval = false;
		$cgiOutput = wfShellExecWithStderr( $cmd, $retval, $env, [ 'memory' => -1 ] );

		/* Parts of HTTP response divided by the empty string */
		$lines = preg_split( "/\r\n/", $cgiOutput );

		$this->headerList = [];
		foreach ( $lines as $line ) {
			if ( $line == '' ) {
				break; /* End of headers */
			}

			$this->headerList[] = $line;
		}

		$lines = array_slice( $lines, count( $this->headerList ) + 1 );
		call_user_func_array( $this->callback, [ null, join( "\r\n", $lines ) ] );

		$this->parseHeader();
		$this->setStatus();

		return Status::wrap( $this->status );
	}
	/**
	 * @return bool
	 */
	public function canFollowRedirects() {
		return false;
	}

	/**
		@brief Returns path to PHP-CGI.
	*/
	protected function getBinaryPath() {
		global $wgModerationTestsuitePhpCGIBinary;
		$phpBinary = $wgModerationTestsuitePhpCGIBinary ?: '/usr/bin/php-cgi';

		if ( !file_exists( $phpBinary ) ) {
			throw new Exception( __CLASS__ . ' requires [php-cgi] binary.' );
		}

		return $phpBinary;
	}

	/** @brief Safely split $bits['path'] into SCRIPT_NAME and PATH_INFO */
	protected function safelyExtractPathInfo( $relPath ) {
		$allowedScripts = array_map( 'wfScript', [ 'index', 'api', 'load' ] );

		list( $scriptName, ) = preg_split( '/(?<=\.php)/', $relPath );
		if ( !in_array( $scriptName, $allowedScripts ) ) {
			/* If the script isn't whitelisted,
				whole $relPath is treated as PATH_INFO */
			$scriptName = wfScript( 'index' );
			$pathInfo = $relPath;
		}
		else {
			$pathInfo = substr( $relPath, strlen( $scriptName ) );
		}

		return [ $scriptName, $pathInfo ];
	}
}



/* Code for quick testing */

if ( 0 ) {

	if ( 0 ) {
		$req = ModerationTestsuiteCGIHttpRequest::factory( '/w/api.php?action=query&meta=siteinfo&format=json', [ 'method' => 'GET' ] );
	}
	else {
		$req = ModerationTestsuiteCGIHttpRequest::factory( '/w/api.php', [ 'method' => 'POST' ] );
		$req->setData(  [ 'action' => 'query', 'meta' => 'siteinfo', 'format' => 'json' ] );
		#$req->setHeader( 'Content-Type', 'multipart/form-data' );
	}

	$req->execute();

	var_dump( $req->getContent() );
	var_dump( $req->getResponseHeaders() );

	posix_kill( posix_getpid(), SIGKILL );
}
