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
 * Unit test of ModerationViewableEntry.
 */

use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;

require_once __DIR__ . "/autoload.php";

class ModerationViewableEntryTest extends ModerationUnitTestCase {
	use MockRevisionLookupTestTrait;
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
		$entry = $this->makeViewableEntry( [ 'stash_key' => 'not empty' ] );
		$this->assertTrue( $entry->isUpload(), 'isUpload() returned false for upload.' );
	}

	/**
	 * Test that isUpload() returns false for non-uploads.
	 * @covers ModerationViewableEntry
	 */
	public function testNotUpload() {
		$entry = $this->makeViewableEntry( [ 'stash_key' => null ] );
		$this->assertFalse( $entry->isUpload(), 'isUpload() returned true for non-upload.' );
	}

	/**
	 * Test that getPendingRevision() returns RevisionRecord with expected content.
	 * @covers ModerationViewableEntry
	 */
	public function testPendingRevision() {
		$title = Title::newFromText( 'Project:UTPage ' . rand( 0, 100000 ) );
		$newText = 'New content of the article';

		$entry = $this->makeViewableEntry( [
			'text' => $newText,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey()
		] );

		$rev = $entry->getPendingRevision();
		$this->assertRevisionTitleAndText( $title, $newText, $rev );
	}

	/**
	 * Throw an exception if RevisionRecord doesn't have expected $title and $text.
	 * @param Title $title
	 * @param string $text
	 * @param RevisionRecord $rev
	 */
	private function assertRevisionTitleAndText( Title $title, $text, RevisionRecord $rev ) {
		$this->assertEquals( $text, $rev->getContent( SlotRecord::MAIN )->serialize() );

		// MediaWiki 1.35 doesn't have LinkTarget::isSameLinkAs().
		$this->assertEquals( $title->getFullText(),
			Title::newFromLinkTarget( $rev->getPageAsLinkTarget() )->getFullText() );
	}

	/**
	 * Test that getPreviousRevision() returns old RevisionRecord on which this pending change is based.
	 * @param int $oldid
	 * @param string|null $oldText If null, RevisionRecord won't be found.
	 * @dataProvider dataProviderPreviousRevision
	 * @covers ModerationViewableEntry
	 */
	public function testPreviousRevision( $oldid, $oldText ) {
		$title = Title::newFromText( 'Project:UTPage ' . rand( 0, 100000 ) );

		// Mock RevisionLookup service to provide $oldText as current text of revision $revid.
		$revisionLookup = $this->mockRevisionLookup( $oldid, $oldText, $title );

		$entry = $this->makeViewableEntry( [
			'last_oldid' => $oldid,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey()
		], $revisionLookup );

		$rev = $entry->getPreviousRevision();
		$this->assertRevisionTitleAndText( $title, $oldText ?: '', $rev );
	}

	/**
	 * Provide datasets for testPreviousRevision() runs.
	 * @return array
	 */
	public function dataProviderPreviousRevision() {
		return [
			'new page' => [ 0, null ],
			'edit based on deleted revision' => [ 123, null ],
			'edit in existing page' => [ 456, 'Text of previous revision' ]
		];
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
		$entry = $this->makeViewableEntry( [ 'id' => $modid ] );

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
		$entry = $this->makeViewableEntry( [ 'stash_key' => null ] );
		$this->assertSame( '', $entry->getImageThumbHTML(),
			"getImageThumbHTML() didn't return an empty string for non-upload." );
	}

	/**
	 * Verify that getImageThumbHTML() returns page name (string) for images not found in Stash.
	 * @covers ModerationViewableEntry
	 */
	public function testThumbMissingStashFile() {
		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );
		$entry = $this->makeViewableEntry( [
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
		$entry = $this->makeViewableEntry( [
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
		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );
		$modid = 12345;

		$entry = $this->makeViewableEntry( [
			'id' => $modid,
			'stash_key' => $this->stashSampleImage(),
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
			'mod_text AS text',
			'mod_stash_key AS stash_key'
		];

		$fields = ModerationViewableEntry::getFields();
		$this->assertEquals( $expectedFields, $fields );
	}

	/**
	 * Verify that getDiffHTML() returns an empty string for reuploads.
	 * @covers ModerationViewableEntry
	 */
	public function testDiffReupload() {
		$context = $this->createMock( IContextSource::class );
		$entry = $this->makeViewableEntry( [
			'stash_key' => 'somekey.jpg',
			'namespace' => 0,
			'title' => 'Some_page'
		] );

		'@phan-var IContextSource $context';

		// This makes Title::exists() to always return true.
		$this->setTemporaryHook( 'TitleExists', static function ( $_, &$exists ) {
			$exists = true;
			return true;
		} );

		$result = $entry->getDiffHTML( $context );
		$this->assertSame( '', $result,
			'Result of getDiffHTML() on reupload must be an empty string.' );
	}

	/**
	 * Verify that getDiffHTML() returns "movepage-page-moved" message for moves.
	 * @covers ModerationViewableEntry
	 */
	public function testDiffMove() {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );

		$oldTitle = Title::newFromText( 'Talk:UTPage ' . rand( 0, 100000 ) );
		$newTitle = Title::newFromText( 'Project:UTPage ' . rand( 0, 100000 ) );

		// Mock LinkRenderer::makeLink() to check that they point to the necessary pages.
		$this->linkRenderer->expects( $this->at( 0 ) )->method( 'makeLink' )->will(
			$this->returnCallback( function ( $linkTarget ) use ( $oldTitle ) {
				$this->assertEquals( $oldTitle->getFullText(), $linkTarget->getFullText() );
				return '{OldTitleLink}';
			}
		) );
		$this->linkRenderer->expects( $this->at( 1 ) )->method( 'makeLink' )->will(
			$this->returnCallback( function ( $linkTarget ) use ( $newTitle ) {
				$this->assertEquals( $newTitle->getFullText(), $linkTarget->getFullText() );
				return '{NewTitleLink}';
			}
		) );

		$entry = $this->makeViewableEntry( [
			'stash_key' => null,
			'namespace' => $oldTitle->getNamespace(),
			'title' => $oldTitle->getDBKey(),
			'type' => ModerationNewChange::MOD_TYPE_MOVE,
			'page2_namespace' => $newTitle->getNamespace(),
			'page2_title' => $newTitle->getDBKey()
		] );

		$result = $entry->getDiffHTML( $context );
		$this->assertEquals(
			'(movepage-page-moved: {OldTitleLink}, {NewTitleLink})',
			Parser::stripOuterParagraph( $result )
		);
	}

	/**
	 * Check the return value of ModerationViewableEntry::getDiffHTML().
	 * @covers ModerationViewableEntry
	 */
	public function testDiff() {
		$title = Title::newFromText( 'File:UTUpload ' . rand( 0, 100000 ) . '.png' );

		$previousRevision = $this->createMock( RevisionRecord::class );
		$pendingRevision = $this->createMock( RevisionRecord::class );

		$row = (object)[
			'type' => ModerationNewChange::MOD_TYPE_EDIT,
			'stash_key' => null,
			'namespace' => $title->getNamespace(),
			'title' => $title->getDBKey()
		];

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );
		$context->setTitle( SpecialPage::getTitleFor( 'Moderation' ) );

		// Mock DifferenceEngine (which is used by ViewableEntry to generate the diff)
		$differenceEngine = $this->createMock( DifferenceEngine::class );
		$differenceEngine->expects( $this->once() )->method( 'setRevisions' )->with(
			$this->identicalTo( $previousRevision ),
			$this->identicalTo( $pendingRevision )
		)->willReturn( '{GeneratedDiff}' );

		$differenceEngine->expects( $this->once() )->method( 'getDiffBody' )->willReturn( '{GeneratedDiff}' );
		$differenceEngine->expects( $this->once() )->method( 'addHeader' )->with(
			$this->identicalTo( '{GeneratedDiff}' ),
			$this->identicalTo( '(moderation-diff-header-before)' ),
			$this->identicalTo( '(moderation-diff-header-after)' )
		)->willReturn( '{GeneratedDiff+Header}' );

		$contentHandler = $this->createMock( ContentHandler::class );
		$contentHandler->expects( $this->once() )->method( 'createDifferenceEngine' )->with(
			$this->identicalTo( $context )
		)->willReturn( $differenceEngine );

		$contentHandlerFactory = $this->createMock( IContentHandlerFactory::class );
		$contentHandlerFactory->expects( $this->once() )->method( 'getContentHandler' )->with(
			$this->identicalTo( $title->getContentModel() )
		)->willReturn( $contentHandler );

		// Method that we are testing here (getDiffHTML) is calling two other methods of the same class
		// (getPendingRevision and getPreviousRevision), and they are already covered by their own tests.
		// Therefore we can make a "partial mock" where getPendingRevision() and getPreviousRevision() are mocked,
		// but call to getDiffHTML() hits real implementation.
		$entry = $this->getMockBuilder( ModerationViewableEntry::class )
			->setConstructorArgs( [
				$row,
				$this->createNoOpMock( LinkRenderer::class ),
				$contentHandlerFactory,
				$this->createNoOpMock( RevisionLookup::class )
			] )
			->onlyMethods( [ 'getPendingRevision', 'getPreviousRevision' ] )
			->getMockForAbstractClass();

		$entry->expects( $this->any() )->method( 'getPreviousRevision' )->willReturn( $previousRevision );
		$entry->expects( $this->any() )->method( 'getPendingRevision' )->willReturn( $pendingRevision );

		'@phan-var ModerationViewableEntry $entry';

		// Run the method we are testing.
		$result = $entry->getDiffHTML( $context );
		$this->assertEquals( '{GeneratedDiff+Header}', $result );
	}

	/**
	 * Make ModerationViewableEntry for $fields with mocks that were created in setUp().
	 * @param array $fields
	 * @param RevisionLookup|null $revisionLookup Optional, used to override result of getRevisionById().
	 * @return ModerationViewableEntry
	 */
	private function makeViewableEntry( $fields, RevisionLookup $revisionLookup = null ) {
		$row = (object)$fields;
		if ( !isset( $row->type ) ) {
			$row->type = ModerationNewChange::MOD_TYPE_EDIT;
		}
		return new ModerationViewableEntry( $row, $this->linkRenderer,
			MediaWikiServices::getInstance()->getContentHandlerFactory(),
			$revisionLookup ?? $this->createMock( RevisionLookup::class )
		);
	}

	/**
	 * Precreate new mocks for $linkRenderer before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->linkRenderer = $this->createMock( LinkRenderer::class );
	}
}
