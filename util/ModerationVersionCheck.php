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
	@brief Functions for seamless database updates between versions.
*/

class ModerationVersionCheck {

	/** @brief Returns true if the database has mod_tags field, false otherwise */
	public static function areTagsSupported() {
		return self::wasDbUpdatedAfter( '1.1.29' );
	}

	/*-------------------------------------------------------------------*/

	const EXTENSION_NAME = 'Moderation'; /**< Name of extension (as listed in extension.json) */

	protected static $dbUpdatedVersion = null; /**< Stores result of getDbUpdatedVersion() */

	/** @brief WHERE conditions used in getDbUpdatedVersionUncached(), markDbAsUpdated() */
	protected static $where = [
		'pp_page' => -1,
		'pp_propname' => 'moderation:lastDbUpdateVersion'
	];

	/** @brief Returns memcached key used by getDbUpdatedVersion() and markDbAsUpdated() */
	protected static function getCacheKey() {
		return wfMemcKey( 'moderation-lastDbUpdateVersion' );
	}

	/**
		@brief Check if update.php was called after $versionOfModeration was installed.
		@param $versionOfModeration Version of Extension:Moderation, as listed in extension.json.
		@returns True if update.php was called, false otherwise.
	*/
	protected static function wasDbUpdatedAfter( $versionOfModeration ) {
		return version_compare( $versionOfModeration, self::getDbUpdatedVersion(), '<=' );
	}

	/**
		@brief Returns version that Moderation had during the latest invocation of update.php.
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

	/** @brief Uncached version of getDbUpdatedVersion(). Shouldn't be used outside of getDbUpdatedVersion() */
	protected static function getDbUpdatedVersionUncached() {
		$dbr = wfGetDB( DB_SLAVE );
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
		@brief Returns current version of Moderation (string).
	*/
	protected static function getVersionOfModeration() {
		global $wgExtensionCredits;
		foreach ( $wgExtensionCredits as $group => $list ) {
			foreach ( $list as $extension ) {
				if ( $extension['name'] == self::EXTENSION_NAME ) {
					return $extension['version'];
				}
			}
		}

		return '';
	}

	/**
		@brief Called from update.php. Remembers current version for further calls to wasDbUpdatedAfter().
	*/
	public static function markDbAsUpdated() {
		$fields = self::$where + [ 'pp_value' => self::getVersionOfModeration() ];

		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace( 'page_props', [ 'pp_page', 'pp_propname' ], $fields, __METHOD__ );

		/* Invalidate cache of wasDbUpdatedAfter() */
		$cache = wfGetMainCache();
		$cache->delete( self::getCacheKey() ); /* Note: won't affect CACHE_ACCEL, update.php has no access to it */
	}
}
