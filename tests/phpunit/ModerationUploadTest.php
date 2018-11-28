<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
 * @file
 * Ensures that uploads are intercepted by Extension:Moderation.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationUploadHooks
 * @requires extension curl
 * @note Only cURL version of MWHttpRequest supports uploads.
 */
class ModerationUploadTest extends ModerationTestCase {
	public function testUpload( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$result = $t->doTestUpload();
		$t->fetchSpecial();

		# Was the upload queued for moderation?
		$this->assertTrue( $result->isIntercepted(),
			"testUpload(): Special:Upload didn't say that upload was queued for moderation." );

		# Is the data on Special:Moderation correct?
		$entry = $t->new_entries[0];
		$this->assertCount( 1, $t->new_entries,
			"testUpload(): One upload was queued for moderation, but number of " .
			"added entries in Pending folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testUpload(): Something was deleted from Pending folder during the queueing" );
		$this->assertEquals( $t->lastEdit['User'], $entry->user );
		$this->assertEquals( $t->lastEdit['Title'], $entry->title );

		# Can we approve this upload?
		$this->assertNotNull( $entry->approveLink,
			"testUpload(): Approve link not found" );

		$t->html->loadFromURL( $entry->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"testUpload(): Result page doesn't contain (moderation-approved-ok: 1)" );

		# Has the file been uploaded after the approval?
		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'imageinfo',
			'iilimit' => 1,
			'iiprop' => 'user|timestamp|comment|size|url|sha1',
			'titles' => $entry->title
		] );
		$ret_page = array_shift( $ret['query']['pages'] );
		$ii = $ret_page['imageinfo'][0];

		$this->assertEquals( $t->lastEdit['User'], $ii['user'] );
		$this->assertEquals( $t->lastEdit['Text'], $ii['comment'] );
		$this->assertEquals( $t->lastEdit['SHA1'], $ii['sha1'] );
	}

	public function testUploadHookVerifies( ModerationTestsuite $t ) {
		# Does upload hook call getVerificationErrorCode() to check
		# the image before queueing the upload?

		$path = tempnam( sys_get_temp_dir(), 'modtest' );
		file_put_contents( $path, '' ); # Empty

		$t->loginAs( $t->unprivilegedUser );
		$result = $t->getBot( 'nonApi' )->upload( "1.png", $path );
		unlink( $path );

		$this->assertEquals( '(emptyfile)', $result->getError(),
			"testUploadHookVerifies(): no error was printed when trying to upload empty file." );
	}

	/**
	 * @covers ModerationApproveHook::onNewRevisionFromEditComplete
	 */
	public function testReupload( ModerationTestsuite $t ) {
		$title = "Test image 1.png";

		# Upload the image first
		$t->loginAs( $t->automoderated );
		$t->doTestUpload( $title, "image640x50.png", "Text 1" );

		# Now queue reupload for moderation
		$t->loginAs( $t->unprivilegedUser );
		$result = $t->doTestUpload( $title, "image100x100.png", "Text 2" );
		$t->fetchSpecial();

		# Was the reupload queued for moderation?
		$this->assertTrue( $result->isIntercepted(),
			"testMove(): Special:MovePage didn't say that reupload was queued for moderation." );

		# Is the data on Special:Moderation correct?
		$entry = $t->new_entries[0];
		$this->assertCount( 1, $t->new_entries,
			"testReupload(): One upload was queued for moderation, but number of " .
			"added entries in Pending folder isn't 1" );
		$this->assertCount( 0, $t->deleted_entries,
			"testReupload(): Something was deleted from Pending folder during the queueing" );
		$this->assertEquals( $t->lastEdit['User'], $entry->user );
		$this->assertEquals( $t->lastEdit['Title'], $entry->title );

		# Does modaction=show display (moderation-diff-reupload) message?
		$this->assertRegExp( '/\(moderation-diff-reupload\)/',
			$t->html->getMainText( $entry->showLink ),
			"testReupload(): (moderation-diff-reupload) not found in the output of modaction=show" );

		# Can we approve this reupload?
		$this->assertNotNull( $entry->approveLink,
			"testReupload(): Approve link not found" );

		/* Wait up to 1 second to avoid archived name collision */
		$t->sleepUntilNextSecond();

		$t->html->loadFromURL( $entry->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"testReupload(): Result page doesn't contain (moderation-approved-ok: 1)" );

		# Has the file been reuploaded after the approval?
		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'imageinfo',
			'iilimit' => 1,
			'iiprop' => 'user|timestamp|comment|size|url|sha1',
			'titles' => $entry->title
		] );
		$ret_page = array_shift( $ret['query']['pages'] );
		$ii = $ret_page['imageinfo'][0];

		$this->assertEquals( $t->lastEdit['User'], $ii['user'] );
		$this->assertEquals( $t->lastEdit['Text'], $ii['comment'] );
		$this->assertEquals( $t->lastEdit['SHA1'], $ii['sha1'] );

		# Check image page history: performUpload(... $user) mistakenly
		# tags image reuploads as made by moderator (and not $user).
		# Was that fixed? (via ModerationApproveHook class)

		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 2, # See below
			'rvprop' => 'user|timestamp|comment|content|ids',
			'titles' => $entry->title
		] );

		# Because API orders entries by timestamp (up to seconds), and
		# it's likely that two uploads we just made will have the same
		# timestamp, they may be ordered incorrectly ([0] not being the
		# most recent). So find the entry with 'parentid' referring to
		# the other entry.
		$ret_page = array_shift( $ret['query']['pages'] );
		$rev1 = $ret_page['revisions'][0];
		$rev2 = $ret_page['revisions'][1];

		# Make $rev1 the most recent edit
		if ( $rev2['parentid'] == $rev1['revid'] ) {
			$tmp = $rev1;
			$rev1 = $rev2;
			$rev2 = $tmp;
		}

		$this->assertEquals( $rev2['revid'], $rev1['parentid'],
			"testReupload(): parentid of new revision doesn't match revid of the previous revision" );
		$this->assertNotEquals( $t->moderator->getName(), $rev1['user'],
			"testReupload(): Image reupload was attributed to the moderator who " .
			"approved it (instead of the user who made the reupload)" );
		$this->assertEquals( $t->lastEdit['User'], $rev1['user'],
			"testReupload(): Image reupload wasn't attributed to the user who made it" );
	}

	/**
	 * @covers ModerationApiHooks::onApiCheckCanExecute()
	 */
	public function testNoApiUploadBefore1_28( ModerationTestsuite $t ) {
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.28.0', '>=' ) ) {
			$this->markTestSkipped( 'Test skipped: only applicable to MediaWiki 1.27.' );
		}

		$t->loginAs( $t->unprivilegedUser );
		$result = $t->getBot( 'api' )->upload();

		/* Uploads via API are only supported in MediaWiki 1.28+,
			older MediaWiki should return error. */
		$this->assertEquals( '(nouploadmodule)', $result->getError() );
	}
}
