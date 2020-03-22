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
 * Verifies that readonly, failed or non-applicable actions have no consequences.
 */

use Wikimedia\ScopedCallback;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ActionsWithoutConsequencesTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;
	use ModifyDbRowTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Ensure that readonly, failed or non-applicable actions don't have any consequences.
	 * @param array $options
	 * @dataProvider dataProviderNoConsequenceActions
	 * @coversNothing
	 *
	 * @codingStandardsIgnoreStart
	 * @phan-param array{action:string,globals?:array,fields?:array|false,expectedError?:string,getModerator?:(callable():User)} $options
	 * @codingStandardsIgnoreEnd
	 */
	public function testNoConsequenceActions( $options ) {
		$this->assertArrayHasKey( 'action', $options );

		// Have to create test user before $wgReadOnly is set in readonly tests.
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$this->setMwGlobals( $options['globals'] ?? [] );

		if ( isset( $options['getModerator'] ) ) {
			$this->moderatorUser = $options['getModerator']();
			$scope = new ScopedCallback( function () {
				$this->moderatorUser = null;
			} );
		}

		$options['fields'] = $options['fields'] ?? [];
		if ( $options['fields'] !== false ) {
			$modid = $this->makeDbRow( $options['fields'] );
		} else {
			// Simulate nonexistent row (to test "moderation-edit-not-found" error)
			$modid = 12345;
		}
		$this->assertConsequencesEqual( [], $this->getConsequences( $modid, $options['action'] ) );

		$this->assertEquals( $options['expectedError'] ?? null, $this->thrownError,
			"Thrown ModerationError doesn't match expected." );
	}

	/**
	 * Get value of mod_timestamp that is too long ago to reapprove already rejected edit.
	 * @return string
	 */
	public function longAgoTimestamp() {
		global $wgModerationTimeToOverrideRejection;

		$dbr = wfGetDB( DB_REPLICA );
		return $dbr->timestamp(
			wfTimestamp( TS_MW, (int)wfTimestamp() - $wgModerationTimeToOverrideRejection - 1 ) );
	}

	/**
	 * Provide datasets for testNoConsequenceActions() runs.
	 * @return array
	 */
	public function dataProviderNoConsequenceActions() {
		$sets = [
			// Actions that are always readonly and shouldn't have any consequences.
			'show' => [ [ 'action' => 'show' ] ],
			'showimg' => [ [ 'action' => 'showimg' ] ],
			'preview' => [ [ 'action' => 'preview' ] ],
			'merge' => [ [ 'action' => 'merge', 'fields' => [ 'mod_conflict' => 1 ] ] ],
			'editchange' =>
				[ [
					'action' => 'editchange',
					'globals' => [ 'wgModerationEnableEditChange' => true ]
				] ],

			// Situations when actions return errors.
			'unknown modaction' => [ [
				'action' => 'makesandwich',
				'expectedError' => 'moderation-unknown-modaction'
			] ],
			'editchange (when not enabled via $wgModerationEnableEditChange)' =>
				[ [ 'action' => 'editchange', 'expectedError' => 'moderation-unknown-modaction' ] ],
			'editchangesubmit (when not enabled via $wgModerationEnableEditChange)' =>
				[ [
					'action' => 'editchangesubmit',
					'expectedError' => 'moderation-unknown-modaction'
				] ],
			'editchange (on a move)' =>
				[ [
					'action' => 'editchange',
					'globals' => [ 'wgModerationEnableEditChange' => true ],
					'fields' => [ 'mod_type' => ModerationNewChange::MOD_TYPE_MOVE ],
					'expectedError' => 'moderation-editchange-not-edit'
				] ],
			'editchangesubmit (on a move)' =>
				[ [
					'action' => 'editchangesubmit',
					'globals' => [ 'wgModerationEnableEditChange' => true ],
					'fields' => [ 'mod_type' => ModerationNewChange::MOD_TYPE_MOVE ],
					'expectedError' => 'moderation-edit-not-found'
				] ],
			'merge (when not a conflict)' =>
				[ [ 'action' => 'merge', 'expectedError' => 'moderation-merge-not-needed' ] ],
			'merge (when not automoderated)' =>
				[ [
					'action' => 'merge',
					'fields' => [ 'mod_conflict' => 1 ],
					'getModerator' => function () {
						return self::getTestUser( [ 'moderator' ] )->getUser();
					},
					'expectedError' => 'moderation-merge-not-automoderated'
				] ],
			'merge (when already merged)' =>
				[ [
					'action' => 'merge',
					'fields' => [ 'mod_conflict' => 1, 'mod_merged_revid' => 123 ],
					'expectedError' => 'moderation-already-merged'
				] ],
			'reject (when already rejected)' =>
				[ [
					'action' => 'reject',
					'fields' => [ 'mod_rejected' => 1 ],
					'expectedError' => 'moderation-already-rejected'
				] ],
			'reject (when already merged)' =>
				[ [
					'action' => 'reject',
					'fields' => [ 'mod_merged_revid' => 123 ],
					'expectedError' => 'moderation-already-merged'
				] ],
			'approve (when already merged)' =>
				[ [
					'action' => 'approve',
					'fields' => [ 'mod_merged_revid' => 123 ],
					'expectedError' => 'moderation-already-merged'
				] ],
			'approve (when rejected too long ago)' =>
				[ [
					'action' => 'approve',
					'fields' => [
						'mod_rejected' => 1,
						'mod_timestamp' => $this->longAgoTimestamp()
					],
					'expectedError' => 'moderation-rejected-long-ago'
				] ],
			'approveall (no edits to approve)' =>
				[ [
					'action' => 'approveall',
					'fields' => [ 'mod_rejected' => 1 ],
					'expectedError' => 'moderation-nothing-to-approveall'
				] ],
			'rejectall (no edits to reject)' =>
				[ [
					'action' => 'rejectall',
					'fields' => [ 'mod_rejected' => 1 ],
					'expectedError' => 'moderation-nothing-to-rejectall'
				] ]
		];

		// ReadOnlyError exception from non-readonly actions
		$actionIsReadOnly = [
			'approve' => false,
			'approveall' => false,
			'reject' => false,
			'rejectall' => false,
			'block' => false,
			'unblock' => false,
			'merge' => false,
			'editchange' => false,
			'editchangesubmit' => false,
			'show' => true,
			'showimg' => true,
			'preview' => true
		];
		foreach ( $actionIsReadOnly as $action => $isReadOnly ) {
			$sets["readonly modaction=$action"] = [ [
				'action' => $action,
				'globals' => [ 'wgReadOnly' => 'for some reason' ],
				'expectedError' => $isReadOnly ? null : 'exceptionClass:ReadOnlyError'
			] ];
		}

		// 'moderation-edit-not-found' from everything
		foreach ( array_keys( $actionIsReadOnly ) as $action ) {
			$options = [
				'action' => $action,
				'fields' => false, // Don't create a row in "moderation" table
				'expectedError' => 'moderation-edit-not-found'
			];
			if ( $action == 'editchange' || $action == 'editchangesubmit' ) {
				$options['globals']['wgModerationEnableEditChange'] = true;
			}

			$sets["modaction=$action on nonexistent change"] = [ $options ];
		}

		return $sets;
	}
}