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
	@file
	@brief Checks SQL table 'moderation' after the edit.
*/

require_once( __DIR__ . "/../../framework/ModerationTestsuite.php" );

/**
	@covers ModerationNewChange
*/
class ModerationQueueTest extends MediaWikiTestCase
{
	/**
		@dataProvider dataProvider
	*/
	public function testQueue( array $options ) {
		ModerationQueueTestSet::run( $options, $this );
	}

	/**
		@brief Provide datasets for testQueueEdit() runs.
	*/
	public function dataProvider() {
		return [
			[ [ 'anonymously' => true ] ],
			[ [] ],
			[ [ 'user' => 'User 6' ] ],
			[ [ 'title' => 'TitleWithoutSpaces' ] ],
			[ [ 'title' => 'Title with spaces' ] ],
			[ [ 'title' => 'Title_with_underscores' ] ],
			[ [ 'title' => 'Project:Title_in_another_namespace' ] ],
			[ [ 'text' => 'Interesting text 1' ] ],
			[ [ 'text' => 'Wikitext with [[links]] and {{templates}} and something' ] ],
			[ [ 'text' => 'Text before signature ~~~~ Text after signature', 'needPst' => true ] ],
			[ [ 'summary' => 'Summary 1' ] ],
			[ [ 'summary' => 'Summary 2' ] ],
			[ [ 'userAgent' => 'UserAgent for Testing/1.0' ] ],
			[ [ 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 10_3_1 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Version/10.0 Mobile/14E304 Safari/602.1' ] ],
			[ [ 'filename' => 'image100x100.png' ] ],
			[ [ 'filename' => 'image100x100.png', 'viaApi' => true ] ],
			// [ [ 'title' => 'Test page 1', 'newTitle' => 'Test page 2' ] ]
		];
	}
}

/**
	@brief Represents one TestSet for testQueue().
*/
class ModerationQueueTestSet {
	protected $user = null; /**< User object */
	protected $title = null; /**< Title object */
	protected $newTitle = null; /**< Title object. Only used for moves. */
	protected $text = 'Hello, World!';
	protected $summary = 'Edit by the Moderation Testsuite';
	protected $userAgent = ModerationTestsuite::DEFAULT_USER_AGENT;
	protected $filename = null; /**< string. Only used for uploads. */
	protected $anonymously = false; /**< If true, the edit will be anonymous. ($user will be ignored) */
	protected $viaApi = false; /**< If true, edits are made via API. If false, they are made via the user interface. */
	protected $needPst = false; /**< If true, text is expected to be altered by PreSaveTransform (e.g. contains "~~~~"). */

	/**
		@brief Run this TestSet from input of dataProvider.
		@param $options Parameters of test, e.g. [ 'user' => 'Bear expert', 'title' => 'Black bears' ].
	*/
	public static function run( array $options, MediaWikiTestCase $testcase ) {
		$set = new self( $options );
		$set->performEdit( $testcase );

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		$expectedRow = $set->getExpectedRow();
		foreach ( $expectedRow as $key => $val ) {
			if ( $val instanceof ModerationTestSetRegex ) {
				$testcase->assertRegExp( $val->regex, $row->$key, "Field $key doesn't match regex" );
			}
			else {
				$testcase->assertEquals( $val, $row->$key, "Field $key doesn't match expected" );
			}
		}
	}

	/**
		@brief Construct TestSet from input of dataProvider.
	*/
	protected function __construct( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'user':
					$this->user = User::newFromName( $value );
					break;

				case 'title':
					$this->title = Title::newFromText( $value );
					break;

				case 'newTitle':
					$this->newTitle = Title::newFromText( $value );
					break;

				case 'text':
				case 'summary':
				case 'userAgent':
				case 'filename':
				case 'anonymously':
				case 'viaApi':
				case 'needPst':
					$this->$key = $value;
					break;

				default:
					throw new Exception( __CLASS__ . ": unknown key {$key} in options" );
			}
		}

		/* Default options */
		if ( $this->anonymously ) {
			$this->user = User::newFromName( '127.0.0.1', false );
		}
		elseif ( !$this->user ) {
			$this->user = User::newFromName( 'User 5' );
		}

		if ( !$this->title ) {
			$pageName = $this->filename ? 'File:Test image 1.png' : 'Test page 1';
			$this->title = Title::newFromText( $pageName );
		}
	}

	/**
		@brief Execute the TestSet, making the edit with requested parameters.
	*/
	protected function performEdit( MediaWikiTestCase $testcase ) {
		$t = new ModerationTestsuite();
		$t->setUserAgent( $this->userAgent );

		$t->loginAs( $this->user );

		if ( $this->filename ) {
			/* Upload */
			$t->uploadViaAPI = $this->viaApi;
			$result = $t->doTestUpload(
				$this->title->getText(), /* Without "File:" namespace prefix */
				$this->filename,
				$this->text
			);

			if ( $this->viaApi ) {
				$testcase->assertEquals( '(moderation-image-queued)', $result );
			}
			else {
				$testcase->assertFalse( $result->getError(), __METHOD__ . "(): Special:Upload displayed an error." );
				$testcase->assertContains( '(moderation-image-queued)', $result->getSuccessText() );
			}
		}
		elseif ( $this->newTitle ) {
			/* Create the page first, or there will be nothing to move */
			throw new Exception( 'Not yet implemented. Need to reuse fastEdit() from ModerationBenchmark.' );

			/* TODO: follow $this->viaApi setting */

			$ret = $t->apiMove(
				$this->title->getFullText(),
				$this->newTitle->getFullText(),
				$this->summary
			);
			$testcase->assertEquals( 'moderation-move-queued', $ret['error']['code'] );
		}
		else {
			/* Normal edit */
			$t->editViaAPI = $this->viaApi;
			$t->doTestEdit(
				$this->title->getFullText(),
				$this->text,
				$this->summary
			);
		}
	}

	/**
		@brief Returns array of expected post-edit values of all mod_* fields in the database.
		@note Values like "/value/" are treated as regular expressions.
		@returns [ 'mod_user' => ..., 'mod_namespace' => ..., ... ]
	*/
	protected function getExpectedRow() {
		$text = $this->text;

		if ( $this->filename && !$this->viaApi && ModerationTestsuite::mwVersionCompare( '1.31', '>=' ) ) {
			/* In MediaWiki 1.31+,
				Special:Upload prepends InitialText with "== Summary ==" header */
			$headerText = '== ' . wfMessage( 'filedesc' )->inContentLanguage()->text() . ' ==';
			$text = "$headerText\n$text";
		}

		$summary = $this->summary;
		if ( $this->filename ) {
			if ( $this->viaApi ) {
				/* API has different parameters for 'text' and 'summary' */
				$summary = '';
			}
			else {
				/* Special:Upload copies text into summary */
				$summary = $this->text;
			}
		}

		$expectedContent = ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT );
		if ( $this->needPst ) {
			global $wgContLang;
			$expectedContent = $expectedContent->preSaveTransform(
				$this->title,
				$this->user,
				ParserOptions::newFromUserAndLang( $this->user, $wgContLang )
			);
		}

		return [
			'mod_id' => new ModerationTestSetRegex( '/^[0-9]+$/' ),
			'mod_timestamp' => new ModerationTestSetRegex( '/^[0-9]{14}$/' ),
			'mod_user' => $this->user->getId(),
			'mod_user_text' => $this->user->getName(),
			'mod_cur_id' => 0,
			'mod_namespace' => $this->title->getNamespace(),
			'mod_title' => $this->title->getDBKey(),
			'mod_comment' => $summary,
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => 1,
			'mod_last_oldid' => 0,
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => 0,
			'mod_new_len' => $expectedContent->getSize(),
			'mod_header_xff' => null,
			'mod_header_ua' => $this->userAgent,
			'mod_preload_id' => (
				$this->user->isLoggedIn() ?
					'[' . $this->user->getName() :
					new ModerationTestSetRegex( '/^\][0-9a-f]+$/' )
			),
			'mod_rejected' => 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => $expectedContent->getNativeData(),
			'mod_stash_key' => $this->filename ? new ModerationTestSetRegex( '/^[0-9a-z\.]+$/i' ) : '',
			'mod_tags' => null,
			'mod_type' => $this->newTitle ? 'move' : 'edit',
			'mod_page2_namespace' => $this->newTitle ? $this->newTitle->getNamespace() : 0,
			'mod_page2_title' => $this->newTitle ? $this->newTitle->getText() : '',
		];
	}
}

/**
	@brief Regular expression returned by getExpectedRow() instead of a constant field value.
*/
class ModerationTestSetRegex {
	public $regex;

	public function __construct( $regex ) {
		$this->regex = $regex;
	}
}

