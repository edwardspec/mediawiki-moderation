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
	public $oldTitle = 'About dogs';
	public $newTitle = 'About herding dogs';
	public $text = 'Initial content of page "About dogs".';
	public $reasonForMoving = 'renamed for whatever reason';

	public function testMove() {
		$this->skipIfDisabled();

		/* Here we create the page $title and then rename it
			to $this->newTitle as non-automoderated user.
			This page move should be intercepted by Moderation. */
		$t = new ModerationTestsuite();

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $this->oldTitle, $this->text );

		$t->loginAs( $t->unprivilegedUser );
		$result = $t->nonApiMove( $this->oldTitle, $this->newTitle, $this->reasonForMoving );

		# Was the move queued for moderation?
		$this->assertFalse( $result->getError(), "testMove(): Special:MoverPage displayed an error." );
		$this->assertContains( '(moderation-move-queued)', $result->getSuccessText() );

		/* Check how it looks on Special:Moderation */
		$t->fetchSpecial();

		$this->assertCount( 1, $t->new_entries,
			"testMove(): One move was queued for moderation, but number of added entries in Pending folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testMove(): Something was deleted from Pending folder during the queueing" );

		$entry = $t->new_entries[0];
		$this->assertEquals( $t->unprivilegedUser->getName(), $entry->user );
		$this->assertTrue( $entry->isMove );
		$this->assertEquals( $this->oldTitle, $entry->title );
		$this->assertEquals( $this->newTitle, $entry->page2Title );

		$this->assertNotNull( $entry->approveLink, "testMove(): Approve link not found" );
		$this->assertNotNull( $entry->rejectLink, "testMove(): Reject link not found" );
		$this->assertNull( $entry->showLink, "testMove(): unexpected Show link found (it's not needed for moves)" );

		/* Ensure that page hasn't been moved yet */
		$rev = $t->getLastRevision( $this->oldTitle );
		$this->assertEquals( $t->automoderated->getName(), $rev['user'] );

		$this->assertFalse( $t->getLastRevision( $this->newTitle ) );

		/* Does modaction=show display this move correctly?
			(there is no Show link on Special:Moderation, but it's present
			in emails from $wgModerationNotificationEnable)
		*/

		$showLink = $entry->expectedActionLink( 'show', false );
		$this->assertContains( '(movepage-page-moved: ' . $this->oldTitle . ', ' . $this->newTitle . ')',
			$t->html->getMainText( $showLink ) );

		/* Check if we can approve this move */
		$t->html->loadFromURL( $t->new_entries[0]->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"testMove(): Result page doesn't contain (moderation-approved-ok: 1)" );

		/* Ensure that page has been moved after approval */
		$rev = $t->getLastRevision( $this->newTitle );
		$this->assertNotFalse( $rev );

		$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		$this->assertEquals( $this->text, $rev['*'] );

		/* Ensure that $this->oldTitle contains redirect */

		$rev = $t->getLastRevision( $this->oldTitle );
		$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		$this->assertNotEquals( $this->text, $rev['*'] );
		$this->assertRegExp( '/^#[^ ]+ \[\[' . preg_quote( $this->newTitle ) . '\]\]$/', $rev['*'] );

		/* Check the log entry */
		$events = $t->apiLogEntries();
		$this->assertCount( 1, $events,
			"testMove(): Number of post-approve log entries isn't 1." );
		$le = $events[0];

		$this->assertEquals( 'approve-move', $le['action'],
			"testMove(): Most recent log entry is not 'approve-move'" );
		$this->assertEquals( $this->oldTitle, $le['title'] );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $this->newTitle, $le['params']['target'] );
		$this->assertEquals( $t->unprivilegedUser->getId(), $le['params']['user'] );
		$this->assertEquals( $t->unprivilegedUser->getName(), $le['params']['user_text'] );

		$events = $t->nonApiLogEntries( 1 );

		$this->assertEquals( 'approve-move', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $this->oldTitle,
			$events[0]['params'][2] );
		$this->assertEquals( $this->newTitle,
			$events[0]['params'][3] );
		$this->assertEquals( $t->unprivilegedUser->getName(),
			$events[0]['params'][4] );
	}

	public function testApiMove() {
		$this->skipIfDisabled();

		/* Same as testMove(),
			but we move via API and check return value of API */

		$t = new ModerationTestsuite();

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $this->oldTitle, $this->text );

		$t->loginAs( $t->unprivilegedUser );
		$ret = $t->apiMove( $this->oldTitle, $this->newTitle, $this->reasonForMoving );

		$this->assertEquals( 'moderation-move-queued', $ret['error']['code'] );
	}

	public function skipIfDisabled() {
		global $wgModerationInterceptMoves;
		if ( !$wgModerationInterceptMoves ) {
			$this->markTestSkipped( 'Test skipped: $wgModerationInterceptMoves is not enabled on test wiki.' );
		}
	}
}
