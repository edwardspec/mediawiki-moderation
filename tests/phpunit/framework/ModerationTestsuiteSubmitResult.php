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
	@brief Return value of non-API methods like $t->nonApiUpload().
*/

class ModerationTestsuiteSubmitResult {

	protected $error; /**< string if error exists, false otherwise */
	protected $successText; /**< string if not an error, false otherwise */

	protected function __construct( $error, $successText ) {
		$this->error = $error;
		$this->successText = $successText;
	}

	protected static function newError( $error ) {
		return new self( $error, false );
	}

	protected static function newSuccess( $successText ) {
		return new self( false, $successText );
	}

	/**
		@brief Create ModerationTestsuiteSubmitResult object from $req.
	*/
	public static function newFromResponse( ModerationTestsuiteResponse $req, ModerationTestsuite $t ) {
		if ( $req->getResponseHeader( 'Location' ) ) {
			return null; # No errors
		}

		$html = $t->html->loadFromReq( $req );
		$divs = $html->getElementsByTagName( 'div' );

		foreach ( $divs as $div ) {
			# Note: the message can have parameters,
			# so we won't remove the braces around it.

			if ( $div->getAttribute( 'class' ) == 'error' ) {
				/* Error found */
				return self::newError( trim( $div->textContent ) );
			}
		}

		/* No errors */
		return self::newSuccess( $html->getMainText() );
	}

	public function getError() {
		return $this->error;
	}

	public function getSuccessText() {
		return $this->successText;
	}
}

