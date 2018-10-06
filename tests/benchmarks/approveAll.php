<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * Benchmark: how fast is ApproveAll on Special:Moderation?

	Usage:
	php maintenance/runScript.php extensions/Moderation/tests/benchmarks/approveAll.php
*/

require_once __DIR__ . '/ModerationBenchmark.php';

class BenchmarkApproveAll extends ModerationBenchmark {

	public $ids = []; /* mod_id of rows where ApproveAll should be applied */

	/**
	 * Default number of loops.
	 */
	public function getDefaultLoops() {
		return 10;
	}

	/**
	 * How many rows to approve with one approveall.
	 */
	public function getEditsPerUser() {
		return 10;
	}

	public function beforeBenchmark( $numberOfUsers ) {
		/* Prepopulate 'moderation' table */
		$dbw = wfGetDB( DB_MASTER );
		$editsPerUser = $this->getEditsPerUser();

		for ( $i = 0; $i < $numberOfUsers; $i ++ ) {
			$fakeIP = IP::formatHex( base_convert( $i, 10, 16 ) );
			$user = User::newFromName( $fakeIP, false );

			$dbw->delete( 'moderation', [ 'mod_user_text' => $fakeIP ], __METHOD__ );

			$modid = false;
			for ( $j = 0; $j < $editsPerUser; $j ++ ) {
				$modid = $this->fastQueue(
					$this->getTestTitle( $i + $j * $numberOfUsers ),
					'Whatever',
					'',
					$user
				);
			}

			$this->ids[$i] = $modid; /* Only one mod_id per User */
		}

		$this->getUser()->addGroup( 'moderator' );
	}

	public function doActualWork( $i ) {
		// Prevent DeferredUpdates::tryOpportunisticExecute() from running updates immediately
		global $wgCommandLineMode;
		$wgCommandLineMode = false;

		$html = $this->runSpecialModeration( [
			'modaction' => 'approveall',
			'modid' => $this->ids[$i],
			'token' => $this->getUser()->getEditToken()
		] );

		// Run the DeferredUpdates
		$wgCommandLineMode = true;
		DeferredUpdates::doUpdates( 'run' );

		Wikimedia\Assert\Assert::postcondition(
			( strpos( $html, '(moderation-approved-ok: ' . $this->getEditsPerUser() . ')' ) !== false ),
			'ApproveAll failed'
		);
	}
}

$maintClass = 'BenchmarkApproveAll';
require RUN_MAINTENANCE_IF_MAIN;
