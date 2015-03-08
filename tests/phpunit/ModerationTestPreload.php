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
	public function testPreload() {
		$t = new ModerationTestsuite();
		$t->loginAs($t->unprivilegedUser);
		$t->doTestEdit();

		$url = wfAppendQuery(wfScript('index'), array(
			'title' => $t->lastEdit['Title'],
			'action' => 'edit'
		));
		$html = $t->getHtmlDocumentByURL($url);

		$elem = $t->lastFetchedDocument->getElementById('wpTextbox1');
		$text = trim($elem->textContent);

		$this->assertEquals($t->lastEdit['Text'], $text,
			"testPreload(): Preloaded text differs from what the user saved before");
	}
}
