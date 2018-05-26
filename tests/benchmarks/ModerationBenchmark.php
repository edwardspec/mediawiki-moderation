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
	@file
	@brief Parent class for benchmark scripts.
*/

abstract class ModerationBenchmark extends Maintenance {

	private $testsuite = null; /**< ModerationTestsuite */

	/**
		@brief User object of automoderated account.
	*/
	public function getAutomoderatedUser() {
		return $this->testsuite->automoderated;
	}

	/**
		@brief User object of non-automoderated account.
	*/
	public function getUnprivilegedUser() {
		return $this->testsuite->unprivilegedUser;
	}

	/**
		@brief User object of moderator account.
	*/
	public function getModeratorUser() {
		return $this->testsuite->moderator;
	}

	/**
		@brief This function will be benchmarked by execute().
	*/
	abstract public function doActualWork( $iterationNumber );

	/**
		@brief Initialize everything before the tests.
	*/
	public function beforeBenchmark( $numberOfLoops ) {
		/* Nothing to do.
			Will be redefined by benchmarks if they need this. */
	}

	/**
		@brief Default number of loops.
	*/
	public function getDefaultLoops() {
		return 500;
	}

	/**
		@brief Main function: test the performance of doActualWork().
	*/
	function execute() {
		global $wgAutoloadClasses;
		$wgAutoloadClasses['ModerationTestsuite'] = __DIR__ . "/../phpunit/framework/ModerationTestsuite.php";

		$this->testsuite = new ModerationTestsuite; /* Clean the database, etc. */

		/* Prepare the initial conditions */
		$loops = $this->getDefaultLoops();

		$dbw = wfGetDB( DB_MASTER );
		$dbw->startAtomic( __METHOD__ );

		$this->beforeBenchmark( $loops );

		$dbw->endAtomic( __METHOD__ );

		/* Run doActualWork() several times */
		$startTime = microtime( true );
		for ( $i = 1; $i <= $loops; $i ++ ) {
			if ( $i % 25 == 0 ) {
				printf( "\r%.3f%%", ( 100 * $i / $loops ) );
			}

			$this->doActualWork( $i );
		}
		$endTime = microtime( true );

		/* Performance report */
		printf( "\r%s: did %d iterations in %.3f seconds\n", get_class( $this ), $loops, ( $endTime - $startTime ) );
	}

	/**
		@brief Edit the page (convenience function to be used by benchmarks).
		@return Status object.
	*/
	public function edit( Title $title, $newText = 'Whatever', $summary = '', User $user = null ) {
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( $newText, null, CONTENT_MODEL_WIKITEXT );

		$flags = 0;
		if ( defined( 'EDIT_INTERNAL' ) ) {
			$flags |= EDIT_INTERNAL; /* No need to check EditStash */
		}

		return $page->doEditContent(
			$content,
			$summary,
			$flags,
			false,
			$user
		);
	}

	/**
		@brief Edit the page by directly modifying the database. Very fast.

		This is used for initialization of tests.
		For example, if moveQueue benchmark needs 500 existing pages,
		it would take forever for doEditContent() to create them all,
		much longer than the actual benchmark.
	*/
	public function fastEdit( Title $title, $newText = 'Whatever', $summary = '', User $user = null ) {
		$page = WikiPage::factory( $title );

		$dbw = wfGetDB( DB_MASTER );
		$page->insertOn( $dbw );

		$revision = new Revision( [
			'page'       => $page->getId(),
			'comment'    => $summary,
			'text'       => $newText,
			'user'       => $user->getId(),
			'user_text'  => $user->getName(),
			'timestamp'  => wfTimestampNow(),
			'content_model' => CONTENT_MODEL_WIKITEXT
		] );

		$revision->insertOn( $dbw );
		$page->updateRevisionOn( $dbw, $revision );
	}
}
