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
 * @brief Checks how HTML of Special:Moderation is rendered from the 'moderation' SQL table.
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
				case 'expectedOutput':
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
		$testcase->assertContains( $this->expectedOutput, $output,
			"modaction={$this->modaction}: unexpected output." );

		// Check the mod_* fields in the database after the action.
		$this->assertRowEquals( $this->expectedFields );
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
