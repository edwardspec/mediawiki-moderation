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
	@brief Ensures that uploads are intercepted by Extension:Moderation.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationUploadHooks
	@requires extension curl
	@note Only cURL version of MWHttpRequest supports uploads.
*/
class ModerationTestUpload extends MediaWikiTestCase
{
	public function testUpload() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload();
		$t->fetchSpecialAndDiff();

		# Was the upload queued for moderation?
		$this->assertEquals('(moderation-image-queued)', $error);

		# Is the data on Special:Moderation correct?
		$entry = $t->new_entries[0];
		$this->assertCount(1, $t->new_entries,
			"testUpload(): One upload was queued for moderation, but number of added entries in Pending folder isn't 1");
		$this->assertCount(0, $t->deleted_entries,
			"testUpload(): Something was deleted from Pending folder during the queueing");
		$this->assertEquals($t->lastEdit['User'], $entry->user);
		$this->assertEquals($t->lastEdit['Title'], $entry->title);

		# Can we approve this upload?
		$this->assertNotNull($entry->approveLink,
			"testUpload(): Approve link not found");

		$req = $t->makeHttpRequest($entry->approveLink, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* TODO: check $req->getContent() */

		# Has the file been uploaded after the approval?
		$ret = $t->query(array(
			'action' => 'query',
			'prop' => 'imageinfo',
			'iilimit' => 1,
			'iiprop' => 'user|timestamp|comment|size|url|sha1',
			'titles' => $entry->title
		));
		$ret_page = array_shift($ret['query']['pages']);
		$ii = $ret_page['imageinfo'][0];

		$this->assertEquals($t->lastEdit['User'], $ii['user']);
		$this->assertEquals($t->lastEdit['Text'], $ii['comment']);
		$this->assertEquals($t->lastEdit['SHA1'], $ii['sha1']);
	}

	public function testUploadHookVerifies() {
		$t = new ModerationTestsuite();

		# Does upload hook call getVerificationErrorCode() to check
		# the image before queueing the upload?

		$path = tempnam(sys_get_temp_dir(), 'modtest');
		file_put_contents($path, ''); # Empty

		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload("1.png", $path);
		unlink($path);

		$this->assertEquals('(emptyfile)', $error);
	}
}
