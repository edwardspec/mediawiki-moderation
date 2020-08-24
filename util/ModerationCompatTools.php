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

	/**
	 * Install backward compatibility hooks when using older versions of MediaWiki.
	 * This method won't be needed after we drop compatibility with 1.31, because MediaWiki 1.35
	 * provides "acknowledged deprecation of hooks" feature (see docs/Hooks.md), which is better.
	 */
	public static function installCompatHooks() {
		if ( !interface_exists( 'MediaWiki\Page\Hook\RevisionFromEditCompleteHook' ) ) {
			// MediaWiki 1.31-1.34
			Hooks::register( 'NewRevisionFromEditComplete',
				'ModerationApproveHook::onNewRevisionFromEditComplete' );
		}

		if ( !interface_exists( 'MediaWiki\Storage\Hook\PageSaveCompleteHook' ) ) {
			// MediaWiki 1.31-1.34
			Hooks::register( 'PageContentSaveComplete',
				'ModerationEditHooks::onPageContentSaveComplete' );

			// Same handler as for non-deprecated PageSaveComplete (not a typo),
			// as it doesn't use any parameters. It just needs to run at the moment
			// when this hook is called.
			Hooks::register( 'PageContentSaveComplete',
				'ModerationApproveHook::onPageSaveComplete' );
		}

		if ( !interface_exists( 'MediaWiki\Hook\PageMoveCompleteHook' ) ) {
			// MediaWiki 1.31-1.34
			// Can use the same handler as for new PageMoveComplete hook,
			// because we don't use the only parameter that is different (Revision/RevisionRecord).
			Hooks::register( 'TitleMoveComplete',
				'ModerationApproveHook::onPageMoveComplete' );
		}
	}
}
