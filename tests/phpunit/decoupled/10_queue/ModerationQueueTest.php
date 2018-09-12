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

require_once __DIR__ . "/../../framework/ModerationTestsuite.php";

/**
 * @covers ModerationNewChange
 */
class ModerationQueueTest extends MediaWikiTestCase {
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
			[ [ 'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 10_3_1 like Mac OS X) ' .
				'AppleWebKit/603.1.30 (KHTML, like Gecko) Version/10.0 ' .
				'Mobile/14E304 Safari/602.1' ] ],
			[ [ 'filename' => 'image100x100.png' ] ],
			[ [ 'filename' => 'image100x100.png', 'viaApi' => true ] ],
			[ [ 'filename' => 'image100x100.png', 'text' => 'Before ~~~ After', 'needPst' => true ] ],
			[ [ 'existing' => true ] ],
			[ [ 'existing' => true, 'filename' => 'image100x100.png' ] ],
			[ [ 'title' => 'Old title', 'newTitle' => 'New title with spaces' ] ],
			[ [ 'title' => 'Old title', 'newTitle' => 'New_title_with_underscores',
				'summary' => 'New title is cooler' ] ],
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

	/** @var User */
	protected $user = null;

	/** @var Title */
	protected $title = null;

	/** @var Title Second title, only used for moves. */
	protected $newTitle = null;

	/** @var string */
	protected $text = 'Hello, World!';

	/** @var string */
	protected $summary = 'Edit by the Moderation Testsuite';

	/** @var string */
	protected $userAgent = ModerationTestsuite::DEFAULT_USER_AGENT;

	/** @var string X-Forwarded-For header */
	protected $xff = null;

	/** @var string Source filename, only used for uploads. */
	protected $filename = null;

	/** @var bool If true, the edit will be anonymous. ($user will be ignored) */
	protected $anonymously = false;

	/** @var bool If true, edits are made via API, if false, via the user interface. */
	protected $viaApi = false;

	/** @var bool If true, text is expected to be altered by PreSaveTransform
		(e.g. contains "~~~~"). */
	protected $needPst = false;

	/** @var bool If true, existing page will be edited. If false, new page will be created. */
	protected $existing = false;

	/** @var string Text of existing article (if any) */
	protected $oldText = '';

	/** @var bool If true, user will be modblocked before the edit */
	protected $modblocked = false;

	/** @var bool|null If true/false, defines the state of "Watch this page" checkbox. */
	protected $watch = null;

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
		} elseif ( !$this->user ) {
			$this->user = User::newFromName( 'User 5' );
		}

		if ( !$this->title ) {
			$pageName = $this->filename ? 'File:Test image 1.png' : 'Test page 1';
			$this->title = Title::newFromText( $pageName );
		}

		if ( $this->filename && $this->viaApi &&
			ModerationTestsuite::mwVersionCompare( '1.28.0', '<' )
		) {
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
		$row = $this->assertRowEquals( $this->getExpectedRow() );

		$this->assertTimestampIsRecent( $row->mod_timestamp );
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
			$this->precreatePage( $this->title, $this->oldText, $this->filename );
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

		$bot = $t->getBot( $this->viaApi ? 'api' : 'nonApi' );

		if ( $this->filename ) {
			/* Upload */
			$result = $bot->upload(
				$this->title->getText(), /* Without "File:" namespace prefix */
				$this->filename,
				$this->text,
				$extraParams
			);
			$testcase->assertTrue( $result->isIntercepted(),
				"Upload wasn't intercepted by Moderation." );
		} elseif ( $this->newTitle ) {
			$result = $bot->move(
				$this->title->getFullText(),
				$this->newTitle->getFullText(),
				$this->summary,
				$extraParams
			);
			$testcase->assertTrue( $result->isIntercepted(),
				"Move wasn't intercepted by Moderation." );
		} else {
			/* Normal edit */
			$result = $bot->edit(
				$this->title->getFullText(),
				$this->text,
				$this->summary,
				'',
				$extraParams
			);
			$testcase->assertTrue( $result->isIntercepted(),
				"Edit wasn't intercepted by Moderation." );
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
			} else {
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
			'mod_rejected_by_user_text' => $this->modblocked ?
				wfMessage( 'moderation-blocker' )->inContentLanguage()->text() : null,
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
		} else {
			/* MediaWiki 1.28+ */
			$watchedItemStore = MediaWiki\MediaWikiServices::getInstance()->getWatchedItemStore();
		}

		$isWatched = (bool)$watchedItemStore->loadWatchedItem( $this->user, $title );
		if ( $expectedState ) {
			$this->getTestcase()->assertTrue( $isWatched,
				"Page edited with \"Watch this page\" is not in watchlist" );
		} else {
			$this->getTestcase()->assertFalse( $isWatched,
				"Page edited without \"Watch this page\" was not deleted from the watchlist" );
		}
	}
}
