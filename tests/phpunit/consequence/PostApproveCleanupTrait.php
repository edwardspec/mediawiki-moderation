<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Trait for Approve tests. Provides tearDown() to clear post-approval global state.
 */

use Wikimedia\TestingAccessWrapper;

trait PostApproveCleanupTrait {
	/**
	 * Exit "approve mode" and destroy the ApproveHook singleton.
	 */
	public function tearDown() {
		// If the previous test used Approve, it enabled "all edits should bypass moderation" mode.
		// Disable it now.
		$canSkip = TestingAccessWrapper::newFromClass( ModerationCanSkip::class );
		$canSkip->inApprove = false;

		// Forget about previous ApproveHook tasks by destroying the object with their list.
		ModerationApproveHook::destroySingleton();

		// @phan-suppress-next-line PhanTraitParentReference
		parent::tearDown();
	}
}
