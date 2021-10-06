<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
	'ActionTestTrait' => __DIR__ . '/trait/ActionTestTrait.php',
	'MakeEditTestTrait' => __DIR__ . '/trait/MakeEditTestTrait.php',
	'MockLoadRowTestTrait' => __DIR__ . '/trait/MockLoadRowTestTrait.php',
	'MockModerationActionTrait' => __DIR__ . '/trait/MockModerationActionTrait.php',
	'MockRevisionLookupTestTrait' => __DIR__ . '/trait/MockRevisionLookupTestTrait.php',
	'MediaWiki\\Moderation\\IsConsequenceEqual' => __DIR__ . '/trait/IsConsequenceEqual.php',
	'MediaWiki\\Moderation\\MockConsequenceManager' => __DIR__ . '/MockConsequenceManager.php',
	'ModerationUnitTestCase' => __DIR__ . '/ModerationUnitTestCase.php',
	'ModifyDbRowTestTrait' => __DIR__ . '/trait/ModifyDbRowTestTrait.php',
	'UploadTestTrait' => __DIR__ . '/trait/UploadTestTrait.php',
];
