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
	@brief Verifies that modaction=show works as expected.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationActionShow
*/
class ModerationTestShow extends MediaWikiTestCase
{
	public function testShow() {
		$t = new ModerationTestsuite();

		$page = 'Test page 1';
		$text1 = "First string\nSecond string\nThird string\n";
		$text2 = "First string\nAnother second string\nThird string\n";

		$t->loginAs($t->automoderated);
		$t->doTestEdit($page, $text1);

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit($page, $text2);
		$t->fetchSpecialAndDiff();

		$url = $t->new_entries[0]->showLink;
		$this->assertNotNull($url,
			"testShow(): Show link not found");
		$url .= '&uselang=qqx'; # Show message IDs instead of text
		$title = $t->getHtmlTitleByURL($url);

		$this->assertRegExp('/\(difference-title: ' . preg_quote($page) . '\)/', $title,
			"testShow(): Difference page has a wrong HTML title");

		$added_lines = array();
		$deleted_lines = array();
		$context_lines = array();

		$html = $t->lastFetchedDocument;
		$table_cells = $html->getElementsByTagName('td');
		foreach($table_cells as $td)
		{
			$class = $td->getAttribute('class');
			$text = $td->textContent;

			if($class == 'diff-addedline') {
				$added_lines[] = $text;
			}
			else if($class == 'diff-deletedline') {
				$deleted_lines[] = $text;
			}
			else if($class == 'diff-context') {
				$context_lines[] = $text;
			}
		}

		# Each context line is shown twice: in Before and After columns
		$this->assertCount(4, $context_lines,
			"testShow(): Two lines were unchanged, but number of context lines on the difference page is not 4");
		$this->assertEquals('First string', $context_lines[0]);
		$this->assertEquals('First string', $context_lines[1]);
		$this->assertEquals('Third string', $context_lines[2]);
		$this->assertEquals('Third string', $context_lines[3]);

		$this->assertCount(1, $added_lines,
			"testShow(): One line was modified, but number of added lines on the difference page is not 1");
		$this->assertCount(1, $deleted_lines,
			"testShow(): One line was modified, but number of deleted lines on the difference page is not 1");
		$this->assertEquals('Another second string', $added_lines[0]);
		$this->assertEquals('Second string', $deleted_lines[0]);
	}
}
