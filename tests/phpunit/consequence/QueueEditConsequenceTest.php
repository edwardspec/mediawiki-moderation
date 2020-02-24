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

use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\SendNotificationEmailConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class QueueEditConsequenceTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Check the secondary consequences of running QueueEditConsequence.
	 * @covers ModerationNewChange::sendNotificationEmail
	 *
	 * See also: ModerationQueueTest from the blackbox integration tests.
	 */
	public function testQueueEdit() {
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) ); // TODO: test namespaces
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( 'Some text' . rand( 0, 100000 ),
			null, CONTENT_MODEL_WIKITEXT );
		$summary = 'Some summary ' . rand( 0, 100000 );
		$isBot = false; // TODO: test both
		$isMinor = false; // TODO: test both
		$pageExistedBefore = false; // TODO: test both

		// Replace real ConsequenceManager with a mock.
		$manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $manager );

		$this->setMwGlobals( [
			'wgModerationNotificationEnable' => true,
			'wgModerationEmail' => 'noreply@localhost'
		] );

		// No actual DB operations will be happening, so mock the returned mod_id.
		$modid = 12345;
		$manager->mockResult( $modid );

		// Create and run the Consequence.
		$consequence = new QueueEditConsequence(
			$page, $user, $content, $summary, '', '', $isBot, $isMinor );
		$consequence->run();

		// This is very similar to ModerationQueueTest::getExpectedRow().
		$dbr = wfGetDB( DB_REPLICA ); // Only for $dbr->timestamp()

		$preload = ModerationPreload::singleton();
		$preload->setUser( $user );

		$expectedFields = [
			'mod_timestamp' => $dbr->timestamp(),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => $pageExistedBefore ?
				$title->getArticleId( IDBAccessObject::READ_LATEST ) : 0,
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getDBKey(),
			'mod_comment' => $summary,
			'mod_minor' => ( $isMinor && $pageExistedBefore ) ? 1 : 0,
			'mod_bot' => $isBot ? 1 : 0,
			'mod_new' => $pageExistedBefore ? 0 : 1,
			'mod_last_oldid' => $pageExistedBefore ?
				$title->getLatestRevID( IDBAccessObject::READ_LATEST ) : 0,
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => $pageExistedBefore ?
				$title->getLength( IDBAccessObject::READ_LATEST ) : 0,
			'mod_new_len' => $content->getSize(),
			'mod_header_xff' => null,
			'mod_header_ua' => null,
			'mod_preload_id' => $preload->getId( false ),
			'mod_rejected' => 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => $content->getNativeData(), // Won't work if $content needs PST
			'mod_stash_key' => null,
			'mod_tags' => null,
			'mod_type' => 'edit',
			'mod_page2_namespace' => 0,
			'mod_page2_title' => ''
		];

		// Check secondary consequences.
		$this->assertConsequencesEqual( [
			new InsertRowIntoModerationTableConsequence( $expectedFields ),
			new SendNotificationEmailConsequence(
				$title,
				$user,
				$modid
			)
		], $manager->getConsequences() );
	}
}
