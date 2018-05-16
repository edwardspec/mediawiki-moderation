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
	@brief Verifies that edit conficts are resolved by modaction=approve.

	Note: unresolvable edit conflicts are tested by ModerationMergeTest.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

class ModerationEditConflictMerge extends MediaWikiTestCase
{
	public function testResolvableEditConflict() {
		/*
			Ensure that resolvable edit conflicts
			are resolved automatically during modaction=approve.

			Here we edit the same existing page with two non-automoderated users.
			These users change different parts of the text,
			therefore their edit conflict should be automatically resolved.

			We then try to approve both edits on Special:Moderation
			and check whether the resulting text is correct.
		*/

		$title = 'Test page 1';
		$originalText = "Original paragraph about dogs\n\nOriginal paragraph about cats";
		$text1 = "Original paragraph about dogs\n\nModified paragraph about cats";
		$text2 = "Modified paragraph about dogs\n\nOriginal paragraph about cats";
		$expectedText = "Modified paragraph about dogs\n\nModified paragraph about cats";

		$t = new ModerationTestsuite();

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $title, $originalText );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $title, $text1 );

		$t->loginAs( $t->unprivilegedUser2 );
		$t->doTestEdit( $title, $text2 );

		$t->fetchSpecial();

		$this->assertNull( $t->html->getModerationError( $t->new_entries[1]->approveLink ),
			"testResolvableEditConflict(): Approval of the first edit failed" );
		$this->assertNull( $t->html->getModerationError( $t->new_entries[0]->approveLink ),
			"testResolvableEditConflict(): Approval of the second edit failed" );

		$rev = $t->getLastRevision( $title );
		$this->assertEquals( $expectedText, $rev['*'],
			"testResolvableEditConflict(): Unexpected text after approving both edits"
		);

	}

}
