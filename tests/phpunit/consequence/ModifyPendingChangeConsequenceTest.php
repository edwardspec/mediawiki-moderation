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
 * Unit test of ModifyPendingChangeConsequence.
 */

use MediaWiki\Moderation\ModifyPendingChangeConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ModifyPendingChangeConsequenceTest extends ModerationUnitTestCase {
	use ModifyDbRowTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Verify that ModifyPendingChangeConsequence changes mod_text, mod_comment and mod_new_len.
	 * @covers MediaWiki\Moderation\ModifyPendingChangeConsequence
	 */
	public function testModify() {
		$modid = $this->makeDbRow();
		$newText = 'Modified text';
		$newComment = 'Another edit comment';
		$newLen = strlen( $newText );

		// Create and run the Consequence.
		$consequence = new ModifyPendingChangeConsequence(
			$modid, $newText, $newComment, $newLen );
		$consequence->run();

		// Check the state of the database.
		$this->assertSelect( 'moderation',
			[
				'mod_text',
				'mod_comment',
				'mod_new_len'
			],
			[ 'mod_id' => $modid ],
			[ [
				$newText,
				$newComment,
				$newLen
			] ]
		);
	}
}
