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
 * Ensure that known error conditions cause exceptions.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationError
 */
class ModerationErrorsTest extends ModerationTestCase {
	/**
	 * @requires extension curl
	 * @note Only cURL version of MWHttpRequest supports uploads.
	 */
	public function testMissingStashedImage( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestUpload();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$stashKey = $entry->getDbField( 'mod_stash_key' );

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash();
		$stash->removeFileNoAuth( $stashKey );

		$error = $t->html->getModerationError( $entry->approveLink );
		$this->assertEquals( '(moderation-missing-stashed-image)', $error );

		/* Additionally check that ShowImg link returns "404 Not Found" */
		$t->ignoreHttpError( 404 );
		$req = $t->httpGet( $entry->expectedShowImgLink() );
		$t->stopIgnoringHttpError( 404 );

		$this->assertEquals( 404, $req->getStatus(),
			"testMissingStashedImage(): URL of modaction=showimg doesn't return 404 Not Found"
		);
	}

	public function testEditNoChange( ModerationTestsuite $t ) {
		$page = 'Test page 1';
		$text = 'This is some ext';

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $page, $text );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $page, $text ); # Make zero edit
		$t->fetchSpecial();

		$entry = $t->new_entries[0];

		$error = $t->html->getModerationError( $entry->approveLink );
		$this->assertEquals( '(edit-no-change)', $error );
	}
}
