<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2023 Edward Chernenko.

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

	/**
	 * Get current action name (e.g. "edit" or "upload") from Context.
	 * @param IContextSource $context
	 * @return string
	 */
	public static function getActionName( IContextSource $context ) {
		if ( method_exists( MediaWikiServices::class, 'getActionFactory' ) ) {
			// MediaWiki 1.37+
			return MediaWikiServices::getInstance()->getActionFactory()->getActionName( $context );
		}

		// MediaWiki 1.35-1.36.
		// This approach can't be used with MediaWiki 1.40 due to T323254.
		return Action::getActionName( $context );
	}

	/**
	 * Use methods of Extension:CheckUser to locate the client IP within the XFF string.
	 * @param string|bool $xff
	 * @return array
	 */
	public static function getClientIPfromXFF( $xff ) {
		$services = MediaWikiServices::getInstance();
		if ( $services->has( 'CheckUserUtilityService' ) ) {
			// MediaWiki 1.40+
			return $services->get( 'CheckUserUtilityService' )->getClientIPfromXFF( $xff );
		}

		if ( method_exists( '\MediaWiki\CheckUser\Hooks', 'getClientIPfromXFF' ) ) {
			// MediaWiki 1.36-1.39
			return \MediaWiki\CheckUser\Hooks::getClientIPfromXFF( $xff );
		}

		// @phan-suppress-next-line PhanUndeclaredClassReference
		if ( method_exists( '\CheckUserHooks', 'getClientIPfromXFF' ) ) {
			// MediaWiki 1.35
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			return \CheckUserHooks::getClientIPfromXFF( $xff );
		}

		// Extension:CheckUser is not installed.
		return [ null, false, '' ];
	}

	/**
	 * Wrap edit comment in the necessary punctuation.
	 * @param string $comment
	 * @param LinkTarget|null $selfLinkTarget
	 * @return string
	 */
	public static function commentBlock( $comment, $selfLinkTarget ) {
		if ( method_exists( MediaWikiServices::class, 'getCommentFormatter' ) ) {
			// MediaWiki 1.38+
			$commentFormatter = MediaWikiServices::getInstance()->getCommentFormatter();
			return $commentFormatter->formatBlock( $comment, $selfLinkTarget );
		}

		// MediaWiki 1.35-1.37
		return Linker::commentBlock( $comment, $selfLinkTarget );
	}

	/**
	 * Get the list of categories in the ParserOutput object.
	 * @param ParserOutput $pout
	 * @return array<string,string>
	 */
	public static function getParserOutputCategories( ParserOutput $pout ) {
		if ( method_exists( $pout, 'getCategoryNames' ) ) {
			// MediaWiki 1.38+
			// This is backward compatibility format, we don't really need to provide sortkeys here.
			return array_fill_keys( $pout->getCategoryNames(), '' );
		}

		// MediaWiki 1.35-1.37
		return $pout->getCategories();
	}
}
