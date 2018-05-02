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
	@brief Implements modaction=approve(all) on [[Special:Moderation]].
*/

class ModerationActionApprove extends ModerationAction {

	public function execute() {
		$ret = ( $this->actionName == 'approve' ) ?
			$this->executeApproveOne() :
			$this->executeApproveAll();

		if ( $ret['approved'] ) {
			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			ModerationNotifyModerator::invalidatePendingTime();
		}

		return $ret;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addWikiMsg(
			'moderation-approved-ok',
			count( $result['approved'] )
		);

		if ( !empty( $result['failed'] ) ) {
			$out->addWikiMsg(
				'moderation-approved-errors',
				count( $result['failed'] )
			);
		}
	}

	public function executeApproveOne() {
		$this->approveEditById( $this->id );
		return [
			'approved' => [ $this->id ]
		];
	}

	public function executeApproveAll() {
		$userpage = $this->getUserpageOfPerformer();
		if ( !$userpage ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$res = $dbw->select( 'moderation',
			[ 'mod_id AS id' ],
			[
				'mod_user_text' => $userpage->getText(),
				'mod_rejected' => 0, # Previously rejected edits are not approved by "Approve all"
				'mod_conflict' => 0 # No previously detected conflicts (they need manual merging).
			],
			__METHOD__,
			[
				# Images are approved first.
				# Otherwise the page can be rendered with the
				# image redlink, because the image didn't exist
				# when the edit to this page was approved.
				'ORDER BY' => 'mod_stash_key IS NULL',
				'USE INDEX' => 'moderation_approveall'
			]
		);
		if ( !$res || $res->numRows() == 0 ) {
			throw new ModerationError( 'moderation-nothing-to-approveall' );
		}

		$approved = [];
		$failed = [];
		foreach ( $res as $row ) {
			try {
				$this->approveEditById( $row->id );
				$approved[$row->id] = '';
			} catch ( ModerationError $e ) {
				$msg = $e->status->getMessage();
				$failed[$row->id] = [
					'code' => $msg->getKey(),
					'info' => $msg->plain()
				];
			}
		}

		if ( $approved ) {
			$logEntry = new ManualLogEntry( 'moderation', 'approveall' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $userpage );
			$logEntry->setParameters( [ '4::count' => count( $approved ) ] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'approved' => $approved,
			'failed' => $failed
		];
	}

	function approveEditById( $id ) {
		$entry = ModerationEntry::newFromId( $id );
		$status = $entry->approve();

		if ( !$status->isGood() ) {
			throw new ModerationError( $status->getMessage() );
		}

		$logEntry = new ManualLogEntry( 'moderation', 'approve' );
		$logEntry->setPerformer( $this->moderator );
		$logEntry->setTarget( $entry->getTitle() );
		$logEntry->setParameters( [ 'revid' => ModerationApproveHook::getLastRevId() ] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		# Approved edits are removed from "moderation" table,
		# because they already exist in page history, recentchanges etc.

		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'moderation', [ 'mod_id' => $id ], __METHOD__ );
	}
}
