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
 * Trait that allows tests to use PHPUnit 8 assert methods in MediaWiki 1.31, which uses PHPUnit 6.
 *
 * The only reason this is needed: old PHPUnit 6.5 methods emit deprecation warnings
 * when used in PHPUnit 8 (and presence of these warnings is treated as failed test).
 */

// phpcs:disable MediaWiki.Files.ClassMatchesFilename.NotMatch
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

use PHPUnit\Framework\TestCase;

/**
 * Empty trait for MediaWiki 1.34+, which uses PHPUnit 8+.
 */
trait CompatPHPUnit6TraitNotNeeded {
}

/**
 * Compatibility trait for MediaWiki 1.31-1.33, which uses PHPUnit 6.5.
 * @method static assertContains($a, $b, string $message='')
 * @method static assertNotContains($a, $b, string $message='')
 */
trait CompatPHPUnit6TraitNeeded {
	public static function assertStringContainsString( string $needle, string $haystack, string $message = '' ) {
		self::assertContains( $needle, $haystack, $message );
	}

	public static function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ) {
		self::assertNotContains( $needle, $haystack, $message );
	}
}

// Enable the polyfill trait if needed.
if ( method_exists( TestCase::class, 'assertStringContainsString' ) ) {
	// PHPUnit 8+, polyfill is not needed.
	class_alias( CompatPHPUnit6TraitNotNeeded::class, 'CompatPHPUnit6Trait' );
} else {
	// PHPUnit 6.5 (MediaWiki 1.31-1.33), use the polyfill.
	// @phan-suppress-next-line PhanRedefineClassAlias
	class_alias( CompatPHPUnit6TraitNeeded::class, 'CompatPHPUnit6Trait' );
}
