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
 * Benchmark: how fast is HTML of Special:Moderation generated?

	Usage:
	php maintenance/runScript.php extensions/Moderation/tests/benchmarks/renderSpecial.php
*/

require_once __DIR__ . '/ModerationBenchmark.php';

class BenchmarkRenderSpecial extends ModerationBenchmark {

	/**
	 * Default number of loops.
	 */
	public function getDefaultLoops() {
		return 50;
	}

	/**
	 * How many rows to show on Special:Moderation.
	 */
	public function getNumberOfEntries() {
		return 200;
	}

	public function beforeBenchmark( $numberOfLoops ) {
		/* Prepopulate 'moderation' table */
// phpcs:disable Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
		for ( $i = 0; $i <= $this->getNumberOfEntries(); $i ++ ) {
			$this->fastQueue( $this->getTestTitle( $i ) );
		}
// phpcs:enable

		$this->getUser()->addGroup( 'moderator' );
	}

	public function doActualWork( $i ) {
		$this->runSpecialModeration( [
			'limit' => $this->getNumberOfEntries()
		] );
	}
}

$maintClass = 'BenchmarkRenderSpecial';
require RUN_MAINTENANCE_IF_MAIN;
