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
 * @brief Hooks that are only needed for moderators.
 */

class ModerationNotifyModerator {

	/*
		onGetNewMessagesAlert()
		Show in-wiki notification "new edits are pending moderation" to moderators.
	*/
	public static function onGetNewMessagesAlert(
		&$newMessagesAlert,
		array $newtalks,
		User $user,
		OutputPage $out
	) {
		if ( $newtalks ) {
			return true; /* Don't suppress "You have new messages" notification, it's more important */
		}

		if ( !$user->isAllowed( 'moderation' ) ) {
			return true; /* Not a moderator */
		}

		if ( $out->getTitle()->isSpecial( 'Moderation' ) ) {
			return true; /* No need to show on Special:Moderation */
		}

		/* Determine the most recent mod_timestamp of pending edit */
		$pendingTime = self::getPendingTime();
		if ( !$pendingTime ) {
			return true; /* No pending changes */
		}

		/*
			Determine if $user visited Special:Moderation after $pendingTime.

			NOTE: $seenTime being false means that moderator hasn't visited
			Special:Moderation for 7 days, so we always notify.
		*/
		$seenTime = self::getSeen( $user );
		if ( $seenTime && $seenTime >= $pendingTime ) {
			return true; /* No new changes appeared after this moderator last visited Special:Moderation */
		}

		/* Need to notify */
		$newMessagesAlert .= Linker::link(
			SpecialPage::getTitleFor( 'Moderation' ),
			$out->msg( 'moderation-new-changes-appeared' )->plain()
		);
		return true;
	}

	/** @brief Returns memcached key used by getPendingTime()/setPendingTime() */
	protected static function getPendingCacheKey() {
		return wfMemcKey( 'moderation-newest-pending-timestamp' );
	}

	/** @brief Returns most recent mod_timestamp of pending edit */
	protected static function getPendingTime() {
		$cache = wfGetMainCache();
		$cacheKey = self::getPendingCacheKey();

		$result = $cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = self::getPendingTimeUncached();
			if ( !$result ) {
				/* Situation "there are no pending edits" must also be cached */
				$result = 0;
			}

			// 24 hours, but can be explicitly renewed by setPendingTime()
			$cache->set( $cacheKey, $result, 86400 );
		}

		return $result;
	}

	/** @brief Uncached version of getPendingTime(). Shouldn't be used outside of getPendingTime() */
	protected static function getPendingTimeUncached() {
		$dbr = wfGetDB( DB_REPLICA );
		return $dbr->selectField( 'moderation', 'mod_timestamp',
			[
				'mod_rejected' => 0,
				'mod_merged_revid' => 0
			],
			__METHOD__,
			[ 'USE INDEX' => 'moderation_folder_pending' ]
		);
	}

	/** @brief Update the cache of getPendingTime() with more actual value. */
	public static function setPendingTime( $newTimestamp ) {
		$cache = wfGetMainCache();
		$cache->set( self::getPendingCacheKey(), $newTimestamp, 86400 ); /* 24 hours */
	}

	/**
	 * @brief Clear the cache of getPendingTime().
		Used instead of setPendingTime() when we don't know $newTimestamp,
		e.g. in modaction=rejectall.
	 */
	public static function invalidatePendingTime() {
		$cache = wfGetMainCache();
		$cache->delete( self::getPendingCacheKey() );
	}

	/** @brief Returns memcached key used by getSeen()/setSeen() */
	protected static function getSeenCacheKey( User $user ) {
		return wfMemcKey( 'moderation-seen-timestamp', $user->getId() );
	}

	/**
	 * @brief Get newest mod_timestamp seen by $user.
	 * @retval false Unknown.
	 */
	protected static function getSeen( User $user ) {
		$cache = wfGetMainCache();
		return $cache->get( self::getSeenCacheKey( $user ) );
	}

	/**
	 * @brief Remember the newest mod_timestamp seen by $user.
	 */
	public static function setSeen( User $user, $timestamp ) {
		$cache = wfGetMainCache();
		$cache->set( self::getSeenCacheKey( $user ), $timestamp, 604800 ); /* 7 days */
	}
}
