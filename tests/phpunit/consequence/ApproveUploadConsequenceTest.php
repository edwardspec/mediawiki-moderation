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
 * Unit test of ApproveUploadConsequence.
 */

use MediaWiki\Moderation\ApproveUploadConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ApproveUploadConsequenceTest extends ModerationUnitTestCase {
	use PostApproveCleanupTrait;
	use UploadTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'uploadstash', 'image', 'oldimage' ];

	/**
	 * Verify that ApproveUploadConsequence uploads a new file.
	 * @covers MediaWiki\Moderation\ApproveUploadConsequence
	 * @dataProvider dataProviderApproveUpload
	 * @param bool $existing
	 */
	public function testApproveUpload( $existing ) {
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );
		$comment = 'Upload comment';
		$pageText = 'New description text';

		// Uploads shouldn't be intercepted (including upload caused by approval).
		$this->setMwGlobals( 'wgModerationEnable', false );

		if ( $existing ) {
			// Precreate file with the same name.
			$initialText = 'Description text of image that already exists';

			$upload = $this->prepareTestUpload( $title );
			$upload->performUpload( '', $initialText, false, $user );

			$expectedText = $initialText; // Reuploads shouldn't modify the File: description page.
		} else {
			$expectedText = $pageText;
		}

		$stash = ModerationUploadStorage::getStash();
		$stashKey = $stash->stashFile( $this->anotherSampleImageFile )->getFileKey();

		// Create and run the Consequence.
		$consequence = new ApproveUploadConsequence( $stashKey, $title, $user, $comment, $pageText );
		$status = $consequence->run();

		$this->assertTrue( $status->isOK(),
			"ApproveUploadConsequence failed: " . $status->getMessage()->plain() );

		// Check whether the newly approved file has been uploaded.
		$file = RepoGroup::singleton()->findFile( $title->getText(), [ 'latest' => true ] );
		$this->assertEquals( $comment, $file->getDescription() );
		$this->assertEquals( $user->getName(), $file->getUser( 'text' ) );
		$this->assertEquals( $user->getId(), $file->getUser( 'id' ) );

		$page = WikiPage::factory( $title );
		$this->assertEquals( $expectedText, $page->getContent()->getNativeData() );
	}

	/**
	 * Provide datasets for testApproveUpload() runs.
	 * @return array
	 */
	public function dataProviderApproveUpload() {
		return [
			'upload (new image)' => [ false ],
			'reupload' => [ true ],
		];
	}
}
