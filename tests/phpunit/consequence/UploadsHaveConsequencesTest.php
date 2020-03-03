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
 * Verifies that uploading a file has consequences.
 */

use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueUploadConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";
require_once __DIR__ . "/UploadTestTrait.php";

/**
 * @group Database
 */
class UploadsHaveConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;
	use UploadTestTrait;

	/** @var Title */
	protected $title;

	/** @var string */
	protected $reason;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'page', 'uploadstash' ];

	/**
	 * Test consequences when an upload is queued for moderation.
	 * @covers ModerationUploadHooks::onUploadVerifyUpload
	 */
	public function testUpload() {
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );
		$upload = $this->prepareTestUpload( $title );

		$user = self::getTestUser()->getUser();
		$comment = 'Edit comment when uploading the file';
		$pageText = 'Initial content of File:Something (description page)';

		list( $scope, $manager ) = MockConsequenceManager::install();

		$status = $upload->performUpload( $comment, $pageText, false, $user );
		$this->assertTrue( $status->hasMessage( 'moderation-image-queued' ),
			"Status returned by performUpload doesn't include \"moderation-image-queued\"." );

		$this->assertConsequencesEqual( [
			new QueueUploadConsequence(
				$upload,
				$user,
				$comment,
				$pageText
			)
		], $manager->getConsequences() );
	}

}
