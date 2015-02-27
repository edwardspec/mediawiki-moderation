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
		$this->assertNotRegExp('/token=/', $url,
				"testShow(): Token was found in the read-only Show link");
			
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

	/**
		@covers ModerationActionShowImage
		@requires extension curl
		@note Only cURL version of MWHttpRequest supports uploads.
	*/
	public function testShowUpload() {
		$t = new ModerationTestsuite();

		/*
			When testing thumbnails, we check two images -
			one smaller than thumbnail's width, one larger,
			because they are handled differently.

			First test is on image640x50.png (large image),
			second on image100x100.png (smaller image).
		*/

		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload("Test image 1.png", __DIR__ . "/../resources/image640x50.png");
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

		$this->assertNotRegExp('/token=/', $href,
				"testShowUpload(): Token was found in the read-only ShowImage link");

		# Check the full image
		$req = $t->makeHttpRequest($href, 'GET');
		$this->assertTrue($req->execute()->isOK());

		$this->assertEquals('image/png', $req->getResponseHeader('Content-Type'),
			"testShowUpload(): Wrong Content-Type header from modaction=showimg");

		$this->assertEquals($t->lastEdit['SHA1'], sha1($req->getContent()),
			"testShowUpload(): Checksum of image downloaded via modaction=showimg doesn't match the checksum of original image");

		# Check the thumbnail
		$req = $t->makeHttpRequest($src, 'GET');
		$this->assertTrue($req->execute()->isOK());

		# Content-type check will catch HTML errors from StreamFile
		$this->assertRegExp('/^image\//', $req->getResponseHeader('Content-Type'),
			"testShowUpload(640x50): Wrong Content-Type header from modaction=showimg&thumb=1");

		list($original_width, $original_height) = getimagesize($t->lastEdit['Source']);
		list($width, $height) = getImageSizeFromString($req->getContent());

		$orig_ratio = round($original_width / $original_height, 2);
		$ratio = round($width / $height, 2);

		# As this image is larger than THUMB_WIDTH,
		# its thumbnail must be exactly THUMB_WIDTH wide.
		$this->assertEquals(ModerationActionShowImage::THUMB_WIDTH, $width,
			"testShowUpload(): Thumbnail's width doesn't match expected");

		$this->assertEquals($orig_ratio, $ratio,
			"testShowUpload(): Thumbnail's ratio doesn't match original");

		# Check the thumbnail of image smaller than THUMB_WIDTH.
		# Its thumbnail must be exactly the same size as original image.
		$t->fetchSpecial();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestUpload("Test image 2.png", __DIR__ . "/../resources/image100x100.png");
		$t->fetchSpecialAndDiff();

		$req = $t->makeHttpRequest($t->new_entries[0]->expectedShowImgLink(), 'GET');
		$this->assertTrue($req->execute()->isOK());

		list($original_width, $original_height) = getimagesize($t->lastEdit['Source']);
		list($width, $height) = getImageSizeFromString($req->getContent());

		$this->assertRegExp('/^image\//', $req->getResponseHeader('Content-Type'),
			"testShowUpload(100x100): Wrong Content-Type header from modaction=showimg&thumb=1");

		$this->assertEquals($original_width, $width,
			"testShowUpload(): Original image is smaller than THUMB_WIDTH, but thumbnail width doesn't match the original width");
		$this->assertEquals($original_height, $height,
			"testShowUpload(): Original image is smaller than THUMB_WIDTH, but thumbnail height doesn't match the original height");
	}

	private function getImageSizeFromString($content) {
		$path = tempnam(sys_get_temp_dir(), 'modtest_thumb');
		file_put_contents($path, $content);
		$size = getimagesize($path);
		unlink($path);

		return $size;
	}
}
