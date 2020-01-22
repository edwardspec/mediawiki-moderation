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
 * Backward compatibility functions to support older versions of MediaWiki.
 */

class ModerationCompatTools {

	/**
	 * Returns content language (mainly for constructing ParserOptions).
	 * @return Language
	 */
	public static function getContentLanguage() {
		if ( method_exists( 'MediaWiki\MediaWikiServices', 'getContentLanguage' ) ) {
			// MediaWiki 1.32+
			return MediaWiki\MediaWikiServices::getInstance()->getContentLanguage();
		}

		// MediaWiki 1.31
		// phpcs:disable MediaWiki.Usage.DeprecatedGlobalVariables.Deprecated$wgContLang
		global $wgContLang;
		return $wgContLang;
		// phpcs:enable MediaWiki.Usage.DeprecatedGlobalVariables.Deprecated$wgContLang
	}
}
