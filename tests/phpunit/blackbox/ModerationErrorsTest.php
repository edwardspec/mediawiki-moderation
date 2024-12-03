<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2024 Edward Chernenko.

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
 * Ensure that known error conditions cause exceptions.
 */

require_once __DIR__ . "/../framework/ModerationTestsuite.php";

/**
 * @group Database
 * @covers ModerationError
 */
class ModerationErrorsTest extends ModerationTestCase {
	public function testMissingStashedImage( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestUpload();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$stashKey = $entry->getDbField( 'mod_stash_key' );

		$stash = ModerationUploadStorage::getStash();
		$stash->removeFile( $stashKey );

		$error = $t->html->loadUrl( $entry->approveLink )->getModerationError();
		$this->assertEquals( '(moderation-missing-stashed-image)', $error );

		/* Additionally check that ShowImg link returns "404 Not Found" */
		$req = $t->httpGet( $entry->expectedShowImgLink() );
		$this->assertEquals( 404, $req->getStatus(),
			"testMissingStashedImage(): URL of modaction=showimg doesn't return 404 Not Found"
		);
	}
}
