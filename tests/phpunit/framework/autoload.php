<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2021 Edward Chernenko.

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
 * List of testsuite-related classes for MediaWiki Autoloader.
 */

global $wgAutoloadClasses;

// phpcs:disable Generic.Files.LineLength
$wgAutoloadClasses += [
	/* Common test/benchmark methods */
	'ModerationTestHTML' => __DIR__ . '/../../common/ModerationTestHTML.php',
	'ModerationTestUtil' => __DIR__ . '/../../common/ModerationTestUtil.php',

	/* Moderation Testsuite framework */
	'IModerationTestsuiteEngine' => __DIR__ . '/engine/IModerationTestsuiteEngine.php',
	'IModerationTestsuiteResponse' => __DIR__ . '/IModerationTestsuiteResponse.php',
	'ModerationTestsuiteBagOStuff' => __DIR__ . '/ModerationTestsuiteBagOStuff.php',
	'ModerationPendingChangeTestSet' => __DIR__ . '/ModerationPendingChangeTestSet.php',
	'ModerationTestCase' => __DIR__ . '/ModerationTestCase.php',
	'ModerationTestSetRegex' => __DIR__ . '/ModerationTestSetRegex.php',
	'ModerationTestsuiteApiBot' => __DIR__ . '/bot/ModerationTestsuiteApiBot.php',
	'ModerationTestsuiteApiBotResponse' => __DIR__ . '/bot/response/ModerationTestsuiteApiBotResponse.php',
	'ModerationTestsuiteBot' => __DIR__ . '/bot/ModerationTestsuiteBot.php',
	'ModerationTestsuiteBotResponse' => __DIR__ . '/bot/response/ModerationTestsuiteBotResponseTrait.php',
	'ModerationTestsuiteEngine' => __DIR__ . '/engine/ModerationTestsuiteEngine.php',
	'ModerationTestsuiteEntry' => __DIR__ . '/ModerationTestsuiteEntry.php',
	'ModerationTestsuiteHTML' => __DIR__ . '/ModerationTestsuiteHTML.php',
	'ModerationTestsuiteLogger' => __DIR__ . '/ModerationTestsuiteLogger.php',
	'ModerationTestsuiteNonApiBot' => __DIR__ . '/bot/ModerationTestsuiteNonApiBot.php',
	'ModerationTestsuiteNonApiBotResponse' => __DIR__ . '/bot/response/ModerationTestsuiteNonApiBotResponse.php',
	'ModerationTestsuitePendingChangeTestSet' => __DIR__ . '/ModerationPendingChangeTestSet.php',
	'ModerationTestsuiteResponse' => __DIR__ . '/ModerationTestsuiteResponse.php',
	'ModerationTestsuiteTestSet' => __DIR__ . '/ModerationTestSet.php',
	'ModerationTestsuiteTestSetRegex' => __DIR__ . '/ModerationTestSetRegex.php',

	/* Completely working Engine, used for integration tests */
	'ModerationTestsuiteCliEngine' => __DIR__ . '/engine/cli/ModerationTestsuiteCliEngine.php',

	/* Deprecated Engine, incompatible with latest MediaWiki due to sandboxing of tests */
	'ModerationTestsuiteRealHttpEngine' => __DIR__ . '/engine/realhttp/ModerationTestsuiteRealHttpEngine.php'
];
// phpcs:enable
