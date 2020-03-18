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
 * Verifies that batch moderation actions like ApproveAll and RejectAll have expected consequences.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\DeleteRowFromModerationTableConsequence;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use MediaWiki\Moderation\InstallApproveHookConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\RejectBatchConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";
require_once __DIR__ . "/PostApproveCleanupTrait.php";

/**
 * @group Database
 */
class BatchActionsHaveConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;
	use PostApproveCleanupTrait;

	/** @var User */
	protected $authorUser;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Test consequences of modaction=approveall when approving several changes.
	 * @covers ModerationActionApprove::executeApproveAll
	 */
	public function testApproveAll() {
		$this->authorUser = self::getTestUser()->getUser();
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		// FIXME: need ability to mock multiple consecutive values
		$expectedRevId = $this->mockLastRevId();

		// First, let's queue some edits by the same user for moderation.
		$numberOfEdits = 3;

		$expectedConsequences = [];

		for ( $i = 0; $i < $numberOfEdits; $i++ ) {
			$fields = $this->getDefaultFields();
			$modid = ( new InsertRowIntoModerationTableConsequence( $fields ) )->run();

			$title = Title::makeTitle( $fields['mod_namespace'], $fields['mod_title'] );

			$expectedConsequences[] = new InstallApproveHookConsequence(
				$title,
				$this->authorUser,
				ModerationNewChange::MOD_TYPE_EDIT,
				[
					'ip' => $fields['mod_ip'],
					'xff' => $fields['mod_header_xff'],
					'ua' => $fields['mod_header_ua'],
					'tags' => $fields['mod_tags'],
					'timestamp' => $fields['mod_timestamp']
				]
			);
			$expectedConsequences[] = new ApproveEditConsequence(
				$this->authorUser,
				$title,
				$fields['mod_text'],
				$fields['mod_comment'],
				(bool)$fields['mod_bot'],
				(bool)$fields['mod_minor'],
				0 // $baseRevId
			);
			$expectedConsequences[] = new AddLogEntryConsequence(
				'approve',
				$this->moderatorUser,
				$title,
				[ 'revid' => $expectedRevId ],
				true // ApproveHook enabled
			);
			$expectedConsequences[] = new DeleteRowFromModerationTableConsequence( $modid );
		}

		// Additional consequences after all edits have been approved: "approveall" LogEntry, etc.
		$expectedConsequences[] = new AddLogEntryConsequence(
			'approveall',
			$this->moderatorUser,
			$this->authorUser->getUserPage(),
			[ '4::count' => $numberOfEdits ]
		);
		$expectedConsequences[] = new InvalidatePendingTimeCacheConsequence();

		// Run modaction=approvell.
		$modid = $this->db->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$actualConsequences = $this->getConsequences( $modid, 'approveall',
			array_fill( 0, $numberOfEdits, [ ApproveEditConsequence::class, Status::newGood() ] )
		);
		$this->assertConsequencesEqual( $expectedConsequences, $actualConsequences );
	}

	/**
	 * Test consequences of modaction=rejectall when rejecting several changes.
	 * @covers ModerationActionReject::executeRejectAll
	 */
	public function testRejectAll() {
		$this->authorUser = self::getTestUser()->getUser();
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		// First, let's queue some edits by the same user for moderation.
		$numberOfEdits = 3;
		$ids = [];
		for ( $i = 0; $i < $numberOfEdits; $i++ ) {
			$fields = $this->getDefaultFields();
			$ids[] = ( new InsertRowIntoModerationTableConsequence( $fields ) )->run();
		}

		// It's possible for RejectBatchConsequence to return a number other than $numberOfEdits
		// in case of a race condition (e.g. another moderator just approved one of these edits).
		$mockedNumberOfAffectedRows = 456;

		$expected = [
			new RejectBatchConsequence( $ids, $this->moderatorUser ),
			new AddLogEntryConsequence(
				'rejectall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => $mockedNumberOfAffectedRows
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];
		$actual = $this->getConsequences( $ids[0], 'rejectall',
			[ [ RejectBatchConsequence::class, $mockedNumberOfAffectedRows ] ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Returns default fields of one row in "moderation" table.
	 * Same as ModerationTestsuitePendingChangeTestSet::getDefaultFields() in blackbox testsuite.
	 * @return array
	 */
	private function getDefaultFields() {
		return [
			'mod_timestamp' => $this->db->timestamp(),
			'mod_user' => $this->authorUser->getId(),
			'mod_user_text' => $this->authorUser->getName(),
			'mod_cur_id' => 0,
			'mod_namespace' => rand( 0, 1 ),
			'mod_title' => 'Test page ' . rand( 0, 100000 ),
			'mod_comment' => 'Some reason ' . rand( 0, 100000 ),
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => 1,
			'mod_last_oldid' => 0,
			'mod_ip' => '127.1.2.3',
			'mod_old_len' => 0,
			'mod_new_len' => 8, // Length of mod_text, see below
			'mod_header_xff' => null,
			'mod_header_ua' => 'TestsuiteUserAgent/1.0.' . rand( 0, 100000 ),
			'mod_preload_id' => ']fake',
			'mod_rejected' => 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => 'New text ' . rand( 0, 100000 ),
			'mod_stash_key' => '',
			'mod_tags' => null,
			'mod_type' => ModerationNewChange::MOD_TYPE_EDIT,
			'mod_page2_namespace' => 0,
			'mod_page2_title' => 'Test page 2'
		];
	}
}
