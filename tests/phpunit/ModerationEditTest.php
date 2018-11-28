<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
 * Verifies that editing works as usual.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

class ModerationEditTest extends ModerationTestCase {
	public function testPreSaveTransform( ModerationTestsuite $t ) {
		# Some substitutions (e.g. ~~~~) must be performed when
		# the edit is queued for moderation, not when it is approved.
		$text = '~~~~';

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( null, $text );
		$t->fetchSpecial();

		$this->assertNotEquals( $text, $t->new_entries[0]->getDbText(),
			"testPreSaveTransform(): Signature (~~~~) hasn't been properly substituted." );
	}

	/**
	 * Provide datasets for testEditSections() runs.
	 */
	public function dataProviderEditSections() {
		/* Sections are handled differently in API and non-API editing.
			Test both situations.
		*/
		return [
			'nonApi' => [ false ],
			'api' => [ true ]
		];
	}

	/**
	 * Ensure that single sections are edited correctly.
	 * @dataProvider dataProviderEditSections
	 */
	public function testEditSections( $useApi, ModerationTestsuite $t ) {
		$bot = $t->getBot( $useApi ? 'api' : 'nonApi' );

		$sections = [
			"Text in zero section\n\n",
			"== First section ==\nText in first section\n\n",
			"== Second section ==\nText in second section\n\n",
			"== Third section ==\nText in third section\n\n"
		];
		$title = 'Test page 1';
		$text = implode( '', $sections );

		$t->loginAs( $t->automoderated );
		$bot->edit( $title, $text );

		$t->loginAs( $t->unprivilegedUser );

		# Do several edits in the different sections of the text.
		$sections[0] = "New text in zero section\n\n";
		$bot->edit( $title, $sections[0], null, 0 );

		$sections[2] = "== Second section (#2) ==\nText in second section\n\n";
		$bot->edit( $title, $sections[2], null, 2 );

		$sections[] = "== New section ==\nText in the new section";
		$bot->edit( $title, end( $sections ), null, 'new' );

		$t->fetchSpecial();

		$expectedText = implode( '', $sections );

		$this->assertEquals( $expectedText, $t->new_entries[0]->getDbText(),
			"testEditSections(): Resulting text doesn't match expected" );

		# Does PreSaveTransform work when editing sections?

		$t->loginAs( $t->unprivilegedUser );
		$bot->edit( $title, "== New section 2 ==\n~~~\n\n", null, 2 );

		$this->assertNotRegExp( '/~~~/', $t->new_entries[0]->getDbText(),
			"testEditSections(): Signature (~~~~) hasn't been properly substituted." );

		# Will editing the section work if section header was deleted?

		$sections[2] = "When editing this section, the user removed <nowiki>== This ==</nowiki>\n\n";
		$bot->edit( $title, $sections[2], null, 2 );

		$expectedText = implode( '', $sections );
		$this->assertEquals( $expectedText, $t->new_entries[0]->getDbText(),
			"testEditSections(): When section header is deleted, resulting text doesn't match expected " );
	}

	public function testNewSizeAfterEditSection( ModerationTestsuite $t ) {
		/* Make sure that mod_new_len is properly recalculated when editing sections */
		$title = 'Test page 1';
		$sections = [
			"Text in zero section",
			"== First section ==\nText in first section",
		];
		$origText = implode( "\n\n", $sections );

		# First, create a preloadable edit
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $title, $origText );

		# Now edit a section (not via API), forcing a situation when mod_new_len should be recalculated
		$t->getBot( 'nonApi' )->edit( $title,
			$sections[0], /* No changes */
			'Some edit comment',
			'0' // section number
		);

		$t->fetchSpecial();

		$newlen = $t->new_entries[0]->getDbField( 'mod_new_len' );

		$this->assertNotEquals( strlen( $sections[0] ), $newlen,
			"testNewSizeAfterEditSection(): Incorrect length: " .
			"matches the length of the edited section" );
		$this->assertEquals( strlen( $origText ), $newlen,
			"testNewSizeAfterEditSection(): Length changed after null edit in a section" );
	}

	public function testApiEditAppend( ModerationTestsuite $t ) {
		# Does api.php?action=edit&{append,prepend}text=[...] work properly?
		$todoPrepend = [ "B", "A" ];
		$todoText = "C";
		$todoAppend = [ "D", "E" ];

		$title = 'Test page 1';
		$expectedText = implode( '', array_merge(
			array_reverse( $todoPrepend ),
			[ $todoText ],
			$todoAppend
		) );

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $title, $todoText );

		$t->loginAs( $t->unprivilegedUser );

		# Do several edits using api.php?appendtext= and api.php?prependtext=
		$query = [
			'action' => 'edit',
			'title' => $title,
			'token' => null
		];

		foreach ( $todoPrepend as $text ) {
			$query['prependtext'] = $text;
			$t->query( $query );
		}

		unset( $query['prependtext'] );
		foreach ( $todoAppend as $text ) {
			$query['appendtext'] = $text;
			$t->query( $query );
		}

		$t->fetchSpecial();

		$this->assertEquals( $expectedText, $t->new_entries[0]->getDbText(),
			"testApiEditAppend(): Resulting text doesn't match expected" );
	}

	public function testApiNoSuchSectionYet( ModerationTestsuite $t ) {
		# Does api.php?action=edit&section=N work if section #N doesn't exist
		# in the article, but exists in the pending (preloaded) revision?

		$title = 'Test page 1';
		$text = "text 0\n== section 1 ==\ntext 1\n== section 2 ==\ntext 2\n== section 3 ==\ntext 3\n";
		$sectionIdx = 2; /* This section exists in $text */

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $title, $text );

		# Try api.php?action=edit&section=$sectionIdx
		$ret = $t->query( [
			'action' => 'edit',
			'title' => $title,
			'token' => null,
			'section' => $sectionIdx,
			'text' => 'Whatever'
		] );

		$this->assertNotEquals( 'nosuchsection', $ret['error']['code'],
			"testApiNoSuchSectionYet(): API returned nosuchsection error (which is incorrect)" );
	}
}
