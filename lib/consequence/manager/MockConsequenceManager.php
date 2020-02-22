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
 * Mocked version of ConsequenceManager for tests. Allows to easily examine queued consequences.
 */

namespace MediaWiki\Moderation;

class MockConsequenceManager implements IConsequenceManager {
	/**
	 * @var IConsequence[]
	 */
	protected $consequences = [];

	/**
	 * @var mixed[]
	 */
	protected $mockedResults = [];

	/**
	 * Mocked version of add(): record the Consequence without running it, return mocked result.
	 * @param IConsequence $consequence
	 * @return mixed|null Mocked return value (if mockResult() was used before add()), if any.
	 */
	public function add( IConsequence $consequence ) {
		$this->consequences[] = $consequence;
		return array_shift( $this->mockedResults );
	}

	/**
	 * Get the list of all previously queued Consequence objects. Can be used in tests.
	 * @return IConsequence[]
	 */
	public function getConsequences() {
		return $this->consequences;
	}

	/**
	 * Add $result into a queue of return values that are consequentially returned by add() calls.
	 * @param mixed $result
	 */
	public function mockResult( $result ) {
		$this->mockedResults[] = $result;
	}
}
