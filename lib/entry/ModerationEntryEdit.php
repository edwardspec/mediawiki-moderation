<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
use MediaWiki\Moderation\ConsequenceUtils;

class ModerationEntryEdit extends ModerationApprovableEntry {
	/**
	 * Approve this edit.
	 * @param User $moderator
	 * @return Status object.
	 */
	public function doApprove( User $moderator ) {
		$row = $this->getRow();
		$user = $this->getUser();

		$manager = ConsequenceUtils::getManager();
		return $manager->add( new ApproveEditConsequence(
			$row->id,
			$user,
			$this->getTitle(),
			$row->text,
			$row->comment,
			( $row->bot && $user->isAllowed( 'bot' ) ),
			(bool)$row->minor,
			$row->last_oldid
		) );
	}
}
