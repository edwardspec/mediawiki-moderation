<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2017 Edward Chernenko.

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
	@brief Checks if the user is allowed to skip moderation.
*/

class ModerationCanSkip {
	protected static $inApprove = false; /**< Flag used in enterApproveMode() */

	/**
		@brief Enters "approve mode", making all further calls of canSkip() return true.
		This is used in ModerationActionApprove, so that newly approved edit
		wouldn't be stopped by Moderation again.
	*/
	public static function enterApproveMode() {
		self::$inApprove = true;
	}

	public static function canSkip( $user ) {
		global $wgModerationEnable;

		/*
			NOTE: it makes little sense for some user to have 'rollback'
			and not have 'skip-moderation', and there is no perfect
			implementation for this case.
			It is much better to allow all rollbacks to skip moderation.
		*/
		if (
			!$wgModerationEnable ||
			self::$inApprove ||
			$user->isAllowed( 'skip-moderation' ) ||
			$user->isAllowed( 'rollback' )
		)
		{
			return true;
		}

		return false;
	}
}
