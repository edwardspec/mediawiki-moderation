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
	@brief Intercepts header() calls in PHP CLI.
*/

class ModerationTestsuiteMockAutoLoader {

	/**
		@brief Array of function rewrites. Populated by replaceFunction() calls.
		[ 'oldFunctionName1' => 'newFunctionName1', ... ]
	*/
	protected static $replacements = [];

	/**
		@brief Array of classnames which already have been mocked.
		[ 'WebRequest', 'FauxRequest', ... ]
	*/
	private static $alreadyMockedClasses = [];


	/**
		@brief Replace all calls to function A with calls to function B.
	*/
	static public function replaceFunction( $oldFunctionName, $newFunctionName ) {
		self::$replacements[$oldFunctionName] = $newFunctionName;
	}

	/**
		@brief Autoload the mock-modified file instead of the original file.
	*/
	static function autoload( $className ) {
		global $wgAutoloadClasses, $wgAutoloadLocalClasses;

		if ( isset( self::$alreadyMockedClasses[$className] ) ) {
			return; /* Already mocked. Will be loaded by AutoLoader from MediaWiki. */
		}
		self::$alreadyMockedClasses[$className] = true;

		# First, we ask MediaWiki to find the PHP file for us.
		$classMap = array_merge(
			$wgAutoloadClasses ?: [],
			$wgAutoloadLocalClasses ?: []
		);
		if ( !isset( $classMap[$className] ) ) {
			return; /* Original file not found */
		}

		# Second, we modify the file and tell PHP to use rewritten file
		$origFilename = $classMap[$className];
		$newFilename = preg_replace( '/([^\/]+)\.php$/', '.$1.mocked.php', $origFilename );

		$oldText = file_get_contents( $origFilename );
		$newText = self::rewriteFile( $oldText );

		if ( $newText == $oldText ) {
			return; /* No changes, can use the original file */
		}

		file_put_contents( $newFilename, $newText );

		# Third, we let MediaWiki know that $newFilename should be loaded for $className
		if ( isset( $wgAutoloadLocalClasses[$className] ) ) {
			$wgAutoloadLocalClasses[$className] = $newFilename;
		}
		if ( isset( $wgAutoloadClasses[$className] ) ) {
			$wgAutoloadClasses[$className] = $newFilename;
		}
	}

	/**
		@brief Rewrite the PHP code, replacing the calls to intercepted functions with mocks.
		@param $text Original PHP source code (string).
		@returns Modified source code (string).
	*/
	static protected function rewriteFile( $text ) {
		$functionNameRegex = join( '|', array_map( 'preg_quote', array_keys( self::$replacements ) ) );
		if ( !$functionNameRegex ) {
			return $text; /* replaceFunction() was never used */
		}

		return preg_replace_callback( '/^.*\s(' . $functionNameRegex . ')\s*\(/m', function ( $matches ) {
			list( $line, $functionName ) = $matches;
			if ( strpos( $line, 'function' ) !== false ) {
				// Don't rewrite function definitions, e.g. HttpStatus::header()
				// when rewriting "header" function.
				return $line;
			}

			return preg_replace(
				'/(\s)' . preg_quote( $functionName ) . '/',
				'$1' . self::$replacements[$functionName],
				$line
			);
		}, $text );
	}
}

spl_autoload_register( [ 'ModerationTestsuiteMockAutoLoader', 'autoload' ] );
