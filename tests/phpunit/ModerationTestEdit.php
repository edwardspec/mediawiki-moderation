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
		$row = $dbw->selectRow( 'moderation',
			array( 'mod_text AS text' ),
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertNotEquals( $text, $row->text,
			"testPreSaveTransform(): Signature (~~~~) hasn't been properly substituted." );
	}

	public function testEditSections() {
		$t = new ModerationTestsuite();

		# Note: we must do more than one edit here,
		# because sections-related code in ModerationEditHooks is only
		# used when user makes more than one edit to the same page.
		#
		# On the first edit, mod_text doesn't exist and therefore
		# doesn't need to be corrected.
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
		$row = $dbw->selectRow( 'moderation',
			array( 'mod_text AS text' ),
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$expected_text = join( '', $sections );
		$this->assertEquals( $expected_text, $row->text,
			"testEditSections(): Resulting text doesn't match expected" );

		# Does PreSaveTransform work when editing sections?

		$t->loginAs( $t->unprivilegedUser );
		$query['section'] = 2;
		$query['text'] = "== New section 2 ==\n~~~\n\n";
		$ret = $t->query( $query );

		$row = $dbw->selectRow( 'moderation',
			array( 'mod_text AS text' ),
			array( 'mod_id' => $t->new_entries[0]->id ),
			__METHOD__
		);

		$this->assertNotRegExp( '/~~~/', $row->text,
			"testEditSections(): Signature (~~~~) hasn't been properly substituted." );


	}
}
