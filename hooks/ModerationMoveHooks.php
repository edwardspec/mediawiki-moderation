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
 * Hooks related to moving (renaming) pages.
 */

class ModerationMoveHooks {

	/**
	 * Intercept attempts to rename pages and queue them for moderation.
	 */
	public static function onMovePageCheckPermissions(
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

		if ( ModerationCanSkip::canMoveSkip(
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

		$globalTitle = RequestContext::getMain()->getTitle();
		if ( $globalTitle && $globalTitle->isSpecial( 'Movepage' ) &&
			!$user->getRequest()->wasPosted()
		) {
			/* Special:MovePage can call MovePageCheckPermissions hook
				while still in showForm(), before the actual Submit.
				At this point we don't need to queue the move yet.
			*/
			return true;
		}

		if ( method_exists( 'AbuseFilterHooks', 'onTitleMove' ) ) {
			/* Since MediaWiki 1.33, AbuseFilter uses TitleMove hook to filter the moves,
				and this hook never gets called,
				because Moderation aborts the move earlier (in MovePageCheckPermissions hook).

				Workaround is to call TitleMove handler of Extension:AbuseFilter right here.

				NOTE: Moderation can't use TitleMove hook yet, because this hook can abort the move
				only in MediaWiki 1.33+, and we need backward compatibility with earlier MediaWiki.
			*/
			AbuseFilterHooks::onTitleMove( $oldTitle, $newTitle, $user, $reason, $status );
			if ( !$status->isOK() ) {
				// AbuseFilter prohibited the move.
				return true;
			}
		}

		$change = new ModerationNewChange( $oldTitle, $user );
		$fields = $change->move( $newTitle )
			->setSummary( $reason )
			->queue();

		if ( $user->isLoggedIn() ) {
			/* Watch/Unwatch $oldTitle/$newTitle immediately:
				watchlist is the user's own business,
				no reason to wait for approval of the move */
			$watch = $user->getRequest()->getCheck( 'wpWatch' );

			WatchAction::doWatchOrUnwatch( $watch, $oldTitle, $user );
			WatchAction::doWatchOrUnwatch( $watch, $newTitle, $user );
		}

		$errorMsg = 'moderation-move-queued';
		ModerationQueuedSuccessException::throwIfNeeded( $errorMsg );

		$status->fatal( $errorMsg );
		return false;
	}
}
