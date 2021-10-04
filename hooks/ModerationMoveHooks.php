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
 * Hooks related to moving (renaming) pages.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\QueueMoveConsequence;

class ModerationMoveHooks {

	/**
	 * Intercept attempts to rename pages and queue them for moderation.
	 * @param Title $oldTitle
	 * @param Title $newTitle
	 * @param User $user
	 * @param string $reason
	 * @param Status $status
	 * @return bool
	 */
	public static function onTitleMove(
		Title $oldTitle,
		Title $newTitle,
		User $user,
		$reason,
		Status $status
	) {
		if ( !$status->isOK() ) {
			// $user is not allowed to move ($status is already fatal)
			return true;
		}

		$canSkip = MediaWikiServices::getInstance()->getService( 'Moderation.CanSkip' );
		if ( $canSkip->canMoveSkip(
			$user,
			$oldTitle->getNamespace(),
			$newTitle->getNamespace()
		) ) {
			// This user is allowed to bypass moderation
			return true;
		}

		if ( !ModerationVersionCheck::hasModType() ) {
			/* Database schema is outdated (intercepting moves is not supported),
				administrator must run update.php */
			return true;
		}

		$manager = MediaWikiServices::getInstance()->getService( 'Moderation.ConsequenceManager' );
		$manager->add( new QueueMoveConsequence(
			$oldTitle, $newTitle, $user, $reason
		) );

		/* Watch/Unwatch $oldTitle/$newTitle immediately:
			watchlist is the user's own business, no reason to wait for approval of the move */
		$editFormOptions = MediaWikiServices::getInstance()->getService( 'Moderation.EditFormOptions' );
		$editFormOptions->watchIfNeeded( $user, [ $oldTitle, $newTitle ] );

		$errorMsg = 'moderation-move-queued';
		ModerationQueuedSuccessException::throwIfNeeded( $errorMsg );

		$status->fatal( $errorMsg );
		return false;
	}
}
