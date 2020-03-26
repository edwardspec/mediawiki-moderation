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
 * Unit test of ModerationAction.
 */

use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ModerationActionUnitTest extends ModerationUnitTestCase {
	use ModifyDbRowTestTrait;

	protected $tablesUsed = [ 'moderation' ];

	/**
	 * Test ModerationAction::run() does all the necessary preparations and then calls execute().
	 * @param bool $requiresWrite True to test non-readonly action, false to test readonly action.
	 * @param bool $simulateReadOnlyMode If true, the wiki will be ReadOnly during this test.
	 * @dataProvider dataProviderActionRun
	 *
	 * @covers ModerationAction
	 */
	public function testActionRun( $requiresWrite, $simulateReadOnlyMode ) {
		if ( $simulateReadOnlyMode ) {
			$this->setMwGlobals( 'wgReadOnly', 'for some reason' );
		}

		$profiler = Profiler::instance()->getTransactionProfiler();
		$profiler->resetExpectations();

		$expectedResult = [ 'key1' => 'value1', 'something' => 'else' ];
		$expectReadOnlyError = ( $requiresWrite && $simulateReadOnlyMode );

		$mock = $this->getModerationActionMock();
		$mock->expects( $this->once() )->method( 'requiresWrite' )->willReturn( $requiresWrite );

		if ( $expectReadOnlyError ) {
			$mock->expects( $this->never() )->method( 'execute' );
		} else {
			$mock->expects( $this->once() )->method( 'execute' )->willReturn( $expectedResult );
		}

		$readOnlyErrorThrown = false;
		$result = null;
		try {
			// @phan-suppress-next-line PhanUndeclaredMethod
			$result = $mock->run();
		} catch ( ReadOnlyError $_ ) {
			$readOnlyErrorThrown = true;
		}

		if ( $expectReadOnlyError ) {
			$this->assertTrue( $readOnlyErrorThrown,
				"ReadOnlyError wasn't thrown by non-readonly action in readonly mode." );
		} else {
			$this->assertFalse( $readOnlyErrorThrown, "Unexpected ReadOnlyError." );
			$this->assertSame( $expectedResult, $result,
					"ModerationAction::run() didn't return the result of execute()" );
		}

		// Verify actions that require write are modifying expectations of $profiler,
		// and that expectations are unchanged after readonly actions.
		// Because we called resetExpectations() above, "unchanged" means "INF for everything".
		$profilerWrapper = TestingAccessWrapper::newFromObject( $profiler );
		$differentExpectations = array_unique( array_values( $profilerWrapper->expect ) );

		if ( $requiresWrite && !$simulateReadOnlyMode ) {
			$this->assertNotEquals( [ INF ], $differentExpectations,
				"TransactionProfiler expectations weren't set by non-readonly action." );
		} else {
			$this->assertEquals( [ INF ], $differentExpectations,
				"TransactionProfiler expectations were changed when they shouldn't have been." );
		}
	}

	/**
	 * Make a mock for ModerationAction class (which is an abstract class).
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function getModerationActionMock() {
		return $this->getMockBuilder( ModerationAction::class )
			->disableOriginalConstructor()
			->setMethods( [ 'requiresWrite', 'execute' ] )
			->getMockForAbstractClass();
	}

	/**
	 * Test ModerationAction::getUserpageOfPerformer() returns correct userpage when edit is found.
	 * @covers ModerationAction::getUserpageOfPerformer()
	 */
	public function testUserpageOfPerformer() {
		$username = 'Test user ' . rand( 0, 100000 );
		$modid = $this->makeDbRow( [ 'mod_user_text' => $username ] );

		$mockWrapper = TestingAccessWrapper::newFromObject( $this->getModerationActionMock() );
		$mockWrapper->id = $modid;
		$title = $mockWrapper->getUserpageOfPerformer();

		$this->assertInstanceof( Title::class, $title );
		$this->assertEquals( "User:$username", $title->getFullText() );
	}

	/**
	 * Test ModerationAction::getUserpageOfPerformer() returns false when edit is NOT found.
	 * @covers ModerationAction::getUserpageOfPerformer()
	 */
	public function testUserpageOfPerformerNotFound() {
		$mockWrapper = TestingAccessWrapper::newFromObject( $this->getModerationActionMock() );
		$mockWrapper->id = 123456;

		$this->assertFalse( $mockWrapper->getUserpageOfPerformer() );
	}

	/**
	 * Test ModerationAction::getUserpageOfPerformer() works for [[User:0]] (where 0 is username).
	 * @covers ModerationAction::getUserpageOfPerformer()
	 */
	public function testUserpageOfPerformerWhenNameIs0() {
		$modid = $this->makeDbRow( [ 'mod_user_text' => '0' ] );

		$mockWrapper = TestingAccessWrapper::newFromObject( $this->getModerationActionMock() );
		$mockWrapper->id = $modid;
		$title = $mockWrapper->getUserpageOfPerformer();

		$this->assertInstanceof( Title::class, $title );
		$this->assertEquals( 'User:0', $title->getFullText() );
	}

	/**
	 * Provide datasets for testActionRun() runs.
	 * @return array
	 */
	public function dataProviderActionRun() {
		return [
			'readonly action (wiki NOT in readonly mode)' => [ false, false ],
			'readonly action (wiki in readonly mode)' => [ false, true ],
			'non-readonly action (wiki NOT in readonly mode)' => [ true, false ],
			'non-readonly action (wiki in readonly mode)' => [ true, true ]
		];
	}
}
