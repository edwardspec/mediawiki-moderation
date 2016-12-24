<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2016 Edward Chernenko.

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
	@brief Affects doEditContent() during modaction=approve(all).

	Corrects rev_timestamp, rc_ip and checkuser logs when edit is approved.
*/

class ModerationApproveHook {

	const REVID_UNKNOWN = 'LAST'; /**< Key for $tasks which means "revid doesn't exist yet", e.g. before doEditContent() */

	/**
		@brief Array of tasks which must be performed by postapprove hooks.
		Format: array( rev_id1 => array( 'ip' => ..., 'xff' => ..., 'ua' => ... ), rev_id2 => ... )
	*/
	protected static $tasks = array();

	protected static $lastRevId = null; /**< Revid of the last edit, populated in onNewRevisionFromEditComplete */

	/** @brief Convenience function, returns revid of the last edit */
	public static function getLastRevId() {
		return self::$lastRevId;
	}

	/**
		@brief Find the entry in $tasks about change $revid.
		@returns array( 'ip' => ..., 'xff' => ..., 'ua' => ..., ... )
		@retval false Not found.
	*/
	public function getTask( $revid ) {
		$try_keys = array(
			$revid,

			/* In case the hook was triggered by doEditContent() immediately, without DeferredUpdate */
			self::REVID_UNKNOWN
		);

		foreach ( $try_keys as $key ) {
			if ( isset( self::$tasks[$key] ) ) {
				return self::$tasks[$key];
			}
		}

		return false;
	}

	/*
		onCheckUserInsertForRecentChange()
		This hook is temporarily installed when approving the edit.

		It modifies the IP, user-agent and XFF in the checkuser database,
		so that they match the user who made the edit, not the moderator.
	*/
	public function onCheckUserInsertForRecentChange( $rc, &$fields ) {
		$task = $this->getTask( $rc->mAttribs['rc_this_oldid'] );
		if ( !$task ) {
			return true; /* Nothing to do */
		}

		$fields['cuc_ip'] = IP::sanitizeIP( $task['ip'] );
		$fields['cuc_ip_hex'] = $task['ip'] ? IP::toHex( $task['ip'] ) : null;
		$fields['cuc_agent'] = $task['ua'];

		if ( method_exists( 'CheckUserHooks', 'getClientIPfromXFF' ) ) {
			list( $xff_ip, $isSquidOnly ) = CheckUserHooks::getClientIPfromXFF( $task['xff'] );

			$fields['cuc_xff'] = !$isSquidOnly ? $task['xff'] : '';
			$fields['cuc_xff_hex'] = ( $xff_ip && !$isSquidOnly ) ? IP::toHex( $xff_ip ) : null;
		} else {
			$fields['cuc_xff'] = '';
			$fields['cuc_xff_hex'] = null;
		}

		return true;
	}

	/*
		onRecentChange_save()
		This hook is temporarily installed when approving the edit.

		It modifies the IP in the recentchanges table,
		so that it matches the user who made the edit, not the moderator.
	*/
	public function onRecentChange_save( &$rc ) {
		global $wgPutIPinRC;
		if ( !$wgPutIPinRC ) {
			return true;
		}

		$task = $this->getTask( $rc->mAttribs['rc_this_oldid'] );
		if ( !$task ) {
			return true; /* Nothing to do */
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'recentchanges',
			array(
				'rc_ip' => IP::sanitizeIP( $task['ip'] )
			),
			array( 'rc_id' => $rc->mAttribs['rc_id'] ),
			__METHOD__
		);

		return true;
	}

	/*
		onNewRevisionFromEditComplete()

		Here we replace REVID_UNKNOWN in $tasks and fix rev_timestamp.
	*/
	public function onNewRevisionFromEditComplete( $article, $rev, $baseID, $user ) {
		/* Remember ID of this revision for getLastRevId() */
		self::$lastRevId = $rev->getId();

		/* Replace REVID_UNKNOWN in $tasks */
		if ( isset( self::$tasks[self::REVID_UNKNOWN] )) {
			self::$tasks[self::$lastRevId] = self::$tasks[self::REVID_UNKNOWN];
			unset( self::$tasks[self::REVID_UNKNOWN] );
		}

		/* Modify rev_timestamp in the newly created revision */
		$task = $this->getTask( self::$lastRevId );
		if ( $task ) {
			$dbw = wfGetDB( DB_MASTER );
			$dbw->update( 'revision',
				$task['revisionUpdate'],
				array( 'rev_id' => self::$lastRevId ),
				__METHOD__
			);
		}

		return true;
	}

	/**
		@brief Prepare the approve hook. Called before doEditContent().
	*/
	public static function install( array $task ) {
		/* At this point we don't yet know the revid, so we use REVID_UNKNOWN */
		self::$tasks[self::REVID_UNKNOWN] = $task;

		static $installed = false;
		if ( !$installed ) {
			global $wgHooks;

			$hook = new self;
			$wgHooks['CheckUserInsertForRecentChange'][] = $hook;
			$wgHooks['RecentChange_save'][] = $hook;

			/* This hook will replace REVID_UNKNOWN with revision ID */
			$wgHooks['NewRevisionFromEditComplete'][] = $hook;

			$installed = true;
		}
	}
}
