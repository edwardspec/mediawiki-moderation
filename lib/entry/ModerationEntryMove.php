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
 * Page move (proposal to rename the page) that awaits moderation.
 */

class ModerationEntryMove extends ModerationApprovableEntry {
	/**
	 * Approve this move.
	 * @return Status object.
	 */
	public function doApprove( User $moderator ) {
		$row = $this->getRow();
		$reason = $row->comment;

		$mp = new MovePage(
			$this->getTitle(), /* old name of the article */
			$this->getPage2Title() /* new (suggested) name of the article */
		);

		/* Sanity checks like "page with the new name should not exist" */
		$status = $mp->isValidMove();
		if ( !$status->isOK() ) {
			return $status;
		}

		/* There is no need to call $mp->checkPermissions( $this->getUser(), $reason ),
			because (1) it was already checked BEFORE the move was queued,
			(2) this move is now being approved by moderator, so it doesn't matter
			whether $user has lost its right to move (e.g. got blocked) or not.

			However, we need to ensure that moderator himself is allowed to move!
			Some wikis may grant moderator flag to random users who offer help,
			and they don't necessarily want to give them "move" right,
			because "move" right can be used for hard-to-revert vandalism.
		*/
		$status = $mp->checkPermissions( $moderator, $reason );
		if ( !$status->isOK() ) {
			return $status; /* Moderator is not allowed to move */
		}

		return $mp->move(
			$this->getUser(), /* User who suggested the move */
			$reason,
			true /* Always create redirect. This may be changed in the future */
		);
	}

	/**
	 * Post-approval log subtype.
	 */
	protected function getApproveLogSubtype() {
		return 'approve-move';
	}

	/**
	 * Parameters for post-approval log.
	 */
	protected function getApproveLogParameters() {
		$row = $this->getRow();
		return [
			'4::target' => $this->getPage2Title()->getPrefixedText(),
			'user' => $row->user,
			'user_text' => $row->user_text
		];
	}
}
