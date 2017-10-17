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
	@file
	@brief Ensure that changes to "moderation" table are NOT undone by database rollback.

	Some third-party extension may call doEditContent(), mistakenly
	assume "moderation-edit-queued" to be an error and call rollback().
	In this situation new row in "moderation" table shouldn't be lost.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );

/**
	@covers RollbackResistantQuery
*/
class ModerationTestRollbackResistantQuery extends MediaWikiTestCase
{
	public function getRandomTitle() {
		return Title::newFromText( 'RandomPage_' . wfTimestampNow() . '_' . MWCryptRand::generateHex( 32 ) );
	}

	public function getRandomPage() {
		return  WikiPage::factory( $this->getRandomTitle() );
	}

	public function getRandomText() {
		return wfTimestampNow() . ' ' . MWCryptRand::generateHex( 32 );
	}

	public function getRandomContent() {
		return ContentHandler::makeContent( $this->getRandomText(), null, CONTENT_MODEL_WIKITEXT );
	}

	public function testRollbackResistantQuery() {
		/* In MediaWiki, DBO_TRX flag can be enabled or disabled in $wgDBServers.
			(default: DBO_TRX is enabled in non-CLI mode, disabled in CLI mode).
			Because behavior of commit( ..., 'flush' ) varies when DBO_TRX is on/off,
			we need to test both situations.
		*/
		foreach ( array( true, false ) as $isTrxAutomatic ) { /* with/without DBO_TRX */
			foreach ( array( true, false ) as $isExplicitTransaction ) { /* with/without begin() before doEditContent() */
				foreach ( array( true, false ) as $isAtomic ) { /* with/without startAtomic() before doEditContent() */
					$this->subtestRollbackResistantQuery(
						$isTrxAutomatic,
						$isExplicitTransaction,
						$isAtomic
					);
				}
			}
		}
	}

	protected function setTrxFlag( $db, $newTrxFlagValue ) {
		if ( $newTrxFlagValue ) {
			$db->setFlag( DBO_TRX );
		}
		else {
			$db->clearFlag( DBO_TRX );
		}
	}

	protected function subtestRollbackResistantQuery( $isTrxAutomatic, $isExplicitTransaction, $isAtomic )
	{
		$t = new ModerationTestsuite();
		$subtestName = 'testRollbackResistantQuery('
			. ( $isTrxAutomatic ? 'DBO_TRX' : '~DBO_TRX' )
			. ', '
			. ( $isExplicitTransaction ? 'with explicit begin()' : 'without begin()' )
			. ', '
			. ( $isAtomic ? 'with startAtomic()' : 'without startAtomic()' )
			. ')';

		$dbw = wfGetDB( DB_MASTER );
		$previousTrxFlagValue = $dbw->getFlag( DBO_TRX ); /* Will be restored after the test */

		/*
			Ensure clean test environment: current connection shouldn't have a pending transaction.
		*/
		$dbw->insert( 'text',
			array(
				'old_text' => 'whatever',
				'old_flags' => ''
			),
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
			"$subtestName: doEditContent doesn't return 'moderation-edit-queued' status" );

		/* Simulate situation when caller of doEditContent() throws an MWException */
		$e = new MWException();

		MWExceptionHandler::rollbackMasterChangesAndLog( $e );
		MWExceptionHandler::logException( $e );

		/* Double-check that DB row was created */
		$wasCreated = $dbw->selectField( 'moderation', '1',
			array(
				'mod_namespace' => $page->getTitle()->getNamespace(),
				'mod_title' => $page->getTitle()->getText() # FIXME: ModerationEditHooks::onPageContentSave() uses getText(), not getDBKey()
			),
			__METHOD__
		);
		$this->assertNotFalse( $wasCreated, "$subtestName: newly added row is not in the 'moderation' table after MWException" );

		/* Restore DBO_TRX to its value before the test */
		$this->setTrxFlag( $dbw, $previousTrxFlagValue );
	}
}
