<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2017 Edward Chernenko.

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
	@brief Ensures that checkuser-related functionality works correctly.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers ModerationApproveHook
*/
class ModerationTestCheckuser extends MediaWikiTestCase
{
	public $moderatorUA = 'UserAgent of Moderator/1.0';
	public $userUA = 'UserAgent of UnprivilegedUser/1.0';

	public function testModerationCheckuser() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$this->assertNull( $entry->ip,
			"testModerationCheckuser(): IP was shown to non-checkuser on Special:Moderation" );

		$t->moderator = $t->moderatorAndCheckuser;

		$t->assumeFolderIsEmpty();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->ip,
			"testModerationCheckuser(): IP wasn't shown to checkuser on Special:Moderation" );
		$this->assertEquals( "127.0.0.1", $entry->ip,
			"testModerationCheckuser(): incorrect IP on Special:Moderation" );
	}

	public function skipIfNoCheckuser() {
		global $wgSpecialPages;

		$dbw = wfGetDB( DB_MASTER );
		if ( !array_key_exists( 'CheckUser', $wgSpecialPages )
			|| !$dbw->tableExists( 'cu_changes' ) )
		{
			$this->markTestSkipped( 'Test skipped: CheckUser extension must be installed to run it.' );
		}
	}

	/**
		@brief Convenience function: get cuc_agent of the last entry in "cu_changes" table.
		@param $where Extra conditions for SQL query.
	*/
	public function getCUCAgent( $where = array( '1' ) ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectField( 'cu_changes', 'cuc_agent',
			$where,
			__METHOD__,
			array(
				'ORDER BY' => 'cuc_id DESC',
				'LIMIT' => 1
			)
		);
	}

	/**
		@brief Ensure that modaction=approve preserves user-agent of edits.
	*/
	public function testApproveEditPrevervesUA() {
		$this->skipIfNoCheckuser();
		$t = new ModerationTestsuite();

		# When the edit is approved, cu_changes.cuc_agent field should
		# contain UserAgent of user who made the edit,
		# not UserAgent or the moderator who approved it.

		$t->setUserAgent( $this->userUA );
		$entry = $t->getSampleEntry();

		$t->setUserAgent( $this->moderatorUA );
		$t->httpGet( $entry->approveLink );

		$agent = $this->getCUCAgent();
		$this->assertNotEquals( $this->moderatorUA, $agent,
			"testApproveEditPrevervesUA(): UserAgent in checkuser tables matches moderator's UserAgent" );
		$this->assertEquals( $this->userUA, $agent,
			"testApproveEditPrevervesUA(): UserAgent in checkuser tables doesn't match UserAgent of user who made the edit" );
	}

	/**
		@brief Ensure that modaction=approveall preserves user-agent of uploads.
	*/
	public function testApproveAllUploadPrevervesUA() {
		$this->skipIfNoCheckuser();
		$t = new ModerationTestsuite();

		# 1. Perform upload.
		$t->loginAs( $t->unprivilegedUser );
		$t->setUserAgent( $this->userUA );
		$t->doTestUpload();
		$t->fetchSpecial();
		$entry = $t->new_entries[0];

		# 2. Perform another edit: we need to make sure that ModerationApproveHook:getTask()
		# will work correctly during ApproveAll (even if this upload wasn't the last of the changes).
		$t->doTestEdit();

		# When the upload is approved, cu_changes.cuc_agent field should
		# contain UserAgent of user who made the edit,
		# not UserAgent or the moderator who approved it.
		$t->setUserAgent( $this->moderatorUA );
		$t->httpGet( $entry->approveAllLink ); # Try modaction=approveall

		$agent = $this->getCUCAgent( array(
			'cuc_type' => RC_LOG /* We need to check the upload, so we ignore the non-upload edit */
		) );
		$this->assertNotEquals( $this->moderatorUA, $agent,
			"testApproveAllUploadPrevervesUA(): UserAgent in checkuser tables matches moderator's UserAgent" );
		$this->assertEquals( $this->userUA, $agent,
			"testApproveAllUploadPrevervesUA(): UserAgent in checkuser tables doesn't match UserAgent of user who made the edit" );
	}
}
