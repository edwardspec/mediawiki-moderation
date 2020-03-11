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
	 * Verify that InstallApproveHookConsequence (without tags) works with one edit.
	 * This is the most common situation of ApproveHook being used in production.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEdit() {
		$this->runApproveHookTest( [ [
			'task' => [
				'ip' => '10.11.12.13',
				'xff' => '10.20.30.40',
				'ua' => 'Some-User-Agent/1.2.3',
				'tags' => null, // Tags are optional, and most edits won't have them
				'timestamp' => wfTimestamp( TS_MW, (int)wfTimestamp() - 100000 )
			]
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence (with tags) works with one edit.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testOneEditWithTags() {
		$this->runApproveHookTest( [ [
			'task' => [
				'ip' => '10.11.12.13',
				'xff' => '10.20.30.40',
				'ua' => 'Some-User-Agent/1.2.3',
				'tags' => "Sample tag 1\nSample tag 2",
				'timestamp' => wfTimestamp( TS_MW, (int)wfTimestamp() - 100000 )
			]
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence won't affect edits that weren't targeted by it.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testNoApproveHookNeeded() {
		$this->runApproveHookTest( [ [
			# Here runApproveHookTest() will still make an edit, but won't install ApproveHook.
			'task' => null
		] ] );
	}

	/**
	 * Verify that InstallApproveHookConsequence modifies rev_timestamp, etc. according to $task.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testInstallApproveHook() {
		// TODO: now that $this->runApproveHookTest() method exists,
		// split this test into more specific tests: editing only 1 page, with DeferredUpdates,
		// without DeferredUpdates, situation where CASE ... WHEN ... THEN is used,
		// situation where rev_timestamp is ignored,
		// situation where tags exist and don't exist,
		// situation where some edits don't need ApproveHook installed and must be unchanged, etc.

		$usernames = [
			'Test user1 ' . rand( 0, 100000 ),
			'Test user2 ' . rand( 0, 100000 ),
			'Test user3 ' . rand( 0, 100000 )
		];

		// TODO: while testing installed InstallApproveHookConsequence followed by multiple edits,
		// also test that ApproveHook doesn't affect edits of another $title OR $user OR $type.

		// TODO: currently timestamps are ordered from older to newer on purpose,
		// because changing ApproveHook purposely ignores rev_timestamp if it is earlier
		// than timestamp of already existing revision in this page.
		// However, this behavior should be tested too!
		// Provide an array of fixed (non-randomized) timestamps which would check exactly that.
		$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp() - 100000 );
		$type = ModerationNewChange::MOD_TYPE_EDIT;

		$todo = [];
		foreach ( $usernames as $username ) {
			$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp( TS_UNIX, $timestamp ) + rand( 0, 12345 ) );
			$task = [
				'ip' => '10.11.' . rand( 0, 255 ) . '.' . rand( 0, 254 ),
				'xff' => '10.20.' . rand( 0, 255 ) . '.' . rand( 0, 254 ),
				'ua' => 'Some-User-Agent/1.0.' . rand( 0, 100000 ),

				// TODO: test some changes WITHOUT any tags.
				'tags' => implode( "\n", [
					"Sample tag 1 " . rand( 0, 100000 ),
					"Sample tag 2 " . rand( 0, 100000 ),
				] ),
				'timestamp' => $timestamp
			];

			$todo[] = [ 'user' => $username, 'task' => $task ];
		}

		$this->runApproveHookTest( $todo );
	}

	/**
	 * Run the ApproveHook test with selected list of edits.
	 * For each edit, Title, User and type ("edit" or "move") must be specified,
	 * and also optional $task for ApproveHook itself (if null, then ApproveHook is NOT installed).
	 *
	 * @param array $todo
	 *
	 * @codingStandardsIgnoreStart
	 * @phan-param list<array{title?:string,user?:string,type?:string,task:?array<string,?string>}> $todo
	 * @codingStandardsIgnoreEnd
	 */
	private function runApproveHookTest( array $todo ) {
		static $pageNameSuffix = 0; // Added to default titles of pages, incremented each time.

		// Convert pagename and username parameters (#0 and #1) to Title/User objects
		$todo = array_map( function ( $testParameters ) use ( &$pageNameSuffix ) {
			$pageName = $testParameters['title'] ??
				'UTPage-' . ( ++$pageNameSuffix ) . '-' . rand( 0, 100000 );
			$username = $testParameters['user'] ?? '127.0.0.1';
			$type = $testParameters['type'] ?? ModerationNewChange::MOD_TYPE_EDIT;
			$task = $testParameters['task'] ?? []; // Consequence won't be called for empty task

			$title = Title::newFromText( $pageName );

			$user = User::isIP( $username ) ?
				User::newFromName( $username, false ) :
				( new TestUser( $username ) )->getUser();

			return [ $title, $user, $type, $task ];
		}, $todo );

		'@phan-var list<array{0:Title,1:User,2:string,3:array<string,?string>}> $todo';

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
		) use ( $todo, &$taggedRevIds, &$taggedLogIds, &$taggedRcIds ) {
			$task = $this->findTaskInTodo( $todo, $rc->getTitle(), $rc->getPerformer(), 'edit' );

			$this->assertNotEmpty( $task['tags'],
				"Tags were changed for something that wasn't supposed to be tagged" );

			// @phan-suppress-next-line PhanTypeMismatchArgumentNullableInternal
			$expectedTags = explode( "\n", $task['tags'] );

			$this->assertEquals( $expectedTags, $tagsToAdd );
			$this->assertEquals( [], $tagsToRemove );

			if ( $rev_id !== false ) {
				$taggedRevIds[] = $rev_id;
			}

			if ( $log_id !== false ) {
				$taggedLogIds[] = $log_id;
			}

			if ( $rc_id !== false ) {
				$taggedRcIds[] = $rc_id;
			}

			return true;
		} );

		// Now make new edits and double-check that all changes from $task were applied to them.
		$this->setMwGlobals( 'wgModerationEnable', false ); // Edits shouldn't be intercepted
		$this->setMwGlobals( 'wgCommandLineMode', false ); // Delay any DeferredUpdates

		$expectedTaggedRevIds = [];

		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;
			$revid = $this->makeEdit( $title, $user );

			if ( !empty( $task['tags'] ) ) {
				$expectedTaggedRevIds[] = $revid;
			}
		}

		// Run any DeferredUpdates that may have been queued when making edits.
		// Note: PRESEND must be first, as this is where RecentChanges_save hooks are called,
		// and results of these hooks are used by ApproveHook, which is in POSTSEND.
		DeferredUpdates::doUpdates( 'run', DeferredUpdates::PRESEND );
		DeferredUpdates::doUpdates( 'run', DeferredUpdates::POSTSEND );

		foreach ( $todo as $testParameters ) {
			list( $title, $user, $type, $task ) = $testParameters;

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

			// Verify that ApproveHook has modified recentchanges.rc_ip field.
			$this->assertSelect(
				'recentchanges',
				[ 'rc_ip' ],
				$rcWhere,
				[ [ $expectedIP ] ]
			);

			if ( $task ) {
				$revid = $this->db->selectField( 'recentchanges', 'rc_this_oldid', $rcWhere,
					__METHOD__ );

				// Verify that ApproveHook has modified revision.rev_timestamp field.
				$this->assertSelect( 'revision',
					[ 'rev_timestamp' ],
					[ 'rev_id' => $revid ],
					// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
					[ [ $this->db->timestamp( $task['timestamp'] ) ] ]
				);
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
		}

		// TODO: test moves: both redirect revision and "page moves" null revision should be affected.

		// Check that ChangeTagsAfterUpdateTags hook was called for all revisions, etc.
		// Note: ChangeTagsAfterUpdateTags hook (see above) checks "were added tags valid or not".
		$this->assertEquals( $expectedTaggedRevIds, $taggedRevIds );

		if ( $expectedTaggedRevIds ) {
			$this->assertSelect( 'recentchanges',
				[ 'rc_id', 'rc_this_oldid' ],
				[ 'rc_this_oldid' => $expectedTaggedRevIds ],
				array_map( function ( $rc_id, $rev_id ) {
					return [
						$rc_id,
						$rev_id,
					];
				}, $taggedRcIds, $expectedTaggedRevIds )
			);
			$this->assertSelect( 'logging',
				[ 'log_id' ],
				'',
				array_map( function ( $log_id ) {
					return [ $log_id ];
				}, $taggedLogIds )
			);
		} else {
			$this->assertEmpty( $taggedRcIds );
			$this->assertEmpty( $taggedLogIds );
		}
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
	 * Find task in $todo (which is populated during runApproveHookTest()).
	 * @param array $todo
	 * @param Title $title
	 * @param User $user
	 * @param string $type
	 * @return array
	 *
	 * @phan-param list<array{0:Title,1:User,2:string,3:array<string,?string>}> $todo
	 * @phan-return array<string,?string>
	 */
	private function findTaskInTodo( array $todo, Title $title, User $user, $type ) {
		foreach ( $todo as $testParameters ) {
			list( $todoTitle, $todoUser, $todoType, $task ) = $testParameters;
			if ( $todoType == $type && $todoTitle->equals( $title ) &&
				$todoUser->equals( $user )
			) {
				return $task;
			}
		}

		throw new MWException( 'findTaskInTodo: not found for title=' . $title->getPrefixedText() .
			', user=' . $user->getName() . ", type=$type" );
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
