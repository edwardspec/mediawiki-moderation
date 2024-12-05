<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2024 Edward Chernenko.

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
 * Benchmark: how fast is Show on Special:Moderation?
 *
 * Usage:
 *	php maintenance/runScript.php extensions/Moderation/tests/benchmarks/showEdit.php
 */

require_once __DIR__ . '/ModerationBenchmark.php';

class BenchmarkShowEdit extends ModerationBenchmark {
	/**
	 * @var int
	 * mod_id of the change
	 */
	public $id;

	private const TEXT_BEFORE = 'Text before';
	private const TEXT_AFTER = 'Newtext after';

	/**
	 * Default number of loops.
	 * @return int
	 */
	public function getDefaultLoops() {
		return 3000;
	}

	/**
	 * @param int $numberOfLoops
	 */
	public function beforeBenchmark( $numberOfLoops ) {
		$this->fastEdit( $this->getTestTitle(), self::TEXT_BEFORE );
		$this->id = $this->fastQueue( $this->getTestTitle(), self::TEXT_AFTER );

		$this->getUser()->addGroup( 'moderator' );
	}

	/**
	 * @param int $i
	 */
	public function doActualWork( $i ) {
		$html = $this->runSpecialModeration( [
			'modaction' => 'show',
			'modid' => $this->id
		] );

		Wikimedia\Assert\Assert::postcondition(
			( strpos( $html, 'Text before</del>' ) !== false ),
			'Unexpected output from modaction=show'
		);
	}
}

$maintClass = 'BenchmarkShowEdit';
require RUN_MAINTENANCE_IF_MAIN;
