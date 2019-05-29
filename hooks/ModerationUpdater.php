<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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
 * Creates/updates the SQL tables when 'update.php' is invoked.
 */

class ModerationUpdater {
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$base = __DIR__;
		$dbw = $updater->getDB();

		/* Main database schema */
		$updater->addExtensionTable( 'moderation',
			"$base/../sql/patch-moderation.sql" );
		$updater->addExtensionTable( 'moderation_block',
			"$base/../sql/patch-moderation_block.sql" );

		/* DB changes needed when updating Moderation from its previous version */

		// ... to Moderation 1.1.29
		$updater->addExtensionField( 'moderation', 'mod_tags',
			"$base/../sql/patch-moderation-mod_tags.sql" );

		// ... to Moderation 1.1.31
		$updater->modifyExtensionField( 'moderation', 'mod_title',
			"$base/../sql/patch-fix-titledbkey.sql" );

		// ... to Moderation 1.2.9
		if ( $dbw->tableExists( 'moderation' ) &&
			!$dbw->indexUnique( 'moderation', 'moderation_load' )
		) {
			$updater->addExtensionUpdate( [ 'applyPatch',
				"$base/../sql/patch-make-preload-unique.sql", true ] );
		}

		// ... to Moderation 1.2.17
		$updater->addExtensionField( 'moderation', 'mod_type',
			"$base/../sql/patch-moderation-mod_type.sql" );

		$updater->addExtensionUpdate( [ 'ModerationVersionCheck::markDbAsUpdated' ] );
		return true;
	}
}
