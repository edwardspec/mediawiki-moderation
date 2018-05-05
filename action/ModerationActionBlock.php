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
	@brief Implements modaction=(un)block on [[Special:Moderation]].
*/

class ModerationActionBlock extends ModerationAction {

	public function outputResult( array $result, OutputPage &$out ) {
		/* Messages used here (for grep):
			moderation-block-fail
			moderation-block-ok
			moderation-unblock-fail
			moderation-unblock-ok
		*/
		$out->addWikiMsg(
			'moderation-' . ( $result['action'] == 'unblock' ? 'un' : '' ) . 'block-' . ( $result['success'] ? 'ok' : 'fail' ),
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
			$dbw->replace( 'moderation_block',
				[ 'mb_address' ],
				[
					'mb_address' => $row->user_text,
					'mb_user' => $row->user,
					'mb_by' => $this->moderator->getId(),
					'mb_by_text' => $this->moderator->getName(),
					'mb_timestamp' => $dbw->timestamp( wfTimestampNow() )
				],
				__METHOD__
			);
			$logEntry = new ManualLogEntry( 'moderation', 'block' );
		} else {
			$dbw->delete( 'moderation_block', [ 'mb_address' => $row->user_text ], __METHOD__ );
			$logEntry = new ManualLogEntry( 'moderation', 'unblock' );
		}

		$nrows = $dbw->affectedRows();
		if ( $nrows > 0 ) {
			ModerationBlockCheck::invalidateCache( User::newFromId( $row->user ) );

			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $row->user_text ) );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'action' => $this->actionName,
			'username' => $row->user_text,
			'success' => ( $nrows > 0 )
		];
	}
}
