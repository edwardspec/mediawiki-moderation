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

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;

class ModerationCompatTools {
	/**
	 * Replace things like "~~~~" in $content.
	 * @param Content $content
	 * @param Title $title
	 * @param User $user
	 * @param ParserOptions $popts
	 * @return Content
	 */
	public static function preSaveTransform(
		Content $content, Title $title, User $user, ParserOptions $popts
	) {
		if ( method_exists( MediaWikiServices::class, 'getContentTransformer' ) ) {
			// MediaWiki 1.37+
			$contentTransformer = MediaWikiServices::getInstance()->getContentTransformer();
			return $contentTransformer->preSaveTransform( $content, $title, $user, $popts );
		}

		// MediaWiki 1.35-1.36
		return $content->preSaveTransform( $title, $user, $popts );
	}

	/**
	 * Create a WikiPage object from LinkTarget.
	 * @param LinkTarget $title
	 * @return WikiPage
	 */
	public static function makeWikiPage( LinkTarget $title ) {
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MediaWiki 1.36+
			$factory = MediaWikiServices::getInstance()->getWikiPageFactory();
			return $factory->newFromLinkTarget( $title );
		}

		// MediaWiki 1.35
		return WikiPage::factory( Title::newFromLinkTarget( $title ) );
	}
}
