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
	@file
	@brief Verifies that AbuseFilter-assigned tags are preserved by Moderation.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

class ModerationTestAbuseFilter extends MediaWikiTestCase
{
	public function testAbuseFilterTags() {
		if ( !ModerationVersionCheck::areTagsSupported() ) {
			$this->markTestSkipped( 'Test skipped: DB schema is outdated, please run update.php.' );
		}

		$this->skipIfNoAbuseFilter();
		$t = new ModerationTestsuite();

		/* Create AbuseFilter rule that will assign tags to all edits */
		$filterId = 123;
		$expectedTags = [ 'Author of edit likes cats', 'Author of edit likes dogs' ];

		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'abuse_filter',
			[
				'af_id' => $filterId,
				'af_pattern' => 'true',
				'af_user' => 0,
				'af_user_text' => 'MediaWiki default',
				'af_timestamp' => wfTimestampNow(),
				'af_enabled' => 1,
				'af_comments' => '',
				'af_public_comments' => 'Assign tags to all edits',
				'af_hidden' => 0,
				'af_hit_count' => 0,
				'af_throttled' => 0,
				'af_deleted' => 0,
				'af_actions' => 'tag',
				'af_global' => 0,
				'af_group' => 'default'
			],
			__METHOD__
		);
		$dbw->insert( 'abuse_filter_action',
			[
				'afa_filter' => $filterId,
				'afa_consequence' => 'tag',
				'afa_parameters' => join( "\n", $expectedTags )
			],
			__METHOD__
		);

		/* Perform the edit as non-automoderated user.
			Edit will be queued for moderation,
			tags set by AbuseFilter should be remembered by Moderation. */

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		/* Disable the filter (so that it would no longer add tags to newly made edits). */
		$dbw->update( 'abuse_filter', [ 'af_enabled' => 0 ], [ 'af_id' => $filterId ], __METHOD__ );

		/* Approve the edit. Make sure that Moderation applies previously stored tags. */
		$entry = $t->new_entries[0];
		$t->httpGet( $entry->approveLink );

		$ret = $t->query( [
			'action' => 'query',
			'list' => 'recentchanges',
			'rclimit' => 1,
			'rcprop' => 'tags|user|title|ids'
		] );
		$rc = $ret['query']['recentchanges'][0];

		/* Make sure it's a correct edit */
		$this->assertEquals( $t->lastEdit['User'], $rc['user'] );
		$this->assertEquals( $t->lastEdit['Title'], $rc['title'] );

		foreach ( $expectedTags as $tag ) {
			$this->assertContains( $tag, $rc['tags'],
				"testAbuseFilterTags(): expected tag [$tag] hasn't been assigned to RecentChange"
			);
		}
	}

	public function skipIfNoAbuseFilter() {
		global $wgSpecialPages;

		$dbw = wfGetDB( DB_MASTER );
		if ( !array_key_exists( 'AbuseFilter', $wgSpecialPages )
			|| !$dbw->tableExists( 'abuse_filter' ) )
		{
			$this->markTestSkipped( 'Test skipped: AbuseFilter extension must be installed to run it.' );
		}
	}
}

