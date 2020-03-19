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
 * Unit test of RejectBatchConsequence.
 */

use MediaWiki\Moderation\RejectBatchConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class RejectBatchConsequenceTest extends ModerationUnitTestCase {
	use ModifySeveralDbRowsTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Verify that RejectBatchConsequence marks database rows as rejected and returns their number.
	 * @covers MediaWiki\Moderation\RejectBatchConsequence
	 */
	public function testRejectBatch() {
		$moderator = User::createNew( 'Some moderator' );

		// Let's reject half of the rows. This allows us to test that other rows are unmodified.
		list( $idsToReject, $idsToPreserve ) = array_chunk( $this->ids, 2 );

		// Create and run the Consequence.
		$consequence = new RejectBatchConsequence( $idsToReject, $moderator );
		$rejectedCount = $consequence->run();

		$this->assertEquals( count( $idsToReject ), $rejectedCount );

		// Check the state of the database.
		$this->assertWereBatchRejected( $idsToReject, $moderator );
		$this->assertNotRejected( $idsToPreserve );
	}

	/**
	 * Assert that the change was marked as rejected in the database.
	 * @param int[] $ids
	 * @param User $moderator
	 */
	private function assertWereBatchRejected( array $ids, User $moderator ) {
		foreach ( $ids as $modid ) {
			$this->assertSelect( 'moderation',
				[
					'mod_rejected',
					'mod_rejected_by_user',
					'mod_rejected_by_user_text',
					'mod_preloadable',
					'mod_rejected_batch',
					'mod_rejected_auto'

				],
				[ 'mod_id' => $modid ],
				[ [
					1,
					$moderator->getId(),
					$moderator->getName(),
					$modid, // mod_preloadable
					1, // mod_rejected_batch
					0, // mod_rejected_auto
				] ]
			);
		}
	}

	/**
	 * Assert that these changes are not rejected.
	 * @param int[] $ids
	 */
	private function assertNotRejected( array $ids ) {
		$this->assertSelect( 'moderation',
			[ 'mod_rejected' ],
			[ 'mod_id' => $ids ],
			array_fill( 0, count( $ids ), [ 0 ] )
		);
	}
}
