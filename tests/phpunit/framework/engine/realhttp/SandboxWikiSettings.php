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
 * This should be included into LocalSettings.php for RealHttpEngine to work.
 */

define( 'MODERATION_TESTSUITE_REALHTTP_CONFIGURED', 1 );

if ( PHP_SAPI !== 'cli' ) {
	$wgDBprefix = 'unittest_';
	$wgLanguageCode = 'qqx';

	putenv( 'ENV_TEST_CHANNEL=' . intval( $_SERVER['HTTP_X_ENV_TEST_CHANNEL'] ) );
}

// Until https://gerrit.wikimedia.org/r/#/c/mediawiki/core/+/567389/ gets merged
Http::$httpEngine = 'curl';
