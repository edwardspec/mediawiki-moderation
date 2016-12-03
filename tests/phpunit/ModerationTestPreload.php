<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2016 Edward Chernenko.

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

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers ModerationPreload
*/
class ModerationTestPreload extends MediaWikiTestCase
{
	public function testLoggedInPreload() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testLoggedInPreload(): Preloaded text differs from what the user saved before" );

		/* For new pages, summary is filled in by [ext.moderation.edit.js] script,
			which uses mw.config.get('wgPreloadedSummary').
			Here we check that this JavaScript variable was indeed populated.
		*/
		$summary = false;
		$scripts = $t->html->getElementsByTagName('script');
		foreach($scripts as $script) {
			if(preg_match('/([\'"])wgPreloadedSummary\1\s*:\s*([\'"])(.+?)\2/', $script->textContent, $matches)) {
				$summary = $matches[3];
				break;
			}
		}

		$this->assertNotNull( $summary,
			"testLoggedInPreload(): JavaScript variable wgPreloadedSummary wasn't populated." );
		$this->assertEquals( $t->lastEdit['Summary'], $summary,
			"testLoggedInPreload(): JavaScript variable wgPreloadedSummary doesn't match the summary of last edit." );

		/* Were the script/style loaded? */
		$this->assertContains( 'ext.moderation.edit', $t->html->getLoaderModulesList(),
			"testLoggedInPreload(): Module ext.moderation.edit wasn't loaded" );
	}

	public function testAnonymousPreload() {
		$t = new ModerationTestsuite();

		$t->logout();
		$ret = $t->doTestEdit();

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testAnonymousPreload(): Preloaded text differs from what the user saved before" );

		/* Now create an account
			and check that text can still be preloaded */

		$username = 'FinallyLoggedIn';
		$user = $t->createAccount( $username );
		if ( !$user ) {
			$this->markTestIncomplete( 'testAnonymousPreload(): Failed to create account, most likely captcha is enabled.' );
		};

		$t->loginAs( $user );
		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testAnonymousPreload(): Text was not preloaded after creating an account" );
	}

	public function testPreloadSummary() {
		$t = new ModerationTestsuite();

		/* For existing pages, summary is preloaded directly (into EditPage object),
			not via [ext.moderation.edit.js] script.

			To test this, we need to create the page first.
		*/

		$page = "Test page 1";
		$summary = "The quick brown fox jumps over the lazy dog";

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $page, "Text 1" );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $page, "Another text", $summary );

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testPreloadSummary(): Preloaded text differs from what the user saved before" );

		$elem = $t->html->getElementById( 'wpSummary' );
		$this->assertTrue( $elem->hasAttribute( 'value' ),
			"testPreloadSummary(): #wpSummary doesn't have a 'value' attribute"
		);
		$this->assertEquals( $summary, $elem->getAttribute( 'value' ),
			"testPreloadSummary(): Preloaded summary doesn't match"
		);

		$this->assertContains( 'ext.moderation.edit', $t->html->getLoaderModulesList(),
			"testPreloadSummary(): Module ext.moderation.edit wasn't loaded" );

		$elem = $t->html->getElementById( 'mw-editing-your-version' );
		$this->assertNotNull( $elem,
			"testPreloadSummary(): #mw-editing-your-version not found" );
		$this->assertEquals( '(moderation-editing-your-version)', $elem->textContent,
			"testPreloadSummary(): #mw-editing-your-version doesn't contain (moderation-editing-your-version) message" );
	}
}
