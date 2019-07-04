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
 * Subclass of MediaWikiTestCase that prints TestsuiteLogger debug messages for failed tests.
 */

class ModerationTestCase extends MediaWikiTestCase {
	/** @var ModerationTestsuite */
	private $testsuite = null;

	/**
	 * Get the ModerationTestsuite object that should be used in the current test.
	 * @return ModerationTestsuite
	 */
	public function getTestsuite() {
		if ( !$this->testsuite ) {
			$this->testsuite = new ModerationTestsuite;
		}

		return $this->testsuite;
	}

	/**
	 * Get a newly initialized (completely clean) ModerationTestsuite object (empty DB, etc.).
	 * @return ModerationTestsuite
	 */
	protected function makeNewTestsuite() {
		$this->testsuite = null;
		return $this->getTestsuite();
	}

	/**
	 * Dump the logs related to the current test.
	 *
	 * FIXME: MediaWiki 1.27 uses PHPUnit 4, where $e is Exception, but modern PHPUnit 6
	 * has "Throwable $e", which leads to PHP warning "Declaration [...] should be compatible".
	 */
	protected function onNotSuccessfulTest( $e ) {
		switch ( get_class( $e ) ) {
			case 'PHPUnit_Framework_SkippedTestError':
			case 'PHPUnit\Framework\SkippedTestError':
				break; // Don't need logs of skipped tests

			default:
				ModerationTestsuiteLogger::printBuffer();
		}

		parent::onNotSuccessfulTest( $e );
	}

	/**
	 * Forget the logs related to previous tests.
	 */
	protected function setUp() {
		ModerationTestsuiteLogger::cleanBuffer();
		parent::setUp();

		/*
			Provide "ModerationTestsuite $t" to all test methods via Dependency Injection.
			This also cleans the database, etc.

			Tests with @depends are excluded, because they might need to inspect an existing
			environment after the previous test, and creating new ModerationTestsuite object
			would clean the database.
		*/
		if ( !$this->hasDependencies() ) {
			$this->setDependencyInput( [ $this->makeNewTestsuite() ] );
		}
	}
}
