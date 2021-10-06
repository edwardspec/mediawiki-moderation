<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
 * Consequence that marks one pending change as rejected.
 */

namespace MediaWiki\Moderation;

use User;

class RejectOneConsequence implements IConsequence {
	/** @var int */
	protected $modid;

	/** @var User */
	protected $moderator;

	/**
	 * @param int $modid
	 * @param User $moderator
	 */
	public function __construct( $modid, User $moderator ) {
		$this->modid = $modid;
		$this->moderator = $moderator;
	}

	/**
	 * Execute the consequence.
	 * @return int Number of newly rejected edits (0 or 1).
	 */
	public function run() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->moderator->getId(),
				'mod_rejected_by_user_text' => $this->moderator->getName(),
				'mod_preloadable=mod_id'
			],
			[
				'mod_id' => $this->modid,

				# These checks prevent race condition
				'mod_merged_revid' => 0,
				'mod_rejected' => 0
			],
			__METHOD__
		);

		return $dbw->affectedRows();
	}
}
