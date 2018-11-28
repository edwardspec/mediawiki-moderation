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
 * Verifies that edit conficts are resolved by modaction=approve.
 *
 * Note: unresolvable edit conflicts are tested by ModerationMergeTest.
*/

require_once __DIR__ . "/framework/ModerationTestsuite.php";

class ModerationEditConflictTest extends ModerationTestCase {
	public function testResolvableEditConflict( ModerationTestsuite $t ) {
		/*
			Ensure that resolvable edit conflicts are automatically
			resolved during modaction=approve.

			Here the two users edit different parts of the text,
			so that their changes can be merged automatically.
		*/
		$title = 'Test page 1';
		$expectedText = "Modified paragraph about dogs\n\nModified paragraph about cats";

		$entry = $t->causeEditConflict(
			$title,
			"Original paragraph about dogs\n\nOriginal paragraph about cats",
			"Original paragraph about dogs\n\nModified paragraph about cats",
			"Modified paragraph about dogs\n\nOriginal paragraph about cats"
		);

		$this->assertNull( $t->html->getModerationError( $entry->approveLink ),
			"testResolvableEditConflict(): Approval failed" );

		$rev = $t->getLastRevision( $title );
		$this->assertEquals( $expectedText, $rev['*'],
			"testResolvableEditConflict(): Unexpected text after approving both edits"
		);
	}

}
