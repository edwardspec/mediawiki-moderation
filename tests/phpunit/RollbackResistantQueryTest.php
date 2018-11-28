<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
 * Ensure that changes to "moderation" table are NOT undone by database rollback.

	Some third-party extension may call doEditContent(), mistakenly
	assume "moderation-edit-queued" to be an error and call rollback().
	In this situation new row in "moderation" table shouldn't be lost.
*/

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers RollbackResistantQuery
 */
class ModerationRollbackResistantQueryTest extends ModerationTestCase {
	public function getRandomTitle() {
		$pageName = 'RandomPage_' . wfTimestampNow() . '_' . MWCryptRand::generateHex( 32 );
		return Title::newFromText( $pageName );
	}

	public function getRandomPage() {
		return WikiPage::factory( $this->getRandomTitle() );
	}

	public function getRandomText() {
		return wfTimestampNow() . ' ' . MWCryptRand::generateHex( 32 );
	}

	public function getRandomContent() {
		return ContentHandler::makeContent( $this->getRandomText(), null, CONTENT_MODEL_WIKITEXT );
	}

	/**
	 * Provide datasets for testRollbackResistantQuery() runs.
	 */
	public function dataProvider() {
		global $wgVersion;
		$is1_31 = version_compare( $wgVersion, '1.31.0', '>=' );

		/* In MediaWiki, DBO_TRX flag can be enabled or disabled in $wgDBServers.
			(default: DBO_TRX is enabled in non-CLI mode, disabled in CLI mode).
			Because behavior of commit( ..., 'flush' ) varies when DBO_TRX is on/off,
			we need to test both situations.
		*/
		$sets = [];

		// with/without DBO_TRX
		foreach ( [ true, false ] as $isTrxAutomatic ) {

			// with/without begin() before doEditContent()
			foreach ( [ true, false ] as $isExplicitTransaction ) {
				if ( $is1_31 && $isTrxAutomatic && $isExplicitTransaction ) {
					// In MediaWiki 1.31+, $dbw->begin() is not allowed in DBO_TRX mode
					continue;
				}

				// with/without startAtomic() before doEditContent()
				foreach ( [ true, false ] as $isAtomic ) {
					$sets[] = [
						$isTrxAutomatic,
						$isExplicitTransaction,
						$isAtomic
					];
				}
			}
		}
		return $sets;
	}

	protected function setTrxFlag( $db, $newTrxFlagValue ) {
		if ( $newTrxFlagValue ) {
			$db->setFlag( DBO_TRX );
		} else {
			$db->clearFlag( DBO_TRX );
		}
	}

	/**
	 * Ensure that RollbackResistantQuery is not reverted after MWException.
	 * @dataProvider dataProvider
	 */
	public function testRollbackResistantQuery( $isTrxAutomatic, $isExplicitTransaction, $isAtomic,
		ModerationTestsuite $t
	) {
		$dbw = wfGetDB( DB_MASTER );
		$previousTrxFlagValue = $dbw->getFlag( DBO_TRX ); /* Will be restored after the test */

		/*
			Ensure clean test environment: current connection shouldn't have a pending transaction.
		*/
		$dbw->insert( 'text',
			[
				'old_text' => 'whatever',
				'old_flags' => ''
			],
			__METHOD__
		);
		if ( $dbw->trxLevel() ) {
			$dbw->commit( __METHOD__, 'flush' );
		}

		/* Subtest condition #1: enable/disable DBO_TRX. */
		$this->setTrxFlag( $dbw, $isTrxAutomatic );

		/* Subtest condition #2: start (or not start) an explicit transaction. */
		if ( $isExplicitTransaction ) {
			$dbw->begin( __METHOD__ );
		}

		/* Subtest condition #3: call (or not call) startAtomic(). */
		if ( $isAtomic ) {
			$dbw->startAtomic( __METHOD__ );
		}

		/* Create article using doEditContent() */
		$page = $this->getRandomPage();
		$status = $page->doEditContent(
			$this->getRandomContent(),
			'', // $summary
			0, // $flags
			false, // $baseRevId
			$t->unprivilegedUser
		);

		$this->assertEquals( 'moderation-edit-queued', $status->getMessage()->getKey(),
			"testRollbackResistantQuery(): doEditContent doesn't return 'moderation-edit-queued' status" );

		/* Simulate situation when caller of doEditContent() throws an MWException */
		$e = new MWException();

		MWExceptionHandler::rollbackMasterChangesAndLog( $e );
		MWExceptionHandler::logException( $e );

		/* Double-check that DB row was created */
		$wasCreated = $dbw->selectField( 'moderation', '1',
			[
				'mod_namespace' => $page->getTitle()->getNamespace(),
				'mod_title' => ModerationVersionCheck::getModTitleFor( $page->getTitle() )
			],
			__METHOD__
		);
		$this->assertNotFalse( $wasCreated,
			"testRollbackResistantQuery(): newly added row is not in the 'moderation' table " .
			"after MWException" );

		/* Restore DBO_TRX to its value before the test */
		$this->setTrxFlag( $dbw, $previousTrxFlagValue );
	}
}
