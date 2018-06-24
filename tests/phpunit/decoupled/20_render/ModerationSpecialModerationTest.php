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
	@brief Checks how HTML of Special:Moderation is rendered from the 'moderation' SQL table.
*/

require_once( __DIR__ . "/../../framework/ModerationTestsuite.php" );

/**
	@covers ModerationEntryFormatter
	@covers SpecialModeration
*/
class ModerationSpecialModerationTest extends MediaWikiTestCase
{
	/**
		@dataProvider dataProvider
	*/
	public function testRenderSpecial( array $options ) {
		ModerationRenderTestSet::run( $options, $this );
	}

	/**
		@brief Provide datasets for testRenderSpecial() runs.
	*/
	public function dataProvider() {
		return [
			[ [] ],
			[ [ 'mod_namespace' => NS_MAIN, 'mod_title' => 'Page_in_main_namespace' ] ],
			[ [ 'mod_namespace' => NS_PROJECT, 'mod_title' => 'Page_in_Project_namespace' ] ],
			[ [ 'mod_user' => 0, 'mod_user_text' => '127.0.0.1' ] ],
			[ [ 'mod_user' => 12345, 'mod_user_text' => 'Some registered user' ] ],
			[ [ 'mod_rejected' => 1, 'expectedFolder' => 'rejected' ] ],
			[ [ 'mod_rejected' => 1, 'mod_rejected_auto' => 1, 'expectedFolder' => 'spam' ] ],
			[ [ 'mod_merged_revid' => 12345, 'expectedFolder' => 'merged' ] ],
			[ [ 'isCheckuser' => 1, 'mod_ip' => '127.0.0.2' ] ],
			[ [ 'isCheckuser' => 1, 'mod_user' => 0, 'mod_user_text' => '127.0.0.3' ] ],
			[ [ 'mod_type' => 'move' ] ],
			[ [ 'mod_type' => 'move', 'mod_page2_namespace' => NS_MAIN, 'mod_page2_title' => 'NewTitle_in_Main_namespace' ] ],
			[ [ 'mod_type' => 'move', 'mod_page2_namespace' => NS_PROJECT, 'mod_page2_title' => 'NewTitle_in_Project_namespace' ] ],
		];
	}
}

/**
	@brief Represents one TestSet for testRenderSpecial().
*/
class ModerationRenderTestSet extends ModerationTestsuiteTestSet {

	protected $fields; /**< mod_* fields of one row in the 'moderation' SQL table */
	protected $expectedFolder = 'DEFAULT'; /**< Folder of Special:Moderation where this entry should appear */
	protected $isCheckuser = false; /**< If true, moderator who visits Special:Moderation will be a checkuser. */

	/**
		@brief Initialize this TestSet from the input of dataProvider.
	*/
	protected function applyOptions( array $options ) {
		$this->fields = $this->getDefaultFields();
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'expectedFolder':
				case 'isCheckuser':
					$this->$key = $value;
					break;

				default:
					if ( strpos( $key, 'mod_' ) !== 0 ) {
						throw new Exception( "Incorrect key \"{$key}\": expected \"mod_\" prefix." );
					}
					$this->fields[$key] = $value;
			}
		}

		/* Anonymous users have mod_user_text=mod_ip, so we don't want mod_ip in $options
			(for better readability of dataProvider and to avoid typos).
		*/
		if ( $this->fields['mod_user'] == 0 ) {
			$this->fields['mod_ip'] = $this->fields['mod_user_text'];
		}
	}

	/**
		@brief Returns default value for $fields.
		This represents situation when dataProvider provides an empty array.
	*/
	protected function getDefaultFields() {
		$t = $this->getTestsuite();
		$user = $t->unprivilegedUser;

		return [
			'mod_timestamp' => wfTimestampNow(),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => 0,
			'mod_namespace' => 0,
			'mod_title' => 'Test page 1',
			'mod_comment' => 'Some reason',
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => 0,
			'mod_last_oldid' => 0,
			'mod_ip' => '127.1.2.3',
			'mod_old_len' => 0,
			'mod_new_len' => 0,
			'mod_header_xff' => null,
			'mod_header_ua' => ModerationTestsuite::DEFAULT_USER_AGENT,
			'mod_preload_id' => ']fake',
			'mod_rejected' => 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => '',
			'mod_stash_key' => '',
			'mod_tags' => null,
			'mod_type' => 'edit',
			'mod_page2_namespace' => 0,
			'mod_page2_title' => ''
		];
	}

	/**
		@brief Returns pagename (string) of the page mentioned in $this->fields.
	*/
	protected function getExpectedTitle( $nsField = 'mod_namespace', $titleField = 'mod_title' ) {
		return Title::makeTitle(
			$this->fields[$nsField],
			$this->fields[$titleField]
		)->getFullText();
	}

	/**
		@brief Returns pagename (string) of the second page mentioned in $this->fields.
	*/
	protected function getExpectedPage2Title() {
		return $this->getExpectedTitle(
			'mod_page2_namespace',
			'mod_page2_title'
		);
	}

	/**
		@brief Assert the state of the database after the edit.
	*/
	protected function assertResults( MediaWikiTestCase $testcase ) {
		$t = $this->getTestsuite();

		if ( $this->isCheckuser ) {
			$t->loginAs( $t->moderatorAndCheckuser );
		}

		$t->fetchSpecial( $this->expectedFolder );
		$testcase->assertCount( 1, $t->new_entries,
			"Incorrect number of entries on Special:Moderation."
		);
		$entry = $t->new_entries[0];

		/* Verify that other Folders of Special:Moderation are empty */
		$this->assertOtherFoldersAreEmpty();

		/* Now we compare $this->fields (expected results)
			with $entry (parsed HTML of Special:Moderation) */

		$testcase->assertEquals( $this->getExpectedTitle(), $entry->title,
			"Special:Moderation: Title of the edited page doesn't match expected" );
		$testcase->assertEquals( $this->fields['mod_user_text'], $entry->user,
			"Special:Moderation: Username of the author doesn't match expected" );

		$this->assertWhoisLink( $entry );
		$this->assertMoveEntry( $entry );
	}

	/**
		@brief Assert that all folders (except expectedFolder) are empty.
	*/
	protected function assertOtherFoldersAreEmpty() {
		$knownFolders = [ 'DEFAULT', 'rejected', 'spam', 'merged' ];
		$t = $this->getTestsuite();

		foreach ( $knownFolders as $folder ) {
			if ( $folder != $this->expectedFolder ) {
				$t->fetchSpecial( $folder );
				$this->getTestcase()->assertEmpty( $t->new_entries,
					"Unexpected entry found in folder \"$folder\" of Special:Moderation (this folder should be empty)."
				);
			}
		}
	}

	/**
		@brief Assert that Whois link is always shown for anonymous users,
		and only to checkusers for registered users.
	*/
	protected function assertWhoisLink( ModerationTestsuiteEntry $entry ) {
		$testcase = $this->getTestcase();
		if ( $this->fields['mod_user'] == 0 ) {
			$testcase->assertEquals( $this->fields['mod_user_text'], $entry->ip,
				"Special:Moderation: incorrect Whois link for anonymous user." );
		}
		else {
			if ( $this->isCheckuser ) {
				$testcase->assertEquals( $this->fields['mod_ip'], $entry->ip,
					"Special:Moderation (viewed by checkuser): incorrect Whois link for registered user." );
			}
			else {
				$testcase->assertNull( $entry->ip,
					"Special:Moderation: Whois link shown to non-checkuser." );
			}
		}
	}

	/**
		@brief Check that the formatting of "suggested move" entry is correct.
	*/
	protected function assertMoveEntry( ModerationTestsuiteEntry $entry ) {
		$testcase = $this->getTestcase();

		if ( $this->fields['mod_type'] == 'move' ) {
			$testcase->assertTrue( $entry->isMove,
				"Special:Moderation: incorrect formatting of the move entry." );

			$testcase->assertEquals( $this->getExpectedPage2Title(), $entry->page2Title,
				"Special:Moderation: New Title of suggested move doesn't match expected" );
		}
	}


	/**
		@brief Execute the TestSet, making an edit/upload/move with requested parameters.
	*/
	protected function makeChanges() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'moderation', $this->fields, __METHOD__ );

		$this->getTestcase()->assertEquals( 1, $dbw->affectedRows(),
			"Failed to insert a row into the 'moderation' SQL table."
		);
	}
}
