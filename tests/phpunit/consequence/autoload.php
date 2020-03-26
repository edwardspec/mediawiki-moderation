<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * List of classes used by Consequence tests (these classes are provided to MediaWiki Autoloader).
 */

global $wgAutoloadClasses;

$wgAutoloadClasses += [
	'ModerationTestHTML' => __DIR__ . '/../../common/ModerationTestHTML.php',
	'ModerationTestUtil' => __DIR__ . '/../../common/ModerationTestUtil.php',
	'ConsequenceTestTrait' => __DIR__ . '/ConsequenceTestTrait.php',
	'MakeEditTestTrait' => __DIR__ . '/MakeEditTestTrait.php',
	'ModerationUnitTestCase' => __DIR__ . '/ModerationUnitTestCase.php',
	'ModifyDbRowTestTrait' => __DIR__ . '/ModifyDbRowTestTrait.php',
	'UploadTestTrait' => __DIR__ . '/UploadTestTrait.php',
];
