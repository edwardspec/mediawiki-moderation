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
 * Normal edit (modification of page text) that awaits moderation.
 */

class ModerationEntryEdit extends ModerationApprovableEntry {
	/**
	 * Approve this edit.
	 * @return Status object.
	 */
	public function doApprove( User $moderator ) {
		$row = $this->getRow();

		$user = $this->getUser();
		$title = $this->getTitle();
		$model = $title->getContentModel();

		$flags = EDIT_AUTOSUMMARY;
		if ( $row->bot && $user->isAllowed( 'bot' ) ) {
			$flags |= EDIT_FORCE_BOT;
		}
		if ( $row->minor ) { # doEditContent() checks the right
			$flags |= EDIT_MINOR;
		}

		# This is normal edit (not an upload).
		$new_content = ContentHandler::makeContent( $row->text, null, $model );

		$page = new WikiPage( $title );
		if ( !$page->exists() ) {
			# New page. No need to check for edit conflicts.
			return $page->doEditContent(
				$new_content,
				$row->comment,
				$flags,
				false,
				$user
			);
		}

		# Existing page
		$latest = $page->getLatest();
		if ( $latest == $row->last_oldid ) {
			# Page hasn't changed since this edit was queued for moderation.
			return $page->doEditContent(
				$new_content,
				$row->comment,
				$flags,
				$latest,
				$user
			);
		}

		# Page has changed! (edit conflict)
		# Let's try to merge this automatically (resolve the conflict),
		# as MediaWiki does in private EditPage::mergeChangesIntoContent().

		$base_content = $row->last_oldid ?
			Revision::newFromId( $row->last_oldid )->getContent( Revision::RAW ) :
			ContentHandler::makeContent( '', null, $model );

		$latest_content = $page->getContent( Revision::RAW );

		$handler = ContentHandler::getForModelID( $base_content->getModel() );
		$merged_content = $handler->merge3( $base_content, $new_content, $latest_content );

		if ( $merged_content ) {
			return $page->doEditContent(
				$merged_content,
				$row->comment,
				$flags,
				$latest, # Because $merged_content goes after $latest
				$user
			);
		}

		/* Failed to merge automatically.
			Can still be merged manually by moderator */
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[ 'mod_conflict' => 1 ],
			[ 'mod_id' => $row->id ],
			__METHOD__
		);

		return Status::newFatal( 'moderation-edit-conflict' );
	}
}
