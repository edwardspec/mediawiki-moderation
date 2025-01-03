<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Verifies that ModerationTestsuite can login.
 */

namespace MediaWiki\Moderation\Tests;

require_once __DIR__ . "/../ModerationTestsuite.php";

class ModerationTestsuiteLoginTest extends ModerationTestCase {
	/**
	 * Attempt to login via the ModerationTestsuite.
	 * @covers MediaWiki\Moderation\ModerationTestsuite::loginAs()
	 */
	public function testLogin( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$this->assertEquals( $t->unprivilegedUser, $t->loggedInAs(),
			"testLogin(): Login unsuccessful." );
	}
}
