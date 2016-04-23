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
	@brief Ensures that checkuser-related functionality works correctly.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

class ModerationTestCheckuser extends MediaWikiTestCase
{
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

	/**
		@covers ModerationCheckUserHook
	*/
	public function testPreverveUserAgent() {
		global $wgSpecialPages;
		$t = new ModerationTestsuite();

		$dbw = wfGetDB( DB_MASTER );
		if ( !array_key_exists( 'CheckUser', $wgSpecialPages )
			|| !$dbw->tableExists( 'cu_changes' ) )
		{
			$this->markTestIncomplete( 'Test skipped: CheckUser extension must be installed to run it.' );
		}

		$moderatorUA = 'UserAgent of Moderator/1.0';
		$userUA = 'UserAgent of UnprivilegedUser/1.0';

		# When the edit is approved, cu_changes.cuc_agent field should
		# contain UserAgent of user who made the edit,
		# not UserAgent or the moderator who approved it.

		$t->setUserAgent( $userUA );
		$entry = $t->getSampleEntry();

		$t->setUserAgent( $moderatorUA );
		$t->httpGet( $entry->approveLink );

		$row = $dbw->selectRow( 'cu_changes',
			array( 'cuc_agent AS agent' ),
			array( '1' ),
			__METHOD__,
			array( 'ORDER BY' => 'cuc_id DESC', 'LIMIT' => 1 )
		);

		$this->assertNotEquals( $moderatorUA, $row->agent,
			"testPreverveUserAgent(): UserAgent in checkuser tables matches moderator's UserAgent" );
		$this->assertEquals( $userUA, $row->agent,
			"testPreverveUserAgent(): UserAgent in checkuser tables doesn't match UserAgent of user who made the edit" );
	}
}
