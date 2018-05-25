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
	@file
	@brief Ensures that moves are intercepted by Extension:Moderation.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

/**
	@covers ModerationMoveHooks
*/
class ModerationMoveEdit extends MediaWikiTestCase
{
	public function testMove() {
		global $wgModerationInterceptMoves;
		if ( !$wgModerationInterceptMoves ) {
			$this->markTestSkipped( 'Test skipped: $wgModerationInterceptMoves is not enabled on test wiki.' );
		}

		/* Here we create the page $title and then rename it
			to $newTitle as non-automoderated user.
			This page move should be intercepted by Moderation. */

		$oldTitle = 'About cats';
		$newTitle = 'About bengal cats';
		$text = 'Initial content of page "About cats".';
		$reasonForMoving = 'This page is only about bengals, ther cats have their own pages.';

		$t = new ModerationTestsuite();

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $oldTitle, $text );

		$t->loginAs( $t->unprivilegedUser );
		$ret = $t->move( $oldTitle, $newTitle, $reasonForMoving );

		$this->assertArrayHasKey( 'error', $ret );
		$this->assertContains( $ret['error']['code'], [
			'unknownerror', # MediaWiki 1.28 and older
			'moderation-edit-queued' # MediaWiki 1.29+
		] );
		if ( $ret['error']['code'] == 'unknownerror' ) {
			$this->assertRegExp( '/moderation-edit-queued/',
				$ret['error']['info'] );
		}

		/* Check how it looks on Special:Moderation */
		$t->fetchSpecial();

		$this->assertCount( 1, $t->new_entries,
			"testMove(): One move was queued for moderation, but number of added entries in Pending folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testMove(): Something was deleted from Pending folder during the queueing" );

		$entry = $t->new_entries[0];
		$this->assertEquals( $t->unprivilegedUser->getName(), $entry->user );
		$this->assertTrue( $entry->isMove );
		$this->assertEquals( $oldTitle, $entry->title );
		$this->assertEquals( $newTitle, $entry->page2Title );

		/* Ensure that page hasn't been moved yet */
		$rev = $t->getLastRevision( $oldTitle );
		$this->assertEquals( $t->automoderated->getName(), $rev['user'] );

		$this->assertFalse( $t->getLastRevision( $newTitle ) );

		/* Check if we can approve this move */

		$t->html->loadFromURL( $t->new_entries[0]->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"testMove(): Result page doesn't contain (moderation-approved-ok: 1)" );

		/* Ensure that page has been moved after approval */
		$rev = $t->getLastRevision( $newTitle );
		$this->assertNotFalse( $rev );

		$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		$this->assertEquals( $text, $rev['*'] );

		/* Ensure that $oldTitle contains redirect */

		$rev = $t->getLastRevision( $oldTitle );
		$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		$this->assertNotEquals( $text, $rev['*'] );
		$this->assertRegExp( '/^#[^ ]+ \[\[' . preg_quote( $newTitle ) . '\]\]$/', $rev['*'] );
	}
}
