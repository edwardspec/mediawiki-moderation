<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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
 * @brief Checks if the user is allowed to skip moderation.
 */

class ModerationCanSkip {
	/** @var bool Flag used in enterApproveMode() */
	protected static $inApprove = false;

	/**
	 * @brief Enters "approve mode", making all further calls of canSkip() return true.
	 * This is used in modaction=approve, so that newly approved edit
	 * wouldn't be stopped by Moderation again.
	 */
	public static function enterApproveMode() {
		self::$inApprove = true;
	}

	/**
	 * @brief Check if edits by $user can bypass moderation in namespace $namespaceNumber.
	 * @param User $user
	 * @param int $namespaceNumber
	 */
	public static function canEditSkip( User $user, $namespaceNumber ) {
		return self::canSkip( $user, 'skip-moderation', [ $namespaceNumber ] );
	}

	/**
	 * @brief Check if uploads by $user can bypass moderation.
	 * @param User $user
	 */
	public static function canUploadSkip( User $user ) {
		return self::canEditSkip( $user, NS_FILE );
	}

	/**
	 * @brief Check if moves by $user can bypass moderation.
	 * @param User $user
	 * @param int $fromNamespace Namespace of the old title.
	 * @param int $toNamespace Namespace of the new title.
	 */
	public static function canMoveSkip( User $user, $fromNamespace, $toNamespace ) {
		return self::canSkip( $user, 'skip-move-moderation', [
			$fromNamespace,
			$toNamespace
		] );
	}

	/*-------------------------------------------------------------------*/

	/**
	 * @brief Returns true if $user can skip moderation, false otherwise.
	 * @param User $user
	 * @param string $permission Name of the user's right that allows to bypass moderation.
	 * @param array $affectedNamespaces Array of namespace numbers of all affected pages.
	 */
	protected static function canSkip( User $user, $permission, array $affectedNamespaces ) {
		global $wgModerationEnable;
		if ( !$wgModerationEnable || self::$inApprove ) {
			return true; /* Moderation is disabled */
		}

		if ( $user->isAllowed( $permission ) ) {
			return true; /* $user is allowed to bypass moderation */
		}

		if ( $permission == 'skip-moderation' && $user->isAllowed( 'rollback' ) ) {
			/*
				It makes little sense for some user to have 'rollback'
				and not have 'skip-moderation', and there is no perfect
				implementation for this case.
				Therefore we allow such users to skip moderation
				of edits (but not moves).
			*/
			return true;
		}

		/* Is moderation disabled in ALL affected namespace(s)? */
		return self::canSkipInAllNamespaces( $affectedNamespaces );
	}

	/**
	 * @brief Check if moderation can be skipped in all $namespaces.
	 * @param array $namespaces Array of namespace numbers.
	 * @retval true All $namespaces are non-moderated.
	 * @retval false At least one of $namespaces in moderated.
	 */
	protected static function canSkipInAllNamespaces( array $namespaces ) {
		foreach ( array_unique( $namespaces ) as $ns ) {
			if ( !self::canSkipInNamespace( $ns ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @brief Check if moderation can be skipped in namespace $namespaceNumber.
	 */
	protected static function canSkipInNamespace( $namespaceNumber ) {
		global $wgModerationOnlyInNamespaces,
			$wgModerationIgnoredInNamespaces;

		if ( in_array( $namespaceNumber, $wgModerationIgnoredInNamespaces ) ) {
			return true; /* This namespace is NOT moderated, e.g. Sandbox:Something */
		}

		if ( $wgModerationOnlyInNamespaces && !in_array(
			$namespaceNumber,
			$wgModerationOnlyInNamespaces
		) ) {
			return true; /* This namespace is NOT moderated */
		}

		return false;
	}
}
