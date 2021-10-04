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
 * Backward compatibility functions to support older versions of MediaWiki.
 */

class ModerationCompatTools {

	/**
	 * Returns content language (mainly for constructing ParserOptions).
	 * @return Language
	 */
	public static function getContentLanguage() {
		return MediaWiki\MediaWikiServices::getInstance()->getContentLanguage();
	}

	/**
	 * Install backward compatibility hooks when using older versions of MediaWiki.
	 * This method won't be needed after we drop compatibility with 1.31, because MediaWiki 1.35
	 * provides "acknowledged deprecation of hooks" feature (see docs/Hooks.md), which is better.
	 */
	public static function installCompatHooks() {
		if ( !interface_exists( 'MediaWiki\Hook\PageMoveCompleteHook' ) ) {
			// MediaWiki 1.31-1.34
			// Can use the same handler as for new PageMoveComplete hook,
			// because we don't use the only parameter that is different (Revision/RevisionRecord).
			Hooks::register( 'TitleMoveComplete',
				'ModerationApproveHook::onPageMoveComplete' );
		}
	}
}
