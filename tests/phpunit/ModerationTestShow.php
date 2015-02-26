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

	public function testShowUpload() {
		$t = new ModerationTestsuite();

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload();
		$t->fetchSpecialAndDiff();

		$entry = $t->new_entries[0];
		$url = $entry->showLink;
		$this->assertNotNull($url,
			"testShowUpload(): Show link not found");
		$url .= '&uselang=qqx'; # Show message IDs instead of text
		$title = $t->getHtmlTitleByURL($url);

		$this->assertRegExp('/\(difference-title: ' . $t->lastEdit['Title'] . '\)/', $title,
			"testShowUpload(): Difference page has a wrong HTML title");

		# Is the image thumbnail displayed on the difference page?
		$html = $t->lastFetchedDocument;
		$images = $html->getElementsByTagName('img');

		$thumb = null;
		$src = null;
		foreach($images as $img)
		{
			$src = $img->getAttribute('src');
			if(strpos($src, 'modaction=showimg') != false)
			{
				$thumb = $img;
				break;
			}
		}

		$this->assertNotNull($thumb,
			"testShowUpload(): Thumbnail image not found");
		$this->assertRegExp('/thumb=1/', $src,
			"testShowUpload(): Thumbnail image URL doesn't contain thumb=1");

		# Is the image thumbnail inside the link to the full image?
		$link = $thumb->parentNode;
		$this->assertEquals('a', $link->nodeName,
			"testShowUpload(): Thumbnail image isn't encased in <a> tag");

		$href = $link->getAttribute('href');
		$this->assertEquals($entry->expectedShowImgLink(), $href,
			"testShowUpload(): Full image URL doesn't match expected URL");

		$nonthumb_src = str_replace('&thumb=1', '', $src);
		$this->assertEquals($nonthumb_src, $href,
			"testShowUpload(): Full image URL doesn't match thumbnail image URL without '&thumb=1'");

		$req = $t->makeHttpRequest($href, 'GET');
		$this->assertTrue($req->execute()->isOK());

		/* TODO: check $req->getContent() */

		$this->assertEquals($t->lastEdit['SHA1'], sha1($req->getContent()),
			"testShowUpload(): Checksum of image downloaded via modaction=showimg doesn't match the checksum of original image");

		/* TODO: check the thumbnail's width */

		/* TODO: run the test on two images -
			one smaller than requested thumbnail's width, one larger */
	}
}
