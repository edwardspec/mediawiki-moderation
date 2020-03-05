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
	 * Verify that InstallApproveHookConsequence marks the database row as conflict.
	 * @covers MediaWiki\Moderation\InstallApproveHookConsequence
	 */
	public function testInstallApproveHook() {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = self::getTestUser()->getUser();
		$type = ModerationNewChange::MOD_TYPE_EDIT;
		$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp() - 12345 );

		$dbw = wfGetDB( DB_MASTER );

		$task = [
			'ip' => '10.11.12.13',
			'xff' => '10.20.30.40',
			'ua' => 'Some-User-Agent/1.0.' . rand( 0, 100000 ),
			'tags' => "Sample tag 1\nSample tag 2",
			'timestamp' => $dbw->timestamp( $timestamp )
		];

		// Create and run the Consequence.
		$consequence = new InstallApproveHookConsequence( $title, $user, $type, $task );
		$consequence->run();

		// Now make a new edit and double-check that all changes from $task were applied to it.
		$this->setMwGlobals( 'wgModerationEnable', false ); // Edit shouldn't be intercepted

		// Track ChangeTagsAfterUpdateTags hook to ensure that $task['tags'] are actually added.
		$taggedRevIds = [];
		$taggedLogIds = [];
		$taggedRcIds = [];
		$this->setTemporaryHook( 'ChangeTagsAfterUpdateTags', function (
			$tagsToAdd, $tagsToRemove, $prevTags,
			$rc_id, $rev_id, $log_id, $params, $rc, $user
		) use ( $task, &$taggedRevIds, &$taggedLogIds, &$taggedRcIds ) {
			$this->assertEquals( explode( "\n", $task['tags'] ), $tagsToAdd );
			$this->assertEquals( [], $tagsToRemove );

			$taggedRevIds[] = $rev_id;
			$taggedLogIds[] = $log_id;
			$taggedRcIds[] = $rc_id;

			return true;
		} );

		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'Some edit summary',
			EDIT_INTERNAL,
			false,
			$user
		);
		$this->assertTrue( $status->isOK(), "Edit failed: " . $status->getMessage()->plain() );

		$revid = $status->value['revision']->getId();

		$this->assertSelect( 'revision',
			[ 'rev_timestamp' ],
			[ 'rev_id' => $revid ],
			[ [ $task['timestamp'] ] ]
		);

		$expectedIP = $task['ip'];
		if ( $dbw->getType() == 'postgres' ) {
			$expectedIP .= '/32';
		}

		$this->assertSelect( 'recentchanges',
			[ 'rc_ip' ],
			[ 'rc_this_oldid' => $revid ],
			[ [ $expectedIP ] ]
		);

		// FIXME: if the method from Extension:CheckUser gets renamed in some future version,
		// this part of test will be silently skipped, and we won't notice it.
		// To avoid this, this check should be in a separate test method,
		// which would be using proper @requires or markTestSkipped().
		if ( method_exists( 'CheckUserHooks', 'updateCheckUserData' ) ) {
			$this->assertSelect( 'cu_changes',
				[
					'cuc_ip',
					'cuc_ip_hex',
					'cuc_agent'
				],
				[ 'cuc_this_oldid' => $revid ],
				[ [
					$task['ip'],
					IP::toHex( $task['ip'] ),
					$task['ua']
				] ]
			);
		}

		// @phan-suppress-next-line PhanRedundantCondition
		if ( $task['tags'] ) {
			$row = $dbw->selectRow( 'recentchanges',
				[ 'rc_id', 'rc_logid' ],
				[ 'rc_this_oldid' => $revid ],
				__METHOD__
			);
			$this->assertEquals( [ $revid ], $taggedRevIds );
			$this->assertEquals( [ $row->rc_id ], $taggedRcIds );

			// FIXME: aside from $row->rc_logid, these can also be "create/create" LogEntry,
			// which doesn't have a row in RecentChanges.
			// $this->assertEquals( array_filter( [ $row->rc_logid ] ), $taggedLogIds );
		} else {
			$this->assertEmpty( $taggedRevIds );
			$this->assertEmpty( $taggedLogIds );
			$this->assertEmpty( $taggedRcIds );
		}
	}

	// TODO: test of multiple installed InstallApproveHookConsequence followed by multiple edits.

	// TODO: test that ApproveHook doesn't affect edits of another $title OR $user OR $type.

	// TODO: test moves: both redirect revision and "page moves" null revision should be affected.

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
}
