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
 * Hooks that are only needed for moderators.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\EntryFactory;

class ModerationNotifyModerator {
	/** @var LinkRenderer */
	protected $linkRenderer;

	/** @var EntryFactory */
	protected $entryFactory;

	/** @var BagOStuff */
	protected $cache;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param EntryFactory $entryFactory
	 * @param BagOStuff $cache
	 */
	public function __construct( LinkRenderer $linkRenderer, EntryFactory $entryFactory,
		BagOStuff $cache
	) {
		$this->linkRenderer = $linkRenderer;
		$this->entryFactory = $entryFactory;
		$this->cache = $cache;
	}

	/**
	 * Name of our hook that runs third-party handlers of GetNewMessagesAlert hook,
	 * but only for "You have new messages" and NOT for "new edits are pending moderation".
	 * See install() for details.
	 */
	const SAVED_HOOK_NAME = 'Moderation__SavedGetNewMessagesAlert';

	/**
	 * BeforeInitialize hook.
	 * Here we install GetNewMessagesAlert hook and prevent Extension:Echo from suppressing it.
	 * @param Title &$title
	 * @param mixed &$unused
	 * @param OutputPage &$out
	 * @param User &$user
	 * @return true
	 */
	public static function onBeforeInitialize( &$title, &$unused, &$out, &$user ) {
		$notifyModerator = MediaWikiServices::getInstance()->getService( 'Moderation.NotifyModerator' );
		$notifyModerator->considerInstall( $user, $title );

		return true;
	}

	/**
	 * GetNewMessagesAlert hook. This hook is installed dynamically (NOT via extension.json).
	 * Shows in-wiki notification "new edits are pending moderation" to moderators.
	 * @param string &$newMessagesAlert
	 * @param array $newtalks
	 * @param User $user
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onGetNewMessagesAlert(
		&$newMessagesAlert,
		array $newtalks,
		User $user,
		OutputPage $out
	) {
		$notifyModerator = MediaWikiServices::getInstance()->getService( 'Moderation.NotifyModerator' );
		return $notifyModerator->runHookInternal( $newMessagesAlert, $newtalks, $user, $out );
	}

	/**
	 * Install GetNewMessagesAlert hook if "new edits are pending moderation" should be shown.
	 * @param User $user
	 * @param Title $title
	 */
	protected function considerInstall( User $user, Title $title ) {
		if ( !$user->isAllowed( 'moderation' ) ) {
			return; /* Not a moderator */
		}

		if ( $title->isSpecial( 'Moderation' ) ) {
			return; /* No need to show on Special:Moderation */
		}

		/* Determine the most recent mod_timestamp of pending edit */
		$pendingTime = $this->getPendingTime();
		if ( !$pendingTime ) {
			return; /* No pending changes */
		}

		/*
			Determine if $user visited Special:Moderation after $pendingTime.

			NOTE: $seenTime being false means that moderator hasn't visited
			Special:Moderation for 7 days, so we always notify.
		*/
		$seenTime = $this->getSeen( $user );
		if ( $seenTime && $seenTime >= $pendingTime ) {
			return; /* No new changes appeared after this moderator last visited Special:Moderation */
		}

		$this->install();
	}

	/**
	 * Install GetNewMessagesAlert hook. Prevent other handlers from interfering.
	 */
	protected function install() {
		global $wgHooks;

		// Assign existing handlers of GetNewMessagesAlert to SAVED_HOOK_NAME hook.
		// We will call them in onGetNewMessagesAlert if/when we show "You have new messages"
		// instead of our notification, but we won't allow them to hide/modify our notification.
		// For example, Extension:Echo aborts GetNewMessagesAlert hook (always hides the notice).
		$hookName = 'GetNewMessagesAlert';
		if ( isset( $wgHooks[$hookName] ) ) {
			$wgHooks[self::SAVED_HOOK_NAME] = $wgHooks[$hookName];
			$wgHooks[$hookName] = []; // Delete existing handlers
		}

		// Install our own handler.
		$handler = __CLASS__ . '::onGetNewMessagesAlert';
		Hooks::register( $hookName, $handler );
	}

	/**
	 * Main logic of GetNewMessagesAlert hook.
	 * @param string &$newMessagesAlert
	 * @param array $newtalks
	 * @param User $user
	 * @param OutputPage $out
	 * @return bool
	 */
	protected function runHookInternal(
		&$newMessagesAlert,
		array $newtalks,
		User $user,
		OutputPage $out
	) {
		if ( $newtalks ) {
			// Don't suppress "You have new messages" notification, it's more important.
			// Also call the hooks suppressed in install(), e.g. hook of Extension:Echo.
			$args = [ &$newMessagesAlert, $newtalks, $user, $out ];
			return Hooks::run( self::SAVED_HOOK_NAME, $args );
		}

		/* Need to notify */
		$newMessagesAlert .= $this->linkRenderer->makeLink(
			SpecialPage::getTitleFor( 'Moderation' ),
			$out->msg( 'moderation-new-changes-appeared' )->plain()
		);
		return true;
	}

	/**
	 * Returns memcached key used by getPendingTime()/setPendingTime()
	 * @return string
	 */
	protected function getPendingCacheKey() {
		return $this->cache->makeKey( 'moderation-newest-pending-timestamp' );
	}

	/**
	 * Returns most recent mod_timestamp of pending edit.
	 * @return string
	 */
	protected function getPendingTime() {
		$cacheKey = $this->getPendingCacheKey();

		$result = $this->cache->get( $cacheKey );
		if ( $result === false ) { /* Not found in the cache */
			$result = $this->getPendingTimeUncached();
			if ( !$result ) {
				/* Situation "there are no pending edits" must also be cached */
				$result = 0;
			}

			// 24 hours, but can be explicitly renewed by setPendingTime()
			$this->cache->set( $cacheKey, $result, 86400 );
		}

		return $result;
	}

	/**
	 * Uncached version of getPendingTime(). Shouldn't be used outside of getPendingTime().
	 * @return string|false
	 */
	protected function getPendingTimeUncached() {
		$row = $this->entryFactory->loadRow(
			[ 'mod_rejected' => 0, 'mod_merged_revid' => 0 ],
			[ 'mod_timestamp AS timestamp' ],
			DB_REPLICA,
			[ 'USE INDEX' => 'moderation_folder_pending' ]
		);
		return $row ? $row->timestamp : false;
	}

	/**
	 * Update the cache of getPendingTime() with more actual value.
	 * @param string $newTimestamp
	 */
	public function setPendingTime( $newTimestamp ) {
		$this->cache->set( $this->getPendingCacheKey(), $newTimestamp, 86400 ); /* 24 hours */
	}

	/**
	 * Clear the cache of getPendingTime().
	 * Used instead of setPendingTime() when we don't know $newTimestamp,
	 * e.g. in modaction=rejectall.
	 */
	public function invalidatePendingTime() {
		$this->cache->delete( $this->getPendingCacheKey() );
	}

	/**
	 * Returns memcached key used by getSeen()/setSeen()
	 * @param User $user
	 * @return string
	 */
	protected function getSeenCacheKey( User $user ) {
		return $this->cache->makeKey( 'moderation-seen-timestamp', (string)$user->getId() );
	}

	/**
	 * Get newest mod_timestamp seen by $user (if known) or false.
	 * @param User $user
	 * @return string|false
	 */
	protected function getSeen( User $user ) {
		return $this->cache->get( $this->getSeenCacheKey( $user ) );
	}

	/**
	 * Remember the newest mod_timestamp seen by $user.
	 * @param User $user
	 * @param string $timestamp
	 */
	public function setSeen( User $user, $timestamp ) {
		$this->cache->set( $this->getSeenCacheKey( $user ), $timestamp, 604800 ); /* 7 days */
	}
}
