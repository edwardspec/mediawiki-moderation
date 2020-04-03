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
 * Verifies that editing a page has consequences.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\MarkAsMergedConsequence;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\TagRevisionAsMergedConsequence;
use MediaWiki\Moderation\WatchOrUnwatchConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class EditsHaveConsequencesTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;

	/** @var int */
	protected $modid;

	/** @var User */
	protected $user;

	/** @var Title */
	protected $title;

	/** @var Content */
	protected $content;

	/** @var string */
	protected $summary;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Test consequences when normal edit is queued for moderation.
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testEdit() {
		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();
		$this->user = self::getTestUser()->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		// Mock the result of canEditSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canEditSkip' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->user,
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->title->getNamespace()
		)->willReturn( false ); // Can't bypass moderation
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$status = $this->makeEdit();
		$this->assertTrue( $status->hasMessage( 'moderation-edit-queued' ),
			"Status returned by doEditContent doesn't include \"moderation-edit-queued\"." );

		$this->assertConsequencesEqual( [
			new QueueEditConsequence(
				WikiPage::factory( $this->title ), $this->user, $this->content, $this->summary,
				'', // section
				'', // sectionText
				false, // isBot
				false // isMinor
			)
		], $manager->getConsequences() );
	}

	/**
	 * Test consequences of normal edit when User is automoderated (can bypass moderation of edits).
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testAutomoderatedEdit() {
		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();
		$this->user = self::getTestUser()->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		// Mock the result of canEditSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canEditSkip' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->user,
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->title->getNamespace()
		)->willReturn( true ); // Can bypass moderation
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$status = $this->makeEdit();
		$this->assertTrue( $status->isGood(),
			"User can bypass moderation, but doEditContent() didn't return successful Status." );

		$this->assertNoConsequences( $manager );
	}

	/**
	 * Verify that ModerationIntercept hook is called when edit is about to be queued for moderation.
	 * Also verify that returning false from this hook will allow this edit to bypass moderation.
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testModerationInterceptHook() {
		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();
		$this->user = self::getTestUser()->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$this->setTemporaryHook( 'ModerationIntercept',
			function ( WikiPage $page, User $user, Content $content,
				$summary, $is_minor, $is_watch,
				$section, $flags, Status $status
			) {
				$this->assertSame( $this->title->getFullText(), $page->getTitle()->getFullText() );
				$this->assertSame( $this->user->getName(), $user->getName() );
				$this->assertSame( $this->user->getId(), $user->getId() );
				$this->assertEquals( $this->summary, $summary );

				// Returning false from this hook means "this edit should bypass moderation".
				return false;
			}
		);

		$status = $this->makeEdit();
		$this->assertTrue( $status->isGood(), "ModerationIntercept hook returned false, " .
			"but doEditContent() didn't return successful Status." );

		// This edit shouldn't have been queued for moderation.
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Verify that editing non-text content (such as Flow forums) will bypass moderation.
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testEditNonTextContent() {
		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();
		$content = new DummyNonTextContent( 'not a TextContent' );

		$this->setMwGlobals( [
			'wgExtraNamespaces' => [
				12314 => 'DummyNonText',
				12315 => 'DummyNonTextTalk'
			],
			'wgNamespaceContentModels' => [
				12314 => 'testing-nontext'
			],
		] );
		$this->mergeMwGlobalArrayValue( 'wgContentHandlers', [
			'testing-nontext' => DummyNonTextContentHandler::class,
		] );
		$title = Title::makeTitle( 12314, 'UTPage-' . rand( 0, 100000 ) );

		$page = WikiPage::factory( $title );
		$status = $page->doEditContent(
			$content,
			'Some summary',
			EDIT_INTERNAL,
			false,
			self::getTestUser()->getUser()
		);
		$this->assertTrue( $status->isGood(), "Moderation shouldn't have intercepted non-text Content, " .
			"but doEditContent() didn't return successful Status." );

		// This edit shouldn't have been queued for moderation.
		$this->assertNoConsequences( $manager );
	}

	/**
	 * Test consequences when moderator saves a manually merged edit (resolving an edit conflict).
	 * @covers ModerationEditHooks::onPageContentSaveComplete
	 */
	public function testMergedEdit() {
		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();

		$modid = 12345;
		RequestContext::getMain()->getRequest()->setVal( 'wpMergeID', $modid );

		$this->user = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$manager->mockResult( MarkAsMergedConsequence::class, true );

		$status = $this->makeEdit();
		$this->assertTrue( $status->isOK(), 'Failed to save an edit.' );

		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		$revid = $status->value['revision']->getId();

		$this->assertConsequencesEqual( [
			new MarkAsMergedConsequence( $modid, $revid ),
			new AddLogEntryConsequence(
				'merge',
				$this->user,
				$this->title,
				[
					'modid' => $modid,
					'revid' => $revid
				]
			),
			new InvalidatePendingTimeCacheConsequence(),
			new TagRevisionAsMergedConsequence( $revid )
		], $manager->getConsequences() );
	}

	/**
	 * Test consequences of 1) editing a section, 2) "Watch this page" checkbox being (un)checked.
	 * @param bool $watch
	 * @dataProvider dataProviderSectionEditAndWatchthis
	 * @covers MediaWiki\Moderation\EditFormOptions::onEditFilter
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testSectionEditAndWatchthis( $watch ) {
		$section = "2"; // Section is a string (not integer), because it can be "new", etc.
		$sectionText = 'New text of section #2';
		$fullText = "Text #0\n== Section 1 ==\nText #1\n\n " .
			"== Section 2 ==\nText #2\n\n== Section 3 ==\nText #3";

		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$summary = 'Some edit summary';
		$user = self::getTestUser()->getUser();

		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();

		$editPage = new EditPage( Article::newFromTitle( $title, RequestContext::getMain() ) );
		$editPage->watchthis = $watch;

		$hookError = null;
		Hooks::run( 'EditFilter',
			[ $editPage, $sectionText, $section, &$hookError, $summary ] );

		$content = ContentHandler::makeContent( $fullText, null, CONTENT_MODEL_WIKITEXT );

		$page = WikiPage::factory( $title );
		$page->doEditContent(
			$content,
			$summary,
			EDIT_INTERNAL,
			false,
			$user
		);

		$this->assertConsequencesEqual( [
			new QueueEditConsequence(
				$page, $user, $content, $summary,
				$section,
				$sectionText,
				false, // isBot
				false // isMinor
			),
			new WatchOrUnwatchConsequence( $watch, $title, $user )
		], $manager->getConsequences() );
	}

	/**
	 * Provide datasets for testSectionEditAndWatchthis() runs.
	 * @return array
	 */
	public function dataProviderSectionEditAndWatchthis() {
		return [
			'watch' => [ true ],
			'unwatch' => [ true ]
		];
	}

	/**
	 * Perform one edit that will be queued for moderation. (for use in different tests)
	 * @return Status
	 */
	private function makeEdit() {
		$this->content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );
		$this->summary = 'Some edit summary';

		$page = WikiPage::factory( $this->title );
		return $page->doEditContent(
			$this->content,
			$this->summary,
			EDIT_INTERNAL,
			false,
			$this->user
		);
	}
}
