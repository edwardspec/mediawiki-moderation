<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
 * Basic interface of sending requests (HTTP or API) to MediaWiki.
 */

interface IModerationTestsuiteEngine {
	public function query( array $apiQuery );

	public function setHeader( $name, $value );
	public function ignoreHttpError( $code );
	public function stopIgnoringHttpError( $code );

	public function loginAs( User $user );
	public function loggedInAs();
	public function logout();

	/**
	 * Create an account and return User object.
	 * @note Will not login automatically (loginAs must be called).
	 */
	public function createAccount( $username );

	public function getEditToken();
}
