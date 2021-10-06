<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
 * Unit test of ModerationActionApprove.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;

require_once __DIR__ . "/autoload.php";

class ModerationActionApproveTest extends ModerationUnitTestCase {
	use ActionTestTrait;

	/**
	 * Check result/consequences of modaction=approve.
	 * @covers ModerationActionApprove
	 */
	public function testExecuteApproveOne() {
		$modid = 12345;

		$entry = $this->createMock( ModerationApprovableEntry::class );
		$entry->expects( $this->once() )->method( 'approve' );

		$action = $this->makeActionForTesting( ModerationActionApprove::class,
			function ( $context, $entryFactory, $manager ) use ( $entry, $modid ) {
				$context->setRequest( new FauxRequest( [
					'modid' => $modid,
					'modaction' => 'approve'
				] ) );

				$entryFactory->expects( $this->once() )->method( 'findApprovableEntry' )->with(
					$this->identicalTo( $modid )
				)->willReturn( $entry );

				$manager->expects( $this->once() )->method( 'add' )->with( $this->consequenceEqualTo(
					new InvalidatePendingTimeCacheConsequence()
				) );
			}
		);

		$this->assertSame( [ 'approved' => [ $modid ] ], $action->execute() );
	}

	/**
	 * Check result/consequences of modaction=approveall.
	 * @param array $opt
	 * @dataProvider dataProviderExecuteApproveAll
	 * @covers ModerationActionApprove
	 */
	public function testExecuteApproveAll( array $opt = [] ) {
		$username = $opt['usernameOfPerformer'] ?? "Author's username";
		$expectedError = $opt['expectedError'] ?? null;
		$numberOfEntriesFound = $opt['numberOfEntriesFound'] ?? 5;
		$approvedCount = $opt['approvedCount'] ?? $numberOfEntriesFound;
		$failedCount = $opt['failedCount'] ?? 0;

		$entries = [];
		$expectedResult = [ 'approved' => [], 'failed' => [] ];

		// Use "qqx" pseudo-language to assert 'info' keys caused by ModerationError exceptions
		$this->setContentLang( 'qqx' );

		for ( $i = 0; $i < $numberOfEntriesFound; $i++ ) {
			$modid = 10000 + $i;

			$entry = $this->createMock( ModerationApprovableEntry::class );
			$entry->expects( $this->any() )->method( 'getId' )->willReturn( $modid );

			if ( $expectedError ) {
				$entry->expects( $this->never() )->method( 'approve' );
			} else {
				if ( $i < $failedCount ) {
					$entry->expects( $this->once() )->method( 'approve' )->willThrowException(
						new ModerationError( 'some-mocked-error-code' )
					);
					$expectedResult['failed'][$modid] = [
						'code' => 'some-mocked-error-code',
						'info' => '(some-mocked-error-code)'
					];
				} else {
					$entry->expects( $this->once() )->method( 'approve' );
					$expectedResult['approved'][$modid] = '';
				}
			}

			$entries[] = $entry;
		}

		$action = $this->makeActionForTesting( ModerationActionApprove::class,
			function ( $context, $entryFactory, $manager )
			use ( $entries, $username, $approvedCount ) {
				$moderator = self::getTestUser()->getUser();

				$context->setRequest( new FauxRequest( [
					'modid' => 12345,
					'modaction' => 'approveall'
				] ) );
				$context->setUser( $moderator );

				// TODO: maybe allow makeActionForTesting() to create a partial mock,
				// and then just mock ModerationAction::getUserpageOfPerformer()?
				$entryFactory->expects( $this->once() )->method( 'loadRow' )->with(
					$this->identicalTo( 12345 ),
					$this->identicalTo( [ 'mod_user_text AS user_text' ] )
				)->willReturn( $username ? (object)[ 'user_text' => $username ] : false );

				if ( !$username ) {
					// Exception will be thrown, so no further consequences are expected.
					$manager->expects( $this->never() )->method( 'add' );
					return;
				}

				$entryFactory->expects( $this->once() )->method( 'findAllApprovableEntries' )->with(
					$this->identicalTo( $username )
				)->willReturn( $entries );

				if ( !$entries || !$approvedCount ) {
					// Exception will be thrown, so no further consequences are expected.
					$manager->expects( $this->never() )->method( 'add' );
					return;
				}

				$manager->expects( $this->at( 0 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new AddLogEntryConsequence(
						'approveall',
						$moderator,
						Title::makeTitle( NS_USER, $username ),
						[ '4::count' => $approvedCount ]
					)
				) );
				$manager->expects( $this->at( 1 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new InvalidatePendingTimeCacheConsequence()
				) );
				$manager->expects( $this->exactly( 2 ) )->method( 'add' );
			}
		);

		if ( $expectedError ) {
			$this->expectExceptionObject( new ModerationError( $expectedError ) );
		}
		$this->assertSame( $expectedResult, $action->execute() );
	}

	/**
	 * Provide datasets for testExecuteApproveAll() runs.
	 * @return array
	 */
	public function dataProviderExecuteApproveAll() {
		return [
			'successful approveall' => [ [] ],
			'partially successful approveall: approved 3 edits, failed to approve 2 edits' => [ [
				'numberOfEntriesFound' => 5,
				'approvedCount' => 3,
				'failedCount' => 2
			] ],
			'unsuccessful approveall: approved 0 edits, failed to approve 5 edits' => [ [
				'numberOfEntriesFound' => 5,
				'approvedCount' => 0,
				'failedCount' => 5
			] ],
			'error: no approvable entries found' => [ [
				'expectedError' => 'moderation-nothing-to-approveall',
				'numberOfEntriesFound' => 0
			] ],
			'error: pending edit not found' => [ [
				'expectedError' => 'moderation-edit-not-found',
				'usernameOfPerformer' => false
			] ]
		];
	}

	/**
	 * Verify that outputResult() correctly converts return value of execute() into HTML output.
	 * @param array $expectedHtml What should outputResult() write into its OutputPage parameter.
	 * @param array $executeResult Return value of execute().
	 * @dataProvider dataProviderOutputResult
	 * @covers ModerationActionApprove
	 */
	public function testOutputResult( $expectedHtml, array $executeResult ) {
		$action = $this->makeActionForTesting( ModerationActionApprove::class );

		// Obtain a new OutputPage object that is different from OutputPage in $context.
		// This verifies that outputResult() does indeed use its second parameter for output
		// rather than printing into $this->getContext()->getOutput() (which would be incorrect).
		$output = clone $action->getOutput();
		$action->outputResult( $executeResult, $output );

		$this->assertSame( $expectedHtml, $output->getHTML(),
			"Result of outputResult() doesn't match expected." );
	}

	/**
	 * Provide datasets for testOutputResult() runs.
	 * @return array
	 */
	public function dataProviderOutputResult() {
		return [
			'approved one edit' => [
				"<p>(moderation-approved-ok: 1)\n</p>",
				[ 'approved' => [ 12345 ] ]
			],
			'approved multiple edits' => [
				"<p>(moderation-approved-ok: 4)\n</p>",
				[ 'approved' => [ 10, 20, 30, 40 ] ]
			],
			'failed to approve one edit' => [
				"<p>(moderation-approved-ok: 0)\n</p><p>(moderation-approved-errors: 1)\n</p>",
				[ 'approved' => [], 'failed' => [ 12345 ] ]
			],
			'approved 3 edits, failed to approve 4 edits' => [
				"<p>(moderation-approved-ok: 3)\n</p><p>(moderation-approved-errors: 4)\n</p>",
				[ 'approved' => [ 10, 12, 14 ], 'failed' => [ 20, 22, 24, 26 ] ]
			]
		];
	}
}
