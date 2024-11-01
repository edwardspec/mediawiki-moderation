<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2024 Edward Chernenko.

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
 * Handles update.php.
 */

use MediaWiki\Installer\SchemaChanges;

class ModerationUpdater {
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$base = dirname( __DIR__ ) . '/sql/';

		$updater->addExtensionTable( 'moderation', $base . 'patch-moderation.sql' );
		$updater->addExtensionField(
			'moderation', 'mod_type',
			$base . 'patch-moderation-mod_type.sql'
		);
		$updater->addExtensionField(
			'moderation', 'mod_tags',
			$base . 'patch-moderation-mod_tags.sql'
		);
		$updater->addExtensionIndex(
			'moderation', 'moderation_signup',
			$base . 'patch-make-preload-unique.sql'
		);

		// Fix incorrect titledbkey in existing moderation entries
		$updater->addExtensionUpdate( [
			'runMaintenance',
			'PopulateModerationDbKey',
			$base . 'patch-fix-titledbkey.sql'
		] );

		$updater->addExtensionUpdate( [
			'modifyField',
			'moderation',
			'mod_comment',
			$base . 'patch-increase-mod_comment-size.sql',
			true
		] );
	}
}
