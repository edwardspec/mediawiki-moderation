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
 * Unit test of WatchOrUnwatchConsequence.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\WatchOrUnwatchConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class WatchOrUnwatchConsequenceTest extends ModerationUnitTestCase {
	/** @var string[] */
	protected $tablesUsed = [ 'watchlist' ];

	/**
	 * Verify that WatchOrUnwatchConsequence watches/unwatches a page.
	 * @covers MediaWiki\Moderation\WatchOrUnwatchConsequence
	 * @param bool $watch
	 * @param string $hookName WatchArticleComplete or UnwatchArticleComplete
	 * @param bool $noop If true, "watch this" will be tested on already watched page
	 * (and "unwatch this" will be tested on non-watched page).
	 * @dataProvider dataProviderWatchUnwatch
	 */
	public function testWatchUnwatch( $watch, $hookName, $noop ) {
		$title = Title::newFromText( 'UTPage' ); // Was created in parent::addCoreDBData()
		$expectedUser = self::getTestUser()->getUser();

		$watchedItemStore = MediaWikiServices::getInstance()->getWatchedItemStore();
		$watchedItemStore->clearUserWatchedItems( $expectedUser );

		if ( !$watch && !$noop ) {
			// Unwatch test was requested.
			// Page should be in the watchlist before the test.
			WatchAction::doWatch( $title, $expectedUser );
		} elseif ( $watch && $noop ) {
			// Noop test: trying to watch an already watched page.
			WatchAction::doWatch( $title, $expectedUser );
		}

		$hookFired = false;

		$this->setTemporaryHook( $hookName,
			function ( $user, $page ) use ( &$hookFired, $expectedUser, $title ) {
				$hookFired = true;

				$this->assertEquals( $expectedUser, $user );
				$this->assertEquals( $title->getFullText(), $page->getTitle()->getFullText() );

				return true;
			} );

		// Create and run the Consequence.
		$consequence = new WatchOrUnwatchConsequence( $watch, $title, $expectedUser );
		$consequence->run();

		if ( $noop ) {
			$this->assertFalse( $hookFired,
				"WatchOrUnwatchConsequence: hook $hookName was called when it wasn't expected." );
		} else {
			$this->assertTrue( $hookFired, "WatchOrUnwatchConsequence: didn't watch/unwatch anything." );
		}
	}

	/**
	 * Provide datasets for dataProviderWatchUnwatch() runs.
	 * @return array
	 */
	public function dataProviderWatchUnwatch() {
		return [
			'watch' => [
				true, // True means "Watch", false means "Unwatch"
				'WatchArticleComplete',
				false // True means "noop test" (trying to watch already watched page)
			],
			'unwatch' => [ false, 'UnwatchArticleComplete', false ],
			'watch (noop)' => [ true, 'WatchArticleComplete', true ],
			'unwatch (noop)' => [ false, 'UnwatchArticleComplete', true ]
		];
	}
}
