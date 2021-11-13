<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2021 Edward Chernenko.

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
 * Affects doEditContent() during modaction=approve(all).
 * Corrects rev_timestamp, rc_ip and checkuser logs when edit is approved.
 */

use MediaWiki\Hook\FileUploadHook;
use MediaWiki\Hook\PageMoveCompletingHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\RevisionFromEditCompleteHook;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;

class ModerationApproveHook implements
	FileUploadHook,
	PageMoveCompletingHook,
	PageSaveCompleteHook,
	RecentChange_saveHook,
	RevisionFromEditCompleteHook
{
	/** @var LoggerInterface */
	private $logger;

	/**
	 * @var int
	 * Counter used in onPageSaveComplete() to ensure that doUpdate() is called only once.
	 */
	protected $useCount = 0;

	/**
	 * @var array Database updates that will be applied in doUpdate().
	 * Format: [ 'recentchanges' => [ 'rc_ip' => [ rc_id1 => '127.0.0.1',  ... ], ... ], ... ]
	 */
	protected $dbUpdates = [];

	/** @var array List of _id fields in tables mentioned in $dbUpdates. */
	protected $idFieldNames = [
		'recentchanges' => 'rc_id',
		'revision' => 'rev_id'
	];

	/**
	 * @var array Tasks which must be performed by postapprove hooks.
	 * Format: [ key1 => [ 'ip' => ..., 'xff' => ..., 'ua' => ... ], key2 => ... ]
	 */
	protected $tasks = [];

	/**
	 * @var array Log entries to modify in FileUpload hook.
	 * Format: [ log_id1 => ManualLogEntry, log_id2 => ... ]
	 *
	 * @phan-var array<int,ManualLogEntry>
	 */
	protected $logEntriesToFix = [];

	/**
	 * @param LoggerInterface $logger
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Used in extension.json to obtain this service as HookHandler.
	 * @return ModerationApproveHook
	 */
	public static function hookHandlerFactory() {
		return MediaWikiServices::getInstance()->getService( 'Moderation.ApproveHook' );
	}

	/**
	 * PageSaveComplete hook.
	 * @param WikiPage $wikiPage @phan-unused-param
	 * @param UserIdentity $user @phan-unused-param
	 * @param string $summary @phan-unused-param
	 * @param int $flags @phan-unused-param
	 * @param RevisionRecord $revisionRecord @phan-unused-param
	 * @param EditResult $editResult @phan-unused-param
	 * @return bool|void
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		$this->scheduleDoUpdate();
	}

	/**
	 * PageMoveCompleting hook.
	 * Here we modify rev_timestamp of a newly created redirect after the page move.
	 * @param LinkTarget $oldTitle
	 * @param LinkTarget $newTitle @phan-unused-param
	 * @param UserIdentity $user
	 * @param int $pageid @phan-unused-param
	 * @param int $redirid @phan-unused-param
	 * @param string $reason @phan-unused-param
	 * @param RevisionRecord $revision @phan-unused-param
	 * @return bool|void
	 */
	public function onPageMoveCompleting( $oldTitle, $newTitle, $user, $pageid, $redirid, $reason, $revision ) {
		$task = $this->getTask( $oldTitle, $user->getName(), ModerationNewChange::MOD_TYPE_MOVE );
		if ( !$task ) {
			return;
		}

		$revid = Title::newFromLinkTarget( $oldTitle )->getLatestRevID();
		if ( $revid ) {
			// Redirect was created. Its timestamp should also be modified.
			$dbr = wfGetDB( DB_REPLICA );
			$timestamp = $dbr->timestamp( $task['timestamp'] ); // Possibly in PostgreSQL format

			$this->queueUpdate( 'revision', [ $revid ], [ 'rev_timestamp' => $timestamp ] );
		}

		$this->scheduleDoUpdate();
	}

	/**
	 * Schedule doUpdate() to run after all other DeferredUpdates that are caused by new edits.
	 */
	public function scheduleDoUpdate() {
		$this->useCount ++;
		DeferredUpdates::addCallableUpdate( [ $this, 'doUpdate' ] );
	}

	/**
	 * Run reallyDoUpdate() if this is the last DeferredUpdate of this kind.
	 */
	public function doUpdate() {
		/* This DeferredUpdate is installed after every edit.
			Only the last of these updates should run, because
			all RecentChange_save hooks must be completed before it.
		*/
		if ( --$this->useCount > 0 ) {
			return;
		}

		$this->reallyDoUpdate();
	}

	/**
	 * Correct rev_timestamp, rc_ip and other fields (as requested by queueUpdate()).
	 */
	protected function reallyDoUpdate() {
		if ( !$this->dbUpdates ) {
			// There are no updates.
			return;
		}

		$this->logger->debug( "[ApproveHook] Running DB updates: {updates}", [
			'updates' => FormatJson::encode( $this->dbUpdates )
		] );

		$dbw = wfGetDB( DB_MASTER );

		// In MediaWiki 1.35, PostgreSQL used CIDR field for rc_ip (we can't insert strings into it).
		// In MediaWiki 1.36+, rc_ip is TEXT field and requires no special handling.
		$isIpFieldCIDR = ( $dbw->getType() == 'postgres' && version_compare( MW_VERSION, '1.36.0', '<' ) );

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
				$skippedIds = 0;
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
							'b.rev_id AS prev_id',
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

					$prevTimestamps = [];
					foreach ( $res as $row ) {
						$prevTimestamps[$row->id] = [ $row->prev_id, $row->prev_timestamp ];
					}

					// Check earlier timestamps first (see below).
					asort( $whenThen );
					foreach ( $whenThen as $id => $newTimestamp ) {
						if ( !isset( $prevTimestamps[$id] ) ) {
							// Page doesn't exist yet, so $newTimestamp clearly doesn't need to be ignored.
							continue;
						}

						list( $prevId, $prevTimestamp ) = $prevTimestamps[$id];

						if ( isset( $whenThen[$prevId] ) && $whenThen[$prevId] != 'rev_timestamp' ) {
							// If we are here, than means ApproveHook also wants to change rev_timestamp of
							// the previous revision too.
							// Because $whenThen is sorted by timestamp (from older to newer),
							// we already checked this revision and decided not to ignore its timestamp.
							$prevTimestamp = $whenThen[$prevId];
						}

						if ( $prevTimestamp > $newTimestamp ) {
							$this->logger->info(
								"[ApproveHook] Decided not to set rev_timestamp={timestamp} for revision #{revid}, " .
								"because previous revision has {prev_timestamp} (which is newer).",
								[
									'revid' => $id,
									'timestamp' => $newTimestamp,
									'prev_timestamp' => $prevTimestamp
								]
							);

							/* Don't modify timestamp of this revision,
								because doing so would be resulting
								in incorrect order of history. */
							$whenThen[$id] = 'rev_timestamp';
							$skippedIds++;
						}
					}
				}

				if ( count( $ids ) == $skippedIds ) {
					/* Nothing to do:
						we decided to skip rev_timestamp update for all rows. */
					continue;
				}

				if ( count( array_count_values( $whenThen ) ) == 1 ) {
					/* There is only one unique value after THEN,
						therefore WHEN...THEN is unnecessary */
					$val = array_pop( $whenThen );
					$set[$field] = $val;
				} else {
					/* Need WHEN...THEN conditional */
					$caseSql = '';
					foreach ( $whenThen as $when => $then ) {
						$whenQuoted = $dbw->addQuotes( $when );

						if ( $then == 'rev_timestamp' ) {
							// Default value for rev_timestamp=(CASE ... ) when certain rows were skipped:
							// leave the previous value of rev_timestamp unchanged.
							$thenQuoted = 'rev_timestamp';
						} else {
							$thenQuoted = $dbw->addQuotes( $then );

							if ( $isIpFieldCIDR && $field == 'rc_ip' ) {
								// PostgreSQL, MediaWiki 1.35 only.
								$thenQuoted .= '::cidr';
							} elseif ( $dbw->getType() == 'postgres' && $field == 'rev_timestamp' ) {
								// In PostgreSQL, rc_timestamp is of type TIMESTAMPZ,
								// and we can't insert strings into it.
								$thenQuoted = 'to_timestamp(' . $thenQuoted .
									', \'YYYY-MM-DD HH24:MI:SS\' )';
							}
						}

						$caseSql .= 'WHEN ' . $whenQuoted . ' THEN ' . $thenQuoted . ' ';
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

			$this->logger->debug( '[ApproveHook] SQL query ({rows} rows affected): {query}',
				[
					'rows' => $dbw->affectedRows(),
					'query' => $dbw->lastQuery()
				]
			);
		}

		$dbw->endAtomic( __METHOD__ );

		// Clean both $dbUpdates and $tasks (everything is done).
		// Further calls to isApprovingNow() will return false.
		$this->dbUpdates = [];
		$this->tasks = [];
	}

	/**
	 * Add revid parameter to ManualLogEntry (if missing). See onFileUpload() for details.
	 * @param int $logid
	 * @param ManualLogEntry $logEntry
	 */
	public function checkLogEntry( $logid, ManualLogEntry $logEntry ) {
		$params = $logEntry->getParameters();
		if ( array_key_exists( 'revid', $params ) && $params['revid'] === null ) {
			$this->logEntriesToFix[$logid] = $logEntry;
		}
	}

	/** @var int|null Revid of the last edit, populated in onRevisionFromEditComplete */
	protected $lastRevId = null;

	/**
	 * Returns revid of the last edit.
	 * @return int|null
	 */
	public function getLastRevId() {
		return $this->lastRevId;
	}

	/**
	 * RevisionFromEditComplete hook.
	 * Here we determine $lastRevId.
	 * @param WikiPage $wikiPage @phan-unused-param
	 * @param RevisionRecord $rev
	 * @param int|bool $originalRevId @phan-unused-param
	 * @param UserIdentity $user @phan-unused-param
	 * @param string[] &$tags
	 * @return bool|void
	 */
	public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
		/* Remember ID of this revision for getLastRevId() */
		$this->lastRevId = $rev->getId();
	}

	/**
	 * Calculate key in $tasks array for $title/$username/$type triplet.
	 * @param LinkTarget $title
	 * @param string $username
	 * @param string $type mod_type of this change.
	 * @return string
	 */
	protected function getTaskKey( LinkTarget $title, $username, $type ) {
		return implode( '[', /* Symbol "[" is not allowed in both titles and usernames */
			[
				$username,
				$title->getNamespace(),
				$title->getDBKey(),
				$type
			]
		);
	}

	/**
	 * Find the task regarding edit by $username on $title.
	 * @param LinkTarget $title
	 * @param string $username
	 * @param string $type One of ModerationNewChange::MOD_TYPE_* values.
	 * @return array|false [ 'ip' => ..., 'xff' => ..., 'ua' => ..., ... ]
	 */
	protected function getTask( LinkTarget $title, $username, $type ) {
		$key = $this->getTaskKey( $title, $username, $type );
		return $this->tasks[$key] ?? false;
	}

	/**
	 * Add a new task. Called before doEditContent().
	 * @param LinkTarget $title
	 * @param User $user
	 * @param string $type
	 * @param array $task
	 *
	 * @phan-param array{ip:?string,xff:?string,ua:?string,tags:?string,timestamp:?string} $task
	 */
	public function addTask( LinkTarget $title, User $user, $type, array $task ) {
		$key = $this->getTaskKey( $title, $user->getName(), $type );
		$this->tasks[$key] = $task;
	}

	/**
	 * Returns true if any ApproveHook tasks were installed, false otherwise.
	 * This is used by CanSkip service to allow Moderation to be bypassed during modaction=approve.
	 * @return bool
	 */
	public function isApprovingNow() {
		return !empty( $this->tasks );
	}

	/**
	 * Find the entry in $tasks about change $rc.
	 * @param RecentChange $rc
	 * @return array|false
	 */
	protected function getTaskByRC( RecentChange $rc ) {
		$logAction = $rc->mAttribs['rc_log_action'] ?? '';

		$type = ModerationNewChange::MOD_TYPE_EDIT;
		if ( $logAction == 'move' || $logAction == 'move_redir' ) {
			$type = ModerationNewChange::MOD_TYPE_MOVE;
		}

		return $this->getTask(
			$rc->getTitle(),
			$rc->mAttribs['rc_user_text'],
			$type
		);
	}

	/**
	 * onCheckUserInsertForRecentChange()
	 * This hook is temporarily installed when approving the edit.
	 * It modifies the IP, user-agent and XFF in the checkuser database,
	 * so that they match the user who made the edit, not the moderator.
	 *
	 * @param RecentChange $rc
	 * @param array &$fields
	 * @return bool|void
	 *
	 * @phan-param array<string,string|int|null> &$fields
	 */
	public function onCheckUserInsertForRecentChange( RecentChange $rc, array &$fields ) {
		$task = $this->getTaskByRC( $rc );
		if ( !$task ) {
			return;
		}

		$fields['cuc_ip'] = IP::sanitizeIP( $task['ip'] );
		$fields['cuc_ip_hex'] = $task['ip'] ? IP::toHex( $task['ip'] ) : null;
		$fields['cuc_agent'] = $task['ua'];

		$cuHooksClassNames = [
			'\CheckUserHooks', // MediaWiki 1.35
			'\MediaWiki\CheckUser\Hooks' // MediaWiki 1.36+
		];

		$xff_ip = null;
		$isSquidOnly = false;

		foreach ( $cuHooksClassNames as $className ) {
			// @phan-suppress-next-line PhanUndeclaredClassReference
			if ( method_exists( $className, 'getClientIPfromXFF' ) ) {
				list( $xff_ip, $isSquidOnly ) = $className::getClientIPfromXFF( $task['xff'] );
				break;
			}
		}

		if ( $xff_ip !== null ) {
			$fields['cuc_xff'] = !$isSquidOnly ? $task['xff'] : '';
			$fields['cuc_xff_hex'] = ( $xff_ip && !$isSquidOnly ) ? IP::toHex( $xff_ip ) : null;
		} else {
			$fields['cuc_xff'] = '';
			$fields['cuc_xff_hex'] = null;
		}
	}

	/**
	 * Fix approve LogEntry not having "revid" parameter (because it wasn't known before).
	 * This happens when approving uploads (but NOT reuploads),
	 * because creation of description page of newly uploaded images is delayed via DeferredUpdate,
	 * so it happens AFTER the LogEntry has been added to the database.
	 *
	 * This is called from FileUpload hook (temporarily installed when approving the edit).
	 *
	 * @param File $file
	 * @param bool $reupload
	 * @param bool $hasDescription @phan-unused-param
	 * @return bool|void
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ) {
		if ( $reupload ) {
			return; // rev_id is not missing for reuploads
		}

		$title = $file->getTitle();

		$dbw = wfGetDB( DB_MASTER );
		foreach ( $this->logEntriesToFix as $logid => $logEntry ) {
			if ( $logEntry->getTarget()->equals( $title ) ) {
				$params = $logEntry->getParameters();
				$params['revid'] = $title->getLatestRevID();

				// TODO: should we use queueUpdate() here?
				$dbw->update( 'logging',
					[ 'log_params' => $logEntry->makeParamBlob( $params ) ],
					[ 'log_id' => $logid ]
				);
			}
		}
	}

	/**
	 * Schedule post-approval UPDATE SQL query.
	 * @param string $table Name of table, e.g. 'revision'.
	 * @param int|array $ids One or several IDs (e.g. rev_id or rc_id).
	 * @param array $values New values, as expected by $db->update,
	 * e.g. [ 'rc_ip' => '1.2.3.4', 'rc_something' => '...' ].
	 *
	 * @phan-param array<string,string> $values
	 */
	protected function queueUpdate( $table, $ids, array $values ) {
		if ( !is_array( $ids ) ) {
			$ids = [ $ids ];
		}

		$this->logger->debug( "[ApproveHook] queueUpdate(): table={table}; ids={ids}; values={values}", [
			'table' => $table,
			'ids' => implode( '|', $ids ),
			'values' => FormatJson::encode( $values )
		] );

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

	/**
	 * onRecentChange_save()
	 * This hook is temporarily installed when approving the edit.
	 * It modifies the IP in the recentchanges table,
	 * so that it matches the user who made the edit, not the moderator.
	 *
	 * @param RecentChange $rc
	 * @return bool|void
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public function onRecentChange_save( $rc ) {
		global $wgPutIPinRC;

		$task = $this->getTaskByRC( $rc );
		if ( !$task ) {
			return;
		}

		if ( $wgPutIPinRC ) {
			$this->queueUpdate( 'recentchanges',
				$rc->mAttribs['rc_id'],
				[ 'rc_ip' => IP::sanitizeIP( $task['ip'] ) ]
			);
		}

		$dbr = wfGetDB( DB_REPLICA );
		$timestamp = $dbr->timestamp( $task['timestamp'] ); // Possibly in PostgreSQL format

		/* Fix rev_timestamp to be equal to mod_timestamp
			(time when edit was queued, i.e. made by the user)
			instead of current time (time of approval). */
		$this->queueUpdate( 'revision',
			[ $rc->mAttribs['rc_this_oldid'] ],
			[ 'rev_timestamp' => $timestamp ]
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
	}
}
