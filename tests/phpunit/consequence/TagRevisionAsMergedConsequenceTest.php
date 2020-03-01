<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Unit test of TagRevisionAsMergedConsequence.
 */

use MediaWiki\Moderation\TagRevisionAsMergedConsequence;

/**
 * @group Database
 */
class TagRevisionAsMergedConsequenceTest extends MediaWikiTestCase {
	/**
	 * Verify that TagRevisionAsMergedConsequence adds a tag to selected revision.
	 * @covers MediaWiki\Moderation\TagRevisionAsMergedConsequence
	 */
	public function testTagRevision() {
		$title = Title::newFromText( 'UTPage' ); // Was created in parent::addCoreDBData()
		$expectedRevid = $title->getLatestRevID();

		$hookFired = false;

		$this->setTemporaryHook( 'ChangeTagsAfterUpdateTags', function (
			$tagsToAdd, $tagsToRemove, $prevTags,
			$rc_id, $rev_id, $log_id, $params, $rc, $user
		) use ( &$hookFired, $expectedRevid ) {
			$hookFired = true;

			$this->assertEquals( $expectedRevid, $rev_id );
			$this->assertEquals( [ 'moderation-merged' ], $tagsToAdd );
			$this->assertEquals( [], $tagsToRemove );

			return true;
		} );

		// Create and run the Consequence.
		$consequence = new TagRevisionAsMergedConsequence( $expectedRevid );
		$consequence->run();

		$this->assertTrue( $hookFired, "TagRevisionAsMergedConsequence didn't tag anything." );
	}
}
