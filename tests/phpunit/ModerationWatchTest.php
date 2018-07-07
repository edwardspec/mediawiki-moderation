<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017-2018 Edward Chernenko.

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
 * @brief Verifies that "Watch this page" checkbox is respected when editing.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationActionApprove
 */
class ModerationWatchTest extends MediaWikiTestCase {
	/**
	 * @brief Test that checkboxes "Watch this page" work.
	 * @dataProvider dataProviderWatch
	 */
	public function testWatch( $actionType ) {
		$t = new ModerationTestsuite();

		$title = 'Some page' . ( ( $actionType == 'upload' ) ? '.png' : '' );
		$newTitle = 'Some page 2';

		if ( $actionType == 'edit' ) {
			$t->loginAs( $t->unprivilegedUser );
			$t->nonApiEdit( $title, 'Some text', 'Some summary', [
				'wpWatchthis' => 1
			] );
		} elseif ( $actionType == 'upload' ) {
			$t->loginAs( $t->unprivilegedUser );
			$t->doTestUpload( $title, "image100x100.png", null, [
				'wpWatchthis' => 1
			] );
		} elseif ( $actionType == 'move' ) {
			$t->loginAs( $t->automoderated );
			$t->doTestEdit( $title, 'Some text' );

			$t->loginAs( $t->unprivilegedUser );
			$t->nonApiMove( $title, $newTitle, 'Some summary', [
				'wpWatch' => 1
			] );
		}

		/* Verify that $title was added to watchlist immediately,
			even though the edit was intercepted by Moderation */
		$ret = $t->query( [
			'action' => 'query',
			'list' => 'watchlistraw',
			'wrlimit' => ( $actionType == 'move' ) ? 2 : 1
		] );

		$wl = $ret['watchlistraw'];
		$this->assertNotEmpty( $wl,
			"testWatch(): One page was watched, watchlist is empty" );

		$watchedTitles = array_map( function ( $item ) {
			return $item['title'];
		}, $wl );
		$expectedWatchedTitles = [ $title ];
		if ( $actionType == 'move' ) {
			$expectedWatchedTitles[] = [ $newTitle ];
		}

		$this->assertEquals( sort( $expectedWatchedTitles ), sort( $watchedTitles ),
			"testWatch(): Page edited with \"Watch this page\" is not in watchlist" );

		/*
			Uncheck the "Watch this page" checkbox and edit the same page.
		*/
		if ( $actionType == 'edit' ) {
			$t->nonApiEdit( $title, 'Some text2', 'Some summary2' );
		} elseif ( $actionType == 'upload' ) {
			$t->doTestUpload( $title, "image100x100.png" );
		} elseif ( $actionType == 'move' ) {
			$t->nonApiMove( $title, $newTitle, 'Some summary2' );
		}

		$ret = $t->query( [
			'action' => 'query',
			'list' => 'watchlistraw'
		] );
		$this->assertEmpty( $ret['watchlistraw'],
			"testWatch(): All pages were unwatched, but watchlist is not empty" );
	}

	/**
	 * @brief Provide datasets for testWatch() runs.
	 */
	public function dataProviderWatch() {
		return [
			[ 'edit' ],
			[ 'upload' ],
			[ 'move' ]
		];
	}
}
