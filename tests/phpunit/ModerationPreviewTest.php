<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2016-2017 Edward Chernenko.

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
	@brief Verifies that modaction=preview works as expected.
*/

require_once( __DIR__ . "/framework/ModerationTestsuite.php" );

/**
	@covers ModerationActionPreview
*/
class ModerationTestPreview extends MediaWikiTestCase
{
	public function testPreview() {
		$t = new ModerationTestsuite();

		/* We make an edit with '''bold''' and ''italic'' markup
			and then check for <b> and <i> tags in Preview.
		*/
		$text = "This text is '''very bold''' and ''most italic''.\n";
		$page = 'Test page 1';

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $page, $text );
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNull( $entry->previewLink,
			"testPreview(): Preview link was mistakenly shown (this link must be hidden in default configuration)" );

		$url = $entry->expectedActionLink( 'preview', false );
		$title = $t->html->getTitle( $url );

		$this->assertRegExp( '/\(moderation-preview-title: ' . preg_quote( $page ) . '\)/', $title,
			"testPreview(): Preview page has a wrong HTML title" );

		$main = $t->html->getMainContent();
		$bold = $main->getElementsByTagName( 'b' )->item( 0 );
		$italic = $main->getElementsByTagName( 'i' )->item( 0 );

		$this->assertNotNull( $bold,
			"testPreview(): <b> tag not found on the preview page" );
		$this->assertNotNull( $italic,
			"testPreview(): <i> tag not found on the preview page" );

		$this->assertEquals( 'very bold', $bold->textContent,
			"testPreview(): Incorrect content within <b></b> tags" );
		$this->assertEquals( 'most italic', $italic->textContent,
			"testPreview(): Incorrect content within <b></b> tags" );
	}
}
