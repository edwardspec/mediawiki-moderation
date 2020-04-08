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
 * Unit test of ModerationVersionCheck and ModerationCompatTools.
 */

require_once __DIR__ . "/autoload.php";

class ModerationVersionCheckTest extends ModerationUnitTestCase {
	/**
	 * Ensure that all returned values are correct for the most recent DB schema.
	 * Because a testsuite always runs on a newly created DB, B/C values should not be returned.
	 *
	 * @covers ModerationVersionCheck
	 */
	public function testModernSchema() {
		$versionCheck = new ModerationVersionCheck( new HashBagOStuff() );
		$this->setService( 'Moderation.VersionCheck', $versionCheck );

		$this->assertTrue( ModerationVersionCheck::areTagsSupported(), 'areTagsSupported' );
		$this->assertTrue( ModerationVersionCheck::usesDbKeyAsTitle(), 'usesDbKeyAsTitle' );
		$this->assertTrue( ModerationVersionCheck::hasModType(), 'hasModType' );
		$this->assertTrue( ModerationVersionCheck::hasUniqueIndex(), 'hasUniqueIndex' );

		$title = Title::newFromText( 'Title with spaces' );
		$this->assertSame( 'Title_with_spaces', ModerationVersionCheck::getModTitleFor( $title ),
			"getModTitleFor" );

		$this->assertSame( 0, ModerationVersionCheck::preloadableYes(), 'preloadableYes' );
		$this->assertSame( 'mod_preloadable=mod_id', ModerationVersionCheck::setPreloadableToNo(),
			'setPreloadableToNo' );
	}

	/**
	 * @covers ModerationCompatTools::getContentLanguage
	 */
	public function testContentLanguage() {
		global $wgLanguageCode;

		$lang = ModerationCompatTools::getContentLanguage();
		$this->assertInstanceOf( Language::class, $lang );
		$this->assertEquals( $wgLanguageCode, $lang->getCode() );
	}
}
