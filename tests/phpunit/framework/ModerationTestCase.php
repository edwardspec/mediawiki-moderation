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
 * @file
 * Subclass of MediaWikiTestCase that prints TestsuiteLogger debug messages for failed tests.
 */

class ModerationTestCase extends MediaWikiTestCase {
	/**
	 * Dump the logs related to the current test.
	 *
	 * FIXME: MediaWiki 1.27 uses PHPUnit 4, where $e is Exception, but modern PHPUnit 6
	 * has "Throwable $e", which leads to PHP warning "Declaration [...] should be compatible".
	 */
	protected function onNotSuccessfulTest( $e ) {
		ModerationTestsuiteLogger::printBuffer();
		parent::onNotSuccessfulTest( $e );
	}

	/**
	 * Forget the logs related to previous tests.
	 */
	protected function setUp() {
		ModerationTestsuiteLogger::cleanBuffer();
		parent::setUp();
	}
}
