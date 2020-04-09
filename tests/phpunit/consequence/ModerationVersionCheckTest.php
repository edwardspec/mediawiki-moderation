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

use Wikimedia\TestingAccessWrapper;

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
	 * Verify that static methods like areTagsSupported() work for different DbUpdatedVersion values.
	 * @param string $method Name of static method in the ModerationVersionCheck:: class.
	 * @param string $version
	 * @param mixed $expectedResult
	 * @dataProvider dataProviderFeatureChecks
	 * @covers ModerationVersionCheck
	 */
	public function testFeatureChecks( $method, $version, $expectedResult ) {
		$this->mockDbUpdatedVersion( $version );
		$this->assertSame( $expectedResult, ModerationVersionCheck::$method(),
			"Result of $method() doesn't match expected when DbUpdatedVersion=$version" );
	}

	/**
	 * Provide datasets for testFeatureChecks() runs.
	 * @return array
	 */
	public function dataProviderFeatureChecks() {
		return [
			[ 'areTagsSupported', '1.1.28', false ],
			[ 'areTagsSupported', '1.1.29', true ],
			[ 'areTagsSupported', '1.1.30', true ],
			[ 'usesDbKeyAsTitle', '1.1.30', false ],
			[ 'usesDbKeyAsTitle', '1.1.31', true ],
			[ 'usesDbKeyAsTitle', '1.1.32', true ],
			[ 'hasModType', '1.2.16', false ],
			[ 'hasModType', '1.2.17', true ],
			[ 'hasModType', '1.2.18', true ],
			[ 'hasUniqueIndex', '1.2.8', false ],
			[ 'hasUniqueIndex', '1.2.9', true ],
			[ 'hasUniqueIndex', '1.2.10', true ],
			[ 'preloadableYes', '1.2.8', 1 ],
			[ 'preloadableYes', '1.2.9', 0 ],
			[ 'preloadableYes', '1.2.10', 0 ],
			[ 'setPreloadableToNo', '1.2.8', 'mod_preloadable=0' ],
			[ 'setPreloadableToNo', '1.2.9', 'mod_preloadable=mod_id' ],
			[ 'setPreloadableToNo', '1.2.10', 'mod_preloadable=mod_id' ]
		];
	}

	/**
	 * Verify that getDbUpdatedVersion() checks the cache and (if found) returns the cached value.
	 * @covers ModerationVersionCheck
	 */
	public function testDbUpdatedVersionFromCache() {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-lastDbUpdateVersion' )
		)->willReturn( '{MockedCacheKey}' );

		$cache->expects( $this->once() )->method( 'get' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' )
		)->willReturn( '{MockedResult}' );

		$cache->expects( $this->never() )->method( 'set' );

		'@phan-var BagOStuff $cache';

		$versionCheck = new ModerationVersionCheck( $cache );
		$result = TestingAccessWrapper::newFromObject( $versionCheck )->getDbUpdatedVersion();

		$this->assertSame( '{MockedResult}', $result, 'Unexpected result from getDbUpdatedVersion()' );
	}

	/**
	 * Verify that getDbUpdatedVersion() uses *Uncached() method if value is not found in cache.
	 * @covers ModerationVersionCheck
	 */
	public function testDbUpdatedVersionNotFoundInCache() {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-lastDbUpdateVersion' )
		)->willReturn( '{MockedCacheKey}' );

		$cache->expects( $this->once() )->method( 'get' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' )
		)->willReturn( false ); // Not found in cache

		$cache->expects( $this->once() )->method( 'set' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedResult}' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 86400 )
		);

		'@phan-var BagOStuff $cache';

		// Create a partial mock: getDbUpdatedVersionUncached() is mocked, but all other methods are real.
		$versionCheck = $this->getMockBuilder( ModerationVersionCheck::class )
			->setConstructorArgs( [ $cache ] )
			->setMethods( [ 'getDbUpdatedVersionUncached' ] )
			->getMock();

		$versionCheck->expects( $this->once() )->method( 'getDbUpdatedVersionUncached' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->isInstanceOf( IDatabase::class )
		)->willReturn( '{MockedResult}' );

		$result = TestingAccessWrapper::newFromObject( $versionCheck )->getDbUpdatedVersion();
		$this->assertSame( '{MockedResult}', $result, 'Unexpected result from getDbUpdatedVersion()' );
	}

	/**
	 * Verify that invalidateCache() clears the cache.
	 * @covers ModerationVersionCheck
	 */
	public function testInvalidateCache() {
		$cache = $this->createMock( BagOStuff::class );
		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-lastDbUpdateVersion' )
		)->willReturn( '{MockedCacheKey}' );

		$cache->expects( $this->once() )->method( 'delete' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' )
		);

		'@phan-var BagOStuff $cache';

		$versionCheck = new ModerationVersionCheck( $cache );
		$this->setService( 'Moderation.VersionCheck', $versionCheck );

		ModerationVersionCheck::invalidateCache();
	}

	/**
	 * Verify that getDbUpdatedVersionUncached() correctly detects the DB schema version.
	 * @param string $expectedResult
	 * @param string $dbType Mocked result of $db->getType()
	 * @param array $fieldExists E.g. [ 'mod_type' => true ]
	 * @param bool $isLoadIndexUnique Mocked result of $db->indexUnique(..., 'moderation_load')
	 * @dataProvider dataProviderDbUpdatedVersionUncached
	 * @covers ModerationVersionCheck
	 */
	public function testDbUpdatedVersionUncached( $expectedResult, $dbType, array $fieldExists,
		$isLoadIndexUnique
	) {
		// Mock the database (which is a parameter of getDbUpdatedVersionUncached).
		$db = $this->createMock( IMaintainableDatabase::class );
		$db->expects( $this->any() )->method( 'getType' )->willReturn( $dbType );

		$db->expects( $this->any() )->method( 'fieldExists' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation' )
		)->will( $this->returnCallback( function ( $_, $field ) use ( $fieldExists ) {
			return $fieldExists[$field] ?? false;
		} ) );

		$db->expects( $this->any() )->method( 'indexUnique' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation_load' )
		)->willReturn( $isLoadIndexUnique );

		// We are not testing the differences between 1.1.29/1.1.31 DB schema,
		// as no wikis are using it.
		$db->expects( $this->any() )->method( 'selectRowCount' )->willReturn( 0 );

		$versionCheck = new ModerationVersionCheck( new EmptyBagOStuff() );

		$wrapper = TestingAccessWrapper::newFromObject( $versionCheck );
		$result = $wrapper->getDbUpdatedVersionUncached( $db );

		$this->assertSame( $expectedResult, $result,
			'Unexpected result from getDbUpdatedVersionUncached()' );
	}

	/**
	 * Provide datasets for testDbUpdatedVersionUncached() runs.
	 * @return array
	 */
	public function dataProviderDbUpdatedVersionUncached() {
		return [
			'1.4.12' => [ '1.4.12', 'postgres', [ 'mod_type' => true, 'mod_tags' => true ], true ],
			'1.2.17' => [ '1.2.17', 'mysql', [ 'mod_type' => true, 'mod_tags' => true ], true ],
			'1.2.9' => [ '1.2.9', 'mysql', [ 'mod_type' => false, 'mod_tags' => true ], true ],
			'1.1.31' => [ '1.1.31', 'mysql', [ 'mod_type' => false, 'mod_tags' => true ], false ],
			'1.0.0' => [ '1.0.0', 'mysql', [ 'mod_type' => false, 'mod_tags' => false ], false ]
		];
	}

	/**
	 * Replace VersionCheck service with a mock that returns $version from getDbUpdatedVersion().
	 * @param string $version
	 */
	private function mockDbUpdatedVersion( $version ) {
		$versionCheck = $this->getMockBuilder( ModerationVersionCheck::class )
			->setConstructorArgs( [ new EmptyBagOStuff() ] )
			->setMethods( [ 'getDbUpdatedVersion' ] )
			->getMock();
		$versionCheck->expects( $this->once() )->method( 'getDbUpdatedVersion' )->willReturn( $version );
		$this->setService( 'Moderation.VersionCheck', $versionCheck );
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
