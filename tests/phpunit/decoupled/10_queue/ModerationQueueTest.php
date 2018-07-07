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
 * @brief Checks SQL table 'moderation' after the edit.
 */

require_once( __DIR__ . "/../../framework/ModerationTestsuite.php" );

/**
 * @covers ModerationNewChange
 */
class ModerationQueueTest extends MediaWikiTestCase
{
	/**
	 * @dataProvider dataProvider
	 */
	public function testQueue( array $options ) {
		ModerationQueueTestSet::run( $options, $this );
	}

	/**
	 * @brief Provide datasets for testQueueEdit() runs.
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
			[ [ 'text' => 'Text before signature ~~~ Text after signature', 'needPst' => true ] ],
			[ [ 'summary' => 'Summary 1' ] ],
			[ [ 'summary' => 'Summary 2' ] ],
			[ [ 'userAgent' => 'UserAgent for Testing/1.0' ] ],
			[ [ 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 10_3_1 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Version/10.0 Mobile/14E304 Safari/602.1' ] ],
			[ [ 'filename' => 'image100x100.png' ] ],
			[ [ 'filename' => 'image100x100.png', 'viaApi' => true ] ],
			[ [ 'filename' => 'image100x100.png', 'text' => 'Before ~~~ After', 'needPst' => true ] ],
			[ [ 'existing' => true ] ],
			[ [ 'existing' => true, 'filename' => 'image100x100.png' ] ],
			[ [ 'title' => 'Old title', 'newTitle' => 'New title with spaces' ] ],
			[ [ 'title' => 'Old title', 'newTitle' => 'New_title_with_underscores', 'summary' => 'New title is cooler' ] ],
			[ [ 'title' => 'Title 1', 'newTitle' => 'Title 2', 'viaApi' => true ] ],
			[ [ 'modblocked' => true ] ],
			[ [ 'modblocked' => true, 'anonymously' => true ] ],
			[ [ 'modblocked' => true, 'viaApi' => true ] ],
			[ [ 'xff' => '127.1.2.3', 'viaApi' => true ] ],
			[ [ 'xff' => '203.0.113.195, 70.41.3.18, 150.172.238.178' ] ],
			[ [ 'xff' => '2001:db8:85a3:8d3:1319:8a2e:370:7348' ] ],
			[ [ 'watch' => true ] ],
			[ [ 'unwatch' => true ] ],
			[ [ 'watch' => true, 'filename' => 'image100x100.png' ] ],
			[ [ 'unwatch' => true, 'filename' => 'image100x100.png' ] ],
			[ [ 'watch' => true, 'newTitle' => 'Title #2' ] ],
			[ [ 'unwatch' => true, 'newTitle' => 'Title #2' ] ],
			[ [ 'watch' => true, 'anonymously' => true ] ],
			[ [ 'unwatch' => true, 'anonymously' => true ] ]
		];
	}
}

/**
 * @brief Represents one TestSet for testQueue().
 */
class ModerationQueueTestSet extends ModerationTestsuiteTestSet {

	protected $user = null; /**< User object */
	protected $title = null; /**< Title object */
	protected $newTitle = null; /**< Title object. Only used for moves. */
	protected $text = 'Hello, World!';
	protected $summary = 'Edit by the Moderation Testsuite';
	protected $userAgent = ModerationTestsuite::DEFAULT_USER_AGENT;
	protected $xff = null; /**< X-Forwarded-For header */
	protected $filename = null; /**< string. Only used for uploads. */
	protected $anonymously = false; /**< If true, the edit will be anonymous. ($user will be ignored) */
	protected $viaApi = false; /**< If true, edits are made via API. If false, they are made via the user interface. */
	protected $needPst = false; /**< If true, text is expected to be altered by PreSaveTransform (e.g. contains "~~~~"). */
	protected $existing = false; /*< If true, existing page will be edited. If false, new page will be created. */
	protected $oldText = ''; /**< Text of existing article (if any). */
	protected $modblocked = false; /**< If true, user will be modblocked before the edit. */
	protected $watch = null; /**< If true/false, defines the state of "Watch this page" checkbox. */

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
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

				case 'watch':
				case 'unwatch':
					$this->watch = ( $key == 'watch' );
					break;

				case 'text':
				case 'summary':
				case 'userAgent':
				case 'xff':
				case 'filename':
				case 'anonymously':
				case 'viaApi':
				case 'needPst':
				case 'existing':
				case 'modblocked':
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

		if ( $this->filename && $this->viaApi && ModerationTestsuite::mwVersionCompare( '1.28.0', '<' ) ) {
			$this->getTestcase()->markTestSkipped(
				'Test skipped: MediaWiki 1.27 doesn\'t support upload via API.' );
		}

		/* Shouldn't contain PreSaveTransform-affected text, e.g. "~~~~" */
		$this->oldText = wfTimestampNow() . '_' . rand() . '_OldText';
		if ( $this->oldText == $this->text ) {
			$this->oldText .= '+'; // Ensure that $oldText is different from $text
		}
	}

	/**
	 * @brief Assert the state of the database after the edit.
	 */
	protected function assertResults( MediaWikiTestCase $testcase ) {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		$expectedRow = $this->getExpectedRow();
		foreach ( $expectedRow as $key => $val ) {
			if ( $val instanceof ModerationTestSetRegex ) {
				$testcase->assertRegExp( $val->regex, $row->$key, "Field $key doesn't match regex" );
			}
			else {
				$testcase->assertEquals( $val, $row->$key, "Field $key doesn't match expected" );
			}
		}

		$this->checkUpload( $row->mod_stash_key );
		$this->checkWatchlist( $this->watch );
	}

	/**
	 * @brief Execute the TestSet, making an edit/upload/move with requested parameters.
	 */
	protected function makeChanges() {
		$testcase = $this->getTestcase();

		$t = $this->getTestsuite();
		$t->setUserAgent( $this->userAgent );

		if ( $this->xff ) {
			$t->setHeader( 'X-Forwarded-For', $this->xff );
		}

		if ( $this->existing || $this->newTitle ) {
			if ( $this->filename ) {
				$moderatorUser = User::newFromName( 'User 1' );
				$t->loginAs( $moderatorUser );
				$t->apiUpload( $this->title->getText(), $this->filename, $this->oldText );
			}
			else {
				ModerationTestUtil::fastEdit(
					$this->title,
					$this->oldText,
					'',
					$this->user
				);
			}
		}

		$t->loginAs( $this->user );

		if ( $this->modblocked ) {
			/* Apply ModerationBlock to $this->user */
			$dbw = wfGetDB( DB_MASTER );
			$dbw->insert( 'moderation_block',
				[
					'mb_address' => $this->user->getName(),
					'mb_user' => $this->user->getId(),
					'mb_by' => 0,
					'mb_by_text' => 'Some moderator',
					'mb_timestamp' => $dbw->timestamp()
				],
				__METHOD__
			);
		}

		if ( $this->watch === false ) {
			/* Unwatch test requested, add $this->title into the Watchlist */
			WatchAction::doWatch( $this->title, $this->user );
		}

		$extraParams = [];
		if ( $this->watch === true ) {
			$watchField = $this->newTitle ? 'wpWatch' : 'wpWatchthis';
			$extraParams[$watchField] = 1;
		}

		if ( $this->filename ) {
			/* Upload */
			$t->uploadViaAPI = $this->viaApi;
			$result = $t->doTestUpload(
				$this->title->getText(), /* Without "File:" namespace prefix */
				$this->filename,
				$this->text,
				$extraParams
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
			$t->moveViaAPI = $this->viaApi;
			$result = $t->doTestMove(
				$this->title->getFullText(),
				$this->newTitle->getFullText(),
				$this->summary,
				$extraParams
			);

			if ( $this->viaApi ) {
				$testcase->assertEquals( '(moderation-move-queued)', $result );
			}
			else {
				$testcase->assertFalse( $result->getError(), __METHOD__ . "(): Special:MovePage displayed an error." );
				$testcase->assertContains( '(moderation-move-queued)', $result->getSuccessText() );
			}
		}
		else {
			/* Normal edit */
			$t->editViaAPI = $this->viaApi;
			$t->doTestEdit(
				$this->title->getFullText(),
				$this->text,
				$this->summary,
				'',
				$extraParams
			);
		}
	}

	/**
	 * @brief Returns array of expected post-edit values of all mod_* fields in the database.
	 * @note Values like "/value/" are treated as regular expressions.
	 * @returns [ 'mod_user' => ..., 'mod_namespace' => ..., ... ]
	 */
	protected function getExpectedRow() {
		$expectedContent = ContentHandler::makeContent( $this->text, null, CONTENT_MODEL_WIKITEXT );
		if ( $this->needPst ) {
			/* Not done for all tests to make tests faster.
				DataSet must explicitly indicate that its text needs PreSaveTransform.
			*/
			global $wgContLang;
			$expectedContent = $expectedContent->preSaveTransform(
				$this->title,
				$this->user,
				ParserOptions::newFromUserAndLang( $this->user, $wgContLang )
			);
		}

		$expectedText = $expectedContent->getNativeData();

		$expectedSummary = $this->summary;
		if ( $this->filename ) {
			if ( $this->viaApi ) {
				/* API has different parameters for 'text' and 'summary' */
				$expectedSummary = '';
			}
			else {
				/* Special:Upload copies text into summary */
				$expectedSummary = $this->text;

				if ( ModerationTestsuite::mwVersionCompare( '1.31.0', '>=' ) ) {
					/* In MediaWiki 1.31+,
						Special:Upload prepends InitialText with "== Summary ==" header */
					$headerText = '== ' . wfMessage( 'filedesc' )->inContentLanguage()->text() . ' ==';
					$expectedText = "$headerText\n$expectedText";
				}
			}
		}

		return [
			'mod_id' => new ModerationTestSetRegex( '/^[0-9]+$/' ),
			'mod_timestamp' => new ModerationTestSetRegex( '/^[0-9]{14}$/' ),
			'mod_user' => $this->user->getId(),
			'mod_user_text' => $this->user->getName(),
			'mod_cur_id' => $this->existing ? $this->title->getArticleId() : 0,
			'mod_namespace' => $this->title->getNamespace(),
			'mod_title' => $this->title->getDBKey(),
			'mod_comment' => $expectedSummary,
			'mod_minor' => 0,
			'mod_bot' => 0,
			'mod_new' => ( $this->existing || $this->newTitle ) ? 0 : 1,
			'mod_last_oldid' => $this->existing ? $this->title->getLatestRevID( Title::GAID_FOR_UPDATE ) : 0,
			'mod_ip' => '127.0.0.1',
			'mod_old_len' => $this->existing ? strlen( $this->oldText ) : 0,
			'mod_new_len' => $this->newTitle ? 0 : strlen( $expectedText ),
			'mod_header_xff' => $this->xff ?: null,
			'mod_header_ua' => $this->userAgent,
			'mod_preload_id' => (
				$this->user->isLoggedIn() ?
					'[' . $this->user->getName() :
					new ModerationTestSetRegex( '/^\][0-9a-f]+$/' )
			),
			'mod_rejected' => $this->modblocked ? 1 : 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => $this->modblocked ? wfMessage( 'moderation-blocker' )->inContentLanguage()->text() : null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => $this->modblocked ? 1 : 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => $this->newTitle ? '' : $expectedText,
			'mod_stash_key' => $this->filename ? new ModerationTestSetRegex( '/^[0-9a-z\.]+$/i' ) : '',
			'mod_tags' => null,
			'mod_type' => $this->newTitle ? 'move' : 'edit',
			'mod_page2_namespace' => $this->newTitle ? $this->newTitle->getNamespace() : 0,
			'mod_page2_title' => $this->newTitle ? $this->newTitle->getDBKey() : '',
		];
	}

	/**
	 * @brief Assert the state of UploadStash after the test.
	 * @param $stashKey Value of mod_stash_key (as found in the database after the test).
	 */
	protected function checkUpload( $stashKey ) {
		if ( !$this->filename ) {
			return; // Not an upload, nothing to do
		}

		/* Check that UploadStash contains the newly uploaded file */
		$srcPath = ModerationTestsuite::findSourceFilename( $this->filename );
		$expectedContents = file_get_contents( $srcPath );

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $this->user );
		$file = $stash->getFile( $stashKey );
		$contents = file_get_contents( $file->getLocalRefPath() );

		$this->getTestcase()->assertEquals( $expectedContents, $contents,
			"Stashed file is different from uploaded file" );
	}

	/**
	 * @brief Assert the state of Watchlist after the test.
	 */
	protected function checkWatchlist( $expected ) {
		if ( $expected === null ) {
			return; // Watch/Unwatch test not requested
		}

		if ( $this->user->isAnon() ) {
			$expected = false; /* Anonymous users don't have a watchlist */
		}

		$this->assertWatched( $expected, $this->title );
		if ( $this->newTitle ) {
			$this->assertWatched( $expected, $this->newTitle );
		}
	}

	/**
	 * @brief Assert that $title is watched/unwatched.
	 * @param $expectedState True if $title should be watched, false if not.
	 */
	protected function assertWatched( $expectedState, Title $title ) {
		// Note: $user->isWatched() can't be used,
		// because it would return cached results.
		if ( method_exists( 'WatchedItemStore', 'getDefaultInstance' ) ) {
			/* MediaWiki 1.27 */
			$watchedItemStore = WatchedItemStore::getDefaultInstance();
		}
		else {
			/* MediaWiki 1.28+ */
			$watchedItemStore = MediaWiki\MediaWikiServices::getInstance()->getWatchedItemStore();
		}

		$isWatched = (bool)$watchedItemStore->loadWatchedItem( $this->user, $title );
		if ( $expectedState ) {
			$this->getTestcase()->assertTrue( $isWatched,
				"Page edited with \"Watch this page\" is not in watchlist" );
		}
		else {
			$this->getTestcase()->assertFalse( $isWatched,
				"Page edited without \"Watch this page\" was not deleted from the watchlist" );
		}
	}
}

/**
 * @brief Regular expression returned by getExpectedRow() instead of a constant field value.
 */
class ModerationTestSetRegex {
	public $regex;

	public function __construct( $regex ) {
		$this->regex = $regex;
	}
}
