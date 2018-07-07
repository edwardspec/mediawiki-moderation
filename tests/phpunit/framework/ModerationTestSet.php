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
 * @brief Parent class for TestSet objects used in the Moderation testsuite.
 */

abstract class ModerationTestsuiteTestSet {
	private $testsuite; /**< ModerationTestsuite object */
	private $testcase; /**< PHPUnitTestCase object */

	/** @brief Returns ModerationTestsuite object. */
	protected function getTestsuite() {
		return $this->testsuite;
	}

	/**
	 * @brief Returns current PHPUnitTestCase object.
	 * Used for calling assert*() methods.
	 */
	protected function getTestcase() {
		return $this->testcase;
	}

	/**
	 * @brief Run this TestSet from input of dataProvider.
	 * @param $options Parameters of test, e.g. [ 'user' => 'Bear expert', 'title' => 'Black bears' ].
	 * @param $testcase Current PHPUnitTestCase object.
	 */
	final public static function run( array $options, MediaWikiTestCase $testcase ) {
		$set = new static( $options, $testcase );

		$set->makeChanges();
		$set->assertResults( $testcase );
	}

	/**
	 * @brief Construct TestSet from the input of dataProvider.
	 */
	final protected function __construct( array $options, MediaWikiTestCase $testcase ) {
		$this->testsuite = new ModerationTestsuite; // Cleans the database
		$this->testcase = $testcase;

		$this->applyOptions( $options );
	}

	/*-------------------------------------------------------------------*/

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	abstract protected function applyOptions( array $options );

	/**
	 * @brief Execute this TestSet, making the edit with requested parameters.
	 */
	abstract protected function makeChanges();

	/**
	 * @brief Assert whether the situation after the edit is correct or not.
	 */
	abstract protected function assertResults( MediaWikiTestCase $testcase );
}
