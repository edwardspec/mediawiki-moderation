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
 * @brief Implements testsuite methods edit(), upload() and move().
 */

abstract class ModerationTestsuiteBot {

	/** @var ModerationTestsuite */
	private $t;

	protected function __construct( ModerationTestsuite $t ) {
		$this->t = $t;
	}

	/** @return ModerationTestsuite */
	private function getTestsuite() {
		return $this->t;
	}

	/**
	 * @brief Create a new bot.
	 * @param string $method One of the following: 'api', 'nonApi'.
	 */
	public static function factory( $method, ModerationTestsuite $t ) {
		switch ( $method ) {
			case 'any':
			case 'api':
				return new ModerationTestsuiteApiBot( $t );
			case 'nonApi':
				return new ModerationTestsuiteNonApiBot( $t );
		}

		throw new MWException( 'Unsupported method in ' . __METHOD__ );
	}

	/**
	 * @brief Perform a test edit.
	 * @param string $title
	 * @param string $text
	 * @param string $summary
	 * @param string|int $section One of the following: section number, empty string or 'new'.
	 * @param array $extraParams Bot-specific parameters.
	 * @return ModerationTestsuiteBotResult
	 */
	final public function edit( $title, $text, $summary, $section = '', array $extraParams = [] ) {
		if ( !$title ) {
			$title = $this->generateRandomTitle();
		}

		if ( !$text ) {
			$text = $this->generateRandomText();
		}

		if ( !$summary ) {
			$summary = $this->generateEditSummary();
		}

		$t = $this->getTestsuite();
		$result = $this->doEdit( $t, $title, $text, $summary, $section, $extraParams );
		$t->setLastEdit( $title, $summary, [ 'Text' => $text ] );

		return $result;
	}

	/** @brief Bot-specific (e.g. API or non-API) implementation of edit(). */
	abstract public function doEdit( ModerationTestsuite $t,
		$title, $text, $summary, $section, array $extraParams );

	/**
	 * @brief Get sample page name (used when the test hasn't specified it).
	 * @return string
	 */
	private function generateRandomTitle() {
		// Simple string, no underscores
		return "Test page 1";
	}

	/**
	 * @brief Get sample text of the page (used when the test hasn't specified it).
	 * @return string
	 */
	private function generateRandomText() {
		return "Hello, World!";
	}

	/**
	 * @brief Get sample edit summary (used when the test hasn't specified it).
	 * @return string
	 */
	private function generateEditSummary() {
		// NOTE: No wikitext! Plaintext only.
		// Otherwise we'll have to run it through the parser before
		// comparing to what's shown on Special:Moderation.

		return "Edit by the Moderation Testsuite";
	}
}
