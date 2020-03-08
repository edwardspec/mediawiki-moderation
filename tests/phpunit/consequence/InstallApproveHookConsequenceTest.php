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
		$titles = array_map(
			function ( $pageName ) {
				return Title::newFromText( $pageName );
			}, [
				'UTPage1-' . rand( 0, 100000 ),
				'Project:UTPage 2-' . rand( 0, 100000 ),
				'UTPage 3-' . rand( 0, 100000 )
			] );
		$users = array_map(
			function ( $username ) {
				return User::createNew( $username );
			}, [
				'UTPage1-' . rand( 0, 100000 ),
				'Project:UTPage 2-' . rand( 0, 100000 ),
				'UTPage 3-' . rand( 0, 100000 )
			] );

		$timestamp = wfTimestamp( TS_MW, (int)wfTimestamp() - rand( 12345, 100000 ) );

		$dbw = wfGetDB( DB_MASTER );

		// TODO: request different $task for different edits,
		// and check that correct User+Title pairs got correct results.
		$task = [
			'ip' => '10.11.12.13',
			'xff' => '10.20.30.40',
			'ua' => 'Some-User-Agent/1.0.' . rand( 0, 100000 ),
			'tags' => "Sample tag 1\nSample tag 2",

			// FIXME: this should really be done in InstallApproveHookConsequence or ApproveHook.
			// Requiring "string" parameter to be encoded like this is counter-intuitive.
			'timestamp' => $dbw->timestamp( $timestamp )
		];

		// Create and run the Consequence.
		// TODO: while testing installed InstallApproveHookConsequence followed by multiple edits,
		// also test that ApproveHook doesn't affect edits of another $title OR $user OR $type.
		$type = ModerationNewChange::MOD_TYPE_EDIT;
		foreach ( $titles as $title ) {
			foreach ( $users as $user ) {
				$consequence = new InstallApproveHookConsequence( $title, $user, $type, $task );
				$consequence->run();
			}
		}

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

		$revIds = [];
		foreach ( $titles as $title ) {
			foreach ( $users as $user ) {
				$revIds[] = $this->makeEdit( $title, $user );
			}
		}

		// TODO: test moves: both redirect revision and "page moves" null revision should be affected.

		$count = count( $revIds );
		$this->assertSelect( 'revision',
			[ 'rev_timestamp' ],
			[ 'rev_id' => $revIds ],
			array_fill( 0, $count, [ $task['timestamp'] ] )
		);

		$expectedIP = $task['ip'];
		if ( $dbw->getType() == 'postgres' ) {
			$expectedIP .= '/32';
		}

		$this->assertSelect( 'recentchanges',
			[ 'rc_ip' ],
			[ 'rc_this_oldid' => $revIds ],
			array_fill( 0, $count, [ $expectedIP ] )
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
				[ 'cuc_this_oldid' => $revIds ],
				array_fill( 0, $count, [
					$task['ip'],
					IP::toHex( $task['ip'] ),
					$task['ua']
				] )
			);
		}

		// @phan-suppress-next-line PhanRedundantCondition
		if ( $task['tags'] ) {
			$this->assertEquals( $revIds, $taggedRevIds );

			$this->assertSelect( 'recentchanges',
				[ 'rc_id', 'rc_this_oldid' ],
				[ 'rc_this_oldid' => $revIds ],
				array_map( function ( $rc_id, $rev_id ) {
					return [
						$rc_id,
						$rev_id,
					];
				}, $taggedRcIds, $revIds )
			);

			$this->assertSelect( 'logging',
				[ 'log_id' ],
				'',
				array_map( function ( $log_id ) {
					return [ $log_id ];
				}, $taggedLogIds )
			);
		} else {
			$this->assertEmpty( $taggedRevIds );
			$this->assertEmpty( $taggedLogIds );
			$this->assertEmpty( $taggedRcIds );
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
