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
	@brief Ensure that known error conditions cause exceptions.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationError
*/
class ModerationTestErrors extends MediaWikiTestCase
{
	public function testUnknownAction() {
		$t = new ModerationTestsuite();
		$t->loginAs($t->moderator);

		$error = $t->getModerationErrorByURL($t->getSpecialURL() .
			"&modaction=findgirlfriend");
		$this->assertEquals('(moderation-unknown-modaction)', $error);
	}

	public function testEditNotFound() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		# Delete this entry by approving it
		$req = $t->makeHttpRequest($entry->approveLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		$entry->fakeBlockLink();
		$links = array(
			$entry->showLink,
			$entry->approveLink,
			$entry->approveAllLink,
			$entry->rejectLink,
			$entry->rejectAllLink,
			$entry->blockLink,
			$entry->unblockLink
			# TODO: check mergeLink
		);
		foreach($links as $url) {
			$error = $t->getModerationErrorByURL($url);
			$this->assertEquals('(moderation-edit-not-found)', $error);
		}
	}

	public function testAlreadyRejected() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$req = $t->makeHttpRequest($entry->rejectLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		$error = $t->getModerationErrorByURL($entry->rejectLink);
		$this->assertEquals('(moderation-already-rejected)', $error);
	}

	public function testNothingToAll() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$req = $t->makeHttpRequest($entry->rejectLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		$error = $t->getModerationErrorByURL($entry->rejectAllLink);
		$this->assertEquals('(moderation-nothing-to-rejectall)', $error);

		$error = $t->getModerationErrorByURL($entry->approveAllLink);
		$this->assertEquals('(moderation-nothing-to-approveall)', $error);
	}

	public function testMissingStashedImage() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array('mod_stash_key AS stash_key'),
			array('mod_id' => $entry->id),
			__METHOD__
		);

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash();
		$stash->removeFileNoAuth($row->stash_key);

		$error = $t->getModerationErrorByURL($entry->approveLink);
		$this->assertEquals('(moderation-missing-stashed-image)', $error);
	}
}
