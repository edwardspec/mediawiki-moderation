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
 * Benchmark: how fast is ModerationFormatTimestamp::format()?
 *
 * Usage:
 *	php maintenance/runScript.php extensions/Moderation/tests/benchmarks/formatTimestamp.php
 */

require_once __DIR__ . '/ModerationBenchmark.php';

class BenchmarkFormatTimestamp extends ModerationBenchmark {

	/**
	 * @var IContextSource
	 */
	protected $context;

	/**
	 * Default number of loops.
	 * @return int
	 */
	public function getDefaultLoops() {
		return 100000;
	}

	public function beforeBenchmark( $numberOfUsers ) {
		$this->context = RequestContext::getMain();
	}

	public function doActualWork( $i ) {
		ModerationFormatTimestamp::format( '20180101000000', $this->context );
	}
}

$maintClass = 'BenchmarkFormatTimestamp';
require RUN_MAINTENANCE_IF_MAIN;
