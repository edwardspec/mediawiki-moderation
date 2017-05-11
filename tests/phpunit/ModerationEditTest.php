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
	@file
	@brief Verifies that editing works as usual.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

class ModerationTestEdit extends MediaWikiTestCase
{
	public function testPreSaveTransform() {
		$t = new ModerationTestsuite();

		# Some substitutions (e.g. ~~~~) must be performed when
		# the edit is queued for moderation, not when it is approved.
		$text = '~~~~';

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( null, $text );
		$t->fetchSpecial();

		$dbw = wfGetDB( DB_MASTER );
		$dbText = $dbw->selectField( 'moderation', 'mod_text',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertNotEquals( $text, $dbText,
			"testPreSaveTransform(): Signature (~~~~) hasn't been properly substituted." );
	}

	public function testEditSections() {
		$t = new ModerationTestsuite();

		$sections = array(
			"Text in zero section\n\n",
			"== First section ==\nText in first section\n\n",
			"== Second section ==\nText in second section\n\n",
			"== Third section ==\nText in third section\n\n"
		);
		$title = 'Test page 1';
		$text = join( '', $sections );

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $title, $text );

		$t->loginAs( $t->unprivilegedUser );

		# Do several edits in the different sections of the text.
		$query = array(
			'action' => 'edit',
			'title' => $title,
			'token' => null
		);

		$query['section'] = 0;
		$query['text'] = $sections[0] = "New text in zero section\n\n";
		$t->query( $query );

		$query['section'] = 2;
		$query['text'] = $sections[2] = "== Second section (#2) ==\nText in second section\n\n";
		$t->query( $query );

		$query['section'] = 'new';
		$query['text'] = $sections[] = "== New section ==\nText in the new section";
		$t->query( $query );

		$t->fetchSpecial();

		$dbw = wfGetDB( DB_MASTER );
		$dbText = $dbw->selectField( 'moderation', 'mod_text',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$expectedText = join( '', $sections );

		$this->assertEquals( $expectedText, $dbText,
			"testEditSections(): Resulting text doesn't match expected" );

		# Does PreSaveTransform work when editing sections?

		$t->loginAs( $t->unprivilegedUser );
		$query['section'] = 2;
		$query['text'] = "== New section 2 ==\n~~~\n\n";
		$ret = $t->query( $query );

		$dbText = $dbw->selectField( 'moderation', 'mod_text',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertNotRegExp( '/~~~/', $dbText,
			"testEditSections(): Signature (~~~~) hasn't been properly substituted." );

		# Will editing the section work if section header was deleted?

		$query['section'] = 2;
		$query['text'] = $sections[2] = "When editing this section, the user removed <nowiki>== This ==</nowiki>\n\n";
		$ret = $t->query( $query );

		$dbText = $dbw->selectField( 'moderation', 'mod_text',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);
		$expectedText = join( '', $sections );
		$this->assertEquals( $expectedText, $dbText,
			"testEditSections(): When section header is deleted, resulting text doesn't match expected " );
	}

	public function testNewSize() {
		$t = new ModerationTestsuite();

		/* Make sure that mod_new_len is properly recalculated when editing sections */
		$title = 'Test page 1';
		$sections = array(
			"Text in zero section",
			"== First section ==\nText in first section",
		);
		$origText = join( "\n\n", $sections );

		# First, create a preloadable edit
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit( $title, $origText );

		# Now edit a section (not via API), forcing a situation when mod_new_len should be recalculated
		$req = $t->nonApiEdit( $title,
			$sections[0], /* No changes */
			'Some edit comment',
			array( 'wpSection' => '0' )
		);

		$t->fetchSpecial();

		$dbw = wfGetDB( DB_MASTER );
		$newlen = $dbw->selectField( 'moderation', 'mod_new_len',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertNotEquals( strlen( $sections[0] ), $newlen,
			"testNewSize(): Incorrect length: matches the length of the edited section" );
		$this->assertEquals( strlen( $origText ), $newlen,
			"testNewSize(): Length changed after null edit in a section" );
	}

	public function testApiEditAppend() {

		# Does api.php?action=edit&{append,prepend}text=[...] work properly?
		$t = new ModerationTestsuite();

		$todoPrepend = array( "B", "A" );
		$todoText = "C";
		$todoAppend = array( "D", "E" );

		$title = 'Test page 1';
		$expectedText = join( '', array_merge(
			array_reverse( $todoPrepend ),
			array( $todoText ),
			$todoAppend
		) );

		$t->loginAs( $t->automoderated );
		$t->doTestEdit( $title, $todoText );

		$t->loginAs( $t->unprivilegedUser );

		# Do several edits using api.php?appendtext= and api.php?prependtext=
		$query = array(
			'action' => 'edit',
			'title' => $title,
			'token' => null
		);

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

		$dbw = wfGetDB( DB_MASTER );
		$dbText = $dbw->selectField( 'moderation', 'mod_text',
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertEquals( $expectedText, $dbText,
			"testApiEditAppend(): Resulting text doesn't match expected" );


	}
}
