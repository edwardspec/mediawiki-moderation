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

require_once __DIR__ . "/ConsequenceTestTrait.php";
require_once __DIR__ . "/ModifyDbRowTestTrait.php";
require_once __DIR__ . "/PostApproveCleanupTrait.php";

/**
 * @group Database
 */
class ActionsWithoutConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;
	use ModifyDbRowTestTrait;
	use PostApproveCleanupTrait;

	/**
	 * Ensure that readonly, failed or non-applicable actions don't have any consequences.
	 * @param array $options
	 * @dataProvider dataProviderNoConsequenceActions
	 * @coversNothing
	 *
	 * @phan-param array{action:string,globals?:array,fields?:array,expectedError:string} $options
	 */
	public function testNoConsequenceActions( $options ) {
		$this->assertArrayHasKey( 'action', $options );
		$this->setMwGlobals( $options['globals'] ?? [] );

		$modid = $this->makeDbRow( $options['fields'] ?? [] );
		$this->assertConsequencesEqual( [], $this->getConsequences( $modid, $options['action'] ) );

		$this->assertEquals( $this->thrownError, $options['expectedError'] ?? null,
			"Thrown ModerationError doesn't match expected." );
	}

	/**
	 * Provide datasets for testNoConsequenceActions() runs.
	 * @return array
	 */
	public function dataProviderNoConsequenceActions() {
		return [
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
			'editchange (when not enabled via $wgModerationEnableEditChange)' =>
				[ [ 'action' => 'editchange', 'expectedError' => 'moderation-unknown-modaction' ] ],

			'merge (when not a conflict merged)' =>
				[ [ 'action' => 'merge', 'expectedError' => 'moderation-merge-not-needed' ] ],
			'merge (when already merged)' =>
				[ [
					'action' => 'merge',
					'fields' => [ 'mod_conflict' => 1, 'mod_merged_revid' => 123 ],
					'expectedError' => 'moderation-already-merged'
				] ],

			// TODO: add checks for all possible error conditions
		];
	}
}
