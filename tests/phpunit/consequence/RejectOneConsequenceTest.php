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
 * Unit test of RejectOneConsequence.
 */

use MediaWiki\Moderation\RejectOneConsequence;

/**
 * @group Database
 */
class RejectOneConsequenceTest extends MediaWikiTestCase {
	/** @var int */
	protected $modid;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Verify that RejectOneConsequence marks the database row as rejected and returns 1.
	 * @covers MediaWiki\Moderation\RejectOneConsequence
	 */
	public function testRejectOne() {
		$moderator = User::createNew( 'Some moderator' );

		// Create and run the Consequence.
		$consequence = new RejectOneConsequence( $this->modid, $moderator );
		$rejectedCount = $consequence->run();

		$this->assertEquals( 1, $rejectedCount );

		// New row should have appeared in the database.
		$this->assertWasRejected( $this->modid, $moderator );
	}

	/**
	 * Verify that RejectOneConsequence returns 0 for an already rejected edit.
	 * @covers MediaWiki\Moderation\RejectOneConsequence
	 */
	public function testNoopRejectOne() {
		$moderator = User::createNew( 'Some moderator' );

		// Create and run the Consequence.
		$consequence1 = new RejectOneConsequence( $this->modid, $moderator );
		$consequence1->run();

		$consequence2 = new RejectOneConsequence( $this->modid, $moderator );
		$rejectedCount = $consequence2->run();
		$this->assertSame( 0, $rejectedCount );

		// Despite $consequence2 doing nothing, the row should still be marked as rejected.
		$this->assertWasRejected( $this->modid, $moderator );
	}

	/**
	 * Verify that RejectOneConsequence does nothing if DB row is marked as merged or rejected.
	 * @param array $fields Passed to $dbw->update( 'moderation', ... ) before the test.
	 * @covers MediaWiki\Moderation\RejectOneConsequence
	 * @dataProvider dataProviderNotApplicableRejectOne
	 */
	public function testNotApplicableRejectOne( array $fields ) {
		$moderator = User::createNew( 'Some moderator' );

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation', $fields, [ 'mod_id' => $this->modid ], __METHOD__ );

		// Create and run the Consequence.
		$consequence = new RejectOneConsequence( $this->modid, $moderator );
		$rejectedCount = $consequence->run();
		$this->assertSame( 0, $rejectedCount );
	}

	public function dataProviderNotApplicableRejectOne() {
		return [
			'already rejected' => [ [ 'mod_rejected' => 1 ] ],
			'already merged' => [ [ 'mod_merged_revid' => 1234 ] ]
		];
	}

	/**
	 * Assert that the change was marked as rejected in the database.
	 * @param int $modid
	 * @param User $moderator
	 */
	private function assertWasRejected( $modid, User $moderator ) {
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
				0, // mod_rejected_batch
				0, // mod_rejected_auto
			] ]
		);
	}

	/**
	 * Create a row in "moderation" SQL table.
	 */
	public function setUp() {
		parent::setUp();

		$name = $this->getName();
		if ( $name == 'testValidCovers' || $name == 'testMediaWikiTestCaseParentSetupCalled' ) {
			return;
		}

		$author = User::newFromName( "127.0.0.1", false );
		$title = Title::newFromText( "Some page" );
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );

		$change = new ModerationNewChange( $title, $author );
		$this->modid = $change->edit( $page, $content, '', '' )->queue();
	}
}
