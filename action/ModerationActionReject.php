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
 * @file
 * Implements modaction=reject(all) on [[Special:Moderation]].
 */

class ModerationActionReject extends ModerationAction {

	public function execute() {
		$ret = ( $this->actionName == 'reject' ) ?
			$this->executeRejectOne() :
			$this->executeRejectAll();

		if ( $ret['rejected-count'] ) {
			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			ModerationNotifyModerator::invalidatePendingTime();
		}

		return $ret;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addWikiMsg( 'moderation-rejected-ok', $result['rejected-count'] );
	}

	public function executeRejectOne() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_rejected AS rejected',
				'mod_merged_revid AS merged_revid'
			],
			[ 'mod_id' => $this->id ],
			__METHOD__
		);

		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		if ( $row->rejected ) {
			throw new ModerationError( 'moderation-already-rejected' );
		}

		if ( $row->merged_revid ) {
			throw new ModerationError( 'moderation-already-merged' );
		}

		$dbw->update( 'moderation',
			[
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				ModerationVersionCheck::setPreloadableToNo()
			],
			[
				'mod_id' => $this->id,

				# These checks prevent race condition
				'mod_merged_revid' => 0,
				'mod_rejected' => 0
			],
			__METHOD__
		);

		$nrows = $dbw->affectedRows();
		if ( !$nrows ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );

		$logEntry = new ManualLogEntry( 'moderation', 'reject' );
		$logEntry->setPerformer( $this->moderator );
		$logEntry->setTarget( $title );
		$logEntry->setParameters( [
			'modid' => $this->id,
			'user' => $row->user,
			'user_text' => $row->user_text
		] );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		return [
			'rejected-count' => $nrows
		];
	}

	public function executeRejectAll() {
		$userpage = $this->getUserpageOfPerformer();
		if ( !$userpage ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$res = $dbw->select( 'moderation',
			[ 'mod_id AS id' ],
			[
				'mod_user_text' => $userpage->getText(),
				'mod_rejected' => 0,
				'mod_merged_revid' => 0
			],
			__METHOD__,
			[ 'USE INDEX' => 'moderation_rejectall' ]
		);
		if ( !$res || $res->numRows() == 0 ) {
			throw new ModerationError( 'moderation-nothing-to-rejectall' );
		}

		$ids = [];
		foreach ( $res as $row ) {
			$ids[] = $row->id;
		}

		$dbw->update( 'moderation',
			[
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				'mod_rejected_batch' => 1,
				ModerationVersionCheck::setPreloadableToNo()
			],
			[
				'mod_id' => $ids
			],
			__METHOD__
		);

		$nrows = $dbw->affectedRows();
		if ( $nrows ) {
			$logEntry = new ManualLogEntry( 'moderation', 'rejectall' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $userpage );
			$logEntry->setParameters( [ '4::count' => $nrows ] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'rejected-count' => $nrows
		];
	}
}
