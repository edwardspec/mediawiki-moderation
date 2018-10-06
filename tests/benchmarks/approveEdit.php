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
 * Benchmark: how fast is Approve on Special:Moderation?

	Usage:
	php maintenance/runScript.php extensions/Moderation/tests/benchmarks/approveEdit.php
*/

require_once __DIR__ . '/ModerationBenchmark.php';

class BenchmarkApproveEdit extends ModerationBenchmark {

	public $ids = []; /* mod_id of all changes to approve */

	/**
	 * Default number of loops.
	 */
	public function getDefaultLoops() {
		return 100;
	}

	public function beforeBenchmark( $numberOfLoops ) {
		$this->getUser()->addGroup( 'moderator' );
	}

	public function beforeBenchmarkPrepareLoop( $i ) {
		/* Prepopulate 'moderation' table */
		$this->ids[] = $this->fastQueue( $this->getTestTitle( $i ) );
	}

	public function doActualWork( $i ) {
		$html = $this->runSpecialModeration( [
			'modaction' => 'approve',
			'modid' => $this->ids[$i],
			'token' => $this->getUser()->getEditToken()
		] );

		Wikimedia\Assert\Assert::postcondition(
			( strpos( $html, '(moderation-approved-ok: 1)' ) !== false ),
			'Approve failed'
		);
	}
}

$maintClass = 'BenchmarkApproveEdit';
require RUN_MAINTENANCE_IF_MAIN;
