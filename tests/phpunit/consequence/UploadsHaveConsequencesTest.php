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
use MediaWiki\Moderation\WatchOrUnwatchConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class UploadsHaveConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

	protected $sampleImageFile = __DIR__ . '/../../resources/image100x100.png';

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
		$curlFile = new CURLFile( $this->sampleImageFile );
		$uploadKey = 'testUploadKey';
		$_FILES['wpUploadFile'] = [
			'name' => 'whatever', # Not used anywhere
			'type' => $curlFile->getMimeType(),
			'tmp_name' => $curlFile->getFilename(),
			'size' => filesize( $curlFile->getFilename() ),
			'error' => 0
		];
		$title = Title::newFromText( 'File:UTUpload-' . rand( 0, 100000 ) . '.png' );

		$upload = new UploadFromFile();
		$upload->initialize(
			$title->getText(),
			RequestContext::getMain()->getRequest()->getUpload( 'wpUploadFile' )
		);
		$this->assertEquals( [ 'status' => UploadBase::OK ], $upload->verifyUpload() );

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
			),

			// See FIXME comment in ModerationUploadHooks::onUploadVerifyUpload().
			new WatchOrUnwatchConsequence( false, $title, $user )
		], $manager->getConsequences() );
	}

}
