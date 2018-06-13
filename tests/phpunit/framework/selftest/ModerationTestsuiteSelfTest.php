<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@brief Tests the ModerationTestsuite itself.

	Used for debugging subclasses of ModerationTestsuiteEngine.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );


class ModerationTestsuiteSelfTest extends MediaWikiTestCase
{
	/**
		@covers ModerationTestsuiteEngine::executeHttpRequest
	*/
	public function testEngineNonApi() {
		$t = new ModerationTestsuite();
		$title = 'Test page 1';

		$req = $t->httpPost( wfScript( 'index' ), [
			'action' => 'edit',
			'title' => $title
		] );

		$this->assertEquals( 200, $req->getStatus() );

		$html = $t->html->loadFromReq( $req );

		/* Ensure that this is indeed an edit form */
		$this->assertStringStartsWith(
			wfMessage( 'creating', $title )->text(),
			$html->getTitle()
		);
		$this->assertNotNull( $html->getElementById( 'wpSave' ), 'testEngineNonApi(): "Save" button not found.' );
	}

}

