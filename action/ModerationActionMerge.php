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
	@brief Implements modaction=merge on [[Special:Moderation]].
*/

class ModerationActionMerge extends ModerationAction {
	public function execute() {
		global $wgHooks;

		$out = $this->mSpecial->getOutput();

		if ( !ModerationCanSkip::canSkip( $this->moderator ) ) { // In order to merge, moderator must also be automoderated
			throw new ModerationError( 'moderation-merge-not-automoderated' );
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_user_text AS user_text',
				'mod_text AS text',
				'mod_conflict AS conflict'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		if ( !$row->conflict ) {
			throw new ModerationError( 'moderation-merge-not-needed' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );
		$article = new Article( $title );

		ModerationEditHooks::$NewMergeID = $this->id;

		$editPage = new EditPage( $article );

		$editPage->isConflict = true;
		$editPage->setContextTitle( $title );
		$editPage->textbox1 = $row->text;
		$editPage->summary = wfMessage( 'moderation-merge-comment', $row->user_text )->inContentLanguage()->plain();

		$editPage->showEditForm();
	}
}
