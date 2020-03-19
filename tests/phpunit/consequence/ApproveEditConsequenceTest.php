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
 * Unit test of ApproveEditConsequence.
 */

use MediaWiki\Moderation\ApproveEditConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ApproveEditConsequenceTest extends ModerationUnitTestCase {
	use MakeEditTestTrait;
	use PostApproveCleanupTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging' ];

	/**
	 * Verify that ApproveEditConsequence makes a new edit.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * @dataProvider dataProviderApproveEdit
	 * @param $params
	 */
	public function testApproveEdit( array $params ) {
		$opt = (object)$params;

		$opt->existing = $opt->existing ?? false;
		$opt->bot = $opt->bot ?? false;
		$opt->minor = $opt->minor ?? false;
		$opt->summary = $opt->summary ?? 'Some summary ' . rand( 0, 100000 );

		$user = empty( $opt->anonymously ) ?
			self::getTestUser( $opt->bot ? [ 'bot' ] : [] )->getUser() :
			User::newFromName( '127.0.0.1', false );
		$title = Title::newFromText( $opt->title ?? 'UTPage-' . rand( 0, 100000 ) );
		$newText = 'New text ' . rand( 0, 100000 );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		if ( $opt->existing ) {
			// Precreate the page.
			$page = WikiPage::factory( $title );
			$page->doEditContent(
				ContentHandler::makeContent( "Before $newText", null, CONTENT_MODEL_WIKITEXT ),
				'',
				EDIT_INTERNAL,
				false,
				User::newFromName( '127.0.0.2', false )
			);
		}

		$baseRevId = $opt->existing ?
			$title->getLatestRevID( IDBAccessObject::READ_LATEST ) : 0;

		// Monitor RecentChange_save hook.
		// Note: we can't use $this->setTemporaryHook(), because it removes existing hook (if any),
		// and Moderation itself uses this hook (so it can't be removed during tests).
		global $wgHooks;
		$hooks = $wgHooks; // For setMwGlobals() below

		$hookFired = false;
		$hooks['RecentChange_save'][] = function ( RecentChange $rc )
			use ( &$hookFired, $user, $title, $newText, $baseRevId, $opt )
		{
			$hookFired = true;

			$this->assertEquals( $title->getFullText(), $rc->getTitle()->getFullText() );
			$this->assertEquals( $user->getName(), $rc->getPerformer()->getName() );
			$this->assertEquals( $user->getId(), $rc->getPerformer()->getId() );
			$this->assertEquals( $opt->minor ? 1 : 0, $rc->getAttribute( 'rc_minor' ) );
			$this->assertEquals( $opt->bot ? 1 : 0, $rc->getAttribute( 'rc_bot' ) );
			$this->assertEquals( $opt->summary, $rc->getAttribute( 'rc_comment' ) );

			$revid = $rc->getAttribute( 'rc_this_oldid' );
			$this->assertNotSame( 0, $revid );

			$rev = Revision::newFromId( $revid );
			$this->assertEquals( $baseRevId, $rev->getParentId() );
			$this->assertEquals( $newText, $rev->getContent()->getNativeData() );

			return true;
		};
		$this->setMwGlobals( 'wgHooks', $hooks );

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $newText, $opt->summary, $opt->bot, $opt->minor, $baseRevId );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveEditConsequence failed: " . $status->getMessage()->plain() );
		$this->assertTrue( $hookFired, "ApproveEditConsequence: didn't edit anything." );
	}

	/**
	 * Provide datasets for testApproveEdit() runs.
	 * @return array
	 */
	public function dataProviderApproveEdit() {
		return [
			'logged-in edit' => [ [] ],
			'anonymous edit' => [ [ 'anonymously' => true ] ],
			'edit in Project namespace' => [ [ 'title' => 'Project:Title in another namespace' ] ],
			'edit in existing page' => [ [ 'existing' => true ] ],
			'edit with edit summary' => [ [ 'summary' => 'Summary 1' ] ],
			'bot edit' => [ [ 'bot' => true ] ],
			'minor edit' => [ [ 'minor' => true, 'existing' => true ] ]
		];
	}

	/**
	 * Verify that ApproveEditConsequence can automatically resolve a resolvable edit conflict.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * See also: ModerationEditConflictTest::testResolvableEditConflict()
	 */
	public function testResolvableEditConflict() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = User::newFromName( '127.0.0.1', false );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		list( $revid1, $revid2 ) = $this->makeTwoEdits( $title,
			"Original paragraph about dogs\n\nOriginal paragraph about cats",
			"Original paragraph about dogs\n\nModified paragraph about cats"
		);

		$textToApprove = "Modified paragraph about dogs\n\nOriginal paragraph about cats";
		$expectedText = "Modified paragraph about dogs\n\nModified paragraph about cats";

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $textToApprove, '', false, false, $revid1 );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveEditConsequence failed: " . $status->getMessage()->plain() );

		$rev = Revision::newFromId( $status->value['revision']->getId() );
		$this->assertEquals( $revid2, $rev->getParentId() );
		$this->assertEquals( $expectedText, $rev->getContent()->getNativeData() );
	}

	/**
	 * Verify that ApproveEditConsequence detects a non-resolvable edit conflict.
	 * @covers MediaWiki\Moderation\ApproveEditConsequence
	 * See also: ModerationMergeTest::testMerge()
	 */
	public function testUnresolvableEditConflict() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = User::newFromName( '127.0.0.1', false );

		// Edits shouldn't be intercepted (including edit caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		list( $revid1, $revid2 ) = $this->makeTwoEdits( $title,
			"Normal line 1\nNormal line 2\nNormal line 3\n",
			"Normal line 1\nLine 2 was modified\nNormal line 3\n"
		);

		$textToApprove = "Normal line 1, but second line was deleted\nNormal line 3\n";

		// Create and run the Consequence.
		$consequence = new ApproveEditConsequence(
			$user, $title, $textToApprove, '', false, false, $revid1 );
		$status = $consequence->run();

		$this->assertFalse( $status->isOK(),
			"ApproveEditConsequence returned incorrect Success for unresolvable edit conflict." );

		$this->assertEquals( 'moderation-edit-conflict', $status->getMessage()->getKey(),
			"ApproveEditConsequence didn't return \"moderation-edit-conflict\" error." );

		$this->assertEquals( $revid2, $title->getLatestRevID( IDBAccessObject::READ_LATEST ),
			"Page was modified after ApproveEditConsequence on unresolvable edit conflict." );
	}

	/**
	 * Make two edits in the same page with two different users.
	 * @return int[] Array of rev_id of both edits
	 */
	public function makeTwoEdits( Title $title, $text1, $text2 ) {
		$revIds = [];
		$revIds[] = $this->makeEdit( $title, User::newFromName( '127.0.0.2', false ), $text1 );
		$revIds[] = $this->makeEdit( $title, User::newFromName( '127.0.0.3', false ), $text2 );
		return $revIds;
	}
}
