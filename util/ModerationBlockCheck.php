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
	@file
	@brief Checks if the user is blacklisted.
*/

class ModerationBlockCheck {
	/**
		@brief Value of getBlockId() that means "not blocked".
		@note Not "false", because this result must also be cacheable.
	*/
	const NOT_BLOCKED = '';

	/** @brief Returns true if $user is blacklisted, false otherwise. */
	public static function isModerationBlocked( User $user ) {
		return ( self::getBlockId( $user ) != self::NOT_BLOCKED );
	}

	/**
		@brief Clear the cache of isModerationBlocked().
		This is used after successful modaction=block/unblock on $user.
	*/
	public static function invalidateCache( User $user ) {
		$cache = wfGetMainCache();
		$cache->delete( self::getCacheKey( $user ) );
	}

	/**
		@brief Returns mb_id of the moderation block of $user, if any.
		@retval NOT_BLOCKED $user is NOT blocked.
	*/
	protected static function getBlockId( User $user ) {
		$cache = wfGetMainCache();
		$cacheKey = self::getCacheKey( $user );

		$result = $cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = self::getBlockIdUncached( $user );
			$cache->set( $cacheKey, $result, 86400 ); /* 24 hours */
		}

		return $result;
	}

	/** @brief Uncached version of getBlockId(). Shouldn't be used outside of getBlockId() */
	protected static function getBlockIdUncached( User $user ) {
		$dbw = wfGetDB( DB_MASTER ); # Need actual data
		$id = $dbw->selectField( 'moderation_block',
			'mb_id',
			[ 'mb_address' => $user->getName() ],
			__METHOD__
		);
		return $id ? $id : self::NOT_BLOCKED;

	}

	/** @brief Returns memcached key used by getBlockId() */
	protected static function getCacheKey( User $user ) {
		return wfMemcKey( 'moderation-blockid', $user->getId() );
	}


}
