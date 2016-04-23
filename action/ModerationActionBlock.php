<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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

	public function execute() {
		$out = $this->mSpecial->getOutput();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_user AS user',
				'mod_user_text AS user_text'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$dbw = wfGetDB( DB_MASTER );
		if ( $this->actionName == 'block' ) {
			$dbw->replace( 'moderation_block',
				array( 'mb_address' ),
				array(
					'mb_address' => $row->user_text,
					'mb_user' => $row->user,
					'mb_by' => $this->moderator->getId(),
					'mb_by_text' => $this->moderator->getName(),
					'mb_timestamp' => $dbw->timestamp( wfTimestampNow() )
				),
				__METHOD__
			);
			$logEntry = new ManualLogEntry( 'moderation', 'block' );
		} else {
			$dbw->delete( 'moderation_block', array( 'mb_address' => $row->user_text ), __METHOD__ );
			$logEntry = new ManualLogEntry( 'moderation', 'unblock' );
		}

		$nrows = $dbw->affectedRows();
		if ( $nrows > 0 ) {
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( Title::makeTitle( NS_USER, $row->user_text ) );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		$out->addWikiMsg(
			'moderation-' . ( $this->actionName == 'unblock' ? 'un' : '' ) . 'block-' . ( $nrows ? 'ok' : 'fail' ),
			$row->user_text
		);
	}
}
