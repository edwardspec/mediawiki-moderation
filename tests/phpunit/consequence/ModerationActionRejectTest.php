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
 * Unit test of ModerationActionReject.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\RejectAllConsequence;
use MediaWiki\Moderation\RejectOneConsequence;

require_once __DIR__ . "/autoload.php";

class ModerationActionRejectTest extends ModerationUnitTestCase {
	use ActionTestTrait;

	/**
	 * Check result/consequences of modaction=reject.
	 * @param array $opt
	 * @dataProvider dataProviderExecuteRejectOne
	 * @covers ModerationActionReject
	 */
	public function testExecuteRejectOne( array $opt ) {
		$expectedError = $opt['expectedError'] ?? null;
		$affectedRows = $opt['affectedRows'] ?? 1;

		$row = (object)[
			'namespace' => rand( 0, 1 ),
			'title' => 'UTPage_' . rand( 0, 100000 ),
			'user' => 678,
			'user_text' => 'Some user',
			'rejected' => 0,
			'merged_revid' => 0
		];
		if ( $opt['isAlreadyRejected'] ?? false ) {
			$row->rejected = 1;
		}
		if ( $opt['isAlreadyMerged'] ?? false ) {
			$row->merged_revid = 56789;
		}

		$action = $this->makeActionForTesting( ModerationActionReject::class,
			function ( $context, $entryFactory, $manager ) use ( $row, $expectedError, $affectedRows ) {
				$moderator = self::getTestUser()->getUser();
				$modid = 12345;

				$context->setRequest( new FauxRequest( [
					'modid' => $modid,
					'modaction' => 'reject'
				] ) );
				$context->setUser( $moderator );

				$entryFactory->expects( $this->once() )->method( 'loadRowOrThrow' )->with(
					$this->identicalTo( $modid ),
					$this->identicalTo( [
						'mod_namespace AS namespace',
						'mod_title AS title',
						'mod_user AS user',
						'mod_user_text AS user_text',
						'mod_rejected AS rejected',
						'mod_merged_revid AS merged_revid'
					] )
				)->willReturn( $row );

				if ( $expectedError && $expectedError !== 'moderation-edit-not-found' ) {
					// Unsuccessful action shouldn't have any consequences,
					// except for no-op reject error ("moderation-edit-not-found"),
					// which becomes known from the return value of RejectOneConsequence.
					$manager->expects( $this->never() )->method( 'add' );
				} else {
					$manager->expects( $affectedRows ? $this->at( 0 ) : $this->once() )->method( 'add' )->with(
						$this->consequenceEqualTo( new RejectOneConsequence( $modid, $moderator ) )
					)->willReturn( $affectedRows );

					if ( $affectedRows ) {
						// Something changed.
						$manager->expects( $this->at( 1 ) )->method( 'add' )->with( $this->consequenceEqualTo(
							new AddLogEntryConsequence(
								'reject', $moderator,
								Title::makeTitle( $row->namespace, $row->title ),
								[
									'modid' => $modid,
									'user' => (int)$row->user,
									'user_text' => $row->user_text
								]
							)
						) );
						$manager->expects( $this->at( 2 ) )->method( 'add' )->with( $this->consequenceEqualTo(
							new InvalidatePendingTimeCacheConsequence()
						) );
						$manager->expects( $this->exactly( 3 ) )->method( 'add' );
					}
				}
			}
		);

		if ( $expectedError ) {
			$this->expectExceptionObject( new ModerationError( $expectedError ) );
		}
		$this->assertSame( [ 'rejected-count' => 1 ], $action->execute() );
	}

	/**
	 * Provide datasets for testExecuteRejectOne() runs.
	 * @return array
	 */
	public function dataProviderExecuteRejectOne() {
		return [
			'successful reject' => [ [] ],
			'no-op reject (no rows affected)' => [ [
				'expectedError' => 'moderation-edit-not-found',
				'affectedRows' => 0
			] ],
			'error: already rejected' => [ [
				'expectedError' => 'moderation-already-rejected',
				'isAlreadyRejected' => true
			] ],
			'error: already merged' => [ [
				'expectedError' => 'moderation-already-merged',
				'isAlreadyMerged' => true
			] ]
		];
	}

	/**
	 * Check result/consequences of modaction=reject.
	 * @param array $opt
	 * @dataProvider dataProviderExecuteRejectAll
	 * @covers ModerationActionReject
	 */
	public function testExecuteRejectAll( array $opt ) {
		$username = $opt['usernameOfPerformer'] ?? "Author's username";
		$affectedRows = $opt['affectedRows'] ?? 6;

		$action = $this->makeActionForTesting( ModerationActionReject::class,
			function ( $context, $entryFactory, $manager ) use ( $username, $affectedRows ) {
				$moderator = self::getTestUser()->getUser();
				$modid = 12345;

				$context->setRequest( new FauxRequest( [
					'modid' => $modid,
					'modaction' => 'rejectall'
				] ) );
				$context->setUser( $moderator );

				// TODO: maybe allow makeActionForTesting() to create a partial mock,
				// and then just mock ModerationAction::getUserpageOfPerformer()?
				$entryFactory->expects( $this->once() )->method( 'loadRow' )->with(
					$this->identicalTo( $modid ),
					$this->identicalTo( [ 'mod_user_text AS user_text' ] )
				)->willReturn( $username ? (object)[ 'user_text' => $username ] : false );

				if ( !$username ) {
					// Exception will be thrown, so no further consequences are expected.
					$manager->expects( $this->never() )->method( 'add' );
					return;
				}

				$manager->expects( $this->at( 0 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new RejectAllConsequence( $username, $moderator )
				) )->willReturn( $affectedRows );

				if ( !$affectedRows ) {
					// Exception will be thrown, so no further consequences are expected.
					$manager->expects( $this->once() )->method( 'add' );
					return;
				}

				$manager->expects( $this->at( 1 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new AddLogEntryConsequence(
						'rejectall',
						$moderator,
						Title::makeTitle( NS_USER, $username ),
						[ '4::count' => $affectedRows ]
					)
				) );
				$manager->expects( $this->at( 2 ) )->method( 'add' )->with( $this->consequenceEqualTo(
					new InvalidatePendingTimeCacheConsequence()
				) );
				$manager->expects( $this->exactly( 3 ) )->method( 'add' );
			}
		);

		$expectedError = $opt['expectedError'] ?? null;
		if ( $expectedError ) {
			$this->expectExceptionObject( new ModerationError( $expectedError ) );
		}
		$this->assertSame( [ 'rejected-count' => $affectedRows ], $action->execute() );
	}

	/**
	 * Provide datasets for testExecuteRejectAll() runs.
	 * @return array
	 */
	public function dataProviderExecuteRejectAll() {
		return [
			'successful rejectall' => [ [] ],
			'no-op reject (no rows affected)' => [ [
				'expectedError' => 'moderation-nothing-to-rejectall',
				'affectedRows' => 0
			] ],
			'error: pending edit not found' => [ [
				'expectedError' => 'moderation-edit-not-found',
				'usernameOfPerformer' => false
			] ]
		];
	}

	/**
	 * Verify that outputResult() correctly converts return value of execute() into HTML output.
	 * @covers ModerationActionReject
	 */
	public function testOutputResult() {
		$action = $this->makeActionForTesting( ModerationActionReject::class );

		// Obtain a new OutputPage object that is different from OutputPage in $context.
		// This verifies that outputResult() does indeed use its second parameter for output
		// rather than printing into $this->getContext()->getOutput() (which would be incorrect).
		$output = clone $action->getOutput();
		$action->outputResult( [ 'rejected-count' => 6 ], $output );

		$this->assertSame( "<p>(moderation-rejected-ok: 6)\n</p>", $output->getHTML(),
			"Result of outputResult() doesn't match expected." );
	}
}
