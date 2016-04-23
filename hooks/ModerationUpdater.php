<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@brief Creates/updates the SQL tables when 'update.php' is invoked.
*/

class ModerationUpdater {
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$base = dirname( __FILE__ );

		$db = $updater->addExtensionTable( 'moderation', "$base/../sql/patch-moderation.sql" );
		$db = $updater->addExtensionTable( 'moderation_block', "$base/../sql/patch-moderation_block.sql" );
	}
}
