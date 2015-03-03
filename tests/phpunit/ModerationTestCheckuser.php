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
	@brief Ensures that only checkusers can see IPs on Special:Moderation.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

class ModerationTestCheckuser extends MediaWikiTestCase
{
	public function testModerationCheckuser() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$this->assertNull($entry->ip,
			"testModerationCheckuser(): IP was shown to non-checkuser on Special:Moderation");

		$t->moderator = $t->moderatorAndCheckuser;

		$t->cleanFetchedSpecial();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];
		$this->assertNotNull($entry->ip,
			"testModerationCheckuser(): IP wasn't shown to checkuser on Special:Moderation");
		$this->assertEquals("127.0.0.1", $entry->ip,
			"testModerationCheckuser(): incorrect IP on Special:Moderation");
	}
}
