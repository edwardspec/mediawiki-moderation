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
 * Unit test of InstallApproveHookConsequence.
 */

use MediaWiki\Moderation\InstallApproveHookConsequence;

/**
 * @group Database
 */
class InstallApproveHookConsequenceTest extends MediaWikiTestCase {
	/** @var string[] */
	protected $tablesUsed = [ 'revision', 'page', 'user', 'recentchanges', 'cu_changes',
		'change_tag', 'logging', 'log_search' ];

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
	 * Verify that InstallApproveHookConsequence (without tags) works with one edit.
	 * This is the most common situation of ApproveHook being used in production,
	 * because tags are optional, and most edits won't have them.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEdit() {
		$this->runApproveHookTest( [ [ 'task' => $this->defaultTask() ] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence works when DeferredUpdates are immediate.
	 * This doesn't happen in production (unless ApproveHook is used in a maintenance script).
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEditImmediateDeferredUpdates() {
		$this->runApproveHookTest( [ [ 'task' => $this->defaultTask() ] ],
			false // Make DeferredUpdates immediate
		);
	}

	/**
	 * Verify that InstallApproveHookConsequence (with tags) works with one edit.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEditWithTags() {
		$this->runApproveHookTest( [ [
			'task' => [ 'tags' => "Sample tag 1\nSample tag 2" ] + $this->defaultTask()
		] ] );
	}

	/**
	 * Verify that ApproveHook changes wouldn't happen if ApproveHook wasn't installed.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testEditWithoutApproveHook() {
		$this->runApproveHookTest( [ [
			# Here runApproveHookTest() will still make an edit, but won't install ApproveHook.
			'task' => null
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence (without tags) works with one move.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneMove() {
		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => [ 'timestamp' => wfTimestampNow() ] + $this->defaultTask()
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence won't affect edits that weren't targeted by it.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testSomeEditsWithoutApproveHook() {
		$this->runApproveHookTest( [
			[ 'task' => [ 'title' => "UTPage1", 'user' => "TestUser1" ] + $this->defaultTask() ],
			[ 'task' => null ],
			[ 'task' => [ 'title' => "UTPage3", 'user' => "TestUser3" ] + $this->defaultTask() ],
		] );
	}

	/**
	 * Test situation when ApproveHook uses "CASE...WHEN...THEN" to reduce the number of SQL queries.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
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
	 * Precreate a page for IgnoredTimestamp tests.
	 * @param string $pageName
	 */
	private function precreatePage( $pageName ) {
		$title = Title::newFromText( $pageName );
		$this->makeEdit( $title, self::getTestUser( [ 'automoderated' ] )->getUser() );
	}

	/**
	 * Get timestamp in the past (N seconds ago).
	 * @param int $secondsAgo.
	 * @return string MediaWiki timestamp (14 digits).
	 */
	private function pastTimestamp( $secondsAgo = 10000 ) {
		return wfTimestamp( TS_MW, (int)wfTimestamp() - $secondsAgo );
	}

	/**
	 * Verify that timestamp of edit is ignored if more recent revisions exist in the history.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
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
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneMoveWithIgnoredTimestamp() {
		$this->runApproveHookTest( [ [
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'task' => $this->defaultTask(),
			'extra' => [ 'expectUnchangedTimestamp' => true ]
		] ] );
	}

	/**
	 * Test situation when ApproveHook uses "CASE...WHEN...THEN", but SOME timestamps are ignored.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
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
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
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

	// TODO: while testing installed InstallApproveHookConsequence followed by multiple edits,
	// also test that ApproveHook doesn't affect edits of another $title OR $user OR $type.

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
			$pageName = $testParameters['title'] ??
				'UTPage-' . ( ++$pageNameSuffix ) . '-' . rand( 0, 100000 );
			$username = $testParameters['user'] ?? '127.0.0.1';
			$type = $testParameters['type'] ?? ModerationNewChange::MOD_TYPE_EDIT;
			$task = $testParameters['task'] ?? []; // Consequence won't be called for empty task

			$title = Title::newFromText( $pageName );

			if ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$this->precreatePage( $pageName );
				$this->setGroupPermissions( '*', 'move', true );
			}

			$user = User::isIP( $username ) ?
				User::newFromName( $username, false ) :
				( new TestUser( $username ) )->getUser();

			return [ $title, $user, $type, $task, $testParameters['extra'] ?? [] ];
		}, $todo );

		'@phan-var list<array{0:Title,1:User,2:string,3:array<string,?string>,4:array}> $todo';

		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;
			if ( $task ) {
				// Create and run the Consequence.
				$consequence = new InstallApproveHookConsequence( $title, $user, $type, $task );
				$consequence->run();
			}
		}

		// Track ChangeTagsAfterUpdateTags hook to ensure that $task['tags'] are actually added.
		$taggedRevIds = [];
		$taggedLogIds = [];
		$taggedRcIds = [];
		$this->setTemporaryHook( 'ChangeTagsAfterUpdateTags', function (
			$tagsToAdd, $tagsToRemove, $prevTags,
			$rc_id, $rev_id, $log_id, $params, $rc, $user
		) use ( &$taggedRevIds, &$taggedLogIds, &$taggedRcIds ) {
			$this->assertEquals( [], $tagsToRemove );

			if ( $tagsToAdd == [ 'mw-new-redirect' ] ) {
				// This tag is irrelevant: it is added when creating a redirect during move tests,
				// it has nothing to do with ApproveHook.
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

		// Now make new edits and double-check that all changes from $task were applied to them.
		$this->setMwGlobals( 'wgModerationEnable', false ); // Edits shouldn't be intercepted

		if ( $deferUpdates ) {
			 // Delay any DeferredUpdates
			$this->setMwGlobals( 'wgCommandLineMode', false );
		}

		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;

			if ( $type == ModerationNewChange::MOD_TYPE_EDIT ) {
				$this->makeEdit( $title, $user );
			} elseif ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$this->makeMove( $title, $user );
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
				$expectedIP .= '/32';
			}

			$rcWhere = [
				'rc_namespace' => $title->getNamespace(),
				'rc_title' => $title->getDBKey(),
				'rc_actor' => $user->getActorId()
			];
			if ( $type == ModerationNewChange::MOD_TYPE_MOVE ) {
				$rcWhere['rc_log_action'] = 'move';
			}

			if ( $rcWhere['rc_actor'] == 0 ) {
				// B/C: MediaWiki 1.31 doesn't use Actors by default.
				unset( $rcWhere['rc_actor'] );
				$rcWhere['rc_user_text'] = $user->getName();
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
			if ( $rc->mAttribs['rc_log_action'] == 'move' ) {
				// For page moves, two revisions should have been modified:
				// 1) revision in newly created redirect, 2) "page was moved" null revision.
				$revIds[] = $rc->getTitle()->getLatestRevID( IDBAccessObject::READ_LATEST );
			}

			if ( $task ) {
				foreach ( $revIds as $rev_id ) {
					$rev = Revision::newFromId( $rev_id, Revision::READ_LATEST );

					if ( empty( $extraInfo['expectUnchangedTimestamp'] ) ) {
						// Verify that ApproveHook has modified revision.rev_timestamp field.
						$this->assertEquals( $task['timestamp'], $rev->getTimestamp() );
					} else {
						$this->assertNotEquals( $task['timestamp'], $rev->getTimestamp() );
					}
				}
			}

			if ( ExtensionRegistry::getInstance()->isLoaded( 'CheckUser' ) ) {
				// Verify that ApproveHook has modified fields in cuc_changes table.
				$expectedRow = [ '127.0.0.1', IP::toHex( '127.0.0.1' ), '0' ];
				if ( $task ) {
					$expectedRow = [
						$task['ip'],
						// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
						$task['ip'] ? IP::toHex( $task['ip'] ) : null,
						$task['ua']
					];
				}

				$this->assertSelect( 'cu_changes',
					[
						'cuc_ip',
						'cuc_ip_hex',
						'cuc_agent'
					],
					[
						'cuc_namespace' => $title->getNamespace(),
						'cuc_title' => $title->getDBKey(),
						'cuc_user_text' => $user->getName()
					],
					[ $expectedRow ]
				);
			}

			// Verify that ChangeTagsAfterUpdateTags hook was called for all revisions, etc.

			// @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal
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
			if ( $logWhere['log_actor'] == 0 ) {
				// B/C: MediaWiki 1.31 doesn't use Actors by default.
				unset( $logWhere['log_actor'] );
				$logWhere['log_user_text'] = $user->getName();
			}

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
	 * Make one test edit on behalf of $user in page $title.
	 * @param Title $title
	 * @param User $user
	 * @return int rev_id of the newly created edit.
	 */
	private function makeEdit( Title $title, User $user ) {
		$text = 'Some text ' . rand( 0, 100000 ) . ' in page ' .
			$title->getFullText() . ' by ' . $user->getName();

		// B/C workaround for User::getBlockedStatus() trying to use $wgUser in MediaWiki 1.31,
		// which leads to WebRequest::getIP() being used (which fails) instead of FauxRequest.
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.32.0', '<' ) ) {
			$request = RequestContext::getMain()->getRequest();
			if ( $request instanceof WebRequest ) {
				RequestContext::getMain()->setRequest( new FauxRequest() );
			}
		}

		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT ),
			'Some edit summary',
			EDIT_INTERNAL,
			false,
			$user
		);
		$this->assertTrue( $status->isGood(), "Edit failed: " . $status->getMessage()->plain() );

		return $status->value['revision']->getId();
	}

	/**
	 * Make one test renaming of $title on behalf of $user, return new title.
	 * @param Title $title
	 * @param User $user
	 * @return Title
	 */
	private function makeMove( Title $title, User $user ) {
		$newTitle = Title::newFromText( $title->getPrefixedText() . '-newTitle' );
		$this->assertTrue( $title->exists(),
			"makeMove(): page doesn't exist: " . $title->getFullText() );

		$reason = 'Some reason to rename the page';
		$createRedirect = true;

		$mp = new MovePage( $title, $newTitle );

		/* Sanity checks like "page with the new name should not exist" */
		$status = $mp->isValidMove();
		if ( $status->isOK() ) {
			$status->merge( $mp->checkPermissions( $user, $reason ) );
			if ( $status->isOK() ) {
				$status->merge( $mp->move( $user, $reason, $createRedirect ) );
			}
		}

		$this->assertTrue( $status->isGood(), "Move failed: " . $status->getMessage()->plain() );

		return $newTitle;
	}

	/**
	 * Destroy ApproveHook object after the test.
	 */
	protected function tearDown() {
		ModerationApproveHook::destroySingleton();
		parent::tearDown();
	}

	public function setUp() {
		parent::setUp();

		// Workaround for MediaWiki 1.31 only: its TestCase class doesn't clean tables properly
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.32.0', '<' ) && $this->db->getType() == 'mysql' ) {
			foreach ( $this->tablesUsed as $table ) {
				$this->db->delete( $this->db->tableName( $table ), '*', __METHOD__ );
			}
		}
	}

	/**
	 * Override the parent method, so that UTSysop and UTPage are not created.
	 * This test doesn't use them, and having to filter them out complicates assertSelect() calls.
	 */
	protected function addCoreDBData() {
		// Nothing
	}
}
