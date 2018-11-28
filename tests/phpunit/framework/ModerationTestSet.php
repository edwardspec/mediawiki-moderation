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
 * Parent class for TestSet objects used in the Moderation testsuite.
 */

abstract class ModerationTestsuiteTestSet {
	/** @var ModerationTestCase */
	private $testcase;

	/**
	 * Returns current ModerationTestCase object.
	 * Used for calling assert*() methods.
	 */
	protected function getTestcase() {
		return $this->testcase;
	}

	/** Returns ModerationTestsuite object. */
	protected function getTestsuite() {
		return $this->getTestcase()->getTestsuite();
	}

	/**
	 * Run this TestSet from input of dataProvider.
	 * @param array $options Parameters of test, e.g. [ 'user' => '...', 'title' => '...' ].
	 * @param ModerationTestCase $testcase
	 */
	final public static function run( array $options, ModerationTestCase $testcase ) {
		$set = new static( $options, $testcase );

		$set->makeChanges();
		$set->assertResults( $testcase );
	}

	/**
	 * Construct TestSet from the input of dataProvider.
	 */
	final protected function __construct( array $options, ModerationTestCase $testcase ) {
		$this->testcase = $testcase;
		$this->applyOptions( $options );
	}

	/*-------------------------------------------------------------------*/

	/**
	 * Initialize this TestSet from the input of dataProvider.
	 */
	abstract protected function applyOptions( array $options );

	/**
	 * Execute this TestSet, making the edit with requested parameters.
	 */
	abstract protected function makeChanges();

	/**
	 * Assert whether the situation after the edit is correct or not.
	 */
	abstract protected function assertResults( ModerationTestCase $testcase );

	/*-------------------------------------------------------------------*/

	/**
	 * Assert that $timestamp is a realistic time for changes made during this test.
	 * @param string $timestamp Timestamp in MediaWiki format (14 digits).
	 */
	protected function assertTimestampIsRecent( $timestamp ) {
		// How many seconds ago are allowed without failing the assertion.
		$allowedRangeInSeconds = 60;

		$this->getTestcase()->assertLessThanOrEqual(
			wfTimestampNow(),
			$timestamp,
			'assertTimestampIsRecent(): timestamp of existing change is in the future.'
		);

		$ts = new MWTimestamp();
		$ts->timestamp->modify( "- $allowedRangeInSeconds seconds" );
		$minTimestamp = $ts->getTimestamp( TS_MW );

		$this->getTestcase()->assertGreaterThan(
			$minTimestamp,
			$timestamp,
			'assertTimestampIsRecent(): timestamp of recently made change is too far in the past.'
		);
	}

	/**
	 * Assert that recent row in 'moderation' SQL table consists of $expectedFields.
	 * @param array $expectedFields Key-value list of all mod_* fields.
	 * @throws AssertionFailedError
	 * @return stdClass $row
	 */
	protected function assertRowEquals( array $expectedFields ) {
		$testcase = $this->getTestcase();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		foreach ( $expectedFields as $key => $val ) {
			if ( $val instanceof ModerationTestSetRegex ) {
				$testcase->assertRegExp( $val->regex, $row->$key, "Field $key doesn't match regex" );
			} else {
				$testcase->assertEquals( $val, $row->$key, "Field $key doesn't match expected" );
			}
		}
		return $row;
	}

	/**
	 * Create an existing page (or file) before the test.
	 * @param Title $title
	 * @param string $initialText
	 * @param string|null $filename If not null, upload another file (NOT $filename) before test.
	 */
	protected function precreatePage( Title $title, $initialText, $filename = null ) {
		$t = $this->getTestsuite();
		$t->loginAs( $t->moderator );

		if ( $filename ) {
			// Important: $filename is the file that will be uploaded by the test itself.
			// We want to pre-upload a different file here, so that attempts
			// to later approve $filename wouldn't fail with (fileexists-no-change).
			$anotherFilename = ( strpos( $filename, 'image100x100.png' ) === false ) ?
				'image100x100.png' : 'image640x50.png';

			$t->getBot( 'api' )->upload(
				$title->getText(),
				$anotherFilename,
				$initialText
			);
		} else {
			// Normal page (not an upload).
			ModerationTestUtil::fastEdit(
				$title,
				$initialText,
				'', // edit summary doesn't matter
				$t->moderator
			);
		}
	}
}

/**
 * Regular expression that can be used in assertRowEquals() as values of $expectedFields.
 */
class ModerationTestSetRegex {
	public $regex;

	public function __construct( $regex ) {
		$this->regex = $regex;
	}
}
