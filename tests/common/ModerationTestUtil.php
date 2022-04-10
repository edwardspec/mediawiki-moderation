<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2022 Edward Chernenko.

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
 * Utility functions used in both benchmark and PHPUnit Testsuite.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\User\UserIdentity;

class ModerationTestUtil {
	/**
	 * Suppress unneeded/temporary deprecation messages caused by compatibility with older MediaWiki.
	 * @param MediaWikiIntegrationTestCase $tc @phan-unused-param
	 */
	public static function ignoreKnownDeprecations( MediaWikiIntegrationTestCase $tc ) {
		// Nothing to ignore.
	}

	/**
	 * Edit the page by directly modifying the database. Very fast.
	 *
	 * This is used for initialization of tests.
	 * For example, if moveQueue benchmark needs 500 existing pages,
	 * it would take forever for doEditContent() to create them all,
	 * much longer than the actual benchmark.
	 * @param Title $title
	 * @param string $newText
	 * @param string $summary
	 * @param User|null $user
	 */
	public static function fastEdit(
		Title $title,
		$newText = 'Whatever',
		$summary = '',
		User $user = null
	) {
		$dbw = wfGetDB( DB_MASTER );

		$page = ModerationCompatTools::makeWikiPage( $title );
		$page->insertOn( $dbw );

		if ( !$user ) {
			$user = User::newFromName( '127.0.0.1', false );
		}

		$rev = new MutableRevisionRecord( $title );

		$rev->setComment( CommentStoreComment::newUnsavedComment( $summary ) );
		$rev->setUser( $user );
		$rev->setTimestamp( $dbw->timestamp() );

		$content = ContentHandler::makeContent( $newText, null, CONTENT_MODEL_WIKITEXT );
		$rev->setContent( SlotRecord::MAIN, $content );

		$store = MediaWikiServices::getInstance()->getRevisionStore();
		$storedRecord = $store->insertRevisionOn( $rev, $dbw );

		$page->updateRevisionOn( $dbw, $storedRecord );
	}

	/**
	 * Render Special:Moderation with $params.
	 * @param User $user
	 * @param array $params
	 * @param bool $wasPosted
	 * @param IContextSource|null &$context Used context will be written here. @phan-output-reference
	 * @return string HTML of the result.
	 */
	public static function runSpecialModeration( User $user, array $params, $wasPosted = false,
		IContextSource &$context = null
	) {
		$page = MediaWikiServices::getInstance()->getSpecialPageFactory()->getPage( 'Moderation' );

		$context = new RequestContext;
		$context->setRequest( new FauxRequest( $params, $wasPosted ) );
		$context->setTitle( $page->getPageTitle() );
		$context->setUser( $user );
		$context->setLanguage( Language::factory( 'qqx' ) );

		$page->setContext( $context );
		$page->execute( '' );

		return $context->getOutput()->getHTML();
	}

	/**
	 * Get performer of LogEntry (for B/C with MediaWiki 1.35).
	 * @param LogEntry $logEntry
	 * @return UserIdentity
	 */
	public static function getLogEntryPerformer( LogEntry $logEntry ) {
		if ( method_exists( $logEntry, 'getPerformerIdentity' ) ) {
			// MediaWiki 1.36+
			return $logEntry->getPerformerIdentity();
		}

		// MediaWiki 1.35
		// @phan-suppress-next-line PhanUndeclaredMethod
		return $logEntry->getPerformer();
	}

	/**
	 * Get performer of LogEntry (for B/C with MediaWiki 1.35).
	 * @param RecentChange $rc
	 * @return UserIdentity
	 */
	public static function getRecentChangePerformer( RecentChange $rc ) {
		if ( method_exists( $rc, 'getPerformerIdentity' ) ) {
			// MediaWiki 1.36+
			return $rc->getPerformerIdentity();
		}

		// MediaWiki 1.35
		return $rc->getPerformer();
	}
}
