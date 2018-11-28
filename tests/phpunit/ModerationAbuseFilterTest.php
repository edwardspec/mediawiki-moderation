<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * Verifies that AbuseFilter-assigned tags are preserved by Moderation.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

class ModerationAbuseFilterTest extends ModerationTestCase {
	private $expectedTags = [
		'Author of edit likes cats',
		'Author of edit likes dogs'
	];

	/**
	 * Are AbuseFilter tags preserved for edits?
	 */
	public function testAFTagsEdit( ModerationTestsuite $t ) {
		$this->skipIfNoAbuseFilter();

		$filterId = $t->addTagAllAbuseFilter( $this->expectedTags );

		/* Perform the edit as non-automoderated user.
			Edit will be queued for moderation,
			tags set by AbuseFilter should be remembered by Moderation. */

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		/* Disable the filter (so that it would no longer add tags to newly made edits). */
		$t->disableAbuseFilter( $filterId );

		/* Approve the edit. Make sure that Moderation applies previously stored tags. */
		$this->assertTagsAfterApproval( $t, $t->new_entries[0], __FUNCTION__ );
	}

	/**
	 * Are AbuseFilter tags preserved for moves?
	 */
	public function testAFTagsMove( ModerationTestsuite $t ) {
		$this->skipIfNoAbuseFilter();

		$title = 'Cat';

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $title, 'Whatever' );

		$filterId = $t->addTagAllAbuseFilter( $this->expectedTags );

		/* Perform the move as non-automoderated user.
			Move will be queued for moderation,
			tags set by AbuseFilter should be remembered by Moderation. */

		$t->loginAs( $t->unprivilegedUser );
		$t->getBot( 'api' )->move( $title, "New $title" );
		$t->fetchSpecial();

		/* Disable the filter (so that it would no longer add tags to newly made moves). */
		$t->disableAbuseFilter( $filterId );

		/* Approve the edit. Make sure that Moderation applies previously stored tags. */
		$this->assertTagsAfterApproval( $t, $t->new_entries[0], __FUNCTION__ );
	}

	/**
	 * Are AbuseFilter tags preserved for uploads?
	 */
	public function testAFTagsUpload( ModerationTestsuite $t ) {
		$this->skipIfNoAbuseFilter();

		$filterId = $t->addTagAllAbuseFilter( $this->expectedTags );

		/* Perform the edit as non-automoderated user.
			Edit will be queued for moderation,
			tags set by AbuseFilter should be remembered by Moderation. */

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestUpload();
		$t->fetchSpecial();

		/* Disable the filter (so that it would no longer add tags to newly made edits). */
		$t->disableAbuseFilter( $filterId );

		/* Approve the edit. Make sure that Moderation applies previously stored tags. */
		$this->assertTagsAfterApproval( $t, $t->new_entries[0], __FUNCTION__ );
	}

	private function assertTagsAfterApproval(
		ModerationTestsuite $t,
		ModerationTestsuiteEntry $entry,
		$caller
	) {
		$waiter = $t->waitForRecentChangesToAppear();
		$t->httpGet( $entry->approveLink );
		$waiter( 1 );

		$ret = $t->query( [
			'action' => 'query',
			'list' => 'recentchanges',
			'rclimit' => 1,
			'rcprop' => 'tags|user|title'
		] );
		$rc = $ret['query']['recentchanges'][0];

		/* Make sure it's a correct change */
		$this->assertEquals( $t->lastEdit['User'], $rc['user'] );
		$this->assertEquals( $t->lastEdit['Title'], $rc['title'] );

		foreach ( $this->expectedTags as $tag ) {
			$this->assertContains( $tag, $rc['tags'],
				"$caller(): expected tag [$tag] hasn't been assigned to RecentChange"
			);
		}
	}

	public function skipIfNoAbuseFilter() {
		global $wgSpecialPages;

		if ( !ModerationVersionCheck::areTagsSupported() ) {
			$this->markTestSkipped( 'Test skipped: DB schema is outdated, please run update.php.' );
		}

		$dbw = wfGetDB( DB_MASTER );
		if ( !array_key_exists( 'AbuseFilter', $wgSpecialPages )
			|| !$dbw->tableExists( 'abuse_filter' ) ) {
			$this->markTestSkipped( 'Test skipped: AbuseFilter extension must be installed to run it.' );
		}
	}
}
