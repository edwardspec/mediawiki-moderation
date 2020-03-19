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
 * Unit test of MarkAsMergedConsequence.
 */

use MediaWiki\Moderation\MarkAsMergedConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class MarkAsMergedConsequenceTest extends ModerationUnitTestCase {
	use ModifyDbRowTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Verify that MarkAsMergedConsequence marks the database row as merged.
	 * @covers MediaWiki\Moderation\MarkAsMergedConsequence
	 */
	public function testMarkAsMerged() {
		$revid = 12345;
		$modid = $this->makeDbRow();

		// Create and run the Consequence.
		$consequence = new MarkAsMergedConsequence( $modid, $revid );
		$somethingChanged = $consequence->run();

		$this->assertTrue( $somethingChanged );
		$this->assertIsMerged( $modid, $revid );

		// Noop test: try applying MarkAsMergedConsequence to an already merged row again.
		$consequence = new MarkAsMergedConsequence( $modid, $revid );
		$somethingChanged = $consequence->run();

		$this->assertFalse( $somethingChanged );
		$this->assertIsMerged( $modid, $revid ); // Should remain merged (as it was before)
	}

	/**
	 * Throw an exception if row is not marked as merged with mod_merged_revid=$revid.
	 * @param int $modid
	 * @param int $revid
	 */
	protected function assertIsMerged( $modid, $revid ) {
		$this->assertSelect( 'moderation',
			[
				'mod_merged_revid',
				'mod_preloadable'
			],
			[ 'mod_id' => $modid ],
			[ [
				$revid, // mod_merged_revid
				$modid // mod_preloadable: when it equals mod_id, it means "NOT preloadable"
			] ]
		);
	}
}
