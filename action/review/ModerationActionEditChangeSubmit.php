<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * @brief Implements modaction=editchangesubmit on [[Special:Moderation]].
 *
 * @see ModerationActionEditChange - handles the edit form
 */

class ModerationActionEditChangeSubmit extends ModerationAction {

	public function execute() {
		if ( !$this->getConfig()->get( 'ModerationEnableEditChange' ) ) {
			throw new ModerationError( 'moderation-unknown-modaction' );
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_namespace AS namespace',
				'mod_title AS title'
			],
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$request = $this->getRequest();
		$dbw->update( 'moderation',
			[
				'mod_text' => $request->getVal( 'wpTextbox1' ),
				'mod_comment' => $request->getVal( 'wpSummary' )

				// TODO: 1) Apply preSaveTransform() to new mod_text
				// (make sure to use original author as User for preSaveTransform(),
				// so that ~~~~ is not transformed into signature of moderator)
				// 2) recalculate mod_new_len.
			],
			[
				'mod_id' => $this->id
			],
			__METHOD__
		);

		$somethingChanged = ( $dbw->affectedRows() > 0 );
		if ( $somethingChanged ) {
			$title = Title::makeTitle( $row->namespace, $row->title );

			$logEntry = new ManualLogEntry( 'moderation', 'editchange' );
			$logEntry->setPerformer( $this->moderator );
			$logEntry->setTarget( $title );
			$logEntry->setParameters( [
				'modid' => $this->id
			] );
			$logid = $logEntry->insert();
			$logEntry->publish( $logid );
		}

		return [
			'id' => $this->id,
			'success' => true,
			'noop' => !$somethingChanged
		];
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addWikiMsg( 'moderation-editchange-ok' );
	}
}
