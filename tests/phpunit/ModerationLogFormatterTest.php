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
 * Checks rendering of moderation-related log entries on Special:Log.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationLogFormatter
 */
class ModerationLogFormatterTest extends ModerationTestCase {
	/**
	 * @dataProvider dataProvider
	 */
	public function testLogFormatter( array $options ) {
		ModerationLogFormatterTestSet::run( $options, $this );
	}

	/**
	 * Provide datasets for testLogFormatter() runs.
	 */
	public function dataProvider() {
		return [
			[ [
				'subtype' => 'approve',
				'target' => 'Project:Some page',
				'params' => [ 'revid' => 12345 ],
				'expectedParams' => [
					3 => [
						'text' => '(moderation-log-diff: 12345)',
						'query' => [ 'title' => '{{TARGET}}', 'diff' => 12345 ],
						'tooltip' => '(tooltip-moderation-approved-diff)'
					]
				]
			] ],
			[ [
				'subtype' => 'approve-move',
				'target' => 'Help:Moving pages',
				'params' => [
					'4::target' => 'New title',
					'user' => 500,
					'user_text' => 'Test username'
				],
				'expectedParams' => [
					3 => [
						'pagelink' => 'New title'
					],
					4 => [
						'userlink' => [ 500, 'Test username' ]
					]
				]
			] ],
			[ [
				'subtype' => 'approveall',
				'target' => 'User:Some author',
				'expectTargetUserlink' => true
			] ],
			[ [
				'subtype' => 'reject',
				'target' => 'Project:Some page',
				'params' => [
					'modid' => 678,
					'user' => 987,
					'user_text' => 'Some author'
				],
				'expectedParams' => [
					3 => [
						'text' => '(moderation-log-change: 678)',
						'query' => [
							'title' => 'Special:Moderation',
							'modaction' => 'show',
							'modid' => 678
						],
						'tooltip' => '(tooltip-moderation-rejected-change)'
					],
					4 => [
						'userlink' => [ 987, 'Some author' ]
					]
				]
			] ],
			[ [
				'subtype' => 'reject',
				'target' => 'Project:Some page',
				'params' => [
					'modid' => 678,
					'user' => 0,
					'user_text' => '10.11.12.13'
				],
				'expectedParams' => [
					3 => [
						'text' => '(moderation-log-change: 678)',
						'query' => [
							'title' => 'Special:Moderation',
							'modaction' => 'show',
							'modid' => 678
						],
						'tooltip' => '(tooltip-moderation-rejected-change)'
					],
					4 => [
						'userlink' => [ 0, '10.11.12.13' ]
					]
				]
			] ],
			[ [
				'subtype' => 'rejectall',
				'target' => 'User:Some author',
				'params' => [
					'4::count' => 42
				],
				'expectTargetUserlink' => true,
				'expectedParams' => [
					3 => [
						'text' => 42
					]
				]

			] ],
			[ [ 'subtype' => 'block', 'target' => 'User:Some author',
				'expectTargetUserlink' => true ] ],
			[ [ 'subtype' => 'unblock', 'target' => 'User:Some author',
				'expectTargetUserlink' => true ] ],
			[ [
				'subtype' => 'merge',
				'target' => 'User:Some page',
				'params' => [
					'modid' => 200,
					'revid' => 3000
				],
				'expectedParams' => [
					3 => [
						'text' => '(moderation-log-change: 200)',
						'query' => [
							'title' => 'Special:Moderation',
							'modaction' => 'show',
							'modid' => 200
						],
						'tooltip' => '(tooltip-moderation-rejected-change)'
					],
					4 => [
						'text' => '(moderation-log-diff: 3000)',
						'query' => [ 'title' => '{{TARGET}}', 'diff' => 3000 ],
						'tooltip' => '(tooltip-moderation-approved-diff)'
					]
				]
			] ]
		];
	}
}

/**
 * Represents one TestSet for testLogFormatter().
 */
class ModerationLogFormatterTestSet extends ModerationTestsuiteTestSet {

	/** @var array Expected parameters, see assertParam() for details */
	protected $expectedParams = [];

	/** @var bool If true, $3 in logentry is expected to be a userlink. */
	protected $expectTargetUserlink = false;

	/** @var string Log action, e.g. 'approve-move' or 'rejectall'. */
	protected $subtype = null;

	/** @var Title Target of LogEntry */
	protected $target = null;

	/** @var array Parameters of LogEntry */
	protected $params = [];

	/** @var User Moderator who did the action. */
	protected $performer = null;

	/** @var string Resulting HTML produced by LogFormatter. Checked in assertResults(). */
	protected $resultHtml = '';

	/**
	 * Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'target':
					$this->target = Title::newFromText( $value );
					break;

				case 'expectedParams':
				case 'expectTargetUserlink':
				case 'subtype':
				case 'target':
				case 'params':
					$this->$key = $value;
					break;

				default:
					throw new Exception( __CLASS__ . ": unknown key {$key} in options" );
			}
		}

		if ( !$this->subtype || !$this->target ) {
			throw new MWException( __CLASS__ . ': subtype and target are mandatory parameters.' );
		}

		$this->performer = User::newFromName( 'Some moderator', false );
	}

	/**
	 * Get human-readable description of this TestSet (for error messages).
	 * @return string
	 */
	protected function getErrorContext() {
		return "Log entry 'moderation/{$this->subtype}'";
	}

	/**
	 * Assert correctness of $this->resultHtml.
	 */
	protected function assertResults( ModerationTestCase $testcase ) {
		$errorContext = $this->getErrorContext();

		// Split resultHtml into parameters
		$isMatched = preg_match(
			'/\(logentry-moderation-([^:]+): (.*)\)\s*$/',
			$this->resultHtml,
			$matches
		);
		$testcase->assertEquals( 1, $isMatched,
			"$errorContext: malformed log line." );

		list( , $subtype, $paramLine ) = $matches;
		$testcase->assertEquals( $this->subtype, $subtype,
			"$errorContext: incorrect subtype." );

		// Now check $params for correctness
		$params = [];
		$params = explode( ', ', $paramLine );

		$testcase->assertEquals( $this->performer->getName(), $params[1],
			"$errorContext: incorrect performer." );
		$testcase->assertEquals(
			Linker::userLink( $this->performer->getId(), $this->performer->getName() ),
			$params[0],
			"$errorContext: incorrect link to performer."
		);

		// Check $params[2], which is the link to the target
		if ( $this->expectTargetUserlink ) {
			$this->assertIsUserLink(
				User::newFromName( $this->target->getText(), false ),
				$params[2],
				"$errorContext: incorrect userlink to the target user."
			);
		} else {
			$testcase->assertEquals(
				Linker::link( $this->target ),
				$params[2],
				"$errorContext: incorrect link to the target page."
			);
		}

		// Check other $params (aside from the already checked 3)
		$testcase->assertCount( 3 + count( $this->expectedParams ), $params,
			"$errorContext: incorrect number of parameters in i18n message of logentry." );

		foreach ( $this->expectedParams as $idx => $expectedParam ) {
			$this->assertParamHtml( $expectedParam, $params[$idx], $idx );
		}
	}

	/**
	 * Assert correctness of the parsed HTML of one LogEntry parameter.
	 * @param array $expectedParam Expected text/tooltip/etc. of the parameter, see dataProvider().
	 * @param string $paramHtml HTML to check.
	 * @param int $idx Index of the parameter (for error messages).
	 */
	protected function assertParamHtml( array $expectedParam, $paramHtml, $idx ) {
		$errorContext = $this->getErrorContext();
		$testcase = $this->getTestcase();

		if ( !isset( $expectedParam['query'] ) ) {
			$title = null;
			if ( isset( $expectedParam['userlink'] ) ) {
				list( $userId, $username ) = $expectedParam['userlink'];
				$title = $userId ?
					Title::makeTitle( NS_USER, $username ) :
					SpecialPage::getTitleFor( 'Contributions', $username );
				unset( $expectedParam['userlink'] );

				$expectedParam['text'] = $username;
			} elseif ( isset( $expectedParam['pagelink'] ) ) {
				$title = Title::newFromText( $expectedParam['pagelink'] );
				unset( $expectedParam['pagelink'] );
			} else {
				// Plaintext parameter (not a link).
				$testcase->assertEquals( $expectedParam['text'], $paramHtml,
					"$errorContext: incorrect text of parameter #$idx." );
				return;
			}

			$expectedParam['query']['title'] = $title->getPrefixedDBKey();
			$expectedParam['tooltip'] = $title->getFullText();

			if ( !isset( $expectedParam['text'] ) ) {
				$expectedParam['text'] = $title->getText();
			}
		}

		if ( $expectedParam['query']['title'] == '{{TARGET}}' ) {
			$expectedParam['query']['title'] = $this->target->getPrefixedDBKey();
		}

		$linkTitle = Title::newFromText( $expectedParam['query']['title'] );
		if ( !$linkTitle->isKnown() ) {
			// Expect a redlink
			$expectedParam['query'] += [
				'action' => 'edit',
				'redlink' => 1
			];

			// Add "page does not exist" if this is NOT a customized tooltip
			$trivialTooltip = str_replace( '_', ' ',  $expectedParam['query']['title'] );
			if ( $expectedParam['tooltip'] == $trivialTooltip ) {
				$expectedParam['tooltip'] .= ' (page does not exist)';
			}
		}

		$param = $this->parseParam( $paramHtml );
		$testcase->assertEquals( $expectedParam, $param,
			"$errorContext: incorrect HTML of parameter #$idx." );
	}

	protected function parseParam( $paramHtml ) {
		$html = new ModerationTestsuiteHTML;
		$html->loadFromString( $paramHtml );

		$parsed = [
			'text' => $html->textContent
		];

		$link = $html->getElementsByTagName( 'a' )->item( 0 );
		if ( !$link ) {
			return $parsed;

		}

		$parsed['tooltip'] = $link->getAttribute( 'title' );
		$href = $link->getAttribute( 'href' );

		// Split $href (URL of this link as a string)
		// into an array of querystring parameters.

		$_SERVER['REQUEST_URI'] = $href;
		$parsed['query'] = WebRequest::getPathInfo(); // Will find 'title', if any.
		unset( $_SERVER['REQUEST_URI'] );

		$bits = wfParseUrl( wfExpandUrl( $href ) );
		if ( isset( $bits['query'] ) ) {
			$parsed['query'] += wfCgiToArray( $bits['query'] );
		}

		return $parsed;
	}

	/**
	 * Execute the TestSet. Populates $this->resultHtml by formatting a LogEntry.
	 */
	protected function makeChanges() {
		$subtype = $this->subtype;

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setLanguage( 'qqx' );

		$entry = new ManualLogEntry( 'moderation', $subtype );
		$entry->setPerformer( $this->performer );
		$entry->setTarget( $this->target );
		if ( $this->params ) {
			$entry->setParameters( $this->params );
		}

		$formatter = LogFormatter::newFromEntry( $entry );
		$formatter->setContext( $context );
		$this->resultHtml = $formatter->getActionText();
	}

	/**
	 * Asserts that $html is the userlink to certain user.
	 * @param User $expectedUser
	 * @param string $html
	 * @param string $errorText
	 */
	protected function assertIsUserLink( User $expectedUser, $html, $errorText ) {
		$this->getTestcase()->assertEquals(
			Linker::userLink( $expectedUser->getId(), $expectedUser->getName() ),
			$html,
			$errorText
		);
	}
}
