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
	@brief Verifies that modaction=approve works as expected.
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

		/*
			NOTE: checking 'timestamp' can't be in this test, because
			we'd have to sleep(N) to make edit time and approval timestamp
			different enough, and that would make this test very slow.

			This should be in a separate test (preferably one of the last
			to run).
		*/
		$t->fetchSpecialAndDiff();

		$this->assertCount(0, $t->new_entries, "testApprove(): Something was added into Pending folder during modaction=accept");
		$this->assertCount(1, $t->deleted_entries, "testApprove(): One edit was accepted, but number of deleted entries in Pending folder isn't 1");
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
}
