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
 * Unit test of ModerationUpdater.
 */

use Wikimedia\Rdbms\IDatabase;

require_once __DIR__ . "/autoload.php";

class ModerationUpdaterTest extends ModerationUnitTestCase {

	/**
	 * Verify that our LoadExtensionSchemaUpdates hook adds the necessary database updates.
	 * @covers ModerationUpdater
	 */
	public function testHook() {
		$updater = $this->createMock( DatabaseUpdater::class );
		$db = $this->createMock( IDatabase::class );

		$updater->expects( $this->any() )->method( 'getDB' )->willReturn( $db );
		$db->expects( $this->any() )->method( 'getType' )->willReturn( 'postgres' );

		$updater->expects( $this->exactly( 2 ) )->method( 'addExtensionTable' )->withConsecutive(
			[ $this->identicalTo( 'moderation' ), $this->fileExists() ],
			[ $this->identicalTo( 'moderation_block' ), $this->fileExists() ]
		);
		$updater->expects( $this->any() )->method( 'addExtensionUpdate' )->with(
			$this->identicalTo( [ 'ModerationVersionCheck::invalidateCache' ] )
		);

		// TODO: check both mysql and postgresql.
		// TODO: check invocations of addExtensionField(), modifyExtensionField(), etc.

		'@phan-var DatabaseUpdater $updater';

		$hookHandler = new ModerationUpdater;
		$this->assertNotFalse( $hookHandler->onLoadExtensionSchemaUpdates( $updater ),
			'Handler of LoadExtensionSchemaUpdates hook shouldn\'t return false.' );
	}
}
