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
 * Checks i18n/*.json files for syntax errors.
 */

class ModerationI18nTest extends ModerationTestCase {
	/**
	 * Ensures that $path is a valid JSON file.
	 * @dataProvider dataProvider
	 */
	public function testLanguageFile( $path ) {
		$status = FormatJson::parse( file_get_contents( $path ) );
		$this->assertTrue( $status->isGood(),
			'testLanguageFile(): ' . realpath( $path ) .
			' is not a valid JSON: [' . $status->getMessage()->getKey() . ']' );
	}

	/**
	 * Provide datasets for testLanguageFile() runs.
	 */
	public function dataProvider() {
		return array_map( function ( $path ) {
			return [ $path ];
		}, glob( __DIR__ . '/../../../..{,/api}/i18n/*.json', GLOB_BRACE ) );
	}
}
