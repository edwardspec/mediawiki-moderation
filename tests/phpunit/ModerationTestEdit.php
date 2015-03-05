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
	@brief Verifies that editing works as usual.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

class ModerationTestEdit extends MediaWikiTestCase
{
	public function testPreSaveTransform() {
		$t = new ModerationTestsuite();

		# Some substitutions (e.g. ~~~~) must be performed when
		# the edit is queued for moderation, not when it is approved.
		$text = '~~~~';

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit(null, $text);
		$t->fetchSpecialAndDiff();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array('mod_text AS text'),
			array('mod_id' => $t->new_entries[0]->id),
			__METHOD__
		);

		$this->assertNotEquals($text, $row->text,
			"testPreSaveTransform(): Signature (~~~~) hasn't been properly substituted.");
	}
}
