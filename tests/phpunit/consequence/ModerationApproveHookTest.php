<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2022 Edward Chernenko.

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
 * Unit test of ModerationApproveHook.
 */

use MediaWiki\MediaWikiServices;
use Psr\Log\NullLogger;
use Wikimedia\IPUtils;
use Wikimedia\ScopedCallback;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ModerationApproveHookTest extends ModerationUnitTestCase {
	use MakeEditTestTrait;
	use UploadTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'revision', 'page', 'user', 'recentchanges',
		'change_tag', 'logging', 'log_search', 'image', 'oldimage' ];

	public function setUp(): void {
		parent::setUp();

		if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) ) {
			// PostgreSQL tests may run without Extension:CheckUser due to T241827.
			$this->tablesUsed[] = 'cu_changes';
		}
	}

	/**
	 * Verify that uploading a file adds missing "revid" to LogEntry passed to checkLogEntry().
	 * @param array $logEntryParams Parameters for ManualLogEntry.
	 * @param bool $isReupload True to reupload the image, false to upload a new image.
	 * @dataProvider dataProviderCheckLogEntry
	 * @covers ModerationApproveHook
	 */
	public function testCheckLogEntry( array $logEntryParams, $isReupload ) {
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );
		$user =	self::getTestUser( [ 'automoderated' ] )->getUser();

		if ( $isReupload ) {
			// Precreate file with the same name.
			$upload = $this->prepareTestUpload( $title );
			$status = $upload->performUpload( '', '', false, $user );
			$this->assertTrue( $status->isGood(), "Upload failed: " . $status->getMessage()->plain() );
		}

		$approveHook = new ModerationApproveHook( new NullLogger() );
		$this->setService( 'Moderation.ApproveHook', $approveHook );

		$logEntry = new ManualLogEntry( 'moderation', 'SomeLogAction' );
		$logEntry->setTarget( $title );
		$logEntry->setPerformer( $user );
		$logEntry->setParameters( $logEntryParams );
		$logid = $logEntry->insert();

		$approveHook->checkLogEntry( $logid, $logEntry );

		// Now trigger an upload and determine if LogEntry was changed in the database.
		$upload = $this->prepareTestUpload( $title, $this->anotherSampleImageFile );
		$status = $upload->performUpload( '', '', false, $user );
		$this->assertTrue( $status->isGood(), "Upload failed: " . $status->getMessage()->plain() );

		// Only uploading a new file should modify log_params (reuploads should leave it unmodified).
		// Furthermore, if "revid" parameter is already not null, then it shouldn't be modified either.
		$expectedParams = $logEntryParams;
		if ( !$isReupload && !isset( $logEntryParams['revid'] ) ) {
			$expectedParams['revid'] = $title->getLatestRevId( IDBAccessObject::READ_LATEST );
		}

		$this->assertSelect( 'logging',
			[ 'log_params' ],
			[ 'log_action' => 'SomeLogAction' ],
			[ [ LogEntryBase::makeParamBlob( $expectedParams ) ] ]
		);
	}

	/**
	 * Verify that getLastRevId() returns rev_id of the most recently created revision.
	 * @covers ModerationApproveHook::getLastRevId
	 * @covers ModerationApproveHook::onRevisionFromEditComplete
	 */
	public function testGetLastRevId() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user =	self::getTestUser( [ 'automoderated' ] )->getUser();

		$approveHook = new ModerationApproveHook( new NullLogger() );
		$this->setService( 'Moderation.ApproveHook', $approveHook );

		$revid = $this->makeEdit( $title, $user );
		$this->assertSame( $revid, $approveHook->getLastRevId(),
			'Value returned by getLastRevId() is different from rev_id of newly created revision.' );
	}

	/**
	 * Verify that isApprovingNow() returns false if no tasks were added to ApproveHook.
	 * @covers ModerationApproveHook::isApprovingNow
	 */
	public function testIsApprovingNowNo() {
		$approveHook = new ModerationApproveHook( new NullLogger() );
		$this->assertFalse( $approveHook->isApprovingNow(),
			'No tasks were added to ApproveHook, but isApprovingNow() returned true.' );
	}

	/**
	 * Verify that isApprovingNow() returns false if no tasks were added to ApproveHook.
	 * @covers ModerationApproveHook::isApprovingNow
	 */
	public function testIsApprovingNowYes() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = User::newFromName( '10.11.12.13', false );
		$type = 'move';
		$task = [ 'ip' => 'a', 'xff' => 'b', 'ua' => 'c', 'tags' => 'd', 'timestamp' => 'e' ];

		$approveHook = new ModerationApproveHook( new NullLogger() );
		$approveHook->addTask( $title, $user, $type, $task );

		$this->assertTrue( $approveHook->isApprovingNow(),
			'A task was added to ApproveHook, but isApprovingNow() returned false.' );
	}

	/**
	 * Provide datasets for testCheckLogEntry() runs.
	 * @return array
	 */
	public function dataProviderCheckLogEntry() {
		return [
			'logEntry with missing revid parameter (must be fixed by ApproveHook)' =>
				[ [ 'revid' => null ], false ],
			'logEntry that doesn\'t need to be fixed by ApproveHook' =>
				[ [ 'revid' => 12345 ], false ],
			'reupload (checkLogEntry shouldn\'t do anything)' =>
				[ [ 'revid' => null ], true ],
		];
	}

	/**
	 * @return array
	 * @phan-return array<string,?string>
	 */
	private function defaultTask() {
		return [
			'ip' => '10.11.12.13',
			'xff' => '10.20.30.40',
			'ua' => 'Some-User-Agent/1.2.3',
			'tags' => null, // Tags are optional, and most edits won't have them
			'timestamp' => $this->pastTimestamp()
		];
	}

	/**
	 * Verify that ApproveHook (without tags) works with one edit.
	 * This is the most common situation of ApproveHook being used in production,
	 * because tags are optional, and most edits won't have them.
	 * @covers ModerationApproveHook
	 */
	public function testOneEdit() {
		$this->runApproveHookTest( [ [ 'task' => $this->defaultTask() ] ] );
	}

	/**
	 * Verify that ApproveHook works when DeferredUpdates are immediate.
	 * This doesn't happen in production (unless ApproveHook is used in a maintenance script).
	 * @covers ModerationApproveHook
	 */
	public function testOneEditImmediateDeferredUpdates() {
		$this->runApproveHookTest( [ [ 'task' => $this->defaultTask() ] ],
			false // Make DeferredUpdates immediate
		);
	}

	/**
	 * Verify that ApproveHook (with tags) works with one edit.
	 * @covers ModerationApproveHook
	 */
	public function testOneEditWithTags() {
		$this->runApproveHookTest( [ [
			'task' => [ 'tags' => "Sample tag 1\nSample tag 2" ] + $this->defaultTask()
		] ] );
	}

	/**
	 * Verify that ApproveHook changes wouldn't happen if ApproveHook wasn't installed for this edit.
	 * @covers ModerationApproveHook
	 */
	public function testEditWithoutApproveHook() {
		$this->runApproveHookTest( [ [
			# Here runApproveHookTest() will still make an edit, but won't install ApproveHook.
			'task' => null
		] ] );
	}

	/**
	 * Verify that ApproveHook (without tags) works with one move.
	 * @covers ModerationApproveHook
	 */
	public function testOneMove() {
		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => $this->defaultTask()
		] ] );
	}

	/**
	 * Verify that ApproveHook works with one move with suppressed redirect.
	 * @covers ModerationApproveHook
	 */
	public function testOneMoveWithoutRedirect() {
		$this->setGroupPermissions( '*', 'suppressredirect', true );

		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => $this->defaultTask(),
			'extra' => [ 'createRedirect' => false ]
		] ] );
	}

	/**
	 * Verify that ApproveHook changes wouldn't happen if ApproveHook wasn't installed for this move.
	 * @covers ModerationApproveHook
	 */
	public function testMoveWithoutApproveHook() {
		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => null
		] ] );
	}

	/**
	 * Verify that ApproveHook won't affect edits that weren't targeted by it,
	 * e.g. changes without ApproveHook or with another $title OR $user OR $type.
	 * @covers ModerationApproveHook
	 */
	public function testSomeEditsWithoutApproveHook() {
		$this->runApproveHookTest( [
			[ 'task' => [ 'title' => "UTPage1", 'user' => "TestUser1" ] + $this->defaultTask() ],
			[ 'task' => null ],
			[ 'task' => [ 'title' => "UTPage3", 'user' => "TestUser3" ] + $this->defaultTask() ],
			[ 'task' => null ],
			[
				'type' => 'move',
				'task' => [ 'title' => "UTPage5", 'user' => "TestUser5" ] + $this->defaultTask()
			]
		] );
	}

	/**
	 * Test situation when ApproveHook uses "CASE...WHEN...THEN" to reduce the number of SQL queries.
	 * @covers ModerationApproveHook
	 */
	public function testCaseWhenThenChanges() {
		$this->runApproveHookTest( [
			[ 'task' => [
				'ip' => '10.0.0.1',
				'xff' => '10.20.0.1',
				'ua' => 'Some-User-Agent/0.0.1',
				'tags' => null,
				'timestamp' => $this->pastTimestamp( 1000 )
			] ],
			[ 'task' => [
				'ip' => '10.0.0.2',
				'xff' => '10.20.0.2',
				'ua' => 'Some-User-Agent/0.0.2',
				'tags' => null,
				'timestamp' => $this->pastTimestamp( 2000 )
			] ],
			[ 'task' => [
				'ip' => '10.0.0.3',
				'xff' => '10.20.0.3',
				'ua' => 'Some-User-Agent/0.0.3',
				'tags' => null,
				'timestamp' => $this->pastTimestamp( 3000 )
			] ]

		] );
	}

	/**
	 * Verify that ApproveHook works when a user edits AND moves the same page.
	 * This is what happens during modaction=approveall, where moves are approved after edits.
	 * @covers ModerationApproveHook
	 */
	public function testEditAndMoveWithSameUserAndPage() {
		$pageName = 'UTPage-to-both-edit-and-move';
		$username = 'Test user 567';

		$this->runApproveHookTest( [
			[
				'title' => $pageName,
				'user' => $username,
				'task' => [
					'ip' => '10.0.0.1',
					'xff' => '10.20.0.1',
					'ua' => 'Some-User-Agent/0.0.1',
					'tags' => null,
					'timestamp' => $this->pastTimestamp( 2000 )
				]
			],
			[
				'title' => $pageName,
				'user' => $username,
				'type' => ModerationNewChange::MOD_TYPE_MOVE,
				'task' => [
					'ip' => '10.0.0.2',
					'xff' => '10.20.0.2',
					'ua' => 'Some-User-Agent/0.0.2',
					'tags' => null,
					'timestamp' => $this->pastTimestamp( 1000 )
				]
			]
		] );
	}

	/**
	 * Verify that ApproveHook works when a user moves AND edits the same page.
	 * Same as testEditAndMoveWithSameUserAndPage(), but move is performed before the edit.
	 * @covers ModerationApproveHook
	 */
	public function testMoveAndEditWithSameUserAndPage() {
		$pageName = 'UTPage-to-both-move-and-edit';
		$username = 'Test user 567';

		$this->runApproveHookTest( [
			[
				'title' => $pageName,
				'user' => $username,
				'type' => ModerationNewChange::MOD_TYPE_MOVE,
				'task' => [
					'ip' => '10.0.0.2',
					'xff' => '10.20.0.2',
					'ua' => 'Some-User-Agent/0.0.2',
					'tags' => null,
					'timestamp' => $this->pastTimestamp( 2000 )
				]
			],
			[
				'title' => $pageName,
				'user' => $username,
				'task' => [
					'ip' => '10.0.0.1',
					'xff' => '10.20.0.1',
					'ua' => 'Some-User-Agent/0.0.1',
					'tags' => null,
					'timestamp' => $this->pastTimestamp( 1000 )
				]
			]
		] );
	}

	/**
	 * Verify that ApproveHook won't populate rc_id if $wgPutIPinRC=false.
	 * @covers ModerationApproveHook
	 */
	public function testOneEditDisabledPutIPinRC() {
		$this->setMwGlobals( 'wgPutIPinRC', false );
		$this->runApproveHookTest( [ [ 'task' => $this->defaultTask() ] ] );
	}

	/**
	 * Precreate a page for IgnoredTimestamp tests.
	 * @param string $pageName
	 * @param string|null $text
	 * @return int rev_id
	 */
	private function precreatePage( $pageName, $text = null ) {
		return $this->makeEdit( Title::newFromText( $pageName ),
			self::getTestUser( [ 'automoderated' ] )->getUser(), $text );
	}

	/**
	 * Precreate a page and make rev_timestamp of its initial revision to 1 January 1970.
	 * @param string $pageName
	 * @param string|null $text
	 */
	private function precreatePageLongAgo( $pageName, $text = null ) {
		$revid = $this->precreatePage( $pageName, $text );

		// Ensure that rev_timestamp of precreated revision is extremely far in the past
		// and won't trigger "rev_timestamp ignored" situation
		// unless the test specifically causes it.
		$this->db->update( 'revision',
			[ 'rev_timestamp' => $this->db->timestamp( '19700101000000' ) ],
			[ 'rev_id' => $revid ],
			__METHOD__
		);
		$this->assertSame( 1, $this->db->affectedRows() );
	}

	/**
	 * Verify that timestamp of edit is ignored if more recent revisions exist in the history.
	 * @covers ModerationApproveHook
	 */
	public function testOneEditWithIgnoredTimestamp() {
		// Precreate a page: if history doesn't exist, then rev_timestamp is never ignored.
		$pageName = 'UTPage-' . rand( 0, 100000 );
		$this->precreatePage( $pageName );

		$this->runApproveHookTest( [ [
			'title' => $pageName,
			'task' => [
				'ip' => '10.11.12.13',
				'xff' => '10.20.30.40',
				'ua' => 'Some-User-Agent/1.2.3',
				'tags' => "Sample tag 1\nSample tag 2",

				// This timestamp will be ignored, because it's earlier than timestamp of existing edit.
				'timestamp' => $this->pastTimestamp()
			],
			'extra' => [ 'expectUnchangedTimestamp' => true ]
		] ] );
	}

	/**
	 * Verify that timestamp of move is ignored if more recent revisions exist in the history.
	 * @covers ModerationApproveHook
	 */
	public function testOneMoveWithIgnoredTimestamp() {
		$pageName = 'UTPage-' . rand( 0, 100000 );
		$this->precreatePage( $pageName );

		$this->runApproveHookTest( [ [
			'title' => $pageName,
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => $this->defaultTask(),
			'extra' => [
				'expectUnchangedTimestamp' => true
			]
		] ] );
	}

	/**
	 * Test situation when ApproveHook uses "CASE...WHEN...THEN", but SOME timestamps are ignored.
	 * @covers ModerationApproveHook
	 */
	public function testCaseWhenThenIgnoredTimestamp() {
		// Precreate a page: if history doesn't exist, then rev_timestamp is never ignored.
		$pageName = 'UTPage with ignored timestamp-' . rand( 0, 100000 );
		$this->precreatePage( $pageName );

		$this->runApproveHookTest( [
			[ 'task' => [
				'ip' => '10.0.0.1',
				'xff' => '10.20.0.1',
				'ua' => 'Some-User-Agent/0.0.1',
				'tags' => null,
				'timestamp' => $this->pastTimestamp( 1000 )
			] ],
			[
				'title' => $pageName,
				'task' => [
					'ip' => '10.0.0.2',
					'xff' => '10.20.0.2',
					'ua' => 'Some-User-Agent/0.0.2',
					'tags' => null,
					'timestamp' => $this->pastTimestamp( 2000 )
				],
				'extra' => [ 'expectUnchangedTimestamp' => true ]
			],
			[ 'task' => [
				'ip' => '10.0.0.3',
				'xff' => '10.20.0.3',
				'ua' => 'Some-User-Agent/0.0.3',
				'tags' => null,
				'timestamp' => $this->pastTimestamp( 3000 )
			] ]

		] );
	}

	/**
	 * Test situation when ApproveHook uses "CASE...WHEN...THEN", but ALL timestamps are ignored.
	 * @covers ModerationApproveHook
	 */
	public function testCaseWhenThenIgnoredAllTimestamps() {
		// Precreate a page: if history doesn't exist, then rev_timestamp is never ignored.
		$pageName1 = 'UTPage-1 with ignored timestamp-' . rand( 0, 100000 );
		$this->precreatePage( $pageName1 );

		$pageName2 = 'UTPage-2 with ignored timestamp-' . rand( 0, 100000 );
		$this->precreatePage( $pageName2 );

		$ignoredTimestamp = $this->pastTimestamp();

		$this->runApproveHookTest( [
			[
				'title' => $pageName1,
				'task' => [
					'ip' => '10.0.0.2',
					'xff' => '10.20.0.2',
					'ua' => 'Some-User-Agent/0.0.2',
					'tags' => null,
					'timestamp' => $ignoredTimestamp
				],
				'extra' => [ 'expectUnchangedTimestamp' => true ]
			],
			[
				'title' => $pageName2,
				'task' => [
					'ip' => '10.0.0.2',
					'xff' => '10.20.0.2',
					'ua' => 'Some-User-Agent/0.0.2',
					'tags' => null,
					'timestamp' => $ignoredTimestamp
				],
				'extra' => [ 'expectUnchangedTimestamp' => true ]
			]
		] );
	}

	/**
	 * Verify that ApproveHook works with a move that overwrites a redirect,
	 * i.e. when before the move $oldTitle was an article and $newTitle a redirect to $oldTitle.
	 * @covers ModerationApproveHook
	 */
	public function testOneMoveOverwriteRedirect() {
		$pageName = 'UTPage-' . rand( 0, 100000 );
		$newPageName = 'NewTitleAfterMoveIsExistingPageThatWasARedirectToOldTitle';

		$this->precreatePageLongAgo( $newPageName, "#REDIRECT [[$pageName]]" );

		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => [ 'timestamp' => wfTimestampNow() ] + $this->defaultTask(),
			'title' => $pageName,
			'extra' => [ 'newTitle' => $newPageName ]
		] ] );
	}

	/**
	 * Same as testOneMoveOverwriteRedirect(), but with ignored timestamp.
	 * @covers ModerationApproveHook
	 */
	public function testOneMoveOverwriteRedirectWithIgnoredTimestamp() {
		$pageName = 'UTPage-' . rand( 0, 100000 );
		$newPageName = 'NewTitleAfterMoveIsExistingPageThatWasARedirectToOldTitle';

		$this->precreatePage( $pageName, "Some text" );
		$this->precreatePage( $newPageName, "#REDIRECT [[$pageName]]" );

		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => $this->defaultTask(),
			'title' => $pageName,
			'timestamp' => $this->pastTimestamp(),
			'extra' => [ 'newTitle' => $newPageName, 'expectUnchangedTimestamp' => true ]
		] ] );
	}

	/**
	 * Run the ApproveHook test with selected list of edits.
	 * For each edit, Title, User and type ("edit" or "move") must be specified,
	 * and also optional $task for ApproveHook itself (if null, then ApproveHook is NOT installed).
	 *
	 * @param array $todo
	 * @param bool $deferUpdates If false, any DeferredUpdates are executed immediately.
	 *
	 * @codingStandardsIgnoreStart
	 * @phan-param list<array{title?:string,user?:string,type?:string,task:?array<string,?string>,extra?:array}> $todo
	 * @codingStandardsIgnoreEnd
	 */
	private function runApproveHookTest( array $todo, $deferUpdates = true ) {
		static $pageNameSuffix = 0; // Added to default titles of pages, incremented each time.

		// Convert pagename and username parameters (#0 and #1) to Title/User objects
		$todo = array_map( function ( $testParameters ) use ( &$pageNameSuffix ) {
			// [ 'pageName1' => true, ... ] - keep track of what pages will be created by $todo
			static $pageCreatedByThisTest = [];

			$pageName = $testParameters['title'] ??
				'UTPage-' . ( ++$pageNameSuffix ) . '-' . rand( 0, 100000 );
			$username = $testParameters['user'] ?? '127.0.0.1';
			$type = $testParameters['type'] ?? ModerationNewChange::MOD_TYPE_EDIT;
			$task = $testParameters['task'] ?? []; // Consequence won't be called for empty task

			$title = Title::newFromText( $pageName );

			if ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$this->setGroupPermissions( '*', 'move', true );

				// Precreate the page that will be moved,
				// unless it was created by the previous task in this $todo.
				if ( !$title->exists() && !isset( $pageCreatedByThisTest[$pageName] ) ) {
					$this->precreatePageLongAgo( $pageName );
				}
			} else {
				$pageCreatedByThisTest[$pageName] = true;
			}

			$user = IPUtils::isIPAddress( $username ) ?
				User::newFromName( $username, false ) :
				( new TestUser( $username ) )->getUser();

			$extraInfo = $testParameters['extra'] ?? [];
			if ( isset( $extraInfo['newTitle'] ) ) {
				$extraInfo['newTitle'] = Title::newFromText( $extraInfo['newTitle'] );
			} else {
				$extraInfo['newTitle'] = Title::newFromText( $title->getPrefixedText() . '-newTitle' );
			}

			$extraInfo['createRedirect'] = $extraInfo['createRedirect'] ?? true;

			return [ $title, $user, $type, $task, $extraInfo ];
		}, $todo );

		'@phan-var list<array{0:Title,1:User,2:string,3:array<string,?string>,4:array}> $todo';

		// Track ChangeTagsAfterUpdateTags hook to ensure that $task['tags'] are actually added.
		$taggedRevIds = [];
		$taggedLogIds = [];
		$taggedRcIds = [];
		$this->setTemporaryHook( 'ChangeTagsAfterUpdateTags', function (
			$tagsToAdd, $tagsToRemove, $prevTags,
			$rc_id, $rev_id, $log_id, $params, $rc, $user
		) use ( &$taggedRevIds, &$taggedLogIds, &$taggedRcIds ) {
			$this->assertEquals( [], $tagsToRemove );

			if ( $tagsToAdd == [ 'mw-new-redirect' ] || $tagsToAdd == [ 'mw-removed-redirect' ] ) {
				// This tag is irrelevant: it is added when creating/removing a redirect
				// during move tests, it has nothing to do with ApproveHook.
				return true;
			}

			if ( $rev_id !== false ) {
				$taggedRevIds[$rev_id] = $tagsToAdd;
			}

			if ( $log_id !== false ) {
				$taggedLogIds[$log_id] = $tagsToAdd;
			}

			if ( $rc_id !== false ) {
				$taggedRcIds[$rc_id] = $tagsToAdd;
			}

			return true;
		} );

		$this->setMwGlobals( 'wgModerationEnable', false ); // Edits shouldn't be intercepted

		if ( $deferUpdates ) {
			 // Delay any DeferredUpdates
			$this->setMwGlobals( 'wgCommandLineMode', false );

			// Prevent getRequest() from making WebRequest due to $wgCommandLineMode=false.
			// Tests must always use FauxRequest.
			RequestContext::getMain()->setRequest( new FauxRequest() );

			/*
				MediaWiki 1.37+ has a transaction listener (see LoadBalancer::setTransactionListener())
				that causes all tests to run DeferredUpdates::tryOpportunisticExecute() on every commit,
				thus causing all deferred updates to be executed immediately.

				This is unacceptable,
				because this test (ApproveHookTest) requires that DeferredUpdates are NOT immediate, but delayed,
				since we are testing how ApproveHook would behave in production (where this is the case),
				and there is a significant difference between these two modes (immediate/not immediate).

				Immediate mode is also tested (separately) in testOneEditImmediateDeferredUpdates().
			*/
			$trxListenerName = 'MWLBFactory::applyGlobalState';
			$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();

			// Remember the original listener.
			$wrapper = TestingAccessWrapper::newFromObject( $loadBalancer );
			$originalTrxCallback = $wrapper->trxRecurringCallbacks[$trxListenerName] ?? null;

			// Delete the original listener.
			$loadBalancer->setTransactionListener( $trxListenerName );

			// @phan-suppress-next-line PhanUnusedVariable
			$scope = new ScopedCallback( static function ()
				use ( $trxListenerName, $loadBalancer, $originalTrxCallback )
			{
				// Restore the original listener after the test.
				$loadBalancer->setTransactionListener( $trxListenerName, $originalTrxCallback );
			} );
		}

		// Step 1: install ApproveHook for edits that will happen later.
		$approveHook = new ModerationApproveHook( new NullLogger() );
		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;
			if ( $task ) {
				// Install ApproveHook.
				$approveHook->addTask( $title, $user, $type, $task );
			}
		}
		$this->setService( 'Moderation.ApproveHook', $approveHook );

		// Step 2: make new edits and double-check that all changes from $task were applied to them.
		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $_, $extraInfo ) = $testParameters;

			if ( $type == ModerationNewChange::MOD_TYPE_EDIT ) {
				$this->makeEdit( $title, $user );
			} elseif ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$this->makeMove( $title, $user, $extraInfo['newTitle'], $extraInfo['createRedirect'] );
			} else {
				throw new MWException( "Unknown type: $type" );
			}
		}

		if ( $deferUpdates ) {
			// Run any DeferredUpdates that may have been queued when making edits.
			// Note: PRESEND must be first, as this is where RecentChanges_save hooks are called,
			// and results of these hooks are used by ApproveHook, which is in POSTSEND.
			DeferredUpdates::doUpdates( 'run', DeferredUpdates::PRESEND );
			DeferredUpdates::doUpdates( 'run', DeferredUpdates::POSTSEND );
		}

		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task, $extraInfo ) = $testParameters;

			$expectedIP = $task ? $task['ip'] : '127.0.0.1';
			if ( $this->db->getType() == 'postgres' ) {
				if ( version_compare( MW_VERSION, '1.36.0', '<' ) ) {
					// MediaWiki 1.35 only.
					$expectedIP .= '/32';
				}
			}

			global $wgPutIPinRC;
			if ( !$wgPutIPinRC ) {
				// Ensure that ApproveHook respects $wgPutIPinRC.
				$expectedIP = '';
			}

			$rcWhere = [
				'rc_namespace' => $title->getNamespace(),
				'rc_title' => $title->getDBKey(),
				'rc_actor' => $user->getActorId()
			];
			if ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$rcWhere['rc_log_action'] = [ 'move', 'move_redir' ];
			} else {
				$rcWhere['rc_log_action'] = '';
			}

			// Verify that ApproveHook has modified recentchanges.rc_ip field.
			$this->assertSelect(
				'recentchanges',
				[ 'rc_ip' ],
				$rcWhere,
				[ [ $expectedIP ] ]
			);

			$rc = RecentChange::newFromConds( $rcWhere, __METHOD__, DB_MASTER );
			$rc_id = $rc->mAttribs['rc_id'];

			$revIds = [ $rc->mAttribs['rc_this_oldid'] ];
			if ( $rc->mAttribs['rc_log_action'] == 'move' && $extraInfo['createRedirect'] ) {
				// For page moves, two revisions should have been modified:
				// 1) "page was moved" null revision. (which is rc_this_oldid)
				// 2) revision in newly created redirect. (next revision after (1))
				$revIds[] = $this->db->selectField( 'revision', 'rev_id',
					[ 'rev_id > ' . $this->db->addQuotes( $rc->mAttribs['rc_this_oldid'] ) ],
					__METHOD__,
					[ 'ORDER BY' => 'rev_id' ]
				);
			}

			if ( $task ) {
				$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
				foreach ( $revIds as $rev_id ) {
					$rec = $revisionLookup->getRevisionById( $rev_id, IDBAccessObject::READ_LATEST );

					if ( empty( $extraInfo['expectUnchangedTimestamp'] ) || !$rec->getParentId() ) {
						// Verify that ApproveHook has modified revision.rev_timestamp field.
						$this->assertEquals( $task['timestamp'], $rec->getTimestamp() );
					} else {
						$this->assertNotEquals( $task['timestamp'], $rec->getTimestamp() );
					}
				}
			}

			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) ) {
				// Verify that ApproveHook has modified fields in cuc_changes table.
				$emptyUserAgent = ''; // MediaWiki 1.38+
				if ( version_compare( MW_VERSION, '1.38.0-alpha', '<' ) ) {
					$emptyUserAgent = '0'; // MediaWiki 1.35-1.37
				}

				$expectedRow = [ '127.0.0.1', IPUtils::toHex( '127.0.0.1' ), $emptyUserAgent, '0' ];
				if ( $task ) {
					$expectedRow = [
						$task['ip'],
						// @phan-suppress-next-line PhanTypeMismatchArgumentNullable - false positive
						$task['ip'] ? IPUtils::toHex( $task['ip'] ) : null,
						$task['ua'],
						$task['xff']
					];
				}

				$cuWhere = [
					'cuc_namespace' => $title->getNamespace(),
					'cuc_title' => $title->getDBKey(),
					'cuc_user_text' => $user->getName()
				];
				if ( $type == ModerationNewChange::MOD_TYPE_EDIT ) {
					$cuWhere['cuc_actiontext'] = '';
				} else {
					$cuWhere[] = 'cuc_actiontext <> ""';
				}

				$this->assertSelect( 'cu_changes',
					[
						'cuc_ip',
						'cuc_ip_hex',
						'cuc_agent',
						'cuc_xff'
					],
					$cuWhere,
					[ $expectedRow ]
				);
			}

			// Verify that ChangeTagsAfterUpdateTags hook was called for all revisions, etc.
			// @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal - false positive
			$expectedTags = empty( $task['tags'] ) ? null : explode( "\n", $task['tags'] );

			foreach ( $revIds as $rev_id ) {
				$this->assertEquals( $expectedTags, $taggedRevIds[$rev_id] ?? null );
				unset( $taggedRevIds[$rev_id] );
			}

			$this->assertEquals( $expectedTags, $taggedRcIds[$rc_id] ?? null );
			unset( $taggedRcIds[$rc_id] );

			$logWhere = [
				'log_namespace' => $title->getNamespace(),
				'log_title' => $title->getDBKey(),
				'log_actor' => $user->getActorId()
			];

			$log_ids = $this->db->selectFieldValues( 'logging', 'log_id', $logWhere, __METHOD__ );
			foreach ( $log_ids as $log_id ) {
				$this->assertEquals( $expectedTags, $taggedLogIds[$log_id] ?? null );
				unset( $taggedLogIds[$log_id] );
			}
		}

		// Verify that things that shouldn't have been tagged weren't tagged.
		$this->assertEmpty( $taggedRevIds );
		$this->assertEmpty( $taggedRcIds );
		$this->assertEmpty( $taggedLogIds );
	}

	/**
	 * Make one test renaming of $title on behalf of $user, return new title.
	 * @param Title $title
	 * @param User $user
	 * @param Title $newTitle
	 * @param bool $createRedirect
	 */
	private function makeMove( Title $title, User $user, Title $newTitle, $createRedirect ) {
		$this->assertTrue( $title->exists(),
			"makeMove(): page doesn't exist: " . $title->getFullText() );

		$reason = 'Some reason to rename the page';
		$mp = MediaWikiServices::getInstance()->getMovePageFactory()->newMovePage( $title, $newTitle );

		/* Sanity checks like "page with the new name should not exist" */
		$status = $mp->isValidMove();
		if ( $status->isOK() ) {
			$status->merge( $mp->checkPermissions( $user, $reason ) );
			if ( $status->isOK() ) {
				$status->merge( $mp->move( $user, $reason, $createRedirect ) );
			}
		}

		$this->assertTrue( $status->isGood(), "Move failed: " . $status->getMessage()->plain() );
	}
}
