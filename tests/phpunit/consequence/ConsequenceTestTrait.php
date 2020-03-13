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

			$this->assertEquals(
				$this->toArray( $expected ),
				$this->toArray( $actual ),
				"Parameters of consequence don't match expected"
			);
		}, $expectedConsequences, $actualConsequences );
	}

	/**
	 * Convert $consequence into a human-readable array of properties (for logging and comparison).
	 * Properties with types like Title are replaced by [ className, mixed, ... ] arrays.
	 * @param IConsequence $consequence
	 * @return array
	 */
	protected function toArray( IConsequence $consequence ) {
		$fields = [];

		$rc = new ReflectionClass( $consequence );
		foreach ( $rc->getProperties() as $prop ) {
			$prop->setAccessible( true );

			$value = $prop->getValue( $consequence );
			$type = gettype( $value );
			if ( $type == 'object' ) {
				if ( $value instanceof Title ) {
					$value = [ 'Title', (string)$value ];
				} elseif ( $value instanceof WikiPage ) {
					$value = [ 'WikiPage', (string)$value->getTitle() ];
				} elseif ( $value instanceof User ) {
					$value = [ 'User', $value->getId(), $value->getName() ];
				}
			} elseif ( $type == 'array' ) {
				// Having timestamps in normalized form leads to flaky comparison results,
				// because it's possible that "expected timestamp" was calculated
				// in a different second than mod_timestamp in an actual Consequence.
				unset( $value['mod_timestamp'] );
			}

			$name = $prop->getName();
			$fields[$name] = $value;
		}

		return [ get_class( $consequence ), $fields ];
	}

	/*----------------------------------------------------------------------------------------*/
	/* These abstract methods are provided by PHPUnit-related classes. */

	abstract public static function assertEquals( $expected, $actual, $message = '',
		$delta = 0.0, $maxDepth = 10, $canonicalize = null, $ignoreCase = null );

	abstract public static function assertInstanceOf( $expected, $actual, $message = '' );

	abstract public static function assertCount( $expectedCount, $haystack, $message = '' );
}
