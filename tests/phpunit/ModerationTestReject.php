<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	@brief Verifies that modaction=reject(all) works as expected.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers ModerationActionReject
*/
class ModerationTestReject extends MediaWikiTestCase
{
	public function testReject() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->rejectLink,
			"testReject(): Reject link not found" );

		$t->html->loadFromURL( $entry->rejectLink );
		$this->assertRegExp( '/\(moderation-rejected-ok: 1\)/',
			$t->html->getMainText(),
			"testReject(): Result page doesn't contain (moderation-rejected-ok: 1)" );

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			"testReject(): Something was added into Pending folder during modaction=reject" );
		$this->assertCount( 1, $t->deleted_entries,
			"testReject(): One edit was rejected, but number of deleted entries in Pending folder isn't 1" );
		$this->assertEquals( $entry->id, $t->deleted_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->deleted_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->deleted_entries[0]->title );

		$t->fetchSpecial( 'rejected' );
		$this->assertCount( 1, $t->new_entries,
			"testReject(): One edit was rejected, but number of new entries in Rejected folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testReject(): Something was deleted from Rejected folder during modaction=reject" );
		$this->assertEquals( $entry->id, $t->new_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->new_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->new_entries[0]->title );

		$this->assertEquals( $t->moderator->getName(), $t->new_entries[0]->rejected_by_user );

		$this->assertFalse( $t->new_entries[0]->rejected_batch,
			"testReject(): Edit rejected via modaction=reject has rejected_batch flag ON" );
		$this->assertFalse( $t->new_entries[0]->rejected_auto,
			"testReject(): Manually rejected edit has rejected_auto flag ON" );

		$this->assertNull( $t->new_entries[0]->rejectLink,
			"testReject(): Reject link found for already rejected edit" );
		$this->assertNull( $t->new_entries[0]->rejectAllLink,
			"testReject(): RejectAll link found for already rejected edit" );
		$this->assertNull( $t->new_entries[0]->approveAllLink,
			"testReject(): ApproveAll link found for already rejected edit" );

		# Check the log entry
		$events = $t->apiLogEntries();
		$this->assertCount( 1, $events,
			"testReject(): Number of log entries isn't 1." );
		$le = $events[0];

		$this->assertEquals( 'reject', $le['action'],
			"testReject(): Most recent log entry is not 'reject'" );
		$this->assertEquals( $t->lastEdit['Title'], $le['title'] );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $t->unprivilegedUser->getName(), $le['params']['user_text'] );
		$this->assertEquals( $entry->id, $le['params']['modid'] );

		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'reject', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->lastEdit['Title'],
			$events[0]['params'][2] );
		$this->assertEquals( '(moderation-log-change: ' . $entry->id . ')',
			$events[0]['params'][3] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage()->getText(),
			$events[0]['params'][4] );
	}

	public function testRejectAll() {
		$t = new ModerationTestsuite();

		# We edit with two users:
		#	$t->unprivilegedUser (A)
		#	and $t->unprivilegedUser2 (B)
		# We're applying rejectall to one of the edits by A.
		# Expected result is:
		# 1) All edits by A were rejected,
		# 2) No edits by B were touched during rejectall.

		$t->doNTestEditsWith( $t->unprivilegedUser, $t->unprivilegedUser2 );
		$t->fetchSpecial();

		# Find edits by user A (they will be rejected)
		$entries = ModerationTestsuiteEntry::findByUser(
			$t->new_entries,
			$t->unprivilegedUser
		);
		$this->assertNotNull( $entries[0]->rejectAllLink,
			"testRejectAll(): RejectAll link not found" );

		$t->html->loadFromURL( $entries[0]->rejectAllLink );
		$this->assertRegExp( '/\(moderation-rejected-ok: ' . $t->TEST_EDITS_COUNT . '\)/',
			$t->html->getMainText(),
			"testRejectAll(): Result page doesn't contain (moderation-rejected-ok: N)" );

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			"testRejectAll(): Something was added into Pending folder during modaction=rejectall" );
		$this->assertCount( $t->TEST_EDITS_COUNT, $t->deleted_entries,
			"testRejectAll(): Several edits were rejected, but number of deleted entries in Pending folder doesn't match" );

		foreach ( $entries as $entry )
		{
			$de = ModerationTestsuiteEntry::findById( $t->deleted_entries, $entry->id );
			$this->assertNotNull( $de );

			$this->assertEquals( $entry->user, $de->user );
			$this->assertEquals( $entry->title, $de->title );
		}

		$t->fetchSpecial( 'rejected' );
		$this->assertCount( $t->TEST_EDITS_COUNT, $t->new_entries,
			"testRejectAll(): Several edits were rejected, but number of new entries in Rejected folder doesn't match" );
		$this->assertCount( 0, $t->deleted_entries,
			"testRejectAll(): Something was deleted from Rejected folder during modaction=rejectall" );

		foreach ( $entries as $entry )
		{
			$de = ModerationTestsuiteEntry::findById( $t->new_entries, $entry->id );
			$this->assertNotNull( $de );

			$this->assertEquals( $entry->user, $de->user );
			$this->assertEquals( $entry->title, $de->title );

			$this->assertEquals( $t->moderator->getName(), $de->rejected_by_user );
			$this->assertTrue( $de->rejected_batch,
				"testRejectAll(): Edit rejected via modaction=rejectall has rejected_batch flag OFF" );
			$this->assertFalse( $de->rejected_auto,
				"testRejectAll(): Manually rejected edit has rejected_auto flag ON" );

			$this->assertNull( $de->rejectLink,
				"testRejectAll(): Reject link found for already rejected edit" );
			$this->assertNull( $de->rejectAllLink,
				"testRejectAll(): RejectAll link found for already rejected edit" );
			$this->assertNull( $de->approveAllLink,
				"testRejectAll(): ApproveAll link found for already rejected edit" );
		}

		# Check the log entry: there should be only one 'rejectall'.
		$events = $t->apiLogEntries();
		$this->assertCount( 1, $events,
			"testRejectAll(): Number of log entries isn't 1." );
		$le = $events[0];

		$this->assertEquals( 'rejectall', $le['action'],
			"testRejectAll(): Most recent log entry is not 'rejectall'" );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage(), $le['title'] );
		$this->assertEquals( $t->TEST_EDITS_COUNT, $le['params']['count'] );

		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'rejectall', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage()->getText(),
			$events[0]['params'][2] );
		$this->assertEquals( $t->TEST_EDITS_COUNT, $events[0]['params'][3] );
	}
}
