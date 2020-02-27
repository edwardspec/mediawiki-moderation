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

use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueMoveConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class MovesHaveConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

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

		list( $scope, $manager ) = MockConsequenceManager::install();

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
