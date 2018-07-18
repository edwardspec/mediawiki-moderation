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
		return [
			[ [
				'modaction' => 'reject',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectedFields' => [
					'mod_rejected' => 1,
					'mod_rejected_by_user' => '{{{MODERATOR_USERID}}}',
					'mod_rejected_by_user_text' => '{{{MODERATOR_USERNAME}}}',
					'mod_preloadable' => '{{{MODID}}}'
				]
			] ],
			[ [
				'modaction' => 'rejectall',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectedFields' => [
					'mod_rejected' => 1,
					'mod_rejected_batch' => 1,
					'mod_rejected_by_user' => '{{{MODERATOR_USERID}}}',
					'mod_rejected_by_user_text' => '{{{MODERATOR_USERNAME}}}',
					'mod_preloadable' => '{{{MODID}}}'
				]
			] ],
			[ [
				'modaction' => 'approve',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectRowDeleted' => true
			] ],
			[ [
				'modaction' => 'approveall',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectRowDeleted' => true
			] ],

			// The following actions shouldn't change the row
			[ [ 'modaction' => 'show' ] ],
			[ [ 'modaction' => 'preview' ] ],
			[ [ 'mod_conflict' => 1, 'modaction' => 'merge' ] ],
			[ [ 'modaction' => 'block', 'expectModblocked' => true ] ],
			[ [ 'modaction' => 'unblock', 'modblocked' => true, 'expectModblocked' => false ] ],

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

			/* TODO: this error is not yet thrown, uncomment when added.
			[ [
				'modaction' => 'merge',
				'mod_conflict' => 1,
				'mod_merged_revid' => 12345,
				'expectedError' => '(moderation-already-merged)'
			] ],
			*/

			// TODO: 'moderation-rejected-long-ago' from 'approve'
			// TODO: 'moderation-edit-not-found' from everything
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
	 * @var bool|null If true/false, author of change is expected to become (not) modblocked.
	 */
	protected $expectModblocked = null;

	/**
	 * @var bool If true, database row is expected to be deleted ($expectedFields are ignored).
	 */
	protected $expectRowDeleted = false;

	/**
	 * @var string Text that should be present in the output of modaction.
	 */
	protected $expectedOutput = '';

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'expectedFields':
				case 'expectedError':
				case 'expectModblocked':
				case 'expectedOutput':
				case 'expectRowDeleted':
				case 'modaction':
					$this->$key = $value;
					unset( $options[$key] );
			}
		}

		if ( !$this->modaction ) {
			throw new MWException( __CLASS__ . ": parameter 'modaction' is required" );
		}

		parent::applyOptions( $options );

		$this->expectedFields += $this->fields;
	}

	/**
	 * @brief Assert the consequences of the action.
	 */
	protected function assertResults( MediaWikiTestCase $testcase ) {
		$dbw = wfGetDB( DB_MASTER );

		$t = $this->getTestsuite();
		$user = $this->notAutomoderated ?
			$t->moderatorButNotAutomoderated :
			$t->moderator;

		// Replace variables like {{MODERATOR_USERID}} in $this->expectedFields
		$this->expectedFields = FormatJson::decode(
			str_replace(
				[
					'{{{MODID}}}',
					'{{{MODERATOR_USERID}}}',
					'{{{MODERATOR_USERNAME}}}'
				],
				[
					$this->fields['mod_id'],
					$user->getId(),
					$user->getName()
				],
				FormatJson::encode( $this->expectedFields )
			),
			true
		);

		$t->loginAs( $user );

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
		}
		else {
			$testcase->assertNull( $this->expectedError,
				"modaction={$this->modaction}: unexpected error." );
		}

		// Check the mod_* fields in the database after the action.
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

		if ( $this->expectModblocked !== null ) {
			$isBlocked = (bool)$dbw->selectField(
				'moderation_block',
				'1',
				[ 'mb_address' => $this->fields['mod_user_text'] ],
				__METHOD__
			);
			$testcase->assertEquals(
				[ 'author is modblocked' => $this->expectModblocked ],
				[ 'author is modblocked' => $isBlocked ]
			);
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

		return SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( $q );
	}
}
