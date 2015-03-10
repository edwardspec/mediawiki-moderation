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
	@brief Checks that user can continue editing their version of the page.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationPreload
*/
class ModerationTestPreload extends MediaWikiTestCase
{
	public function testLoggedInPreload() {
		$t = new ModerationTestsuite();

		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->getPreloadedText($t->lastEdit['Title']),
			"testLoggedInPreload(): Preloaded text differs from what the user saved before");

		# Summary is not preloaded for new pages, see KNOWN_LIMITATIONS

		$this->assertContains('ext.moderation.edit', $t->getLoaderModulesList(),
			"testLoggedInPreload(): Module ext.moderation.edit wasn't loaded");
	}

	public function testAnonymousPreload() {
		$t = new ModerationTestsuite();

		$t->logout();
		$ret = $t->doTestEdit();

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->getPreloadedText($t->lastEdit['Title']),
			"testAnonymousPreload(): Preloaded text differs from what the user saved before");
	}

	public function testPreloadSummary() {
		$t = new ModerationTestsuite();

		# Summaries are only preloaded for existing pages, so we need
		# to create the page first. (see KNOWN_LIMITATIONS for details)

		$page = "Test page 1";
		$summary = "The quick brown fox jumps over the lazy dog";

		$t->loginAs($t->automoderated);
		$t->doTestEdit($page, "Text 1");

		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit($page, "Another text", $summary);

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->getPreloadedText($t->lastEdit['Title']),
			"testPreloadSummary(): Preloaded text differs from what the user saved before");

		$elem = $t->lastFetchedDocument->getElementById('wpSummary');
		$this->assertTrue($elem->hasAttribute('value'),
			"testPreloadSummary(): #wpSummary doesn't have a 'value' attribute"
		);
		$this->assertEquals($summary, $elem->getAttribute('value'),
			"testPreloadSummary(): Preloaded summary doesn't match"
		);

		$this->assertContains('ext.moderation.edit', $t->getLoaderModulesList(),
			"testPreloadSummary(): Module ext.moderation.edit wasn't loaded");
	}
}
