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
 * Trait for tests that need an UploadBase object which can be used for performUpload() calls.
 */

/**
 * @method static assertEquals($a, $b, $message='', $d=0.0, $e=10, $f=null, $g=null)
 */
trait UploadTestTrait {
	/**
	 * Path to the locally stored image that will be uploaded.
	 * @var string
	 */
	protected $sampleImageFile = __DIR__ . '/../../../resources/image100x100.png';

	/**
	 * Path to another locally stored image, which is different from $sampleImageFile.
	 * Can be used for reupload tests (to avoid "This is duplicate of existing file" upload error).
	 * @var string
	 */
	protected $anotherSampleImageFile = __DIR__ . '/../../../resources/image640x50.png';

	/**
	 * Prepare a test upload. It won't actually start until its performUpload() method is called.
	 * @param Title $title
	 * @return UploadBase
	 */
	protected function prepareTestUpload( Title $title ) {
		/* Create a temporary copy of this file,
			so that the original file won't be deleted after the upload */
		$tmpFile = TempFSFile::factory( 'testsuite.upload', basename( $this->sampleImageFile ) );
		$tmpFile->preserve(); // Otherwise it will be deleted after exiting prepareTestUpload()

		$srcPath = $tmpFile->getPath();
		copy( $this->sampleImageFile, $srcPath );

		$curlFile = new CURLFile( $srcPath );
		$_FILES['wpUploadFile'] = [
			'name' => 'whatever', # Not used anywhere
			'type' => $curlFile->getMimeType(),
			'tmp_name' => $curlFile->getFilename(),
			'size' => filesize( $curlFile->getFilename() ),
			'error' => 0
		];

		$upload = new UploadFromFile();
		$upload->initialize(
			$title->getText(),
			RequestContext::getMain()->getRequest()->getUpload( 'wpUploadFile' )
		);
		$this->assertEquals( [ 'status' => UploadBase::OK ], $upload->verifyUpload() );

		return $upload;
	}
}
