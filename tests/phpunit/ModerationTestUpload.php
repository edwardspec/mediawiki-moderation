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
	@brief Ensures that uploads are intercepted by Extension:Moderation.
*/

require_once(__DIR__ . "/../ModerationTestsuite.php");

/**
	@covers ModerationUploadHooks
*/
class ModerationTestUpload extends MediaWikiTestCase
{
	/**
		@requires extension curl
		@note Only cURL version of MWHttpRequest supports uploads.
	*/

	public function testUpload() {
		$t = new ModerationTestsuite();

		$t->loginAs($t->unprivilegedUser);
		$error = $t->doTestUpload();
		$this->assertEquals('(moderation-image-queued)', $error);
	}
}
