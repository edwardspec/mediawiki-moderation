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
	@brief Verifies that modaction=reject works as expected.
*/

require_once(__DIR__ . "/ModerationTestsuite.php");

/**
	@covers ModerationActionReject
*/
class ModerationTestReject extends MediaWikiTestCase
{
	public function testReject() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];
		$t->fetchSpecial('rejected');

		$req = $t->makeHttpRequest($entry->rejectLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* TODO: check $req->getContent() */

		$t->fetchSpecialAndDiff();
		$this->assertCount(0, $t->new_entries, "testReject(): Something was added into Pending folder during modaction=reject");
		$this->assertCount(1, $t->deleted_entries, "testReject(): One edit was rejected, but number of deleted entries in Pending folder isn't 1");
		$this->assertEquals($entry->id, $t->deleted_entries[0]->id);
		$this->assertEquals($t->lastEdit['User'], $t->deleted_entries[0]->user);
		$this->assertEquals($t->lastEdit['Title'], $t->deleted_entries[0]->title);

		$t->fetchSpecialAndDiff('rejected');
		$this->assertCount(1, $t->new_entries, "testReject(): One edit was rejected, but number of new entries in Rejected folder isn't 1");
		$this->assertCount(0, $t->deleted_entries, "testReject(): Something was deleted from Rejected folder during modaction=reject");
		$this->assertEquals($entry->id, $t->new_entries[0]->id);
		$this->assertEquals($t->lastEdit['User'], $t->new_entries[0]->user);
		$this->assertEquals($t->lastEdit['Title'], $t->new_entries[0]->title);

		$this->assertEquals($t->moderator->getName(), $t->new_entries[0]->rejected_by_user);

		# Not yet implemented in ModerationTestsuiteEntry:
		# $this->assertFalse($t->new_entries[0]->rejected_batch, "testReject(): Edit rejected via modaction=reject has rejected_batch flag ON");
		# $this->assertFalse($t->new_entries[0]->rejected_auto, "testReject(): Manually rejected edit has rejected_auto flag ON");
	}
}
