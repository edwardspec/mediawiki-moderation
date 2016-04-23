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
	@brief Verifies that modaction=approve(all) works as expected.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers ModerationActionApprove
*/
class ModerationTestApprove extends MediaWikiTestCase
{
	public function testApprove() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApprove(): Approve link not found" );

		$rev = $this->tryToApprove( $t, $entry );
		$t->fetchSpecial();

		$this->assertCount( 0, $t->new_entries,
			"testApprove(): Something was added into Pending folder during modaction=approve" );
		$this->assertCount( 1, $t->deleted_entries,
			"testApprove(): One edit was approved, but number of deleted entries in Pending folder isn't 1" );
		$this->assertEquals( $entry->id, $t->deleted_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->deleted_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->deleted_entries[0]->title );

		# Check the log entry
		$events = $t->apiLogEntries();
		$this->assertCount( 1, $events,
			"testApprove(): Number of log entries isn't 1." );
		$le = $events[0];

		$this->assertEquals( 'approve', $le['action'],
			"testApprove(): Most recent log entry is not 'approve'" );
		$this->assertEquals( $t->lastEdit['Title'], $le['title'] );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $rev['revid'], $le['params']['revid'] );

		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'approve', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->lastEdit['Title'],
			$events[0]['params'][2] );
		$this->assertEquals( "(moderation-log-diff: " . $rev['revid'] . ")",
			$events[0]['params'][3] );
	}

	public function testApproveAll() {
		$t = new ModerationTestsuite();

		# We edit with two users:
		#	$t->unprivilegedUser (A)
		#	and $t->unprivilegedUser2 (B)
		# We're applying approveall to one of the edits by A.
		# Expected result is:
		# 1) All edits by A were approved,
		# 2) No edits by B were touched during approveall.

		$t->doNTestEditsWith( $t->unprivilegedUser, $t->unprivilegedUser2 );
		$t->fetchSpecial();

		# Find edits by user A (they will be approved)
		$entries = ModerationTestsuiteEntry::findByUser(
			$t->new_entries,
			$t->unprivilegedUser
		);
		$this->assertNotNull( $entries[0]->approveAllLink,
			"testApproveAll(): ApproveAll link not found" );

		$t->html->loadFromURL( $entries[0]->approveAllLink );
		$this->assertRegExp( '/\(moderation-approved-ok: ' . $t->TEST_EDITS_COUNT . '\)/',
			$t->html->getMainText(),
			"testApproveAll(): Result page doesn't contain (moderation-approved-ok: N)" );

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			"testApproveAll(): Something was added into Pending folder during modaction=approveall" );
		$this->assertCount( $t->TEST_EDITS_COUNT, $t->deleted_entries,
			"testApproveAll(): Several edits were approved, but number of deleted entries in Pending folder doesn't match" );

		foreach ( $entries as $entry )
		{
			$rev = $t->getLastRevision( $entry->title );
			$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		}

		# Check the log entries: there should be
		# - one 'approveall' log entry
		# - TEST_EDITS_COUNT 'approve' log entries.

		$events = $t->apiLogEntries();
		$this->assertCount( 1 + $t->TEST_EDITS_COUNT, $events,
			"testApproveAll(): Number of log entries doesn't match the number of approved edits PLUS ONE (log entry for ApproveAll itself)." );

		# Per design, 'approveall' entry MUST be the most recent.
		$le = array_shift( $events );
		$this->assertEquals( 'approveall', $le['action'],
			"testApproveAll(): Most recent log entry is not 'approveall'" );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage(), $le['title'] );

		foreach ( $events as $le )
		{
			$this->assertEquals( 'approve', $le['action'] );
			$this->assertEquals( $t->moderator->getName(), $le['user'] );
		}

		# Only the formatting of 'approveall' line needs to be checked,
		# formatting of 'approve' lines already tested in testApprove()
		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'approveall', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage()->getText(),
			$events[0]['params'][2] );
		$this->assertEquals( $t->TEST_EDITS_COUNT, $events[0]['params'][3] );
	}

	public function testApproveAllNotRejected() {
		$t = new ModerationTestsuite();

		$t->TEST_EDITS_COUNT = 10;
		$t->doNTestEditsWith( $t->unprivilegedUser );
		$t->fetchSpecial();

		# Already rejected edits must not be affected by ApproveAll.
		# So let's reject some edits and check...

		$approveAllLink = $t->new_entries[0]->approveAllLink;

		# Odd edits are rejected, even edits are approved.
		for ( $i = 1; $i < $t->TEST_EDITS_COUNT; $i += 2 )
		{
			$t->httpGet( $t->new_entries[$i]->rejectLink );
		}

		$t->fetchSpecial( 'rejected' );
		$t->httpGet( $approveAllLink, 'GET' );
		$t->fetchSpecial( 'rejected' );

		$this->assertCount( 0, $t->new_entries,
			"testApproveAllNotRejected(): Something was added into Rejected folder during modaction=approveall" );
		$this->assertCount( 0, $t->deleted_entries,
			"testApproveAllNotRejected(): Something was deleted from Rejected folder during modaction=approveall" );
	}

	public function testApproveRejected() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$t->httpGet( $t->new_entries[0]->rejectLink );
		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApproveRejected(): Approve link not found" );
		$this->tryToApprove( $t, $entry );

		$t->fetchSpecial( 'rejected' );

		$this->assertCount( 0, $t->new_entries,
			"testApproveRejected(): Something was added into Rejected folder during modaction=approve" );
		$this->assertCount( 1, $t->deleted_entries,
			"testApproveRejected(): One rejected edit was approved, but number of deleted entries in Rejected folder isn't 1" );
		$this->assertEquals( $entry->id, $t->deleted_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->deleted_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->deleted_entries[0]->title );
	}

	public function testApproveNotExpiredRejected() {
		global $wgModerationTimeToOverrideRejection;
		$t = new ModerationTestsuite();

		# Rejected edits can only be approved if they are no older
		# than $wgModerationTimeToOverrideRejection.

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$id = $t->new_entries[0]->id;

		$t->httpGet( $t->new_entries[0]->rejectLink );

		/* Modify mod_timestamp to make this edit 1 hour older than
			allowed by $wgModerationTimeToOverrideRejection. */

		$ts = new MWTimestamp( time() );
		$ts->timestamp->modify( '-' . intval( $wgModerationTimeToOverrideRejection ) . ' seconds' );
		$ts->timestamp->modify( '-1 hour' ); /* Should NOT be approvable */

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array( 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ),
			array( 'mod_id' => $id ),
			__METHOD__
		);

		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNull( $entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link found for edit that was rejected more than $wgModerationTimeToOverrideRejection seconds ago" );

		# Ensure that usual approve URL doesn't work:
		$error = $t->html->getModerationError( $entry->expectedActionLink( 'approve' ) );
		$this->assertEquals( '(moderation-rejected-long-ago)', $error,
			"testApproveNotExpiredRejected(): No expected error from modaction=approve" );

		/* Make the edit less ancient
			than $wgModerationTimeToOverrideRejection ago */

		$ts->timestamp->modify( '+2 hour' ); /* Should be approvable */

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array( 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ),
			array( 'mod_id' => $id ),
			__METHOD__
		);

		$t->assumeFolderIsEmpty( 'rejected' );
		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link is missing for edit that was rejected less than $wgModerationTimeToOverrideRejection seconds ago" );

		$this->tryToApprove( $t, $entry );
	}

	/**
		@covers ModerationActionApprove::prepareApproveHooks()
		@brief This test verifies that moderator can be NOT automoderated.

		There is no real use for such setup other than debugging,
		and that's why we don't want to test this manually.
	*/
	public function testModeratorNotAutomoderated() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->moderatorButNotAutomoderated );

		$t->editViaAPI = true;
		$ret = $t->doTestEdit();

		$t->fetchSpecial();

		/* Edit must be intercepted (this user is not automoderated) */
		$this->assertArrayHasKey( 'error', $ret );
		$this->assertEquals( 'edit-hook-aborted', $ret['error']['code'] );

		$entry = $t->new_entries[0];
		$this->assertCount( 1, $t->new_entries,
			"testModeratorNotAutomoderated(): One edit was queued for moderation, but number of added entries in Pending folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testModeratorNotAutomoderated(): Something was deleted from Pending folder during the queueing" );
		$this->assertEquals( $t->lastEdit['User'], $entry->user );
		$this->assertEquals( $t->lastEdit['Title'], $entry->title );

		/* Must be able to approve the edit (this user is moderator) */
		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->tryToApprove( $t, $entry );

		/* ApproveAll must also work */
		$t->doNTestEditsWith( $t->moderatorButNotAutomoderated );
		$t->fetchSpecial();

		$t->loginAs( $t->moderatorButNotAutomoderated );
		$t->httpGet( $t->new_entries[0]->approveAllLink );

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			"testModeratorNotAutomoderated(): Something was added into Pending folder during modaction=approveall" );
		$this->assertCount( $t->TEST_EDITS_COUNT, $t->deleted_entries,
			"testModeratorNotAutomoderated(): Several edits were approved, but number of deleted entries in Pending folder doesn't match" );
	}

	public function testApproveTimestamp() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$TEST_TIME_CHANGE = '6 hours';
		$ACCEPTABLE_DIFFERENCE = 300; # in seconds

		$ts = new MWTimestamp( time() );
		$ts->timestamp->modify( '-' . $TEST_TIME_CHANGE );

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array( 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ),
			array( 'mod_id' => $entry->id ),
			__METHOD__
		);
		$rev = $this->tryToApprove( $t, $entry );

		# Page history should mention the time when edit was made,
		# not when it was approved.

		$expected = $ts->getTimestamp( TS_ISO_8601 );
		$this->assertEquals( $expected, $rev['timestamp'],
			"testApproveTimestamp(): approved edit has incorrect timestamp in the page history" );

		# RecentChanges should mention the time when the edit was
		# approved, so that it won't "appear in the past", confusing
		# those who read RecentChanges.

		$ret = $t->query( array(
			'action' => 'query',
			'list' => 'recentchanges',
			'rcprop' => 'timestamp',
			'rclimit' => 1,
			'rcuser' => $t->lastEdit['User']
		) );
		$rc_timestamp = $ret['query']['recentchanges'][0]['timestamp'];

		$this->assertNotEquals( $expected, $rc_timestamp,
			"testApproveTimestamp(): approved edit has \"appeared in the past\" in the RecentChanges" );

		# Does the time in RecentChanges match the time of approval?
		#
		# NOTE: we don't know the time of approval to the second, so
		# string comparison can't be used. Difference can be seconds
		# or even minutes (if system time is off).
		$ts->timestamp->modify( '+' . $TEST_TIME_CHANGE );
		$expected = $ts->getTimestamp( TS_UNIX );

		$ts_actual = new MWTimestamp( $rc_timestamp );
		$actual = $ts_actual->getTimestamp( TS_UNIX );

		$this->assertLessThan( $ACCEPTABLE_DIFFERENCE, abs( $expected - $actual ),
			"testApproveTimestamp(): timestamp of approved edit in RecentChanges is too different from the time of approval" );
	}

	private function tryToApprove( $t, $entry )
	{
		$t->html->loadFromURL( $entry->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"testApproveAll(): Result page doesn't contain (moderation-approved-ok: 1)" );

		$rev = $t->getLastRevision( $entry->title );

		$this->assertEquals( $t->lastEdit['User'], $rev['user'] );
		$this->assertEquals( $t->lastEdit['Text'], $rev['*'] );
		$this->assertEquals( $t->lastEdit['Summary'], $rev['comment'] );

		return $rev;
	}
}
