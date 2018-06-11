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
	@covers ModerationNewChanges
*/
class ModerationQueueEditTest extends MediaWikiTestCase
{
	/**
		@dataProvider dataProvider
	*/
	public function testQueueEdit( array $options ) {
		$set = new ModerationQueueEditTestSet( $options );
		$set->performEdit();

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		$expectedRow = $set->getExpectedRow();
		foreach ( $expectedRow as $key => $val ) {
			if ( substr( $val, 0, 1 ) == '/' && substr( $val, -1 ) == '/' ) {
				$this->assertRegExp( $val, $row->$key, "Field $key doesn't match regex" );
			}
			else {
				$this->assertEquals( $val, $row->$key, "Field $key doesn't match expected" );
			}
		}
	}

	/**
		@brief Provide datasets for testQueueEdit() runs.
	*/
	public function dataProvider() {
		return [
			[ [] ],
			[ [ 'user' => 'User 5' ] ],
			[ [ 'user' => 'User 6' ] ],
			[ [ 'title' => 'TitleWithoutSpaces' ] ],
			[ [ 'title' => 'Title with spaces' ] ],
			[ [ 'title' => 'Title_with_underscores' ] ],
			[ [ 'title' => 'Project:Title_in_another_namespace' ] ],
			[ [ 'text' => 'Interesting text 1' ] ],
			[ [ 'text' => 'Another very interesting text 2' ] ],
			[ [ 'text' => 'Wikitext with [[links]] and {{templates}} and something' ] ],
			[ [ 'summary' => 'Summary 1' ] ],
			[ [ 'summary' => 'Summary 2' ] ],
			[ [ 'userAgent' => 'UserAgent for Testing/1.0' ] ],
			[ [ 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 10_3_1 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Version/10.0 Mobile/14E304 Safari/602.1' ] ]
		];
	}
}

/**
	@brief Represents one TestSet for testQueueEdit().
*/
class ModerationQueueEditTestSet implements IModerationQueueTestSet {
	private $user = null;
	private $title = null;

	protected $text = 'Hello, World!';
	protected $summary = 'Edit by the Moderation Testsuite';
	protected $userAgent = ModerationTestsuite::DEFAULT_USER_AGENT;

	public function __construct( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'user':
					$this->user = User::newFromName( $value );
					break;

				case 'title':
					$this->title = Title::newFromText( $value );
					break;

				case 'text':
				case 'summary':
				case 'userAgent':
					$this->$key = $value;
					break;

				default:
					throw new Exception( __CLASS__ . ": unknown key {$key} in options" );
			}
		}
	}

	protected function getUser() {
		if ( is_null( $this->user ) ) {
			$this->user = User::newFromName( '127.0.0.1', false );
		}

		return $this->user;
	}

	protected function getTitle() {
		if ( is_null( $this->title ) ) {
			$this->title = Title::newFromText( 'Test page 1' );
		}

		return $this->title;
	}


	public function performEdit() {
		$t = new ModerationTestsuite();
		$t->setUserAgent( $this->userAgent );

		$t->loginAs( $this->getUser() );
		$t->doTestEdit(
			$this->getTitle()->getFullText(),
			$this->text,
			$this->summary
		);
	}

	public function getExpectedRow() {
		return [
			'mod_id' => '/^[0-9]+$/',
			'mod_timestamp' => '/^[0-9]{14}$/',
			'mod_user' => $this->getUser()->getId(),
			'mod_user_text' => $this->getUser()->getName(),
			'mod_cur_id' => 0,
			'mod_namespace' => $this->getTitle()->getNamespace(),
			'mod_title' => $this->getTitle()->getDBKey(),
			'mod_comment' => $this->summary,
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => 1,
			'mod_last_oldid' => 0,
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => 0,
			'mod_new_len' => strlen( $this->text ), /* FIXME: do preSaveTransform */
			'mod_header_xff' => null,
			'mod_header_ua' => $this->userAgent,
			'mod_preload_id' => (
				$this->getUser()->isLoggedIn() ?
					'[' . $this->getUser()->getName() :
					'/^\][0-9a-f]+$/'
			),
			'mod_rejected' => 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => $this->text, /* FIXME: do preSaveTransform */
			'mod_stash_key' => '',
			'mod_tags' => null,
			'mod_type' => 'edit',
			'mod_page2_namespace' => 0,
			'mod_page2_title' => ''
		];
	}
}
