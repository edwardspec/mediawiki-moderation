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

class ModerationTestUtil {
	/**
	 * Suppress unneeded/temporary deprecation messages caused by keeping compatibility with MW 1.31.
	 * @param MediaWikiTestCase $tc
	 */
	public static function ignoreKnownDeprecations( MediaWikiTestCase $tc ) {
		// Temporary: MediaWiki core itself calls this in PageUpdater.php.
		$tc->hideDeprecated( 'Revision::__construct' );

		// TODO: replace these hooks in MW 1.35+ (still needed for MW 1.31).
		$tc->hideDeprecated( 'NewRevisionFromEditComplete hook' );
		$tc->hideDeprecated( 'PageContentSaveComplete hook' );
		$tc->hideDeprecated( 'TitleMoveComplete hook' );
		$tc->hideDeprecated( 'SkinTemplateOutputPageBeforeExec hook' );

		// TODO: replace uses of this in MW 1.35+ (used in NewRevisionFromEditComplete).
		$tc->hideDeprecated( 'Revision::getId' );

		// TODO: replace uses of this in MW 1.35+ (used in PageContentSaveComplete).
		$tc->hideDeprecated( 'MediaWiki\Storage\PageUpdater::doCreate status get \'revision\'' );
		$tc->hideDeprecated( 'MediaWiki\Storage\PageUpdater::doModify status get \'revision\'' );

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
		$dbw = wfGetDB( DB_MASTER );

		$page = WikiPage::factory( $title );
		$page->insertOn( $dbw );

		if ( !$user ) {
			$user = User::newFromName( '127.0.0.1', false );
		}

		$revision = new Revision( [
			'page'       => $page->getId(),
			'comment'    => $summary,
			'text'       => $newText, # No preSaveTransform or serialization
			'user'       => $user->getId(),
			'user_text'  => $user->getName(),
			'timestamp'  => $dbw->timestamp(),
			'content_model' => CONTENT_MODEL_WIKITEXT
		] );

		$revision->insertOn( $dbw );
		$page->updateRevisionOn( $dbw, $revision );
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
}
