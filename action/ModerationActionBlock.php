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
 * Implements modaction=(un)block on [[Special:Moderation]].
 */

class ModerationActionBlock extends ModerationAction {

	public function outputResult( array $result, OutputPage &$out ) {
		/* Messages used here (for grep)
			moderation-block-ok
			moderation-unblock-ok
		*/
		$out->addWikiMsg(
			'moderation-' . ( $result['action'] == 'unblock' ? 'un' : '' ) . 'block-ok',
			$result['username']
		);
	}

	public function execute() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_user AS user',
				'mod_user_text AS user_text'
			],
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$dbw = wfGetDB( DB_MASTER );
		if ( $this->actionName == 'block' ) {
			$dbw->insert( 'moderation_block',
				[
					'mb_address' => $row->user_text,
					'mb_user' => $row->user,
					'mb_by' => $this->moderator->getId(),
					'mb_by_text' => $this->moderator->getName(),
					'mb_timestamp' => $dbw->timestamp()
				],
				__METHOD__,
				[ 'IGNORE' ]
			);
			$logEntry = new ManualLogEntry( 'moderation', 'block' );
		} else {
			$dbw->delete( 'moderation_block', [ 'mb_address' => $row->user_text ], __METHOD__ );
			$logEntry = new ManualLogEntry( 'moderation', 'unblock' );
		}

		/*
			If the user was already (un)blocked and we attempt to (un)block,
			we silently ignore this (saying "successfully (un)blocked!" to moderator),
			because the desired outcome has been reached anyway.
			E.g. this can happen if the moderator clicked "Mark as spammer" twice.
		*/
		$somethingChanged = ( $dbw->affectedRows() > 0 );
		if ( $somethingChanged ) {
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $row->user_text ) );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'action' => $this->actionName,
			'username' => $row->user_text,
			'noop' => !$somethingChanged // Already was blocked/unblocked
		];
	}
}
