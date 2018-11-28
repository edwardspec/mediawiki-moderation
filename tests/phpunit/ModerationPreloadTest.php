<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2017 Edward Chernenko.

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
 * @file
 * Checks that user can continue editing their version of the page.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationPreload
 */
class ModerationPreloadTest extends ModerationTestCase {
	/** @covers ModerationPreload::onEditFormPreloadText */
	public function testPreloadNewPage( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( null, null, "The quick brown fox jumps over the lazy dog" );

		$this->tryToPreload( $t, __FUNCTION__ );
	}

	/** @covers ModerationPreload::onEditFormInitialText */
	public function testPreloadExistingPage( ModerationTestsuite $t ) {
		$page = "Test page 1";

		$t->loginAs( $t->automoderated ); /* Create the page first */
		$t->doTestEdit( $page, "Text 1" );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $page, "Another text", "The quick brown fox jumps over the lazy dog" );

		$this->tryToPreload( $t, __FUNCTION__ );
	}

	/** @covers ModerationPreload::onLocalUserCreated */
	public function testAnonymousPreload( ModerationTestsuite $t ) {
		$t->logout();
		$t->doTestEdit();

		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testAnonymousPreload(): Preloaded text differs from what the user saved before" );

		/* Now create an account
			and check that text can still be preloaded */

		$username = 'FinallyLoggedIn';
		$user = $t->createAccount( $username );
		if ( !$user ) {
			$this->markTestIncomplete( 'testAnonymousPreload(): Failed to create account, ".
				"most likely the captcha is enabled.' );
		};

		$t->loginAs( $user );
		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"testAnonymousPreload(): Text was not preloaded after creating an account" );
	}

	/** @covers ApiQueryModerationPreload */
	public function testApiPreload( ModerationTestsuite $t ) {
		/* We make an edit with '''bold''' and ''italic'' markup
			and then check for <b> and <i> tags in "mpmode=parsed" mode.
		*/
		$boldText = 'very bold';
		$italicText = 'somewhat italic';
		$categories = [ 'Example category', 'Cats' ];
		$extraSectionText = "== More information ==\nText in section #1";

		$text = "This text is '''$boldText''' and ''$italicText''.";
		foreach ( $categories as $name ) {
			$text .= "\n[[Category:$name]]";
		}
		$text .= "\n" . $extraSectionText;

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( null, $text, "The quick brown fox jumps over the lazy dog" );

		/* Test 1: mpmode=wikitext */
		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'moderationpreload',
			'mptitle' => $t->lastEdit['Title']
		] );

		$this->assertArrayHasKey( 'query', $ret );
		$this->assertArrayHasKey( 'moderationpreload', $ret['query'] );

		$mp = $ret['query']['moderationpreload'];
		$this->assertEquals( $t->lastEdit['User'], $mp['user'] );
		$this->assertEquals( $t->lastEdit['Title'], $mp['title'] );
		$this->assertEquals( $t->lastEdit['Summary'], $mp['comment'] );
		$this->assertArrayHasKey( 'wikitext', $mp );
		$this->assertArrayNotHasKey( 'parsed', $mp );
		$this->assertEquals( $text, $mp['wikitext'] );

		/* Test 2: mpmode=parsed */
		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'moderationpreload',
			'mptitle' => $t->lastEdit['Title'],
			'mpmode' => 'parsed'
		] );

		$mp = $ret['query']['moderationpreload'];
		$this->assertArrayHasKey( 'parsed', $mp );
		$this->assertArrayNotHasKey( 'wikitext', $mp );

		$parsed = $mp['parsed'];
		$this->assertArrayHasKey( 'text', $parsed );
		$this->assertArrayHasKey( 'categorieshtml', $parsed );
		$this->assertArrayHasKey( 'displaytitle', $parsed );

		$this->assertContains( '<b>' . $boldText . '</b>', $parsed['text'] );
		$this->assertContains( '<i>' . $italicText . '</i>', $parsed['text'] );

		$this->assertContains( '(pagecategories: ' . count( $categories ) . ')',
			$parsed['categorieshtml'] );
		foreach ( $categories as $name ) {
			$this->assertContains( $name, $parsed['categorieshtml'] );
		}

		/* Test 3: mpsection=N */
		$ret = $t->query( [
			'action' => 'query',
			'prop' => 'moderationpreload',
			'mptitle' => $t->lastEdit['Title'],
			'mpsection' => 1
		] );
		$this->assertEquals( $extraSectionText, $ret['query']['moderationpreload']['wikitext'] );
	}

	private function tryToPreload( ModerationTestsuite $t, $caller ) {
		$this->assertEquals(
			$t->lastEdit['Text'],
			$t->html->getPreloadedText( $t->lastEdit['Title'] ),
			"$caller(): Preloaded text differs from what the user saved before" );

		$elem = $t->html->getElementByXPath( '//input[@name="wpSummary"]' );
		$this->assertEquals( $t->lastEdit['Summary'], $elem->getAttribute( 'value' ),
			"$caller(): Preloaded summary doesn't match"
		);

		$this->assertContains( 'ext.moderation.edit', $t->html->getLoaderModulesList(),
			"$caller(): Module ext.moderation.edit wasn't loaded" );

		$elem = $t->html->getElementById( 'mw-editing-your-version' );
		$this->assertNotNull( $elem,
			"$caller(): #mw-editing-your-version not found" );
		$this->assertEquals( '(moderation-editing-your-version)', $elem->textContent,
			"$caller(): #mw-editing-your-version doesn't contain " .
			"(moderation-editing-your-version) message" );
	}
}
