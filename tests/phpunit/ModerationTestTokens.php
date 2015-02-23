<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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
	@brief Verifies that moderation tokens are required.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");


class ModerationTestTokens extends MediaWikiTestCase
{
	public function testTokens() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];
		$entry->fakeBlockLink();

		# Non-readonly actions require a correct token
		$links = array($entry->approveLink,
			$entry->approveAllLink,
			$entry->rejectLink,
			$entry->rejectAllLink,
			$entry->blockLink,
			$entry->unblockLink
		);
		foreach($links as $url)
		{
			$url .= '&uselang=qqx'; # Show message IDs instead of text

			$bad_url = preg_replace('/token=[^&]*/', '', $url);
			$title = $t->getHtmlTitleByURL($bad_url);
			$this->assertRegExp('/\(sessionfailure-title\)/', $title);

			/* Double-check that nothing happened */
			$t->fetchSpecialAndDiff();
			$this->assertCount(0, $t->new_entries);
			$this->assertCount(0, $t->deleted_entries);

			# Would the wrong token work?

			$bad_url = preg_replace('/(token=)([^&]*)/', '\1WRONG\2', $url);
			$title = $t->getHtmlTitleByURL($bad_url);
			$this->assertRegExp('/\(sessionfailure-title\)/', $title);

			/* Double-check that nothing happened */
			$t->fetchSpecialAndDiff();
			$this->assertCount(0, $t->new_entries);
			$this->assertCount(0, $t->deleted_entries);
		}

		# Show link must work without a token.
		$links = array($entry->showLink);
		foreach($links as $url)
		{
			$url .= '&uselang=qqx'; # Show message IDs instead of text
			$title = $t->getHtmlTitleByURL($url);

			$this->assertNotRegExp('/token=[^&]*/', $url,
				"testTokens(): Token was found in the read-only Show link");
			$this->assertNotRegExp('/\(sessionfailure-title\)/', $title);
		}

		# Note: modaction=showimg should be checked in ShowImg test,
		# because its output is different. Tokens test is not the
		# best place for action-specific assumptions.
	}
}
