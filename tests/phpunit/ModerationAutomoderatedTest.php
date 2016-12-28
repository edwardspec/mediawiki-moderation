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
	@brief Ensures that automoderated users can bypass moderation.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers ModerationCanSkip
*/
class ModerationTestAutomoderated extends MediaWikiTestCase
{
	public function testAutomoderated() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->automoderated );

		$t->editViaAPI = true;
		$ret = $t->doTestEdit();

		$t->fetchSpecial();

		$this->assertArrayHasKey( 'edit', $ret );
		$this->assertEquals( 'Success', $ret['edit']['result'] );

		$this->assertCount( 0, $t->new_entries,
			"testAutomoderated(): Something was added into Pending folder" );

	}
}
