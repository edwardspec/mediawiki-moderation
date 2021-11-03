<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2021 Edward Chernenko.

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

use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\IRevertableEntry;
use MediaWiki\Moderation\MarkAsConflictConsequence;
use MediaWiki\Moderation\RevertLastEditConsequence;

class ModerationEntryEdit extends ModerationApprovableEntry implements IRevertableEntry {
	/**
	 * Approve this edit.
	 * @param User $moderator @phan-unused-param
	 * @return Status object.
	 */
	public function doApprove( User $moderator ) {
		$row = $this->getRow();
		$user = $this->getUser();

		$status = $this->consequenceManager->add( new ApproveEditConsequence(
			$user,
			$this->getTitle(),
			$row->text,
			$row->comment,
			( $row->bot && $user->isAllowed( 'bot' ) ),
			(bool)$row->minor,
			(int)$row->last_oldid
		) );

		if ( $status->hasMessage( 'moderation-edit-conflict' ) ) {
			/* Failed to merge automatically.
				Can still be merged manually by moderator */
			$this->consequenceManager->add( new MarkAsConflictConsequence( $row->id ) );
		}

		return $status;
	}

	/**
	 * Revert this edit after approval.
	 * @param User $moderator
	 * @return Status
	 */
	public function revert( User $moderator ) {
		return $this->consequenceManager->add( new RevertLastEditConsequence(
			$moderator,
			$this->getTitle()
		) );
	}
}
