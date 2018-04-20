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
	@brief Functions for gently applying database updates between versions.
*/

class ModerationVersionCheck {

	const EXTENSION_NAME = 'Moderation'; /**< Name of extension (as listed in extension.json) */

	/** @brief WHERE conditions used in wasDbUpdatedAfter(), markDbAsUpdated() */
	protected static $where = [
		'pp_page' => -1,
		'pp_propname' => 'moderation:lastDbUpdateVersion'
	];

	/** @brief Returns memcached key used by wasDbUpdatedAfter() and markDbAsUpdated() */
	protected static function getCacheKey() {
		return wfMemcKey( 'moderation-lastDbUpdateVersion' );
	}

	/**
		@brief Check if update.php was called after $versionOfModeration was installed.
		@param $versionOfModeration Version of Extension:Moderation, as listed in extension.json.
		@returns True if update.php was called, false otherwise.
	*/
	public static function wasDbUpdatedAfter( $versionOfModeration ) {
		$cache = wfGetMainCache();
		$cacheKey = self::getCacheKey();

		$result = $cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = self::wasDbUpdatedAfterUncached();
			$cache->set( $cacheKey, $result, 86400 ); /* 24 hours */
		}

		return version_compare( $versionOfModeration, $result, '<=' );
	}

	/** @brief Uncached version of wasDbUpdatedAfter(). Shouldn't be used outside of wasDbUpdatedAfter() */
	protected static function wasDbUpdatedAfterUncached() {
		$dbr = wfGetDB( DB_SLAVE );
		return $dbr->selectField( 'page_props', 'pp_value', self::$where, __METHOD__ );
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
		$dbw->insert( 'page_props', $fields, __METHOD__ );

		/* Invalidate cache of wasDbUpdatedAfter() */
		$cache = wfGetMainCache();
		$cache->delete( self::getCacheKey() );
	}
}
