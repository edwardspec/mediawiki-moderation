<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Trait that provides assertConsequencesEqual(), which is useful for Consequence tests.
 */

use MediaWiki\Moderation\IConsequence;
use Wikimedia\TestingAccessWrapper;

trait ConsequenceTestTrait {
	/**
	 * Assert that $expectedConsequences are exactly the same as $actualConsequences.
	 * @param IConsequence[] $expectedConsequences
	 * @param IConsequence[] $actualConsequences
	 */
	private function assertConsequencesEqual(
		array $expectedConsequences,
		array $actualConsequences
	) {
		$expectedCount = count( $expectedConsequences );
		$this->assertCount( $expectedCount, $actualConsequences,
			"Unexpected number of consequences" );

		array_map( function ( $expected, $actual ) {
			$expectedClass = get_class( $expected );
			$this->assertInstanceOf( $expectedClass, $actual,
				"Class of consequence doesn't match expected" );

			// Remove optionally calculated fields from Title/User objects within both consequences
			$this->flattenFields( $expected );
			$this->flattenFields( $actual );

			$this->assertEquals( $expected, $actual, "Parameters of consequence don't match expected" );
		}, $expectedConsequences, $actualConsequences );
	}

	/**
	 * Recalculate Title/User fields to ensure that no optionally calculated fields are calculated.
	 * This is needed to use assertEquals() of consequences: direct comparison of Title objects
	 * would fail, because Title object has fields like mUserCaseDBKey (they must not be compared).
	 */
	private function flattenFields( IConsequence $consequence ) {
		$wrapper = TestingAccessWrapper::newFromObject( $consequence );
		try {
			$wrapper->title = Title::newFromText( (string)$wrapper->title );
		} catch ( ReflectionException $e ) {
			// Not applicable to this Consequence.
		}

		try {
			$wrapper->originalAuthor = User::newFromName( $wrapper->originalAuthor->getName() );
		} catch ( ReflectionException $e ) {
			// Not applicable to this Consequence.
		}
	}

	/*----------------------------------------------------------------------------------------*/
	/* These abstract methods are provided by PHPUnit-related classes. */

	abstract public function assertEquals( $expected, $actual, $message = '' );

	abstract public function assertInstanceOf( $expected, $actual, $message = '' );

	abstract public function assertCount( $expectedCount, $haystack, $message = '' );
}
