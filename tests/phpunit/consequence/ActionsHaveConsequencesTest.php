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
use MediaWiki\Moderation\RejectBatchConsequence;
use MediaWiki\Moderation\RejectOneConsequence;
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
	 * @param string $modaction
	 * @param Closure $getExpectedConsequences
	 * @param array $extraParams
	 * @covers ModerationActionReject::execute
	 * @dataProvider dataProviderActionConsequences
	 */
	public function testActionConsequences(
		$modaction,
		Closure $getExpectedConsequences,
		$extraParams = []
	) {
		if ( $modaction == 'editchangesubmit' ) {
			$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		}

		$expectedConsequences = $getExpectedConsequences->call( $this );

		$this->assertConsequencesEqual(
			$expectedConsequences,
			$this->getConsequences( $modaction, $extraParams )
		);
	}

	/**
	 * Provide datasets for testActionConsequences() runs.
	 */
	public function dataProviderActionConsequences() {
		$sets = [];

		$sets['reject'] = [ 'reject', function () {
			return [
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
		} ];

		$sets['block'] = [ 'block', function () {
			return [
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
		} ];

		// TODO: test block when user is already modblocked (shouldn't add any log entries),
		// and similarly unblock for a modblocked and non-modblocked user.

		$sets['rejectall'] = [ 'rejectall', function () {
			return [
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
		} ];

		$sets['editchangesubmit'] = [ 'editchangesubmit', function () {
			return [
				new AddLogEntryConsequence(
					'editchange',
					$this->moderatorUser,
					$this->title,
					[
						'modid' => $this->modid
					]
				)
			];
		}, [
			'wpTextbox1' => 'New text',
			'wpSummary' => 'Edit comment'
		] ];

		// TODO: no-op editchangesubmit (when wpTextbox1 and wpSummary are exactly as before)

		// TODO: test approve/approveall
		// NOTE: running Approve without process isolation (like in ModerationTestsuite framework)
		// would confuse ApproveHooks class. Need a way to clean ApproveHooks between tests.
		// If ApproveHooks themselves use consequences, mocked Manager can be used too.

		return $sets;
	}

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

			if ( $expected instanceof AddLogEntryConsequence ) {
				// Remove optionally calculated fields from Title objects within both consequences.
				$this->flattenTitle( $expected );
				$this->flattenTitle( $actual );
			}

			$this->assertEquals( $expected, $actual, "Parameters of consequence don't match expected" );
		}, $expectedConsequences, $actualConsequences );
	}

	/**
	 * Recalculate the Title field to ensure that no optionally calculated fields are calculated.
	 * This is needed to use assertEquals() of consequences: direct comparison of Title objects
	 * would fail, because Title object has fields like mUserCaseDBKey (they must not be compared).
	 */
	private function flattenTitle( IConsequence $consequence ) {
		$wrapper = TestingAccessWrapper::newFromObject( $consequence );
		if ( !$wrapper->title ) {
			// Not applicable to this Consequence.
			return;
		}

		$wrapper->title = Title::newFromText( (string)$wrapper->title );
	}

	/**
	 * Get an array of consequences after running $modaction on an edit that was queued in setUp().
	 * @param string $modaction
	 * @param array $extraParams Additional HTTP request parameters when running ModerationAction.
	 * @return IConsequence[]
	 */
	private function getConsequences( $modaction, $extraParams = [] ) {
		// Replace real ConsequenceManager with a mock.
		$manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $manager );

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $this->modid
		] + $extraParams );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

		// FIXME: move this away from getConsequences() into its parameter,
		// e.g. $mockedResults array.
		if ( $modaction == 'block' || $modaction == 'unblock' || $modaction == 'reject'
			|| $modaction == 'rejectall'
		) {
			$manager->mockResult( true );
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
		$this->moderatorUser = self::getTestUser( [ 'moderator' ] )->getUser();

		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$page = WikiPage::factory( $this->title );
		$page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'',
			EDIT_INTERNAL,
			false,
			$this->authorUser
		);

		$this->modid = wfGetDB( DB_MASTER )->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$this->assertNotFalse( $this->modid );
	}
}
