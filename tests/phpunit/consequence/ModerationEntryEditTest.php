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
 * Unit test of ModerationEntryEdit.
 */

use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\MarkAsConflictConsequence;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class ModerationEntryEditTest extends ModerationUnitTestCase {
	/**
	 * Check result/consequences of ModerationEntryEdit::doApprove.
	 * @param array $opt
	 * @dataProvider dataProviderApprove
	 * @covers ModerationEntryEdit
	 */
	public function testApprove( array $opt ) {
		$errorFromConsequence = $opt['errorFromConsequence'] ?? null;
		$isMinor = $opt['minor'] ?? false;
		$isBot = $opt['bot'] ?? false;
		$isAllowedBot = $opt['isAllowedBot'] ?? false;

		$row = (object)[
			'id' => 12345,
			'comment' => 'Edit comment',
			'text' => 'New text',
			'minor' => $isMinor ? 1 : 0,
			'bot' => $isBot ? 1 : 0,
			'last_oldid' => $opt['last_oldid'] ?? 0
		];
		$title = $this->createMock( Title::class );
		$authorUser = $this->createMock( User::class );

		$authorUser->expects( $this->any() )->method( 'isAllowed' )->with(
			$this->identicalTo( 'bot' )
		)->willReturn( $isAllowedBot );

		$status = Status::newGood();
		if ( $errorFromConsequence ) {
			$status->fatal( $errorFromConsequence );
		}

		'@phan-var Title $title';
		'@phan-var User $authorUser';

		$manager = $this->createMock( IConsequenceManager::class );
		$manager->expects( $this->at( 0 ) )->method( 'add' )->with( $this->consequenceEqualTo(
			new ApproveEditConsequence(
				$authorUser,
				$title,
				$row->text,
				$row->comment,
				$isBot && $isAllowedBot,
				$isMinor,
				$row->last_oldid
			)
		) )->willReturn( $status );

		if ( $errorFromConsequence === 'moderation-edit-conflict' ) {
			$manager->expects( $this->at( 1 ) )->method( 'add' )->with( $this->consequenceEqualTo(
				new MarkAsConflictConsequence( $row->id )
			) );
			$manager->expects( $this->exactly( 2 ) )->method( 'add' );
		} else {
			$manager->expects( $this->once() )->method( 'add' );
		}

		$entry = $this->getMockBuilder( ModerationEntryEdit::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'getRow', 'getTitle', 'getUser' ] )
			->getMock();

		$entry->expects( $this->once() )->method( 'getRow' )->willReturn( $row );
		$entry->expects( $this->once() )->method( 'getUser' )->willReturn( $authorUser );
		$entry->expects( $this->once() )->method( 'getTitle' )->willReturn( $title );

		$wrapper = TestingAccessWrapper::newFromObject( $entry );
		$wrapper->consequenceManager = $manager;

		$result = $wrapper->doApprove( $this->createMock( User::class ) );
		$this->assertSame( $status, $result, "Result of doApprove() doesn't match expected." );
	}

	/**
	 * Provide datasets for testApprove() runs.
	 * @return array
	 */
	public function dataProviderApprove() {
		return [
			'successful approval' => [ [] ],
			'error: ApproveEditConsequence returned Status that indicates failure' => [ [
				'errorFromConsequence' => 'some-mocked-error-code'
			] ],
			'error: ApproveEditConsequence returned Status that indicates edit conflict' => [ [
				'errorFromConsequence' => 'moderation-edit-conflict'
			] ],
			'successful approval: minor edit' => [ [ 'minor' => true ] ],
			'successful approval: ignored bot=true: author is not allowed to make bot edits' =>
				[ [ 'bot' => true, 'isAllowedBot' => false ] ],
			'successful approval: bot edit, author has "bot" permission' =>
				[ [ 'bot' => true, 'isAllowedBot' => true ] ],
			'successful approval: last_oldid=98765' =>
				[ [ 'last_oldid' => 98765 ] ]
		];
	}
}
