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
 * Unit test of AddLogEntryConsequence.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class AddLogEntryConsequenceTest extends ModerationUnitTestCase {

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'logging', 'log_search' ];

	/**
	 * Verify that AddLogEntryConsequence creates a log entry when executed.
	 * @param string $subtype
	 * @param string $username
	 * @param string $pageName
	 * @param array $params
	 * @param bool $runApproveHook
	 * @dataProvider dataProviderAddLogEntry
	 * @covers MediaWiki\Moderation\AddLogEntryConsequence
	 */
	public function testAddLogEntry( $subtype, $username, $pageName, array $params,
		$runApproveHook
	) {
		$user = User::createNew( $username );
		$title = Title::newFromText( $pageName );

		// This variable is set in mocked ApproveHook::checkLogEntry() for further checks.
		$checkedLogId = null;
		$checkedLogEntry = null;

		'@phan-var ManualLogEntry $checkedLogEntry';

		// Check whether ApproveHook will queue this LogEntry for modification.
		$approveHook = $this->createMock( ModerationApproveHook::class );
		if ( $runApproveHook ) {
			$approveHook->expects( $this->once() )->method( 'checkLogEntry' )->with(
				$this->isType( 'int' ),
				$this->IsInstanceOf( ManualLogEntry::class )
			)->will( $this->returnCallback(
				static function ( $logid, ManualLogEntry $logEntry ) use ( &$checkedLogId, &$checkedLogEntry ) {
					$checkedLogId = $logid;
					$checkedLogEntry = $logEntry;
				}
			) );
		} else {
			$approveHook->expects( $this->never() )->method( 'checkLogEntry' );
		}
		$this->setService( 'Moderation.ApproveHook', $approveHook );

		// Create and run the Consequence.
		$consequence = new AddLogEntryConsequence( $subtype, $user, $title, $params,
			$runApproveHook );
		$consequence->run();

		// Test the new LogEntry that appeared in the database.
		$dbw = wfGetDB( DB_MASTER );
		$logid = $dbw->selectField( 'logging', 'log_id', '', __METHOD__ );

		$logEntry = DatabaseLogEntry::newFromId( $logid, $dbw );

		$this->assertEquals( 'moderation', $logEntry->getType() );
		$this->assertEquals( $subtype, $logEntry->getSubtype() );
		$this->assertEquals( $user->getName(), ModerationTestUtil::getLogEntryPerformer( $logEntry )->getName() );
		$this->assertEquals( $title->getPrefixedText(),
			$logEntry->getTarget()->getPrefixedText() );
		$this->assertEquals( $params, $logEntry->getParameters() );

		if ( $runApproveHook ) {
			$this->assertEquals( $logid, $checkedLogId,
				"logid passed to ApproveHook:checkLogEntry() doesn't match expected." );

			$this->assertEquals( 'moderation', $checkedLogEntry->getType() );
			$this->assertEquals( $subtype, $checkedLogEntry->getSubtype() );
			$this->assertEquals( $user->getName(),
				ModerationTestUtil::getLogEntryPerformer( $checkedLogEntry )->getName() );
			$this->assertEquals( $title->getPrefixedText(),
				$checkedLogEntry->getTarget()->getPrefixedText() );
			$this->assertEquals( $params, $checkedLogEntry->getParameters() );
		}
	}

	/**
	 * Provide datasets for testAddLogEntry() runs.
	 * @return array
	 */
	public function dataProviderAddLogEntry() {
		return [
			'without ApproveHook' => [
				'reject',
				'Some moderator',
				'Talk:Title in non-main namespace, spaces_and_underscores',
				[
					'someparam' => 123,
					'anotherparam' => 'anothervalue',
					'nonscalar' => [ 'key1' => [ 11, 12 ], 'key2' => [ 'val21', 'val22' ] ],
					'revid' => null
				],
				false // Don't run ApproveHook
			],
			'with ApproveHook' => [
				'approve',
				'AnotherModerator',
				'SampleArticle',
				[ 'revid' => null ],
				true // Run ApproveHook
			]
		];
	}
}
