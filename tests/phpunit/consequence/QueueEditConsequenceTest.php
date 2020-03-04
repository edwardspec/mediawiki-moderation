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
	 * @covers MediaWiki\Moderation\QueueEditConsequence
	 * @covers ModerationNewChange::sendNotificationEmail
	 * @dataProvider dataProviderQueueEdit
	 *
	 * See also: ModerationQueueTest from the blackbox integration tests.
	 */
	public function testQueueEdit( array $params ) {
		$opt = (object)$params;

		$user = empty( $opt->anonymously ) ? self::getTestUser()->getUser() :
			User::newFromName( '127.0.0.1', false );
		$title = Title::newFromText( $opt->title ?? 'UTPage-' . rand( 0, 100000 ) );
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( 'Some text' . rand( 0, 100000 ),
			null, CONTENT_MODEL_WIKITEXT );
		$summary = $opt->summary ?? 'Some summary ' . rand( 0, 100000 );
		$opt->bot = $opt->bot ?? false;
		$opt->minor = $opt->minor ?? false;
		$opt->existing = $opt->existing ?? false;
		$opt->modblocked = $opt->modblocked ?? false;
		$opt->notifyEmail = $opt->notifyEmail ?? false;
		$opt->notifyNewOnly = $opt->notifyNewOnly ?? false;

		if ( $opt->existing ) {
			// Precreate the page.
			$status = $page->doEditContent(
				ContentHandler::makeContent( 'Original text' . rand( 0, 100000 ),
					null, CONTENT_MODEL_WIKITEXT
				), '', 0, false,
				self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser()
			);
			$this->assertTrue( $status->isOK() ); // Not intercepted and no other errors.
		}

		if ( $opt->modblocked ) {
			$consequence = new BlockUserConsequence(
				$user->getId(),
				$user->getName(),
				self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser()
			);
			$consequence->run();
		}

		// @phan-suppress-next-line PhanUndeclaredMethod - Phan thinks it's not FauxRequest
		$user->getRequest()->setHeaders( [
			'User-Agent' => $opt->userAgent ?? '',
			'X-Forwarded-For' => $opt->xff ?? ''
		] );

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

		// Create and run the Consequence.
		$consequence = new QueueEditConsequence(
			$page, $user, $content, $summary, '', '', $opt->bot, $opt->minor );
		$consequence->run();

		// This is very similar to ModerationQueueTest::getExpectedRow().
		$preload = ModerationPreload::singleton();
		$preload->setUser( $user );

		$expectedFields = [
			'mod_timestamp' => 'ignored by assertConsequencesEqual()',
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => $opt->existing ?
				$title->getArticleId( IDBAccessObject::READ_LATEST ) : 0,
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getDBKey(),
			'mod_comment' => $summary,
			'mod_minor' => $opt->minor ? 1 : 0,
			'mod_bot' => $opt->bot ? 1 : 0,
			'mod_new' => $opt->existing ? 0 : 1,
			'mod_last_oldid' => $opt->existing ?
				$title->getLatestRevID( IDBAccessObject::READ_LATEST ) : 0,
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => $opt->existing ?
				$title->getLength( IDBAccessObject::READ_LATEST ) : 0,
			'mod_new_len' => $content->getSize(),
			'mod_header_xff' => $opt->xff ?? null,
			'mod_header_ua' => $opt->userAgent ?? null,
			'mod_preload_id' => $preload->getId( false ),
			'mod_rejected' => $opt->modblocked ? 1 : 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => $opt->modblocked ?
				wfMessage( 'moderation-blocker' )->inContentLanguage()->text() : null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => $opt->modblocked ? 1 : 0,
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
		$expectedConsequences = [
			new InsertRowIntoModerationTableConsequence( $expectedFields )
		];
		if ( !$opt->modblocked && $opt->notifyEmail && ( !$opt->notifyNewOnly || !$opt->existing ) ) {
			$expectedConsequences[] = new SendNotificationEmailConsequence(
				$title,
				$user,
				$modid
			);
		}

		$this->assertConsequencesEqual( $expectedConsequences, $manager->getConsequences() );
	}

	/**
	 * Provide datasets for testQueueEdit() runs.
	 * @return array
	 */
	public function dataProviderQueueEdit() {
		return [
			'logged-in edit' => [ [] ],
			'anonymous edit' => [ [ 'anonymously' => true ] ],
			'edit in Project namespace' => [ [ 'title' => 'Project:Title in another namespace' ] ],
			'edit in existing page' => [ [ 'existing' => true ] ],
			'edit with edit summary' => [ [ 'summary' => 'Summary 1' ] ],
			'edit with User-Agent' => [ [ 'userAgent' => 'UserAgent for Testing/1.0' ] ],
			'edit with XFF' => [ [ 'xff' => '10.11.12.13' ] ],
			'edit by modblocked user' => [ [ 'modblocked' => true ] ],
			'bot edit' => [ [ 'bot' => true ] ],
			'minor edit' => [ [ 'minor' => true, 'existing' => true ] ],
			'email notification' => [ [ 'notifyEmail' => 'noreply@localhost' ] ],
			'email notification for new page when $wgModerationNotificationNewOnly=true' =>
				[ [ 'notifyEmail' => 'noreply@localhost', 'notifyNewOnly' => true ] ],
			'absence of email for existing page when $wgModerationNotificationNewOnly=true' =>
				[ [
					'existing' => true,
					'notifyNewOnly' => true,
					'notifyEmail' => 'noreply@localhost'
				] ],
			'absence of email for edit by modblocked user' =>
				[ [
					'modblocked' => true,
					'notifyEmail' => 'noreply@localhost'
				] ],
		];
	}
}
