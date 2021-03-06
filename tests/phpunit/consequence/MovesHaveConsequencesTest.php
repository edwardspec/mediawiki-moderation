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
 * Verifies that renaming a page has consequences.
 */

use MediaWiki\Moderation\QueueMoveConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class MovesHaveConsequencesTest extends ModerationUnitTestCase {
	/** @var Title */
	protected $title;

	/** @var string */
	protected $reason;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging' ];

	/**
	 * Test consequences when a move is queued for moderation.
	 * @covers ModerationMoveHooks::onMovePageCheckPermissions
	 */
	public function testMove() {
		$this->precreatePage();

		$user = self::getTestUser()->getUser();
		$newTitle = Title::newFromText( 'UTPage-new-' . rand( 0, 100000 ) );
		$reason = 'Some reason for renaming the page';

		$manager = $this->mockConsequenceManager();

		// Mock the result of canMoveSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canMoveSkip' )->with(
			$user,
			$this->title->getNamespace(),
			$newTitle->getNamespace()
		)->willReturn( false ); // Can't bypass moderation
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$mp = new MovePage( $this->title, $newTitle );
		$status = $mp->isValidMove();
		$status->merge( $mp->checkPermissions( $user, $reason ) );
		if ( $status->isOK() ) {
			$status->merge( $mp->move( $user, $reason, true ) );
		}

		$this->assertTrue( $status->hasMessage( 'moderation-move-queued' ),
			"Status returned by MovePage doesn't include \"moderation-move-queued\" message." );

		$this->assertConsequencesEqual( [
			new QueueMoveConsequence(
				$this->title,
				$newTitle,
				$user,
				$reason
			)
		], $manager->getConsequences() );
	}

	/**
	 * Test consequences of move when User is automoderated (can bypass moderation of moves).
	 * @covers ModerationMoveHooks::onMovePageCheckPermissions
	 */
	public function testAutomoderatedMove() {
		$this->precreatePage();

		$user = self::getTestUser()->getUser();
		$newTitle = Title::newFromText( 'UTPage-new-' . rand( 0, 100000 ) );
		$reason = 'Some reason for renaming the page';

		$manager = $this->mockConsequenceManager();

		// Mock the result of canMoveSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canMoveSkip' )->with(
			$user,
			$this->title->getNamespace(),
			$newTitle->getNamespace()
		)->willReturn( true ); // Can bypass moderation
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$mp = new MovePage( $this->title, $newTitle );
		$status = $mp->isValidMove();
		$status->merge( $mp->checkPermissions( $user, $reason ) );
		if ( $status->isOK() ) {
			$status->merge( $mp->move( $user, $reason, true ) );
		}

		$this->assertTrue( $status->isGood(),
			"User can bypass moderation, but move() still failed: " . $status->getMessage()->plain() );

		// The moderation was skipped, so should be no consequences.
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Test situation when Move was disallowed by some other extension.
	 * @covers ModerationMoveHooks::onMovePageCheckPermissions
	 */
	public function testThirdPartyDisallowedMove() {
		$this->precreatePage();

		// Simulate situation when Move is blocked by $wgSummarySpamRegex.
		// In this case $status will be fatal, so Moderation won't queue this edit.
		$this->setMwGlobals( 'wgSummarySpamRegex', '//' ); // Matches everything

		$user = self::getTestUser()->getUser();
		$newTitle = Title::newFromText( 'UTPage-new-' . rand( 0, 100000 ) );
		$reason = 'Some reason for renaming the page';

		$manager = $this->mockConsequenceManager();

		// Moderation shouldn't even check "can this user skip moderation?" here,
		// because Status is already an error, so the move should be forbidden.
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->never() )->method( 'canMoveSkip' );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$mp = new MovePage( $this->title, $newTitle );
		$status = $mp->isValidMove();
		$status->merge( $mp->checkPermissions( $user, $reason ) );
		if ( $status->isOK() ) {
			$status->merge( $mp->move( $user, $reason, true ) );
		}

		$this->assertTrue( $status->hasMessage( 'spamprotectiontext' ),
			"Status returned by MovePage doesn't include \"spamprotectiontext\" message." );

		// The moderation was skipped, so should be no consequences.
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Verify that move will not be queued when simply viewing Special:Movepage (without submitting).
	 * @covers ModerationMoveHooks::onMovePageCheckPermissions
	 */
	public function testMovePageOnlyView() {
		$oldTitle = Title::newFromText( 'Old title' );
		$newTitle = Title::newFromText( 'New title' );
		$user = User::newFromName( '127.0.0.1', false );
		$reason = 'Some reason';
		$status = Status::newGood();

		$globalContext = RequestContext::getMain();
		$globalContext->setTitle( SpecialPage::getTitleFor( 'Movepage' ) );
		$globalContext->setRequest( new FauxRequest( [], false ) ); // GET request

		$manager = $this->mockConsequenceManager();

		$hookResult = Hooks::run( 'MovePageCheckPermissions',
			[ $oldTitle, $newTitle, $user, $reason, $status ] );
		$this->assertTrue( $hookResult, 'Handler of MovePageCheckPermissions hook should return true.' );

		// Form of Special:Movepage wasn't submitted, so nothing should have been queued for moderation.
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Create a page as automoderated user. (this edit will bypass moderation)
	 * @return Status
	 */
	private function precreatePage() {
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$page = WikiPage::factory( $this->title );
		return $page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'',
			EDIT_INTERNAL,
			false,
			self::getTestUser( [ 'automoderated' ] )->getUser()
		);
	}
}
