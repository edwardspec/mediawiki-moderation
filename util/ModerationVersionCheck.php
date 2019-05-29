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
 * @file
 * Functions for seamless database updates between versions.
 */

class ModerationVersionCheck {

	/** Returns true if the database has mod_tags field, false otherwise */
	public static function areTagsSupported() {
		return self::wasDbUpdatedAfter( '1.1.29' );
	}

	/**
	 * True if mod_title contains underscores (correct behavior),
	 * false if mod_title contains spaces (obsolete behavior).
	 * @return bool
	 */
	public static function usesDbKeyAsTitle() {
		return self::wasDbUpdatedAfter( '1.1.31' );
	}

	/** Returns true if the database has mod_type field, false otherwise */
	public static function hasModType() {
		return self::wasDbUpdatedAfter( '1.2.17' );
	}

	/**
	 * True if field mod_preloadable is unique for rejected edits (correct behavior),
	 * false if field mod_preloadable is 0 or 1 (obsolete behavior).
	 * @return bool
	 */
	public static function hasUniqueIndex() {
		return self::wasDbUpdatedAfter( '1.2.9' );
	}

	/**
	 * Calculate mod_title for $title.
	 * Backward compatible with old Moderation databases that used spaces, not underscores.
	 */
	public static function getModTitleFor( Title $title ) {
		if ( self::usesDbKeyAsTitle() ) {
			return $title->getDBKey();
		}

		return $title->getText(); /* Legacy approach */
	}

	/**
	 * Returns value of mod_preloadable that means "YES, this change can be preloaded".
	 */
	public static function preloadableYes() {
		if ( self::hasUniqueIndex() ) {
			/* Current approach: 0 for YES, mod_id for NO */
			return 0;
		}

		/* Legacy approach: 1 for YES, 0 for NO */
		return 1;
	}

	/**
	 * Determines how to mark edit as NOT preloadable in SQL UPDATE.
	 * @return string One element of $fields parameter for $db->update().
	 */
	public static function setPreloadableToNo() {
		if ( self::hasUniqueIndex() ) {
			/* Current approach: 0 for YES, mod_id for NO */
			return 'mod_preloadable=mod_id';
		}

		/* Legacy approach: 1 for YES, 0 for NO */
		return 'mod_preloadable=0';
	}

	/*-------------------------------------------------------------------*/

	/** @const string Name of extension (as listed in extension.json) */
	const EXTENSION_NAME = 'Moderation';

	/** @var string|null Local cache used by getDbUpdatedVersion() */
	protected static $dbUpdatedVersion = null;

	/** @var array WHERE conditions used in getDbUpdatedVersionUncached(), markDbAsUpdated() */
	protected static $where = [
		'pp_page' => -1,
		'pp_propname' => 'moderation:lastDbUpdateVersion'
	];

	/** Returns memcached key used by getDbUpdatedVersion() and markDbAsUpdated() */
	protected static function getCacheKey() {
		return wfMemcKey( 'moderation-lastDbUpdateVersion' );
	}

	/**
	 * Check if update.php was called after $versionOfModeration was installed.
	 * @param string $versionOfModeration Version of Extension:Moderation, as listed in extension.json.
	 * @return bool True if update.php was called, false otherwise.
	 */
	protected static function wasDbUpdatedAfter( $versionOfModeration ) {
		return version_compare( $versionOfModeration, self::getDbUpdatedVersion(), '<=' );
	}

	/**
	 * Returns version that Moderation had during the latest invocation of update.php.
	 */
	protected static function getDbUpdatedVersion() {
		if ( self::$dbUpdatedVersion ) {
			/* Already known, no need to look in Memcached */
			return self::$dbUpdatedVersion;
		}

		$cache = wfGetMainCache();
		$cacheKey = self::getCacheKey();

		$result = $cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = self::getDbUpdatedVersionUncached();
			$cache->set( $cacheKey, $result, 86400 ); /* 24 hours */
		}

		self::$dbUpdatedVersion = $result;
		return $result;
	}

	/**
	 * Uncached version of getDbUpdatedVersion().
	 * @note Shouldn't be used outside of getDbUpdatedVersion()
	 */
	protected static function getDbUpdatedVersionUncached() {
		$dbr = wfGetDB( DB_REPLICA );
		$version = $dbr->selectField( 'page_props', 'pp_value', self::$where, __METHOD__ );

		if ( !$version ) {
			/* Assume that update.php hasn't been called for a very long time.
				This will disable all features that check wasDbUpdatedAfter().
			*/
			$version = '1.0.0';
		}

		return $version;
	}

	/**
	 * Returns current version of Moderation (string).
	 */
	protected static function getVersionOfModeration() {
		$status = FormatJson::parse( file_get_contents( __DIR__ . "/../extension.json" ) );
		if ( $status->isOK() ) {
			$extensionInfo = $status->getValue();
			return $extensionInfo->version;
		}

		return '';
	}

	/**
	 * Remember the current version of Moderation for use in wasDbUpdatedAfter().
	 * Called from update.php.
	 */
	public static function markDbAsUpdated() {
		$fields = self::$where + [ 'pp_value' => self::getVersionOfModeration() ];

		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace( 'page_props', [ 'pp_page', 'pp_propname' ], $fields, __METHOD__ );

		/* Invalidate cache of wasDbUpdatedAfter()
			Note: won't affect CACHE_ACCEL, update.php has no access to it */
		$cache = wfGetMainCache();
		$cache->delete( self::getCacheKey() );
	}
}
