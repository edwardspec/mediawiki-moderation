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
 * Utility functions used in both benchmark and PHPUnit Testsuite.
 */

class ModerationTestUtil {
	/**
	 * Edit the page by directly modifying the database. Very fast.
	 *
	 * This is used for initialization of tests.
	 * For example, if moveQueue benchmark needs 500 existing pages,
	 * it would take forever for doEditContent() to create them all,
	 * much longer than the actual benchmark.
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
			'timestamp'  => wfTimestampNow(),
			'content_model' => CONTENT_MODEL_WIKITEXT
		] );

		$revision->insertOn( $dbw );
		$page->updateRevisionOn( $dbw, $revision );
	}

	/**
	 * Render Special:Moderation with $params.
	 * @return HTML of the result.
	 */
	public static function runSpecialModeration( User $user, array $params, $wasPosted = false ) {
		$page = SpecialPageFactory::getPage( 'Moderation' );

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
