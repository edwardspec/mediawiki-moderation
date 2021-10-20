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
 * Unit test of ModerationActionPreview.
 */

require_once __DIR__ . "/autoload.php";

use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Revision\RevisionRecord;

class ModerationActionPreviewTest extends ModerationUnitTestCase {
	use ActionTestTrait;

	/**
	 * Check result/consequences of modaction=preview.
	 * @covers ModerationActionPreview
	 */
	public function testExecute() {
		$title = Title::makeTitle( NS_PROJECT, 'Some page ' . rand( 0, 100000 ) );
		$expectedResult = [
			'title' => $title->getFullText(),
			'html' => '{MockedParserOutput}',
			'categories' => [ 'Birds' => '', 'Cats' => '' ]
		];

		$revision = $this->createMock( RevisionRecord::class );

		$entry = $this->createMock( ModerationViewableEntry::class );
		$entry->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );
		$entry->expects( $this->once() )->method( 'getPendingRevision' )->willReturn( $revision );

		$parserOutput = $this->createMock( ParserOutput::class );
		$parserOutput->expects( $this->once() )->method( 'getText' )->with(
			$this->identicalTo( [ 'enableSectionEditLinks' => false ] )
		)->willReturn( $expectedResult['html'] );
		$parserOutput->expects( $this->once() )->method( 'getCategories' )
			->willReturn( $expectedResult['categories'] );

		$renderedRevision = $this->createMock( RenderedRevision::class );
		$renderedRevision->expects( $this->once() )->method( 'getRevisionParserOutput' )
			->willReturn( $parserOutput );

		$action = $this->makeActionForTesting( ModerationActionPreview::class,
			function (
				$context, $entryFactory, $manager, $canSkip, $editFormOptions, $actionLinkRenderer,
				$repoGroup, $contentLanguage, $revisionRenderer
			) use ( $entry, $renderedRevision, $revision ) {
				$modid = 12345;
				$context->setRequest( new FauxRequest( [ 'modid' => $modid ] ) );

				$entryFactory->expects( $this->once() )->method( 'findViewableEntry' )->with(
					$this->identicalTo( $modid )
				)->willReturn( $entry );

				$revisionRenderer->expects( $this->once() )->method( 'getRenderedRevision' )->with(
					$this->identicalTo( $revision )
				)->willReturn( $renderedRevision );

				// This is a readonly action. Ensure that it has no consequences.
				$manager->expects( $this->never() )->method( 'add' );
			}
		);
		$this->assertSame( $expectedResult, $action->execute() );
	}

	/**
	 * Verify that outputResult() correctly converts return value of execute() into HTML output.
	 * @covers ModerationActionPreview
	 */
	public function testOutputResult() {
		$executeResult = [
			'title' => 'Sample page',
			'html' => '{MockedParserOutput}',
			'categories' => [ 'Birds' => '', 'Cats' => '' ]
		];

		$action = $this->makeActionForTesting( ModerationActionPreview::class );

		$output = clone $action->getOutput();
		$action->outputResult( $executeResult, $output );

		$this->assertSame( '(moderation-preview-title: Sample page)', $output->getPageTitle() );
		$this->assertSame( '{MockedParserOutput}', $output->getHTML() );
		$this->assertSame( [ 'Birds', 'Cats' ], $output->getCategories() );
	}
}
