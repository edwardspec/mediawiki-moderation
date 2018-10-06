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
 * Implements modaction=editchangesubmit on [[Special:Moderation]].
 *
 * @see ModerationActionEditChange - handles the edit form
 */

class ModerationActionEditChangeSubmit extends ModerationAction {

	public function execute() {
		global $wgContLang;

		if ( !$this->getConfig()->get( 'ModerationEnableEditChange' ) ) {
			throw new ModerationError( 'moderation-unknown-modaction' );
		}

		$where = [ 'mod_id' => $this->id ];
		if ( ModerationVersionCheck::hasModType() ) {
			// Disallow modification of non-edits, e.g. pending page moves.
			$where['mod_type'] = ModerationNewChange::MOD_TYPE_EDIT;
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_user AS user',
				'mod_user_text AS user_text'
			],
			$where,
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$request = $this->getRequest();

		/* Apply preSaveTransform to the submitted text */
		$title = Title::makeTitle( $row->namespace, $row->title );
		$newContent = ContentHandler::makeContent(
			$request->getVal( 'wpTextbox1' ),
			$title
		);

		$originalAuthor = $row->user ?
			User::newFromId( $row->user ) :
			User::newFromName( $row->user_text, false );
		$pstContent = $newContent->preSaveTransform(
			$title,
			$originalAuthor,
			ParserOptions::newFromUserAndLang(
				$originalAuthor,
				$wgContLang
			)
		);

		$dbw->update( 'moderation',
			[
				'mod_text' => $pstContent->getNativeData(),
				'mod_new_len' => $pstContent->getSize(),
				'mod_comment' => $request->getVal( 'wpSummary' )
			],
			[
				'mod_id' => $this->id
			],
			__METHOD__
		);

		$somethingChanged = ( $dbw->affectedRows() > 0 );
		if ( $somethingChanged ) {
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
