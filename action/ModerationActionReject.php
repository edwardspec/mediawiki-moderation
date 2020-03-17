<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2020 Edward Chernenko.

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

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\RejectBatchConsequence;
use MediaWiki\Moderation\RejectOneConsequence;

class ModerationActionReject extends ModerationAction {

	public function execute() {
		$ret = ( $this->actionName == 'reject' ) ?
			$this->executeRejectOne() :
			$this->executeRejectAll();

		if ( $ret['rejected-count'] ) {
			/* Clear the cache of "Most recent mod_timestamp of pending edit"
				- could have changed */
			$manager = ConsequenceUtils::getManager();
			$manager->add( new InvalidatePendingTimeCacheConsequence() );
		}

		return $ret;
	}

	public function outputResult( array $result, OutputPage $out ) {
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

		$manager = ConsequenceUtils::getManager();
		$rejectedCount = $manager->add( new RejectOneConsequence( $this->id, $this->moderator ) );
		if ( !$rejectedCount ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );
		$manager->add( new AddLogEntryConsequence( 'reject', $this->moderator, $title, [
			'modid' => $this->id,
			'user' => (int)$row->user,
			'user_text' => $row->user_text
		] ) );

		return [
			'rejected-count' => $rejectedCount
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
			$ids[] = (int)$row->id;
		}

		$manager = ConsequenceUtils::getManager();
		$rejectedCount = $manager->add( new RejectBatchConsequence( $ids, $this->moderator ) );
		if ( !$rejectedCount ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$manager = ConsequenceUtils::getManager();
		$manager->add( new AddLogEntryConsequence( 'rejectall', $this->moderator, $userpage, [
			'4::count' => $rejectedCount
		] ) );

		return [
			'rejected-count' => $rejectedCount
		];
	}
}
