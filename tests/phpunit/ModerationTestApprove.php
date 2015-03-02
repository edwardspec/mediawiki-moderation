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

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationActionApprove
*/
class ModerationTestApprove extends MediaWikiTestCase
{
	public function testApprove() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];
		$this->assertNotNull($entry->approveLink,
			"testApprove(): Approve link not found");

		$this->tryToApprove($t, $entry);

		/*
			NOTE: checking 'timestamp' can't be in this test, because
			we'd have to sleep(N) to make edit time and approval timestamp
			different enough, and that would make this test very slow.

			This should be in a separate test (preferably one of the last
			to run).
		*/
		$t->fetchSpecialAndDiff();

		$this->assertCount(0, $t->new_entries,
			"testApprove(): Something was added into Pending folder during modaction=accept");
		$this->assertCount(1, $t->deleted_entries,
			"testApprove(): One edit was approved, but number of deleted entries in Pending folder isn't 1");
		$this->assertEquals($entry->id, $t->deleted_entries[0]->id);
		$this->assertEquals($t->lastEdit['User'], $t->deleted_entries[0]->user);
		$this->assertEquals($t->lastEdit['Title'], $t->deleted_entries[0]->title);
	}

	public function testApproveAll() {
		$t = new ModerationTestsuite();
		$t->fetchSpecial();

		# We edit with two users:
		#	$t->unprivilegedUser (A)
		#	and $t->unprivilegedUser2 (B)
		# We're applying approveall to one of the edits by A.
		# Expected result is:
		# 1) All edits by A were approved,
		# 2) No edits by B were touched during approveall.

		$t->doNTestEditsWith($t->unprivilegedUser, $t->unprivilegedUser2);
		$t->fetchSpecialAndDiff();

		# Find edits by user A (they will be approved)
		$entries = ModerationTestsuiteEntry::findByUser(
			$t->new_entries,
			$t->unprivilegedUser
		);
		$this->assertNotNull($entries[0]->approveAllLink,
			"testApproveAll(): ApproveAll link not found");

		$req = $t->makeHttpRequest($entries[0]->approveAllLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* TODO: check $req->getContent() */

		$t->fetchSpecialAndDiff();
		$this->assertCount(0, $t->new_entries,
			"testApproveAll(): Something was added into Pending folder during modaction=approveall");
		$this->assertCount($t->TEST_EDITS_COUNT, $t->deleted_entries,
			"testApproveAll(): Several edits were approved, but number of deleted entries in Pending folder doesn't match");

		foreach($entries as $entry)
		{
			$ret = $t->query(array(
				'action' => 'query',
				'prop' => 'revisions',
				'rvlimit' => 1,
				'rvprop' => 'user|timestamp|comment|content',
				'titles' => $entry->title
			));
			$ret_page = array_shift($ret['query']['pages']);
			$rev = $ret_page['revisions'][0];

			$this->assertEquals($t->unprivilegedUser->getName(), $rev['user']);
		}
	}
	
	public function testApproveAllNotRejected() {
		$t = new ModerationTestsuite();
		
		$t->fetchSpecial();
		$t->TEST_EDITS_COUNT = 10;
		$t->doNTestEditsWith($t->unprivilegedUser);
		$t->fetchSpecialAndDiff();
		
		# Already rejected edits must not be affected by ApproveAll.
		# So let's reject some edits and check...
		
		$approveAllLink = $t->new_entries[0]->approveAllLink;

		# Odd edits are rejected, even edits are approved.
		for($i = 1; $i < $t->TEST_EDITS_COUNT; $i += 2)
		{
			$req = $t->makeHttpRequest($t->new_entries[$i]->rejectLink, 'GET');
			$this->assertTrue($req->execute()->isOK());
		}

		$t->fetchSpecial('rejected');
		$req = $t->makeHttpRequest($approveAllLink, 'GET');
		$this->assertTrue($req->execute()->isOK());
		$t->fetchSpecialAndDiff('rejected');
		
		$this->assertCount(0, $t->new_entries,
			"testApproveAllNotRejected(): Something was added into Rejected folder during modaction=approveall");
		$this->assertCount(0, $t->deleted_entries,
			"testApproveAllNotRejected(): Something was deleted from Rejected folder during modaction=approveall");
	}

	public function testApproveRejected() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$t->fetchSpecial('rejected');
		$req = $t->makeHttpRequest($t->new_entries[0]->rejectLink, 'GET');
		$this->assertTrue($req->execute()->isOK());
		$t->fetchSpecialAndDiff('rejected');

		$entry = $t->new_entries[0];
		$this->assertNotNull($entry->approveLink,
			"testApproveRejected(): Approve link not found");
		$this->tryToApprove($t, $entry);

		$t->fetchSpecialAndDiff('rejected');

		$this->assertCount(0, $t->new_entries,
			"testApproveRejected(): Something was added into Rejected folder during modaction=accept");
		$this->assertCount(1, $t->deleted_entries,
			"testApproveRejected(): One rejected edit was approved, but number of deleted entries in Rejected folder isn't 1");
		$this->assertEquals($entry->id, $t->deleted_entries[0]->id);
		$this->assertEquals($t->lastEdit['User'], $t->deleted_entries[0]->user);
		$this->assertEquals($t->lastEdit['Title'], $t->deleted_entries[0]->title);
	}

	/* TODO: $wgModerationTimeToOverrideRejection check */

	public function testApproveNotExpiredRejected() {
		global $wgModerationTimeToOverrideRejection;
		$t = new ModerationTestsuite();

		# Rejected edits can only be approved if they are no older
		# than $wgModerationTimeToOverrideRejection.

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$id = $t->new_entries[0]->id;

		$t->fetchSpecial('rejected');
		$req = $t->makeHttpRequest($t->new_entries[0]->rejectLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* Modify mod_timestamp to make this edit 1 hour older than
			allowed by $wgModerationTimeToOverrideRejection. */

		$ts = new MWTimestamp(time());
		$ts->timestamp->modify('-' . intval($wgModerationTimeToOverrideRejection) . ' seconds');
		$ts->timestamp->modify('-1 hour'); /* Should NOT be approvable */

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array('mod_timestamp' => $ts->getTimestamp(TS_MW)),
			array('mod_id' => $id),
			__METHOD__
		);

		# We need to fetch Special:Moderation again to ensure
		# that Approve link no longer exists for this entry.
		$t->cleanFetchedSpecial('rejected');
		$t->fetchSpecialAndDiff('rejected');

		$entry = $t->new_entries[0];
		$this->assertNull($entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link found for edit that was rejected more than $wgModerationTimeToOverrideRejection seconds ago");

		# Ensure that usual approve URL doesn't work:
		$error = $t->getModerationErrorByURL($entry->expectedActionLink('approve'));
		$this->assertEquals('(moderation-rejected-long-ago)', $error,
			"testApproveNotExpiredRejected(): No expected error from modaction=approve");

		/* Make the edit less ancient
			than $wgModerationTimeToOverrideRejection ago */

		$ts->timestamp->modify('+2 hour'); /* Should be approvable */

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array('mod_timestamp' => $ts->getTimestamp(TS_MW)),
			array('mod_id' => $id),
			__METHOD__
		);

		$t->cleanFetchedSpecial('rejected');
		$t->fetchSpecialAndDiff('rejected');

		$entry = $t->new_entries[0];
		$this->assertNotNull($entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link is missing for edit that was rejected less than $wgModerationTimeToOverrideRejection seconds ago");

		$this->tryToApprove($t, $entry);
	}

	/**
		@covers ModerationActionApprove::prepareApproveHooks()
		@brief This test verifies that moderator can be NOT automoderated.

		There is no real use for such setup other than debugging,
		and that's why we don't want to test this manually.
	*/
	public function testModeratorNotAutomoderated() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->moderatorButNotAutomoderated);
		$ret = $t->doTestEdit();
		$t->fetchSpecialAndDiff();

		/* Edit must be intercepted (this user is not automoderated) */
		$this->assertArrayHasKey('error', $ret);
		$this->assertEquals('edit-hook-aborted', $ret['error']['code']);

		$entry = $t->new_entries[0];
		$this->assertCount(1, $t->new_entries,
			"testModeratorNotAutomoderated(): One edit was queued for moderation, but number of added entries in Pending folder isn't 1");
		$this->assertCount(0, $t->deleted_entries,
			"testModeratorNotAutomoderated(): Something was deleted from Pending folder during the queueing");
		$this->assertEquals($t->lastEdit['User'], $entry->user);
		$this->assertEquals($t->lastEdit['Title'], $entry->title);

		/* Must be able to approve the edit (this user is moderator) */
		$t->loginAs($t->moderatorButNotAutomoderated);
		$this->tryToApprove($t, $entry);
	}

	private function tryToApprove($t, $entry)
	{
		$req = $t->makeHttpRequest($entry->approveLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* TODO: check $req->getContent() */

		$ret = $t->query(array(
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'user|timestamp|comment|content',
			'titles' => $entry->title
		));
		$ret_page = array_shift($ret['query']['pages']);
		$rev = $ret_page['revisions'][0];

		$this->assertEquals($t->lastEdit['User'], $rev['user']);
		$this->assertEquals($t->lastEdit['Text'], $rev['*']);
		$this->assertEquals($t->lastEdit['Summary'], $rev['comment']);
	}
}
