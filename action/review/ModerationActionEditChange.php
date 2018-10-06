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
 * Implements modaction=editchange on [[Special:Moderation]].
 *
 * Here moderator can modify the pending change before approving it.
 * Note: this is disabled by default, because pending changes don't have edit history,
 * so the moderator can accidentally erase their text.
 */

class ModerationActionEditChange extends ModerationAction {

	public function requiresEditToken() {
		return false; // True in ModerationActionEditChangeSubmit
	}

	public function execute() {
		if ( !$this->getConfig()->get( 'ModerationEnableEditChange' ) ) {
			throw new ModerationError( 'moderation-unknown-modaction' );
		}

		$fields = [
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_text AS text',
			'mod_comment AS comment'
		];
		if ( ModerationVersionCheck::hasModType() ) {
			$fields[] = 'mod_type AS type';
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			$fields,
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		if ( isset( $row->type ) && $row->type != ModerationNewChange::MOD_TYPE_EDIT ) {
			throw new ModerationError( 'moderation-editchange-not-edit' );
		}

		return [
			'id' => $this->id,
			'namespace' => $row->namespace,
			'title' => $row->title,
			'text' => $row->text,
			'summary' => $row->comment
		];
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$title = Title::makeTitle( $result['namespace'], $result['title'] );
		$article = new Article( $title );

		$editPage = new ModerationEditChangePage( $article );

		$editPage->setContextTitle( $title );
		$editPage->textbox1 = $result['text'];
		$editPage->summary = $result['summary'];

		$editPage->showEditForm();

		$out->setPageTitle( $this->msg(
			'moderation-editchange-title',
			$title->getFullText()
		) );
	}
}
