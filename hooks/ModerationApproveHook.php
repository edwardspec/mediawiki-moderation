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
	@brief Affects doEditContent() during modaction=approve(all).

	Corrects rev_timestamp, rc_ip and checkuser logs when edit is approved.
*/

class ModerationApproveHook implements DeferrableUpdate {

	protected $useCount = 0; /**< How many times was this DeferrableUpdate queued */

	/**
		@brief Database updates that will be applied in doUpdate().
		Format: [ 'recentchanges' => [ 'rc_ip' => [ rc_id1 => '127.0.0.1', rc_id2 => '127.0.0.5', ... ], ... ]
	*/
	protected $dbUpdates = [];

	/**
		@brief List of _id fields in tables mentioned in $dbUpdates.
	*/
	protected $idFieldNames = [
		'recentchanges' => 'rc_id',
		'revision' => 'rev_id'
	];

	/**
		@brief Array of tasks which must be performed by postapprove hooks.
		Format: [ key1 => [ 'ip' => ..., 'xff' => ..., 'ua' => ... ], key2 => ... ]
	*/
	protected static $tasks = [];

	protected function __construct() {
	}

	public function newDeferrableUpdate() {
		$this->useCount ++;
		return $this;
	}

	public function onPageContentSaveComplete() {
		DeferredUpdates::addUpdate( $this->newDeferrableUpdate() );
	}

	public function doUpdate() {
		/* This DeferredUpdate is installed after every edit.
			Only the last of these updates should run, because
			all RecentChange_save hooks must be completed before it.
		*/
		if ( -- $this->useCount > 0 ) {
			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );

		foreach ( $this->dbUpdates as $table => $updates ) {
			$idFieldName = $this->idFieldNames[$table]; /* e.g. "rev_id" */
			$ids = array_keys( array_values( $updates )[0] ); /* All rev_ids/rc_ids of affected rows */

			/*
				Calculate $set (SET values for UPDATE query):
					[ 'rc_ip=(CASE rc_id WHEN 105 THEN 127.0.0.1 WHEN 106 THEN 127.0.0.5 END)' ]
					or
					[ 'rc_ip' => '127.0.0.8' ]
			*/
			$set = [];
			foreach ( $updates as $field => $whenThen ) {
				if ( $table == 'revision' && $field == 'rev_timestamp' ) {
					/*
						IMPORTANT: sometimes we DON'T update rev_timestamp
						to preserve the order of Page History.

						The situation is:
						we want to set rev_timestamp of revision A to T1,
						and revision A happened after revision B,
						and revision B has rev_timestamp=T2, with T2 > T1.

						Then if we were to update rev_timestamp of A,
						the history (which is sorted by rev_timestamp) would
						incorrectly show that A precedes B.

						What we do is:
						for each revision A ($when) we determine rev_timestamp of revision B,
						and if it's earlier than $then, then we don't update revision A.
					*/
					$res = $dbw->select(
						[
							'a' => 'revision', /* This revision, one of $ids */
							'b' => 'revision' /* Previous revision */
						],
						[
							'a.rev_id AS id',
							'b.rev_timestamp AS prev_timestamp'
						],
						[
							'a.rev_id' => $ids
						],
						__METHOD__,
						[],
						[
							'b' => [ 'INNER JOIN', [
								'b.rev_id=a.rev_parent_id'
							] ]
						]
					);
					foreach ( $res as $row ) {
						if ( $row->prev_timestamp > $whenThen[$row->id] ) {
							/* Skip this revision,
								because updating its timestamp would be
								resulting in incorrect order of history. */
							unset( $whenThen[$row->id] );
						}
					}
				}

				if ( empty( $whenThen ) ) {
					/* Nothing to do.
						This can happen when we skip rev_timestamp update (see above) */
					continue;
				}

				if ( count( array_count_values( $whenThen ) ) == 1 ) {
					/* There is only one unique value after THEN,
						therefore WHEN...THEN is unnecessary */
					$val = array_pop( $whenThen );
					$set[$field] = $val;
				}
				else {
					/* Need WHEN...THEN conditional */
					$caseSql = '';
					foreach ( $whenThen as $when => $then ) {
						$caseSql .=
						'WHEN ' .
						$dbw->addQuotes( $when ) .
						' THEN ' .
						$dbw->addQuotes( $then ) .
						' ';
					}

					$set[] = $field . '=(CASE ' . $idFieldName . ' ' . $caseSql . 'END)';
				}
			}

			if ( empty( $set ) ) {
				continue; /* Nothing to do */
			}

			$dbw->update( $table,
				$set,
				[ $idFieldName => $ids ],
				__METHOD__
			);
		}

		$dbw->endAtomic( __METHOD__ );
	}

	protected static $lastRevId = null; /**< Revid of the last edit, populated in onNewRevisionFromEditComplete */

	/** @brief Returns revid of the last edit */
	public static function getLastRevId() {
		return self::$lastRevId;
	}

	/**
		@brief NewRevisionFromEditComplete hook.
		Here we determine $lastRevId.
	*/
	public function onNewRevisionFromEditComplete( $article, $rev, $baseID, $user ) {
		/* Remember ID of this revision for getLastRevId() */
		self::$lastRevId = $rev->getId();
		return true;
	}

	/**
		@brief Calculate key in $tasks array for $title/$username/$type triplet.
		@param $type mod_type of this change.
	*/
	protected static function getTaskKey( Title $title, $username, $type ) {
		return join( '[', /* Symbol "[" is not allowed in both titles and usernames */
			[
				$username,
				$title->getNamespace(),
				$title->getDBKey(),
				$type
			]
		);
	}

	/**
		@brief Find the task regarding edit by $username on $title.
		@param type One of ModerationNewChange::MOD_TYPE_* values.
		@returns [ 'ip' => ..., 'xff' => ..., 'ua' => ..., ... ]
	*/
	public function getTask( Title $title, $username, $type ) {
		$key = self::getTaskKey( $title, $username, $type );
		return isset( self::$tasks[$key] ) ? self::$tasks[$key] : false;
	}

	/**
		@brief Find the entry in $tasks about change $rc.
	*/
	public function getTaskByRC( RecentChange $rc ) {
		$type = ModerationNewChange::MOD_TYPE_EDIT;
		if ( $rc->mAttribs['rc_log_action'] == 'move' ) {
			$type = ModerationNewChange::MOD_TYPE_MOVE;
		}

		return $this->getTask(
			$rc->getTitle(),
			$rc->mAttribs['rc_user_text'],
			$type
		);
	}

	/*
		onCheckUserInsertForRecentChange()
		This hook is temporarily installed when approving the edit.

		It modifies the IP, user-agent and XFF in the checkuser database,
		so that they match the user who made the edit, not the moderator.
	*/
	public function onCheckUserInsertForRecentChange( $rc, &$fields ) {
		$task = $this->getTaskByRC( $rc );
		if ( !$task ) {
			return true;
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

	/**
		@brief Schedule post-approval UPDATE SQL query.
		@param $table Name of table, e.g. 'revision'.
		@param $ids ID (integer, e.g. rev_id or rc_id) or array of IDs.
		@param $values New values, as expected by $db->update(), e.g. [ 'rc_ip' => '1.2.3.4', 'rc_something' => '...' ].
	*/
	public function queueUpdate( $table, $ids, array $values ) {
		if ( !is_array( $ids ) ) {
			$ids = [ $ids ];
		}

		if ( !isset( $this->dbUpdates[$table] ) ) {
			$this->dbUpdates[$table] = [];
		}

		foreach ( $values as $field => $value ) {
			if ( !isset( $this->dbUpdates[$table][$field] ) ) {
				$this->dbUpdates[$table][$field] = [];
			}

			foreach ( $ids as $id ) {
				$this->dbUpdates[$table][$field][$id] = $value;
			}
		}
	}

	/*
		onRecentChange_save()
		This hook is temporarily installed when approving the edit.

		It modifies the IP in the recentchanges table,
		so that it matches the user who made the edit, not the moderator.
	*/
	public function onRecentChange_save( &$rc ) {
		global $wgPutIPinRC;

		$task = $this->getTaskByRC( $rc );
		if ( !$task ) {
			return true;
		}

		if ( $wgPutIPinRC ) {
			$this->queueUpdate( 'recentchanges',
				$rc->mAttribs['rc_id'],
				[ 'rc_ip' => IP::sanitizeIP( $task['ip'] ) ]
			);
		}

		/* Fix rev_timestamp to be equal to mod_timestamp
			(time when edit was queued, i.e. made by the user)
			instead of current time (time of approval). */
		$revIdsToModify = [
			 $rc->mAttribs['rc_this_oldid']
		];
		if ( $rc->mAttribs['rc_log_action'] == 'move' ) {
			/* When page A is moved to B, there will be
				only one row in the recentchanges table ($rc),
				but there are actually TWO revisions:
				(1) rc_this_oldid - null revision in B,
				(2) newly created redirect in A.
				We need to modify both of them.
			*/
			if ( $rc->getParam( '5::noredir' ) == 0 ) {
				/* Redirect exists */
				$redirectTitle = $rc->getTitle();
				$revIdsToModify[] = $redirectTitle->getLatestRevID();
			}
		}

		$this->queueUpdate( 'revision',
			$revIdsToModify,
			[ 'rev_timestamp' => $task['timestamp'] ]
		);

		if ( $task['tags'] ) {
			/* Add tags assigned by AbuseFilter, etc. */
			ChangeTags::addTags(
				explode( "\n", $task['tags'] ),
				$rc->mAttribs['rc_id'],
				$rc->mAttribs['rc_this_oldid'],
				$rc->mAttribs['rc_logid'],
				null,
				$rc
			);
		}

		return true;
	}

	/**
		@brief Prepare the approve hook. Called before doEditContent().
	*/
	public static function install( Title $title, User $user, $type, array $task ) {
		$key = self::getTaskKey( $title, $user->getName(), $type );
		self::$tasks[$key] = $task;

		static $installed = false;
		if ( !$installed ) {
			global $wgHooks;

			$hook = new self;
			$wgHooks['CheckUserInsertForRecentChange'][] = $hook;
			$wgHooks['RecentChange_save'][] = $hook;
			$wgHooks['NewRevisionFromEditComplete'][] = $hook;
			$wgHooks['PageContentSaveComplete'][] = $hook;

			$installed = true;
		}
	}
}
