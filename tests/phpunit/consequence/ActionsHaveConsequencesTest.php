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
use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
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
	protected $tablesUsed = [ 'moderation' ];

	/**
	 * @coversNothing
	 */
	public function testReject() {
		$this->assertConsequencesEqual( [
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
		], $this->getConsequences( 'reject' ) );
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
	 * @return IConsequence[]
	 */
	private function getConsequences( $modaction ) {
		// Replace real ConsequenceManager with a mock.
		$manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $manager );

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $this->modid
		] );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

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
