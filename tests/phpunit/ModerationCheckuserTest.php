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
 * @file
 * Ensures that checkuser-related functionality works correctly.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationApproveHook
 */
class ModerationCheckuserTest extends ModerationTestCase {
	public $moderatorUA = 'UserAgent of Moderator/1.0';
	public $userUA = 'UserAgent of UnprivilegedUser/1.0';

	/**
	 * Verifies that checkusers can see the IP of registered users via API,
	 * but non-checkusers can't.
	 */
	public function testApiModerationCheckuser( ModerationTestsuite $t ) {
		$t->doTestEdit();

		$this->assertNull( $this->getIpFromApi( $t, $t->moderator ),
			"testModerationCheckuser(): API exposed IP to non-checkuser" );

		$ip = $this->getIpFromApi( $t, $t->moderatorAndCheckuser );
		$this->assertNotNull( $ip,
			"testModerationCheckuser(): API didn't show IP to checkuser" );
		$this->assertEquals( "127.0.0.1", $ip,
			"testModerationCheckuser(): incorrect IP shown via API" );
	}

	/**
	 * Returns mod_ip of the last edit (if provided to the current user by QueryPage API) or null.
	 * @return string|null
	 */
	protected function getIpFromApi( ModerationTestsuite $t, User $user ) {
		$t->loginAs( $user );
		$ret = $t->query( [
			'action' => 'query',
			'list' => 'querypage',
			'qppage' => 'Moderation',
			'qplimit' => 1
		] );

		$row = $ret['query']['querypage']['results'][0]['databaseResult'];
		return isset( $row['ip'] ) ? $row['ip'] : null;
	}

	public function skipIfNoCheckuser() {
		global $wgSpecialPages;

		$dbw = wfGetDB( DB_MASTER );
		if ( !array_key_exists( 'CheckUser', $wgSpecialPages )
			|| !$dbw->tableExists( 'cu_changes' ) ) {
			$this->markTestSkipped( 'Test skipped: CheckUser extension must be installed to run it.' );
		}
	}

	/**
	 * Ensure that modaction=approve preserves user-agent of edits.
	 */
	public function testApproveEditPrevervesUA( ModerationTestsuite $t ) {
		$this->skipIfNoCheckuser();

		# When the edit is approved, cu_changes.cuc_agent field should
		# contain UserAgent of user who made the edit,
		# not UserAgent or the moderator who approved it.

		$t->setUserAgent( $this->userUA );
		$entry = $t->getSampleEntry();

		$t->setUserAgent( $this->moderatorUA );

		$waiter = $t->waitForRecentChangesToAppear();
		$t->httpGet( $entry->approveLink );
		$waiter( 1 );

		$agent = $t->getCUCAgent();
		$this->assertNotEquals( $this->moderatorUA, $agent,
			"testApproveEditPrevervesUA(): UserAgent in checkuser tables matches moderator's UserAgent" );
		$this->assertEquals( $this->userUA, $agent,
			"testApproveEditPrevervesUA(): UserAgent in checkuser tables " .
			"doesn't match UserAgent of user who made the edit" );
	}

	/**
	 * Ensure that modaction=approveall preserves user-agent of uploads.
	 * @covers ModerationApproveHook::getTask()
	 */
	public function testApproveAllUploadPrevervesUA( ModerationTestsuite $t ) {
		$this->skipIfNoCheckuser();

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

		$waiter = $t->waitForRecentChangesToAppear();
		$t->httpGet( $entry->approveAllLink ); # Try modaction=approveall
		$waiter( $NUMBER_OF_UPLOADS );

		/* Counting backwards, because getCUCAgents() selects in newest-to-latest order */
		$i = $NUMBER_OF_UPLOADS;
		foreach ( $t->getCUCAgents( $NUMBER_OF_UPLOADS ) as $agent ) {
			$this->assertNotEquals( $this->moderatorUA, $agent,
				"testApproveAllUploadPrevervesUA(): Upload #$i: UserAgent in checkuser " .
				"tables matches moderator's UserAgent" );
			$this->assertEquals( $this->userUA . '#' . $i, $agent,
				"testApproveAllUploadPrevervesUA(): Upload #$i: UserAgent in checkuser " .
				"tables doesn't match UserAgent of user who made the upload" );

			$i --;
		}
	}
}
