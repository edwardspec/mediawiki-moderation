<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
 * @codingStandardsIgnoreStart
 * @method static void assertEquals($a, $b, string $message='', float $d=0.0, int $e=10, bool $f=false, bool $g=false)
 * @codingStandardsIgnoreEnd
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
	 * @param string $srcPath
	 * @return UploadBase
	 */
	protected function prepareTestUpload( Title $title, $srcPath = '' ) {
		if ( !$srcPath ) {
			$srcPath = $this->sampleImageFile;
		}

		/* Create a temporary copy of this file,
			so that the original file won't be deleted after the upload */
		$tmpFile = TempFSFile::factory( 'testsuite.upload', basename( $srcPath ) );
		$tmpFile->preserve(); // Otherwise it will be deleted after exiting prepareTestUpload()

		$tmpFilePath = $tmpFile->getPath();
		copy( $srcPath, $tmpFilePath );

		$curlFile = new CURLFile( $tmpFilePath );
		$uploadData = [
			'name' => 'whatever', # Not used anywhere
			'type' => $curlFile->getMimeType(),
			'tmp_name' => $curlFile->getFilename(),
			'size' => filesize( $curlFile->getFilename() ),
			'error' => 0
		];

		$fauxRequest = RequestContext::getMain()->getRequest();
		'@phan-var FauxRequest $fauxRequest';

		if ( method_exists( $fauxRequest, 'setUpload' ) ) {
			// MediaWiki 1.37+
			$fauxRequest->setUpload( 'wpUploadFile', $uploadData );
		} else {
			// MediaWiki 1.35-1.36
			// phpcs:ignore MediaWiki.Usage.SuperGlobalsUsage.SuperGlobals
			$_FILES['wpUploadFile'] = $uploadData;
		}

		$upload = new UploadFromFile();
		$upload->initialize(
			$title->getText(),
			$fauxRequest->getUpload( 'wpUploadFile' )
		);

		$this->assertEquals( [ 'status' => UploadBase::OK ], $upload->verifyUpload() );

		return $upload;
	}

	/**
	 * Store test image into the ModerationUploadStorage and return its stash_key.
	 * @param string|null $srcPath
	 * @return string Valid stash_key of newly stored file.
	 */
	protected function stashSampleImage( $srcPath = null ) {
		$file = TempFSFile::factory( '', 'png' );
		$path = $file->getPath();

		file_put_contents( $path, file_get_contents( $srcPath ?? $this->sampleImageFile ) );
		return ModerationUploadStorage::getStash()->stashFile( $path )->getFileKey();
	}
}
