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
 * Verifies that ModerationAction subclasses have consequences like AddLogEntryConsequence.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\BlockUserConsequence;
use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\ModifyPendingChangeConsequence;
use MediaWiki\Moderation\RejectBatchConsequence;
use MediaWiki\Moderation\RejectOneConsequence;
use MediaWiki\Moderation\UnblockUserConsequence;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 */
class ActionsHaveConsequencesTest extends MediaWikiTestCase {
	/** @var int */
	protected $modid;

	/** @var User */
	protected $authorUser;

	/** @var User */
	protected $moderatorUser;

	/** @var Title */
	protected $title;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation', 'moderation_block' ];

	/**
	 * Test consequences of modaction=reject.
	 * @covers ModerationActionReject::executeRejectOne
	 */
	public function testReject() {
		$expected = [
			new RejectOneConsequence( $this->modid, $this->moderatorUser ),
			new AddLogEntryConsequence(
				'reject',
				$this->moderatorUser,
				$this->title,
				[
					'modid' => $this->modid,
					'user' => $this->authorUser->getId(),
					'user_text' => $this->authorUser->getName()
				]
			)
		];
		$actual = $this->getConsequences( 'reject', [ 1 ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=block.
	 * @covers ModerationActionBlock::execute
	 */
	public function testBlock() {
		$expected = [
			new BlockUserConsequence(
				$this->authorUser->getId(),
				$this->authorUser->getName(),
				$this->moderatorUser
			),
			new AddLogEntryConsequence(
				'block',
				$this->moderatorUser,
				$this->authorUser->getUserPage()
			)
		];
		$actual = $this->getConsequences( 'block', [ true ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=block when the user is already blocked.
	 * @covers ModerationActionBlock::execute
	 */
	public function testNoopBlock() {
		$expected = [
			new BlockUserConsequence(
				$this->authorUser->getId(),
				$this->authorUser->getName(),
				$this->moderatorUser
			)
			// No AddLogEntryConsequence, because the user was already modblocked.
		];
		$actual = $this->getConsequences( 'block', [
			// Mocked manager won't run BlockUserConsequence and would instead return "false",
			// which is what BlockUserConsequence does when the user is already modblocked.
			// That fact should be checked by unit test of BlockUserConsequence itself, not here.
			false
		] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=unblock.
	 * @covers ModerationActionBlock::execute
	 */
	public function testUnblock() {
		$expected = [
			new UnblockUserConsequence( $this->authorUser->getName() ),
			new AddLogEntryConsequence(
				'unblock',
				$this->moderatorUser,
				$this->authorUser->getUserPage()
			)
		];
		$actual = $this->getConsequences( 'unblock', [ true ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=unblock when the user is already not blocked.
	 * @covers ModerationActionBlock::execute
	 */
	public function testNoopUnblock() {
		$expected = [
			new UnblockUserConsequence( $this->authorUser->getName() ),
			// No AddLogEntryConsequence, because the user wasn't modblocked to begin with.
		];
		$actual = $this->getConsequences( 'unblock', [
			// Mocked return value from UnblockUserConsequence: simulate "nothing changed".
			false
		] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=editchangesubmit.
	 * @covers ModerationActionEditChangeSubmit::execute
	 */
	public function testEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		$expected = [
			new ModifyPendingChangeConsequence(
				$this->modid,
				'Some new text',
				'Some new summary',
				$row->mod_text,
				$row->mod_comment,
				$this->title,
				$this->authorUser
			),
			new AddLogEntryConsequence(
				'editchange',
				$this->moderatorUser,
				$this->title,
				[
					'modid' => $this->modid
				]
			)
		];

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( 'editchangesubmit', [ true ],
			[
				'wpTextbox1' => 'Some new text',
				'wpSummary' => 'Some new summary'
			]
		);

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=editchangesubmit when both text and summary are unchanged.
	 * @covers ModerationActionEditChangeSubmit::execute
	 */
	public function testNoopEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		$expected = [
			new ModifyPendingChangeConsequence(
				$this->modid,
				'Some new text',
				'Some new summary',
				$row->mod_text,
				$row->mod_comment,
				$this->title,
				$this->authorUser
			),
			// No AddLogEntryConsequence, because there were no changes.
		];

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( 'editchangesubmit',
			[
				// Mocked return value from ModifyPendingChangeConsequence:
				// simulate "nothing changed".
				false
			],
			[
				'wpTextbox1' => 'Some new text',
				'wpSummary' => 'Some new summary'
			]
		);

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Test consequences of modaction=rejectall.
	 * @covers ModerationActionReject::executeRejectAll
	 */
	public function testRejectAll() {
		$expected = [
			new RejectBatchConsequence( [ $this->modid ], $this->moderatorUser ),
			new AddLogEntryConsequence(
				'rejectall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => 1
				]
			)
		];
		$actual = $this->getConsequences( 'rejectall', [ 1 ] );

		$this->assertConsequencesEqual( $expected, $actual );
	}

	/**
	 * Ensure that readonly actions don't have any consequences.
	 * @param string $modaction
	 * @param Closure|null $beforeCallback Will be called before the test.
	 * @dataProvider dataProviderNoConsequenceActions
	 * @coversNothing
	 */
	public function testNoConsequenceActions( $modaction, Closure $beforeCallback = null ) {
		if ( $beforeCallback ) {
			$beforeCallback->call( $this );
		}

		$this->assertConsequencesEqual( [], $this->getConsequences( $modaction ) );
	}

	/**
	 * Provide datasets for testNoConsequenceActions() runs.
	 * @return array
	 */
	public function dataProviderNoConsequenceActions() {
		return [
			[ 'show', null ],
			[ 'showimg', null ],
			[ 'preview', null ],
			[ 'merge', function () {
				$dbw = wfGetDB( DB_MASTER );
				$dbw->update( 'moderation',
					[ 'mod_conflict' => 1 ],
					[ 'mod_id' => $this->modid ]
				);
			} ],
			[ 'editchange', function () {
				$this->setMwGlobals( 'wgModerationEnableEditChange', true );
			} ]
		];
	}

	// TODO: test approve/approveall
	// NOTE: running Approve without process isolation (like in ModerationTestsuite framework)
	// would confuse ApproveHooks class. Need a way to clean ApproveHooks between tests.
	// If ApproveHooks themselves use consequences, mocked Manager can be used too.

	/**
	 * Assert that $expectedConsequences are exactly the same as $actualConsequences.
	 * @param IConsequence[] $expectedConsequences
	 * @param IConsequence[] $actualConsequences
	 */
	private function assertConsequencesEqual(
		array $expectedConsequences,
		array $actualConsequences
	) {
		$expectedCount = count( $expectedConsequences );
		$this->assertCount( $expectedCount, $actualConsequences,
			"Unexpected number of consequences" );

		array_map( function ( $expected, $actual ) {
			$expectedClass = get_class( $expected );
			$this->assertInstanceof( $expectedClass, $actual,
				"Class of consequence doesn't match expected" );

			// Remove optionally calculated fields from Title/User objects within both consequences
			$this->flattenFields( $expected );
			$this->flattenFields( $actual );

			$this->assertEquals( $expected, $actual, "Parameters of consequence don't match expected" );
		}, $expectedConsequences, $actualConsequences );
	}

	/**
	 * Recalculate Title/User fields to ensure that no optionally calculated fields are calculated.
	 * This is needed to use assertEquals() of consequences: direct comparison of Title objects
	 * would fail, because Title object has fields like mUserCaseDBKey (they must not be compared).
	 */
	private function flattenFields( IConsequence $consequence ) {
		$wrapper = TestingAccessWrapper::newFromObject( $consequence );
		try {
			$wrapper->title = Title::newFromText( (string)$wrapper->title );
		} catch ( ReflectionException $e ) {
			// Not applicable to this Consequence.
		}

		try {
			$wrapper->originalAuthor = User::newFromName( $wrapper->originalAuthor->getName() );
		} catch ( ReflectionException $e ) {
			// Not applicable to this Consequence.
		}
	}

	/**
	 * Get an array of consequences after running $modaction on an edit that was queued in setUp().
	 * @param string $modaction
	 * @param array $mockedResults Each of these values will be passed to $manager->mockResult().
	 * @param array $extraParams Additional HTTP request parameters when running ModerationAction.
	 * @return IConsequence[]
	 */
	private function getConsequences( $modaction, array $mockedResults = [], $extraParams = [] ) {
		// Replace real ConsequenceManager with a mock.
		$manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $manager );

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $this->modid
		] + $extraParams );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( SpecialPage::getTitleFor( 'Moderation' ) );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

		foreach ( $mockedResults as $result ) {
			$manager->mockResult( $result );
		}

		$action = ModerationAction::factory( $context );
		$action->run();

		return $manager->getConsequences();
	}

	/**
	 * Queue an edit for moderation. Populate all fields ($this->modid, etc.) used by actual tests.
	 */
	public function setUp() {
		parent::setUp();

		$this->authorUser = self::getTestUser()->getUser();
		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$page = WikiPage::factory( $this->title );
		$page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'',
			EDIT_INTERNAL,
			false,
			$this->authorUser
		);

		$dbw = wfGetDB( DB_MASTER );
		$this->modid = (int)$dbw->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$this->assertNotSame( 0, $this->modid );
	}
}
