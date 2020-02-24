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
use MediaWiki\Moderation\SendNotificationEmailConsequence;

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
		$this->assertConsequences( [] );
	}

	/**
	 * Test that notification emails are sent when normal edit is queued for moderation.
	 * @covers ModerationNewChange::sendNotificationEmail
	 */
	public function testEditNotificationEmail() {
		// Replace real ConsequenceManager with a mock.
		$this->setMwGlobals( [
			'wgModerationNotificationEnable' => true,
			'wgModerationEmail' => 'noreply@localhost'
		] );

		$this->makeEdit();

		$this->assertConsequences( [
			new SendNotificationEmailConsequence(
				$this->title,
				$this->user,
				$this->modid
			)
		] );
	}

	/**
	 * Perform one edit that will be queued for moderation. (for use in different tests)
	 */
	private function makeEdit() {
		$this->user = self::getTestUser()->getUser();
		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );

		$page = WikiPage::factory( $this->title );
		$page->doEditContent(
			ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT ),
			'',
			EDIT_INTERNAL,
			false,
			$this->user
		);

		$dbw = wfGetDB( DB_MASTER );
		$this->modid = (int)$dbw->selectField( 'moderation', 'mod_id', '', __METHOD__ );
		$this->assertNotSame( 0, $this->modid );
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
