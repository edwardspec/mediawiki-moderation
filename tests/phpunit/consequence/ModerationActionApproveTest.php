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
 * Unit test of ModerationActionApprove.
 */

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
