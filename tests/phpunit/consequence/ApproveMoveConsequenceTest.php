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
 * Unit test of ApproveMoveConsequence.
 */

use MediaWiki\Moderation\ApproveMoveConsequence;

/**
 * @group Database
 */
class ApproveMoveConsequenceTest extends MediaWikiTestCase {
	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'logging' ];

	/**
	 * Verify that ApproveMoveConsequence renames the page.
	 * @covers MediaWiki\Moderation\ApproveMoveConsequence
	 */
	public function testApproveMove() {
		$moderator = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$newTitle = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) . '-new' );
		$reason = 'Some reasons why the page was renamed';

		// Precreate the page that will be renamed.
		$page = WikiPage::factory( $title );
		$page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'',
			EDIT_INTERNAL,
			false,
			$moderator // Should bypass moderation
		);

		// Monitor TitleMoveComplete hook
		$hookFired = false;
		$this->setTemporaryHook( 'TitleMoveComplete',
			function ( $hookTitle, $hookNewTitle, $hookUser, $pageid, $redirid, $hookReason )
			use ( $user, $title, $reason, &$hookFired ) {
				$hookFired = true;

				$this->assertEquals( $title->getFullText(), $hookTitle->getFullText() );
				$this->assertEquals( $user->getName(), $hookUser->getName() );
				$this->assertEquals( $user->getId(), $hookUser->getId() );
				$this->assertEquals( $reason, $hookReason );

				return true;
			} );

		// Create and run the Consequence.
		$consequence = new ApproveMoveConsequence( $moderator, $title, $newTitle, $user, $reason );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveMoveConsequence failed: " . $status->getMessage()->plain() );
		$this->assertTrue( $hookFired, "ApproveMoveConsequence: didn't move anything." );
	}
}
