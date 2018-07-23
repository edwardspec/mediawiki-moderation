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
 * @brief Checks rendering of moderation-related log entries on Special:Log.
 */

/**
 * @covers ModerationLogFormatter
 */
class ModerationLogFormatterTest extends MediaWikiTestCase {
	/**
	 * @dataProvider dataProvider
	 */
	public function testLogFormatter( array $data ) {
		$performer = User::newFromName( 'Some moderator', false );
		$target = Title::newFromText( $data['target'] );

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );

		$entry = new ManualLogEntry( 'moderation', $data['subtype'] );
		$entry->setPerformer( $performer );
		$entry->setTarget( $target );
		if ( isset( $data['params'] ) ) {
			$entry->setParameters( $data['params'] );
		}

		$formatter = LogFormatter::newFromEntry( $entry );
		$formatter->setContext( $context );
		$html = $formatter->getActionText();

		// Split $html into parameters
		$this->assertEquals( 1,
			preg_match( '/\(logentry-moderation-([^:]+): (.*)\)\s*$/', $html, $matches ),
			"Log entry 'moderation/{$data['subtype']}': malformed log line." );

		list( , $subtype, $paramLine ) = $matches;
		$params = explode( ', ', $paramLine );

		// Now check $subtype and $params for correctness
		$this->assertEquals( $data['subtype'], $subtype,
			"Log entry 'moderation/{$data['subtype']}': incorrect subtype." );
		$this->assertEquals( $performer->getName(), $params[1],
			"Log entry 'moderation/{$data['subtype']}': incorrect performer." );
		$this->assertEquals(
			Linker::userLink( $performer->getId(), $performer->getName() ),
			$params[0],
			"Log entry 'moderation/{$data['subtype']}': incorrect link to performer."
		);

		// TODO: check other $params

		// libxml treats <bdi> tag as syntax error
		$params = array_map( function ( $html ) {
			return preg_replace( '/<\/?bdi>/', '', $html );
		}, $params );

		// TODO
	}

	/**
	 * @brief Provide datasets for testLogFormatter() runs.
	 */
	public function dataProvider() {
		return [
			[ [
				'subtype' => 'approve',
				'target' => 'Project:Some page',
				'params' => [ 'revid' => 12345 ]
			] ],
			[ [
				'subtype' => 'approveall',
				'target' => 'User:Some author',
				'4::count' => 345
			] ],
			[ [
				'subtype' => 'reject',
				'target' => 'Project:Some page',
				'params' => [
					'modid' => 678,
					'user' => 987,
					'user_text' => 'Some author'
				]
			] ],
			[ [
				'subtype' => 'rejectall',
				'target' => 'User:Some author',
				'params' => [
					'4::count' => 345
				]
			] ],
			[ [ 'subtype' => 'block', 'target' => 'User:Some author' ] ],
			[ [ 'subtype' => 'unblock', 'target' => 'User:Some author' ] ],
			[ [
				'subtype' => 'merge',
				'target' => 'User:Some page',
				'params' => [
					'modid' => 200,
					'revid' => 3000
				]
			] ]
		];
	}
}
