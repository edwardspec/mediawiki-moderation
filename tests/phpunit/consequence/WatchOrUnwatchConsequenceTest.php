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

use MediaWiki\Moderation\WatchOrUnwatchConsequence;

require_once __DIR__ . "/autoload.php";

class WatchOrUnwatchConsequenceTest extends ModerationUnitTestCase {
	/**
	 * Verify that WatchOrUnwatchConsequence watches/unwatches a page.
	 * @covers MediaWiki\Moderation\WatchOrUnwatchConsequence
	 * @param bool $watch
	 * @param bool $noop If true, "watch this" will be tested on already watched page
	 * (and "unwatch this" will be tested on non-watched page).
	 * @dataProvider dataProviderWatchUnwatch
	 */
	public function testWatchUnwatch( $watch, $noop ) {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = self::getTestUser()->getUser();

		$watchedItemStore = $this->createMock( WatchedItemStore::class );
		$watchedItemStore->expects( $this->once() )->method( 'isWatched' )->with(
			$this->identicalTo( $user ),
			$this->identicalTo( $title )
		)->willReturn( ( $watch && $noop ) || ( !$watch && !$noop ) );

		if ( $noop || !$watch ) {
			$watchedItemStore->expects( $this->never() )->method( 'addWatchBatchForUser' );
		} else {
			$watchedItemStore->expects( $this->once() )->method( 'addWatchBatchForUser' )->with(
				$this->identicalTo( $user ),
				$this->equalTo( [ $title->getSubjectPage(), $title->getTalkPage() ] )
			);
		}

		if ( $noop || $watch ) {
			$watchedItemStore->expects( $this->never() )->method( 'removeWatch' );
		} else {
			$watchedItemStore->expects( $this->at( 1 ) )->method( 'removeWatch' )->with(
				$this->identicalTo( $user ),
				$this->equalTo( $title->getSubjectPage() )
			);
			$watchedItemStore->expects( $this->at( 2 ) )->method( 'removeWatch' )->with(
				$this->identicalTo( $user ),
				$this->equalTo( $title->getTalkPage() )
			);
		}
		$this->setService( 'WatchedItemStore', $watchedItemStore );

		// Create and run the Consequence.
		$consequence = new WatchOrUnwatchConsequence( $watch, $title, $user );
		$consequence->run();
	}

	/**
	 * Provide datasets for dataProviderWatchUnwatch() runs.
	 * @return array
	 */
	public function dataProviderWatchUnwatch() {
		return [
			'watch' => [
				true, // True means "Watch", false means "Unwatch"
				false // True means "noop test" (trying to watch already watched page)
			],
			'unwatch' => [ false, false ],
			'watch (noop)' => [ true, true ],
			'unwatch (noop)' => [ false, true ]
		];
	}
}
