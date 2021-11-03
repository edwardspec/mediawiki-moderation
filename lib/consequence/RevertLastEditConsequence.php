<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2021 Edward Chernenko.

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
 * Consequence that reverts the last revision in an article.
 */

namespace MediaWiki\Moderation;

use Status;
use Title;
use User;

class RevertLastEditConsequence implements IConsequence {
	/** @var User */
	protected $moderator;

	/** @var Title */
	protected $title;

	/**
	 * @param User $moderator
	 * @param Title $title
	 */
	public function __construct( User $moderator, Title $title ) {
		$this->moderator = $moderator;
		$this->title = $title;
	}

	/**
	 * Execute the consequence.
	 * @return Status
	 */
	public function run() {
		// TODO
		return Status::newFatal( 'moderation-not-yet-implemented' );
	}
}
