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
	/** @covers ModerationPreload::onEditFormPreloadText */
	public function testPreloadNewPage() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit(null, null, "The quick brown fox jumps over the lazy dog");

		$this->tryToPreload($t, __FUNCTION__);
	}

	/** @covers ModerationPreload::onEditFormInitialText */
	public function testPreloadExistingPage() {
		$t = new ModerationTestsuite();
		$page = "Test page 1";

		$t->loginAs( $t->automoderated ); /* Create the page first */
		$t->doTestEdit( $page, "Text 1" );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $page, "Another text", "The quick brown fox jumps over the lazy dog" );

		$this->tryToPreload($t, __FUNCTION__);
	}

	/** @covers ModerationPreload::onLocalUserCreated */
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

	private function tryToPreload( ModerationTestsuite $t, $caller )
	{
		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"$caller(): Preloaded text differs from what the user saved before" );

		$elem = $t->html->getElementById( 'wpSummary' );
		$this->assertEquals( $t->lastEdit['Summary'], $elem->getAttribute( 'value' ),
			"$caller(): Preloaded summary doesn't match"
		);

		$this->assertContains( 'ext.moderation.edit', $t->html->getLoaderModulesList(),
			"$caller(): Module ext.moderation.edit wasn't loaded" );

		$elem = $t->html->getElementById( 'mw-editing-your-version' );
		$this->assertNotNull( $elem,
			"$caller(): #mw-editing-your-version not found" );
		$this->assertEquals( '(moderation-editing-your-version)', $elem->textContent,
			"$caller(): #mw-editing-your-version doesn't contain (moderation-editing-your-version) message" );


	}
}
