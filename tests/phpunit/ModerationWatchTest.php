<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
	@file
	@brief Verifies that "Watch this page" checkbox is respected when editing.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

/**
	@covers ModerationActionApprove
*/
class ModerationTestWatch extends MediaWikiTestCase
{
	public function testWatch() {
		$t = new ModerationTestsuite();

		$title = 'Some page';

		$t->loginAs( $t->unprivilegedUser );
		$t->nonApiEdit( $title, 'Some text', 'Some summary', [
			'wpWatchthis' => 1
		] );

		/* Verify that $title was added to watchlist immediately,
			even though the edit was intercepted by Moderation */
		$ret = $t->query( [
			'action' => 'query',
			'list' => 'watchlistraw',
			'wrnamespace' => NS_MAIN,
			'wrfromtitle' => $title,
			'wrlimit' => 1
		] );

		$wl = $ret['watchlistraw'];
		$this->assertNotEmpty( $wl,
			"testWatch(): One page was watched, watchlist is empty" );
		$this->assertEquals( $title, $wl[0]['title'],
			"testWatch(): Page edited with wpWatchthis=1 is not in watchlist" );

		/*
			Uncheck the "Watch this page" checkbox and edit the same page.
		*/
		$t->nonApiEdit( $title, 'Some text2', 'Some summary2' );
		$ret = $t->query( [
			'action' => 'query',
			'list' => 'watchlistraw'
		] );
		$this->assertEmpty( $ret['watchlistraw'],
			"testWatch(): All pages were unwatched, but watchlist is not empty" );
	}
}
