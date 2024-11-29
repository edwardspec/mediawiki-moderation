<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2024 Edward Chernenko.

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

require_once __DIR__ . "/../framework/ModerationTestsuite.php";

/**
 * @group Database
 */
class ModerationAbuseFilterTest extends ModerationTestCase {
	/** @var string[] */
	private $expectedTags = [
		'Author of edit likes cats',
		'Author of edit likes dogs'
	];

	/**
	 * If AbuseFilter added "moderation-spam" tag, was the queued edit placed into the Spam folder?
	 * @covers ModerationNewChange::addChangeTags
	 */
	public function testAbuseFilterMarkAsSpam( ModerationTestsuite $t ) {
		$this->requireExtension( 'Abuse Filter' );

		$tagsToAdd = array_merge( $this->expectedTags, [ 'moderation-spam' ] );
		$filterId = $t->addTagAllAbuseFilter( $tagsToAdd );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			'New edit appeared in Pending folder (expected: Spam folder).' );

		$t->fetchSpecial( 'spam' );
		$this->assertCount( 1, $t->new_entries, "New edit didn't appear in Spam folder." );

		/* Disable the filter (so that it would no longer add tags to newly made edits). */
		$t->disableAbuseFilter( $filterId );

		/* Double-check that approved edit doesn't include "moderation-spam" tag,
			but still includes other tags. */
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
}
