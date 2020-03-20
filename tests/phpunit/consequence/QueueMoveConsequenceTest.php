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
 * Unit test of QueueEditConsequence.
 */

use MediaWiki\Moderation\BlockUserConsequence;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueMoveConsequence;
use MediaWiki\Moderation\RememberAnonIdConsequence;
use MediaWiki\Moderation\SendNotificationEmailConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class QueueMoveConsequenceTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Check the secondary consequences of running QueueMoveConsequence.
	 * @covers MediaWiki\Moderation\QueueMoveConsequence
	 * @covers ModerationNewChange::sendNotificationEmail
	 * @dataProvider dataProviderQueueMove
	 *
	 * See also: ModerationQueueTest from the blackbox integration tests.
	 */
	public function testQueueMove( array $params ) {
		$opt = (object)$params;

		$opt->modblocked = $opt->modblocked ?? false;
		$opt->notifyEmail = $opt->notifyEmail ?? false;
		$opt->notifyNewOnly = $opt->notifyNewOnly ?? false;
		$opt->anonymously = $opt->anonymously ?? false;

		$user = $opt->anonymously ? User::newFromName( '127.0.0.1', false ) :
			self::getTestUser()->getUser();
		$title = Title::newFromText( $opt->title ?? 'UTPage-' . rand( 0, 100000 ) );
		$page = WikiPage::factory( $title );
		$summary = $opt->summary ?? 'Some summary ' . rand( 0, 100000 );

		$newTitle = Title::newFromText( $opt->newTitle ?? 'UTPage-' . rand( 0, 100000 ) . '-new' );

		// Precreate the page.
		$moderator = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$status = $page->doEditContent(
			ContentHandler::makeContent( 'Original text' . rand( 0, 100000 ),
				null, CONTENT_MODEL_WIKITEXT
			), '', 0, false, $moderator

		);
		$this->assertTrue( $status->isOK() ); // Not intercepted and no other errors.

		if ( $opt->modblocked ) {
			$consequence = new BlockUserConsequence(
				$user->getId(),
				$user->getName(),
				$moderator
			);
			$consequence->run();
		}

		// Replace real ConsequenceManager with a mock.
		list( $scope, $manager ) = MockConsequenceManager::install();

		$this->setMwGlobals( [
			'wgModerationNotificationEnable' => $opt->notifyEmail ? true : false,
			'wgModerationEmail' => $opt->notifyEmail,
			'wgModerationNotificationNewOnly' => $opt->notifyNewOnly ?? false
		] );

		// No actual DB operations will be happening, so mock the returned mod_id.
		$modid = 12345;
		$manager->mockResult( InsertRowIntoModerationTableConsequence::class, $modid );

		$anonId = 67890;
		$manager->mockResult( RememberAnonIdConsequence::class, $anonId );

		// Create and run the Consequence.
		$consequence = new QueueMoveConsequence( $title, $newTitle, $user, $summary );
		$consequence->run();

		// This is very similar to ModerationQueueTest::getExpectedRow().
		$expectedFields = [
			'mod_timestamp' => 'ignored by assertConsequencesEqual()',
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => 0, // Not populated for moves
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getDBKey(),
			'mod_comment' => $summary,
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => 0,
			'mod_last_oldid' => 0, // Not populated for moves
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => 0, // Not populated for moves
			'mod_new_len' => 0, // Not populated for moves
			'mod_header_xff' => null,
			'mod_header_ua' => null,
			'mod_preload_id' => $opt->anonymously ? ']' . $anonId : '[' . $user->getName(),
			'mod_rejected' => $opt->modblocked ? 1 : 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => $opt->modblocked ?
				wfMessage( 'moderation-blocker' )->inContentLanguage()->text() : null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => $opt->modblocked ? 1 : 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => '', // Not populated for moves
			'mod_stash_key' => null,
			'mod_tags' => null,
			'mod_type' => 'move',
			'mod_page2_namespace' => $newTitle->getNamespace(),
			'mod_page2_title' => $newTitle->getDBKey()
		];

		// Check secondary consequences.
		$expectedConsequences = [];
		if ( $opt->anonymously ) {
			$expectedConsequences[] = new RememberAnonIdConsequence();
		}

		$expectedConsequences[] = new InsertRowIntoModerationTableConsequence( $expectedFields );

		if ( !$opt->modblocked && $opt->notifyEmail && !$opt->notifyNewOnly ) {
			$expectedConsequences[] = new SendNotificationEmailConsequence(
				$title,
				$user,
				$modid
			);
		}

		$this->assertConsequencesEqual( $expectedConsequences, $manager->getConsequences() );
	}

	/**
	 * Provide datasets for testQueueMove() runs.
	 * @return array
	 */
	public function dataProviderQueueMove() {
		return [
			'logged-in move' => [ [] ],
			'anonymous move' => [ [ 'anonymously' => true ] ],
			'move in non-Main namespaces' =>
				[ [
					'title' => 'Project:Title in another namespace',
					'newTitle' => 'User:Another title'
				] ],
			'move with edit summary' => [ [ 'summary' => 'Summary 1' ] ],
			'move by modblocked user' => [ [ 'modblocked' => true ] ],
			'email notification' => [ [ 'notifyEmail' => 'noreply@localhost' ] ],
			'absence of email notification for move when $wgModerationNotificationNewOnly=true' =>
				[ [ 'notifyEmail' => 'noreply@localhost', 'notifyNewOnly' => true ] ],
			'absence of email for move by modblocked user' =>
				[ [
					'modblocked' => true,
					'notifyEmail' => 'noreply@localhost'
				] ],
		];
	}
}
