<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
 * Additional LocalSettings.php for Travis testing of Extension:Moderation.
 */

# Allow parallel runs via Fastest: each thread uses its own database.
if ( getenv( 'ENV_TEST_CHANNEL' ) ) {
	$wgDBname .= "_thread" . getenv( 'ENV_TEST_CHANNEL' );
} elseif ( getenv( 'PARALLEL_PHPUNIT_TESTS' ) && class_exists( 'MediaWikiTestCase' ) ) {
	throw new MWException( "ENV_TEST_CHANNEL not defined! Parallel testing is not possible." );
}

# URL of the testwiki (as configured in .travis.yml).
$wgServer = "http://moderation.example.com";
$wgScriptPath = "/mediawiki";
$wgArticlePath = "/wiki/$1";

# For localisation cache to not be stored in /, which is readonly on Travis.
$wgCacheDirectory = "$IP/cache";

# For upload-related tests
$wgEnableUploads = true;

# Skin prints the notice which is tested by ModerationNotifyModeratorTest
wfLoadSkin( 'Vector' );

# Object cache is needed for ModerationNotifyModeratorTest
$wgMainCacheType = CACHE_MEMCACHED;
$wgMemCachedServers = [ "127.0.0.1:11211" ];

# Don't trigger $wgRateLimits in simultaneous Selenium tests
$wgGroupPermissions['*']['noratelimit'] = true;

if ( version_compare( $wgVersion, '1.34-rc.0', '>=' ) ) {
	# Allow testsuite accounts to have "123456" as a password.
	foreach ( [ 'default', 'bureaucrat', 'sysop', 'interface-admin', 'bot' ] as $group ) {
		$wgPasswordPolicy['policies'][$group] = [];
	}
}

if ( getenv( 'MODERATION_TEST_ENGINE' ) == 'realhttp' ) {
	// phpcs:ignore Generic.Files.LineLength.TooLong
	require_once "$IP/extensions/Moderation/tests/phpunit/framework/engine/realhttp/SandboxWikiSettings.php";
}

# Extensions below are needed for some tests of Extension:Moderation.
if ( $wgDBtype != 'postgres' ) {
	// FIXME: what AbuseFilter does in its "UnitTestsAfterDatabaseSetup" and
	// "UnitTestsBeforeDatabaseTeardown" hooks interferes with CliEngine.
	// Until a workaround is found, we can't test with it.
	wfLoadExtension( 'AbuseFilter' ); # For PHPUnit testsuite
}

if ( $wgDBtype != 'postgres' ) {
	// Extension:CheckUser itself doesn't support PostgreSQL, so we can't test with it.
	// (see T241827)
	wfLoadExtension( 'CheckUser' ); # For PHPUnit testsuite
}

wfLoadExtensions( [
	# For PHPUnit testsuite
	'PageForms',

	# For Selenium testsuite
	'MobileFrontend',
	'VisualEditor'
] );

# ModerationNotifyModeratorTest should be tested with and without Extension:Echo.
# NOTE: Extension:Echo doesn't support PostgreSQL yet.
# gerrit:565045 adds such support, but it's unlikely to be backported to MediaWiki 1.31, etc.
if ( getenv( 'WITH_ECHO' ) && $wgDBtype != 'postgres' ) {
	wfLoadExtension( 'Echo' );
}

# Default skin for Extension:MobileFrontend
wfLoadSkin( 'MinervaNeue' );

# Parsoid configuration (used by Extension:VisualEditor)
$wgVirtualRestConfig['modules']['parsoid'] = [
	'url' => 'http://moderation.example.com:8142',
	'domain' => 'moderation.example.com'
];
$wgDefaultUserOptions['visualeditor-enable'] = 1; # Enable VisualEditor for all users

# Tested extension.
# Note: Moderation should always be enabled LAST in LocalSettings.php, after any other extension.
wfLoadExtension( "Moderation" );
