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
 * Wrapper around the API response of ApiBot methods.
 *
 * Behaves like a usual API response array, but also has ModerationTestsuiteBotResponse methods.
 */

namespace MediaWiki\Moderation\Tests;

use ArrayObject;

class ModerationTestsuiteApiBotResponse extends ArrayObject {
	use ModerationTestsuiteBotResponse;
}
