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

use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueEditConsequence;

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
	 * Perform one edit that will be queued for moderation. (for use in different tests)
	 */
	private function makeEdit() {
		$this->user = self::getTestUser()->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$this->content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );
		$this->summary = 'Some edit summary';

		$page = WikiPage::factory( $this->title );
		$page->doEditContent(
			$this->content,
			$this->summary,
			EDIT_INTERNAL,
			false,
			$this->user
		);
	}

	/**
	 * Replace real ConsequenceManager with a mock.
	 */
	public function setUp() {
		parent::setUp();

		$this->manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $this->manager );
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
