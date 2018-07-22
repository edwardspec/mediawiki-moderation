<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * @brief Checks consequences of the moderation actions on Special:Moderation.
 */

require_once __DIR__ . "/../../framework/ModerationTestsuite.php";

/**
 * @covers ModerationAction
 */
class ModerationActionTest extends MediaWikiTestCase {
	/**
	 * @dataProvider dataProvider
	 */
	public function testAction( array $options ) {
		ModerationActionTestSet::run( $options, $this );
	}

	/**
	 * @brief Provide datasets for testAction() runs.
	 */
	public function dataProvider() {
		global $wgModerationTimeToOverrideRejection;

		return [
			[ [
				'modaction' => 'reject',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true
			] ],
			[ [
				'modaction' => 'rejectall',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true,
				'expectedFields' => [ 'mod_rejected_batch' => 1 ],
				'expectedLogTargetIsAuthor' => true
			] ],
			[ [
				'modaction' => 'approve',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectRowDeleted' => true
			] ],
			[ [
				'modaction' => 'approveall',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectRowDeleted' => true,
				'expectedLogTargetIsAuthor' => true
			] ],

			// Can we reject an edit with the conflict?
			[ [
				'modaction' => 'reject',
				'mod_conflict' => 1,
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true
			] ],

			// Actions show/preview/merge/block/unblock shouldn't change the row
			[ [ 'modaction' => 'show' ] ],
			[ [ 'modaction' => 'preview' ] ],
			[ [ 'mod_conflict' => 1, 'modaction' => 'merge' ] ],

			// Check block/unblock
			[ [
				'modaction' => 'block',
				'expectModblocked' => true,
				'expectedOutput' => 'moderation-block-ok',
				'expectedLogTargetIsAuthor' => true
			] ],
			[ [
				'modaction' => 'unblock',
				'modblocked' => true,
				'expectModblocked' => false,
				'expectedOutput' => 'moderation-unblock-ok',
				'expectedLogTargetIsAuthor' => true
			] ],
			[ [
				// Attempting to block when already blocked
				// (e.g. moderator clicked twice on "Mark as spammer"):
				// should report success, but shouldn't create a new LogEntry.
				'modaction' => 'block',
				'modblocked' => true,
				'expectedOutput' => 'moderation-block-ok',
				'expectLogEntry' => false
			] ],
			[ [
				// Attempting to unblock when not blocked:
				// should report success, but shouldn't create a new LogEntry.
				'modaction' => 'unblock',
				'expectedOutput' => 'moderation-unblock-ok',
				'expectLogEntry' => false
			] ],

			// Errors printed by actions:
			[ [
				'modaction' => 'makesandwich',
				'expectedError' => '(moderation-unknown-modaction)'
			] ],
			[ [
				'modaction' => 'reject',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-already-rejected)'
			] ],
			[ [
				'modaction' => 'approve',
				'mod_rejected' => 1,
				'mod_timestamp' => '-' . ( $wgModerationTimeToOverrideRejection + 1 ) . ' seconds',
				'expectedError' => '(moderation-rejected-long-ago)'
			] ],
			[ [
				'modaction' => 'approveall',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-nothing-to-approveall)'
			] ],
			[ [
				'modaction' => 'rejectall',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-nothing-to-rejectall)'
			] ],
			[ [
				'modaction' => 'approve',
				'mod_merged_revid' => 12345,
				'expectedError' => '(moderation-already-merged)'
			] ],
			[ [
				'modaction' => 'reject',
				'mod_merged_revid' => 12345,
				'expectedError' => '(moderation-already-merged)'
			] ],
			[ [
				'modaction' => 'merge',
				'expectedError' => '(moderation-merge-not-needed)'
			] ],
			[ [
				'modaction' => 'merge',
				'mod_conflict' => 1,
				'notAutomoderated' => true,
				'expectedError' => '(moderation-merge-not-automoderated)'
			] ],
			[ [
				'modaction' => 'merge',
				'mod_conflict' => 1,
				'mod_merged_revid' => 12345,
				'expectedError' => '(moderation-already-merged)'
			] ],

			// 'moderation-edit-not-found' from everything
			[ [ 'modaction' => 'approve', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'approveall', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'reject', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'rejectall', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'block', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'unblock', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'merge', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'show', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],
			[ [ 'modaction' => 'showimg', 'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)' ] ],

			// TODO: ReadOnlyError exception from non-readonly actions
			// TODO: approval errors originating from doEditContent(), etc.

			// TODO: test uploads, moves
			// TODO: modaction=showimg
		];
	}
}

/**
 * @brief Represents one TestSet for testAction().
 */
class ModerationActionTestSet extends ModerationTestsuitePendingChangeTestSet {

	/**
	 * @var string Name of action, e.g. 'approve' or 'rejectall'.
	 */
	protected $modaction = null;

	/**
	 * @var array Expected field values after the action.
	 * Field that are NOT in this array are expected to be unmodified.
	 */
	protected $expectedFields = [];

	/**
	 * @var string|null Error that should be printed by this action, e.g. "(sessionfailure)".
	 */
	protected $expectedError = null;

	/**
	 * @var string|null Expected subtype of LogEntry. If null, assumed to be same as $modaction.
	 * Example of non-default value: 'approve-move' for modaction=approve.
	 */
	protected $expectedLogAction = null;

	/**
	 * @var bool If true, new LogEntry is expected to appear after the action.
	 * If false, LogEntry is expected to NOT appear. If null, auto-detect.
	 */
	protected $expectLogEntry = null;

	/**
	 * @var bool If true, userpage of author of the change is the expected target of LogEntry.
	 */
	protected $expectedLogTargetIsAuthor = false;

	/**
	 * @var bool|null If true/false, author of change is expected to become (not) modblocked.
	 * If null, blocked status is expected to remain the same.
	 */
	protected $expectModblocked = null;

	/**
	 * @var bool If true, rejection fields will be added to $expectedFields.
	 */
	protected $expectRejected = false;

	/**
	 * @var bool If true, database row is expected to be deleted ($expectedFields are ignored).
	 */
	protected $expectRowDeleted = false;

	/**
	 * @var string Text that should be present in the output of modaction.
	 */
	protected $expectedOutput = '';

	/**
	 * @var bool If true, incorrect modid will be used in the action URL.
	 */
	protected $simulateNoSuchEntry = false;

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'expectedFields':
				case 'expectedError':
				case 'expectedLogAction':
				case 'expectLogEntry':
				case 'expectedLogTargetIsAuthor':
				case 'expectModblocked':
				case 'expectedOutput':
				case 'expectRejected':
				case 'expectRowDeleted':
				case 'modaction':
				case 'simulateNoSuchEntry':
					$this->$key = $value;
					unset( $options[$key] );
			}
		}

		if ( !$this->modaction ) {
			throw new MWException( __CLASS__ . ": parameter 'modaction' is required" );
		}

		if ( $this->expectLogEntry === null ) {
			// Auto-detect whether the log entry is needed.
			$this->expectLogEntry = false;
			if ( $this->expectedFields ||
				$this->expectRejected ||
				$this->expectRowDeleted ||
				$this->expectModblocked !== null
			) {
				$this->expectLogEntry = true;
			}
		}

		parent::applyOptions( $options );
		$this->expectedFields += $this->fields;

		if ( !$this->expectedLogAction ) {
			// Default: $expectedLogAction is the same as $modaction
			// (e.g. modaction=reject creates 'moderation/reject' log entries).
			$this->expectedLogAction = $this->modaction;
		}
	}

	/**
	 * @brief Returns the expected target of LogEntry created by this modaction.
	 * @return Title
	 */
	protected function getExpectedLogTarget() {
		if ( $this->expectedLogTargetIsAuthor ) {
			// For actions like 'block' or 'rejectall':
			// target is the author's userpage.
			return Title::makeTitle( NS_USER, $this->fields['mod_user_text'] );
		}

		// Default (for actions like 'approve' or 'reject'):
		// target is the page affected by this change.
		return $this->getExpectedTitleObj();
	}

	/**
	 * @brief Returns the User who will perform this modaction.
	 * @return User
	 */
	protected function getModerator() {
		$t = $this->getTestsuite();
		return $this->notAutomoderated ?
			$t->moderatorButNotAutomoderated :
			$t->moderator;
	}

	/**
	 * @brief Assert the consequences of the action.
	 */
	protected function assertResults( MediaWikiTestCase $testcase ) {
		// Add rejection-related fields to $this->expectedFields.
		// It was too early to do in applyOptions(), because $this->fields['mod_id'] was unknown.
		if ( $this->expectRejected ) {
			$this->expectedFields = array_merge( $this->expectedFields, [
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->getModerator()->getId(),
				'mod_rejected_by_user_text' => $this->getModerator()->getName(),
				'mod_preloadable' => $this->fields['mod_id']
			] );
		}

		$t = $this->getTestsuite();
		$t->loginAs( $this->getModerator() );

		// Execute the action, check HTML printed by the action
		$output = $t->html->getMainText( $this->getActionURL() );
		if ( $this->expectedOutput ) {
			$testcase->assertContains( $this->expectedOutput, $output,
				"modaction={$this->modaction}: unexpected output." );
		}

		$error = $t->html->getModerationError();
		if ( $this->expectedError ) {
			$testcase->assertEquals( $this->expectedError, $error,
				"modaction={$this->modaction}: expected error not shown." );
		} else {
			$testcase->assertNull( $this->expectedError,
				"modaction={$this->modaction}: unexpected error." );
		}

		// Check the mod_* fields in the database after the action.
		$this->assertDatabaseChanges( $testcase );
		$this->assertBlockedStatus( $testcase );
		$this->assertLogEntry( $testcase );
	}

	/**
	 * @brief Check whether/how was the database row modified by this action.
	 */
	protected function assertDatabaseChanges( MediaWikiTestCase $testcase ) {
		$dbw = wfGetDB( DB_MASTER );
		if ( $this->expectRowDeleted ) {
			$row = $dbw->selectRow(
				'moderation',
				'*',
				[ 'mod_id' => $this->fields['mod_id'] ],
				__METHOD__
			);
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: database row wasn't deleted" );
		} else {
			$this->assertRowEquals( $this->expectedFields );
		}
	}

	/**
	 * @brief Check whether the moderation block was added/deleted.
	 */
	protected function assertBlockedStatus( MediaWikiTestCase $testcase ) {
		$expectedBlocker = $this->getModerator();
		if ( $this->expectModblocked === null ) {
			// Default: block status shouldn't change.
			$this->expectModblocked = $this->modblocked;

			// If the user was already blocked via [ 'modblocked' => true ],
			// we should expect getModeratorWhoBlocked() to tell who did it.
			$expectedBlocker = $this->getModeratorWhoBlocked();
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow(
			'moderation_block',
			[
				'mb_user',
				'mb_by',
				'mb_by_text',
				'mb_timestamp'
			],
			[ 'mb_address' => $this->fields['mod_user_text'] ],
			__METHOD__
		);

		if ( $this->expectModblocked ) {
			$fields = get_object_vars( $row );

			// Not-strict check that 'mb_timestamp' is not too far from "now"
			$this->assertTimestampIsRecent( $fields['mb_timestamp'] );
			unset( $fields['mb_timestamp'] );

			$expectedFields = [
				'mb_user' => $this->fields['mod_user'],
				'mb_by' => $expectedBlocker->getId(),
				'mb_by_text' => $expectedBlocker->getName()
			];
			$testcase->assertEquals( $expectedFields, $fields );
		} else {
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: Author is unexpectedly blacklisted as spammer." );
		}
	}

	/**
	 * @brief Check the log entry created by this action (if any).
	 */
	protected function assertLogEntry( MediaWikiTestCase $testcase ) {
		// Check the LogEntry, if any
		$queryInfo = DatabaseLogEntry::getSelectQueryData();
		$queryInfo['options']['ORDER BY'] = 'log_id DESC';

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);

		if ( !$this->expectLogEntry ) {
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: unexpected LogEntry appeared after readonly action" );
		} else {
			$testcase->assertNotFalse( $row,
				"modaction={$this->modaction}: logging table is empty after the action" );

			$logEntry = DatabaseLogEntry::newFromRow( $row );

			$testcase->assertEquals( 'moderation', $logEntry->getType(),
				"modaction={$this->modaction}: incorrect LogEntry type" );
			$testcase->assertEquals( $this->modaction, $logEntry->getSubtype(),
				"modaction={$this->modaction}: incorrect LogEntry subtype" );
			$testcase->assertEquals(
				$this->getModerator()->getName(),
				$logEntry->getPerformer()->getName(),
				"modaction={$this->modaction}: incorrect name of moderator in LogEntry" );
			$testcase->assertEquals( $this->getExpectedLogTarget(), $logEntry->getTarget(),
				"modaction={$this->modaction}: incorrect LogEntry target" );

			$this->assertTimestampIsRecent( $logEntry->getTimestamp() );

			// TODO: check $logEntry->getParameters()
		}
	}

	/**
	 * @brief Calculates the URL of modaction requested by this TestSet.
	 */
	protected function getActionURL() {
		$q = [
			'modid' => $this->fields['mod_id'],
			'modaction' => $this->modaction
		];
		if ( !in_array( $this->modaction, [ 'show', 'showimg', 'preview' ] ) ) {
			$q['token'] = $this->getTestsuite()->getEditToken();
		}

		if ( $this->simulateNoSuchEntry ) {
			$q['modid'] = 0; // Wrong
		}

		return SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( $q );
	}
}
