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
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\MarkAsMergedConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\TagRevisionAsMergedConsequence;
use MediaWiki\Moderation\WatchCheckbox;
use MediaWiki\Moderation\WatchOrUnwatchConsequence;
use Wikimedia\ScopedCallback;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class EditsHaveConsequencesTest extends MediaWikiTestCase {
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

	/** @var MockConsequenceManager */
	protected $manager;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Test consequences when normal edit is queued for moderation.
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testEdit() {
		// Replace real ConsequenceManager with a mock.
		list( $managerScope, $this->manager ) = MockConsequenceManager::install();

		$this->user = self::getTestUser()->getUser();
		$this->makeEdit();
		$this->assertConsequences( [
			new QueueEditConsequence(
				WikiPage::factory( $this->title ), $this->user, $this->content, $this->summary,
				'', // section
				'', // sectionText
				false, // isBot
				false // isMinor
			)
		] );
	}

	/**
	 * Test consequences when moderator saves a manually merged edit (resolving an edit conflict).
	 * @covers ModerationEditHooks::onPageContentSaveComplete
	 */
	public function testMergedEdit() {
		// Replace real ConsequenceManager with a mock.
		list( $managerScope, $this->manager ) = MockConsequenceManager::install();

		$modid = 12345;
		RequestContext::getMain()->getRequest()->setVal( 'wpMergeID', $modid );

		$this->user = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		$this->manager->mockResult( MarkAsMergedConsequence::class, true );

		$status = $this->makeEdit();
		$this->assertTrue( $status->isOK(), 'Failed to save an edit.' );

		$revid = $status->value['revision']->getId();

		$this->assertConsequences( [
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
		] );
	}

	/**
	 * Test consequences of 1) editing a section, 2) "Watch this page" checkbox being (un)checked.
	 * @param bool $watch
	 * @dataProvider dataProviderSectionEditAndWatchthis
	 * @covers ModerationEditHooks::onEditFilter
	 * @covers ModerationEditHooks::onPageContentSave
	 */
	public function testSectionEditAndWatchthis( $watch ) {
		$cleanupScope = new ScopedCallback( function () {
			// Undo all changes that this test makes to static fields of ModerationEditHooks class.
			$wrapper = TestingAccessWrapper::newFromClass( ModerationEditHooks::class );
			$wrapper->section = '';
			$wrapper->sectionText = '';
			WatchCheckbox::clear();
		} );

		$section = "2"; // Section is a string (not integer), because it can be "new", etc.
		$sectionText = 'New text of section #2';
		$fullText = "Text #0\n== Section 1 ==\nText #1\n\n " .
			"== Section 2 ==\nText #2\n\n== Section 3 ==\nText #3";

		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$summary = 'Some edit summary';
		$user = self::getTestUser()->getUser();

		// Replace real ConsequenceManager with a mock.
		list( $managerScope, $this->manager ) = MockConsequenceManager::install();

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

		$this->assertConsequences( [
			new QueueEditConsequence(
				$page, $user, $content, $summary,
				$section,
				$sectionText,
				false, // isBot
				false // isMinor
			),
			new WatchOrUnwatchConsequence( $watch, $title, $user )
		] );
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
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
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

	/**
	 * Assert that ConsequenceManager received $expectedConsequences and nothing else.
	 * @param IConsequence[] $expectedConsequences
	 */
	public function assertConsequences( $expectedConsequences ) {
		$actualConsequences = $this->manager->getConsequences();
		$this->assertConsequencesEqual( $expectedConsequences, $actualConsequences );
	}
}
