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
 * Functions for seamless database updates between versions.
 */

class ModerationVersionCheck {

	/**
	 * Returns true if the database has mod_tags field, false otherwise.
	 * @return bool
	 */
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

	/**
	 * Returns true if the database has mod_type field, false otherwise.
	 * @return bool
	 */
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
	 * @param Title $title
	 * @return string
	 */
	public static function getModTitleFor( Title $title ) {
		if ( self::usesDbKeyAsTitle() ) {
			return $title->getDBKey();
		}

		return $title->getText(); /* Legacy approach */
	}

	/**
	 * Returns value of mod_preloadable that means "YES, this change can be preloaded".
	 * @return int
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

	/**
	 * Returns memcached key used by getDbUpdatedVersion() and invalidateCache()
	 * @return string
	 */
	protected static function getCacheKey() {
		return self::getCache()->makeKey( 'moderation-lastDbUpdateVersion' );
	}

	/**
	 * Returns cache used by getDbUpdatedVersion().
	 * @return BagOStuff
	 */
	protected static function getCache() {
		return wfGetMainCache();
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
	 * @return string Version number, e.g. "1.2.3".
	 */
	protected static function getDbUpdatedVersion() {
		if ( self::$dbUpdatedVersion ) {
			/* Already known, no need to look in Memcached */
			return self::$dbUpdatedVersion;
		}

		$cache = self::getCache();
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
	 * @return string Version number, e.g. "1.2.3".
	 */
	protected static function getDbUpdatedVersionUncached() {
		$dbr = wfGetDB( DB_REPLICA );

		// These checks are sorted "most recent changes first",
		// so that the wiki with the most recent schema would only need one check.

		if ( $dbr->getType() == 'postgres' ) {
			return '1.4.12';
		}

		if ( $dbr->fieldExists( 'moderation', 'mod_type', __METHOD__ ) ) {
			return '1.2.17';
		}

		if ( $dbr->indexUnique( 'moderation', 'moderation_load', __METHOD__ ) ) {
			return '1.2.9';
		}

		if ( !$dbr->fieldExists( 'moderation', 'mod_tags', __METHOD__ ) ) {
			return '1.0.0';
		}

		$titlesWithSpace = $dbr->selectRowCount(
			'moderation',
			'*',
			[ 'mod_title LIKE "% %"' ],
			__METHOD__
		);
		return $titlesWithSpace ? '1.1.29' : '1.1.31';
	}

	/**
	 * Returns current version of Moderation (string).
	 * @return string Version number, e.g. "1.2.3".
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
	 * Invalidate cache of wasDbUpdatedAfter().
	 * Called from update.php.
	 * Note: this won't affect CACHE_ACCEL, update.php has no access to it.
	 */
	public static function invalidateCache() {
		self::getCache()->delete( self::getCacheKey() );
	}
}
