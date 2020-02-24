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
 * Unit test of QueueEditConsequence.
 */

use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\MockConsequenceManager;
use MediaWiki\Moderation\QueueEditConsequence;
use MediaWiki\Moderation\SendNotificationEmailConsequence;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 */
class QueueEditConsequenceTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'moderation', 'user' ];

	/**
	 * Check the secondary consequences of running QueueEditConsequence.
	 * @covers ModerationNewChange::sendNotificationEmail
	 */
	public function testQueueEdit() {
		$user = self::getTestUser()->getUser();
		$page = WikiPage::factory( Title::newFromText( 'UTPage-' . rand( 0, 100000 ) ) );
		$content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );

		// Replace real ConsequenceManager with a mock.
		$manager = new MockConsequenceManager();
		ConsequenceUtils::installManager( $manager );

		$this->setMwGlobals( [
			'wgModerationNotificationEnable' => true,
			'wgModerationEmail' => 'noreply@localhost'
		] );

		// Create and run the Consequence.
		$consequence = new QueueEditConsequence(
			$page, $user, $content, 'Some summary', '', '', false, false );
		$modid = $consequence->run();

		// Check secondary consequences.
		$this->assertConsequencesEqual( [
			new SendNotificationEmailConsequence(
				$page->getTitle(),
				$user,
				$modid
			)
		], $manager->getConsequences() );
	}
}
