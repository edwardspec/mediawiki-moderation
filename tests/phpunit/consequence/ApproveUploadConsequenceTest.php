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
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/UploadTestTrait.php";

/**
 * @group Database
 */
class ApproveUploadConsequenceTest extends MediaWikiTestCase {
	use UploadTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'uploadstash', 'image', 'oldimage' ];

	/**
	 * Verify that ApproveUploadConsequence uploads a new file.
	 * @covers MediaWiki\Moderation\ApproveUploadConsequence
	 */
	public function testApproveUpload() {
		$user = self::getTestUser()->getUser();
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );
		$comment = 'Upload comment';
		$pageText = 'Initial description text';

		$stash = ModerationUploadStorage::getStash();
		$stashKey = $stash->stashFile( $this->sampleImageFile )->getFileKey();

		// Enter approve mode, as ApprovUploadConsequence is not supposed to be used outside of it.
		// Otherwise this upload will just get queued for moderation again.
		ModerationCanSkip::enterApproveMode();

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
		$this->assertEquals( $pageText, $page->getContent()->getNativeData() );
	}

	// TODO: add reupload test

	/**
	 * Disable post-approval global state.
	 */
	public function tearDown() {
		// If the previous test used Approve, it enabled "all edits should bypass moderation" mode.
		// Disable it now.
		$canSkip = TestingAccessWrapper::newFromClass( ModerationCanSkip::class );
		$canSkip->inApprove = false;

		parent::tearDown();
	}
}
