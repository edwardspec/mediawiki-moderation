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

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

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
		@returns User-agent (string).
	*/
	public function getCUCAgent() {
		$agents = self::getCUCAgents( 1 );
		return array_pop( $agents );
	}

	/**
		@brief Convenience function: get cuc_agent of the last entries in "cu_changes" table.
		@param $limit How many entries to select.
		@returns Array of user-agents.
	*/
	public function getCUCAgents( $limit ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectFieldValues( 'cu_changes', 'cuc_agent',
			'1',
			__METHOD__,
			[
				'ORDER BY' => 'cuc_id DESC',
				'LIMIT' => $limit
			]
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
		@covers ModerationApproveHook::getTask()
	*/
	public function testApproveAllUploadPrevervesUA() {
		$this->skipIfNoCheckuser();
		$t = new ModerationTestsuite();

		# Perform several uploads.
		$NUMBER_OF_UPLOADS = 2;

		$t->loginAs( $t->unprivilegedUser );
		for ( $i = 1; $i <= $NUMBER_OF_UPLOADS; $i ++ ) {
			$t->setUserAgent( $this->userUA . '#' . $i );
			$t->doTestUpload( "UA_Test_Upload${i}.png" );
		}
		$t->fetchSpecial();
		$entry = $t->new_entries[0];

		# When the upload is approved, cu_changes.cuc_agent field should
		# contain UserAgent of user who made the edit,
		# not UserAgent or the moderator who approved it.
		$t->setUserAgent( $this->moderatorUA );
		$t->httpGet( $entry->approveAllLink ); # Try modaction=approveall

		$agents = $this->getCUCAgents( $NUMBER_OF_UPLOADS );
		$i = $NUMBER_OF_UPLOADS; /* Counting backwards, because getCUCAgents() selects in newest-to-latest order */
		foreach ( $agents as $agent ) {
			$this->assertNotEquals( $this->moderatorUA, $agent,
				"testApproveAllUploadPrevervesUA(): Upload #$i: UserAgent in checkuser tables matches moderator's UserAgent" );
			$this->assertEquals( $this->userUA . '#' . $i, $agent,
				"testApproveAllUploadPrevervesUA(): Upload #$i: UserAgent in checkuser tables doesn't match UserAgent of user who made the upload" );

			$i --;
		}
	}
}
