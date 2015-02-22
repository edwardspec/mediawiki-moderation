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
	@brief Ensures that edits are intercepted by Extension:Moderation.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationEditHooks
*/
class ModerationTestInterceptEdit extends MediaWikiTestCase
{
	public function testInterceptEdit() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$ret = $t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$this->assertArrayHasKey('error', $ret);
		$this->assertEquals('edit-hook-aborted', $ret['error']['code']);

		$this->assertCount(1, $t->new_entries, "testQueued(): One edit was queued for moderation, but number of added entries in Pending folder isn't 1");
		$this->assertCount(0, $t->deleted_entries, "testQueued(): Something was deleted from Pending folder during the queueing");
		$this->assertEquals($t->lastEdit['User'], $t->new_entries[0]->user);
		$this->assertEquals($t->lastEdit['Title'], $t->new_entries[0]->title);

	}
}
