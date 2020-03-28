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
 * Unit test of ModerationViewableEntry.
 */

use MediaWiki\Linker\LinkRenderer;

require_once __DIR__ . "/autoload.php";

class ModerationViewableEntryTest extends ModerationUnitTestCase {
	use UploadTestTrait;

	/**
	 * @var mixed
	 */
	private $linkRenderer;

	/**
	 * Test that isUpload() returns true for uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testIsUpload() {
		$entry = $this->makeViewableEntry( (object)[ 'stash_key' => 'not empty' ] );
		$this->assertTrue( $entry->isUpload(), 'isUpload() returned false for upload.' );
	}

	/**
	 * Test that isUpload() returns false for non-uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testNotUpload() {
		$entry = $this->makeViewableEntry( (object)[ 'stash_key' => null ] );
		$this->assertFalse( $entry->isUpload(), 'isUpload() returned true for non-upload.' );
	}

	/**
	 * Verify that getImageURL() returns correct URL for modaction=showimg link.
	 * @param bool $isThumb True to test thumbnail URL, false otherwise.
	 * @dataProvider dataProviderImageURL
	 *
	 * @covers ModerationViewableEntry
	 */
	public function testImageURL( $isThumb ) {
		$modid = 12345;
		$entry = $this->makeViewableEntry( (object)[ 'id' => $modid ] );

		$expectedResult = 'Some link ' . rand( 0, 100000 );
		$expectedQuery = [ 'modaction' => 'showimg', 'modid' => $modid ];
		if ( $isThumb ) {
			$expectedQuery['thumb'] = 1;
		}

		// This hook will verify that Title::getLocalURL() was called with correct parameters,
		// and that its return value will be returned by getImageURL().
		$this->setTemporaryHook( 'GetLocalURL',
			function ( $title, &$url, $query ) use ( $expectedQuery, $expectedResult ) {
				$this->assertTrue( $title->isSpecial( 'Moderation' ),
					'URL from getImageURL() doesn\'t point to Special:Moderation.' );
				$this->assertEquals( $expectedQuery, wfCgiToArray( $query ),
					'URL from getImageURL() has incorrect query string.' );

				$url = $expectedResult;
				return true;
			}
		);
		$result = $entry->getImageURL( $isThumb );
		$this->assertEquals( $expectedResult, $result );
	}

	/**
	 * Provide datasets for testImageURL() runs.
	 * @return array
	 */
	public function dataProviderImageURL() {
		return [
			'not a thumbnail' => [ false ],
			'thumbnail' => [ true ]
		];
	}

	/**
	 * Verify that getImageThumbHTML() returns empty string for non-uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testThumbNotUpload() {
		$entry = $this->makeViewableEntry( (object)[ 'stash_key' => null ] );
		$this->assertSame( '', $entry->getImageThumbHTML(),
			"getImageThumbHTML() didn't return an empty string for non-upload." );
	}

	/**
	 * Verify that getImageThumbHTML() returns page name (string) for images not found in Stash.
	 * @covers ModerationViewableEntry
	 */
	public function testThumbMissingStashFile() {
		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );
		$entry = $this->makeViewableEntry( (object)[
			'stash_key' => 'nosuchkey.jpg',
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey(),
		] );
		$this->assertSame( $title->getFullText(), $entry->getImageThumbHTML(),
			"getImageThumbHTML() didn't return PageName for upload that is missing in Stash." );
	}

	/**
	 * Verify that getImageThumbHTML() returns page name (string) for non-image uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testThumbNotImage() {
		$file = TempFSFile::factory( '', '.txt' );
		$path = $file->getPath();

		file_put_contents( $path, "Non-image upload (e.g. OGG file with music)" );
		$stashKey = ModerationUploadStorage::getStash()->stashFile( $path )->getFileKey();

		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );
		$entry = $this->makeViewableEntry( (object)[
			'stash_key' => $stashKey,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey(),
		] );
		$this->assertSame( $title->getFullText(), $entry->getImageThumbHTML(),
			"getImageThumbHTML() didn't return PageName for non-image upload." );
	}

	/**
	 * Verify that getImageThumbHTML() returns correct HTML of thumbnail for image uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testThumbImage() {
		$file = TempFSFile::factory( '', '.png' );
		$path = $file->getPath();

		file_put_contents( $path, file_get_contents( $this->sampleImageFile ) );
		$stashKey = ModerationUploadStorage::getStash()->stashFile( $path )->getFileKey();

		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );
		$modid = 12345;

		$entry = $this->makeViewableEntry( (object)[
			'id' => $modid,
			'stash_key' => $stashKey,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey(),
		] );
		$result = $entry->getImageThumbHTML();

		$html = new ModerationTestHTML();
		$html->loadString( $result );

		$image = $html->getElementByXPath( '//body/img' );
		$this->assertNotNull( $image, '<img> tag not found.' );

		$src = $image->getAttribute( 'src' );
		$this->assertNotNull( $src );

		$bits = wfParseUrl( wfExpandUrl( $src ) );
		$this->assertEquals( wfScript(), $bits['path'] );
		$this->assertArrayHasKey( 'query', $bits );

		$query = wfCgiToArray( $bits['query'] );
		$expectedQuery = [
			'title' =>
				SpecialPage::getTitleFor( 'Moderation' )->fixSpecialName()->getPrefixedDBKey(),
			'modaction' => 'showimg',
			'modid' => $modid,
			'thumb' => 1
		];
		$this->assertEquals( $expectedQuery, $query, 'Incorrect URL of <img> tag.' );
	}

	/**
	 * Test the return value of ModerationViewableEntry::getFields().
	 * @covers ModerationViewableEntry
	 */
	public function testFields() {
		$expectedFields = [
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_type AS type',
			'mod_page2_namespace AS page2_namespace',
			'mod_page2_title AS page2_title',
			'mod_last_oldid AS last_oldid',
			'mod_new AS new',
			'mod_text AS text',
			'mod_stash_key AS stash_key'
		];

		$fields = ModerationViewableEntry::getFields();
		$this->assertEquals( $expectedFields, $fields );
	}

	/**
	 * Make ModerationViewableEntry for $row with mocks that were created in setUp().
	 * @param object|null $row
	 * @return ModerationViewableEntry
	 */
	private function makeViewableEntry( $row = null ) {
		return new ModerationViewableEntry( $row ?? new stdClass, $this->linkRenderer );
	}

	/**
	 * Precreate new mocks for $linkRenderer before each test.
	 */
	public function setUp() : void {
		parent::setUp();

		$this->linkRenderer = $this->createMock( LinkRenderer::class );
	}
}
