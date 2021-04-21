<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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

class ModerationTestUtil {
	/**
	 * Suppress unneeded/temporary deprecation messages caused by keeping compatibility with MW 1.31.
	 * @param MediaWikiTestCase $tc
	 */
	public static function ignoreKnownDeprecations( MediaWikiTestCase $tc ) {
		// Temporary: MediaWiki core itself calls this in PageUpdater.php.
		$tc->hideDeprecated( 'Revision::__construct' );
		$tc->hideDeprecated( 'Revision::getId' );

		// TODO: replace this in MW 1.35+ (only used in testsuite, not in production code)
		$tc->hideDeprecated( 'Hooks::clear' );

		// Warning from Extension:Echo (which we need in Echo-related tests), unrelated to Moderation.
		$tc->hideDeprecated( 'Revision::getRevisionRecord' );

		// Warning from Extension:Cite, unrelated to Moderation.
		$tc->hideDeprecated( 'ResourceLoaderTestModules hook' );
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
		global $wgVersion;
		$dbw = wfGetDB( DB_MASTER );

		$page = WikiPage::factory( $title );
		$page->insertOn( $dbw );

		if ( !$user ) {
			$user = User::newFromName( '127.0.0.1', false );
		}

		$store = MediaWikiServices::getInstance()->getRevisionStore();
		$rev = $store->newMutableRevisionFromArray( [
			'page' => $page->getId(),
			'comment' => $summary,
			'user' => $user,
			'timestamp'  => $dbw->timestamp(),
		] );

		$content = ContentHandler::makeContent( $newText, null, CONTENT_MODEL_WIKITEXT );
		$rev->setContent( 'main', $content );

		$storedRecord = $store->insertRevisionOn( $rev, $dbw );

		if ( version_compare( $wgVersion, '1.35-rc.0', '>=' ) ) {
			// MediaWiki 1.35+
			$page->updateRevisionOn( $dbw, $storedRecord );
		} else {
			// MediaWiki 1.31-1.34: WikiPage::updateRevisionOn() expects Revision object.
			$page->updateRevisionOn( $dbw, Revision::newFromId( $storedRecord->getId() ) );
		}
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
		$page = new SpecialModeration;

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
	 * Get performer of LogEntry (for B/C with MediaWiki 1.31-1.35).
	 * @param LogEntry $logEntry
	 * @return UserIdentity
	 */
	public static function getLogEntryPerformer( LogEntry $logEntry ) {
		if ( method_exists( $logEntry, 'getPerformerIdentity' ) ) {
			// MediaWiki 1.36+
			return $logEntry->getPerformerIdentity();
		}

		// MediaWiki 1.31-1.35
		return $logEntry->getPerformer();
	}
}
