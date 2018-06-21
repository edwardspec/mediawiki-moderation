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
	@brief Additional LocalSettings.php for Travis testing of Extension:Moderation.
*/

$wgEnableUploads = true; # For upload-related tests
wfLoadSkin( 'Vector' ); # Skin prints the notice which is tested by ModerationNotifyModeratorTest

$wgMainCacheType = CACHE_MEMCACHED;
$wgMemCachedServers = [ "127.0.0.1:11211" ];

# These extensions are needed for some tests of Extension:Moderation
wfLoadExtensions( [
	'AbuseFilter',
	'CheckUser'
] );

# The following extensions are tested by Selenium testsuite,
# they are not needed for PHPUnit testsuite to run.
# wfLoadExtension( 'MobileFrontend' );
# wfLoadExtension( 'VisualEditor' );

# Tested extension.
# Note: Moderation must always be enabled LAST in LocalSettings.php.
wfLoadExtension( "Moderation" );
