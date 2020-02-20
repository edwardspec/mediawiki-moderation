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
 * Consequence-related utility methods.
 */

namespace MediaWiki\Moderation;

class ConsequenceUtils {
	/**
	 * @var IConsequenceManager
	 * Currently used ConsequenceManager.
	 */
	private static $manager;

	/**
	 * @param IConsequenceManager $manager
	 */
	public static function installManager( IConsequenceManager $manager ) {
		self::$manager = $manager;
	}

	/**
	 * Get currently used ConsequenceManager.
	 * @return IConsequenceManager
	 */
	public static function getManager() {
		if ( !self::$manager ) {
			self::$manager = new ConsequenceManager();
		}

		return self::$manager;
	}
}
