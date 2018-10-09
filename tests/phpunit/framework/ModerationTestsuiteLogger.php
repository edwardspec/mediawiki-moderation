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
 * Logger used by Moderation testsuite to dump the log of HTTP requests related to the failed test.
*/
class ModerationTestsuiteLogger extends MediaWiki\Logger\LegacyLogger {
	/**
	 * @var array[string]
	 * Accumulator of log entries.
	 * If the test succeeds, they are silently ignored.
	 * If the test fails, they are printed (to help with troubleshooting).
	 */
	static protected $buffer = [];

	/**
	 * Forget all stored entries. Meant to be used in ModerationTestCase::setUp().
	 */
	public static function cleanBuffer() {
		self::$buffer = [];
	}

	/**
	 * Print all stored entries. Meant to be used in ModerationTestCase::onNotSuccessfulTest().
	 */
	public static function printBuffer() {
		if ( !self::$buffer ) {
			return; // No log entries
		}

		$messageCount = count( self::$buffer );
		error_log( "\n" . __CLASS__ . ": events ($messageCount) related to the failed test:\n" .
			"----------------- START OF LOG ----------------\n" .
			implode( '', self::$buffer ) .
			"----------------- END OF LOG ------------------\n" );
	}

	/**
	 * Add new log entry to accumulator.
	 */
	public function log( $level, $message, array $context = [] ) {
		self::$buffer[] = self::format( $this->channel, $message, $context );
	}
}
