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
 * Unit test of EntryFactory.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\TimestampFormatter;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class EntryFactoryTest extends ModerationUnitTestCase {
	use ModifyDbRowTestTrait;

	/**
	 * Test that EntryFactory can create ModerationEntryFormatter, ModerationViewableEntry, etc.
	 * @covers MediaWiki\Moderation\EntryFactory
	 */
	public function testFactory() {
		$context = $this->createMock( IContextSource::class );

		'@phan-var IContextSource $context';

		$factory = $this->makeFactory();

		// Test makeFormatter()
		$row = (object)[ 'param1' => 'value1', 'param2' => 'value2' ];
		$formatter = $factory->makeFormatter( $row, $context );
		$this->assertInstanceOf( ModerationEntryFormatter::class, $formatter );

		// Test makeViewableEntry()
		$row = (object)[ 'param1' => 'value1', 'param2' => 'value2' ];
		$viewableEntry = $factory->makeViewableEntry( $row );
		$this->assertInstanceOf( ModerationViewableEntry::class, $viewableEntry );

		// Test makeApprovableEntry()
		$row = (object)[ 'type' => 'move', 'stash_key' => null ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryMove::class, $approvableEntry );

		$row = (object)[ 'type' => 'edit', 'stash_key' => null ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryEdit::class, $approvableEntry );

		$row = (object)[ 'type' => 'edit', 'stash_key' => 'some non-empty stash key' ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryUpload::class, $approvableEntry );
	}

	/**
	 * Test loadRow() and loadRowOrThrow().
	 * @param string $testedMethod Either 'loadRow' or 'loadRowOrThrow'.
	 * @param bool $isFound True to use correct mod_id of existing row. False to use incorrect id.
	 * @param int $dbType Either DB_MASTER or DB_REPLICA.
	 * @dataProvider dataProviderLoadRow
	 *
	 * @covers MediaWiki\Moderation\EntryFactory
	 */
	public function testLoadRow( $testedMethod, $isFound, $dbType ) {
		$expectedUA = 'SampleUserAgent/1.0.' . rand( 0, 100000 );
		$expectedIP = '10.11.12.13';

		if ( $isFound ) {
			$modid = $this->makeDbRow( [
				'mod_header_ua' => $expectedUA,
				'mod_ip' => $expectedIP
			] );
		} else {
			// Simulate "row not found" error.
			$modid = 12345;

			if ( $testedMethod == 'loadRowOrThrow' ) {
				$this->expectExceptionObject( new ModerationError( 'moderation-edit-not-found' ) );
			}
		}

		$factory = $this->makeFactory();
		$row = $factory->$testedMethod(
			$modid,
			[ 'mod_header_ua AS header_ua', 'mod_ip AS ip' ],
			$dbType
		);

		if ( $isFound ) {
			$this->assertNotFalse( $row );
			$this->assertEquals( $expectedUA, $row->header_ua );
			$this->assertEquals( $expectedIP, $row->ip );
			$this->assertEquals( $modid, $row->id, "Incorrect \$row->id in return value of $testedMethod." );

			$this->assertEquals( [ 'header_ua', 'ip', 'id' ], array_keys( get_object_vars( $row ) ),
				"List of properties in \$row (return value of $testedMethod)."
			);
		} else {
			$this->assertFalse( $row,
				"The row shouldn't exist, but $testedMethod didn't return false." );
		}
	}

	/**
	 * Provide datasets for testLoadRow() runs.
	 * @return array
	 */
	public function dataProviderLoadRow() {
		return [
			'situation when loadRow() finds a row' => [ 'loadRow', true, DB_MASTER ],
			'situation when loadRow() doesn\'t find a row' => [ 'loadRow', false, DB_MASTER ],
			'situation when loadRowOrThrow() finds a row' => [ 'loadRowOrThrow', true, DB_MASTER ],
			'situation when loadRowOrThrow() doesn\'t find a row' => [ 'loadRowOrThrow', false, DB_MASTER ]
		];
	}

	/**
	 * Test findApprovableEntry() and findViewableEntry().
	 * @param string $testedMethod Name of method, e.g. "findViewableEntry".
	 * @param bool $isFound True to use correct mod_id of existing row. False to use incorrect id.
	 * @param string $expectedClass Return value of $testedMethod should be object of this class.
	 * @dataProvider dataProviderFindEntryById
	 *
	 * @covers MediaWiki\Moderation\EntryFactory
	 */
	public function testFindEntryById( $testedMethod, $isFound, $expectedClass ) {
		if ( $isFound ) {
			$modid = $this->makeDbRow();
		} else {
			// Simulate "row not found" error.
			$modid = 12345;
			$this->expectExceptionObject( new ModerationError( 'moderation-edit-not-found' ) );
		}

		$factory = $this->makeFactory();
		$entry = $factory->$testedMethod( $modid );

		$this->assertInstanceOf( $expectedClass, $entry );
	}

	/**
	 * Provide datasets for testfindEntryById() runs.
	 * @return array
	 */
	public function dataProviderFindEntryById() {
		return [
			'findViewableEntry() finds an entry' =>
				[ 'findViewableEntry', true, ModerationViewableEntry::class ],
			'findViewableEntry() doesn\'t find an entry' =>
				[ 'findViewableEntry', false, ModerationViewableEntry::class ],
			'findApprovableEntry() finds an entry' =>
				[ 'findApprovableEntry', true, ModerationApprovableEntry::class ],
			'findApprovableEntry() doesn\'t find an entry' =>
				[ 'findApprovableEntry', false, ModerationApprovableEntry::class ]
		];
	}

	/**
	 * Create one EntryFactory with mocked parameters.
	 * @return EntryFactory
	 */
	private function makeFactory() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$timestampFormatter = $this->createMock( TimestampFormatter::class );

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var ActionLinkRenderer $actionLinkRenderer';
		'@phan-var TimestampFormatter $timestampFormatter';

		return new EntryFactory( $linkRenderer, $actionLinkRenderer, $timestampFormatter );
	}
}
