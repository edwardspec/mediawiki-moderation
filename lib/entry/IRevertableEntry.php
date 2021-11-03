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
 * Interface for ApprovableEntry classes that support "Approve and revert" operation.
 */

namespace MediaWiki\Moderation;

use Status;
use User;

interface IRevertableEntry {
	/**
	 * Publicly revert the newly approved change on behalf of the moderator.
	 * @param User $moderator
	 * @return Status
	 */
	public function revert( User $moderator );
}
