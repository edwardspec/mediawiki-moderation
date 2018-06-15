<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
	@brief Automated testsuite of Extension:Moderation.
*/

require_once( __DIR__ . '/ModerationTestsuiteEntry.php' );
require_once( __DIR__ . '/ModerationTestsuiteHTML.php' );
require_once( __DIR__ . '/ModerationTestsuiteResponse.php' );
require_once( __DIR__ . '/ModerationTestsuiteSubmitResult.php' );

/* FIXME: this can really use some autoloading, as only one engine is needed at a time */
require_once( __DIR__ . '/engine/IModerationTestsuiteEngine.php' );
require_once( __DIR__ . '/engine/ModerationTestsuiteEngine.php' );

/* Completely working Engine, used for pre-commit testing */
require_once( __DIR__ . '/engine/realhttp/ModerationTestsuiteRealHttpEngine.php' );

/* Experimental, idea looks promising */
require_once( __DIR__ . '/engine/cli/ModerationTestsuiteCliEngine.php' );

/* Proof of concept Engine, will probably be abandoned */
require_once( __DIR__ . '/engine/realcgi/ModerationTestsuiteRealCGIEngine.php' );
require_once( __DIR__ . '/engine/realcgi/ModerationTestsuiteCGIHttpRequest.php' );

/* Not yet ready (issues with SessionManager),
	less useful than RealHttp (interferes too much with MediaWiki during the test) */
require_once( __DIR__ . '/engine/internal/ModerationTestsuiteApiMain.php' );
require_once( __DIR__ . '/engine/internal/ModerationTestsuiteInternalInvocationEngine.php' );
require_once( __DIR__ . '/engine/internal/ModerationTestsuiteInternallyInvokedWiki.php' );

class ModerationTestsuite
{
	const TEST_PASSWORD = '123456';
	const DEFAULT_USER_AGENT = 'MediaWiki Moderation Testsuite';

	protected $engine; /**< ModerationTestsuiteEngine class */
	public $html; /**< ModerationTestsuiteHTML class */

	public $lastEdit = []; /**< array, populated by setLastEdit() */

	function __construct() {
		$this->prepareDbForTests();

		$this->engine = ModerationTestsuiteEngine::factory();

		$this->html = new ModerationTestsuiteHTML( $this->engine );
		$this->setUserAgent( self::DEFAULT_USER_AGENT );
	}

	public function query( $apiQuery ) {
		return $this->engine->query( $apiQuery );
	}
	public function httpGet( $url ) {
		return $this->engine->httpGet( $url );
	}
	public function httpPost( $url, array $postData = [] ) {
		return $this->engine->httpPost( $url, $postData );
	}
	public function setUserAgent( $ua ) {
		$this->engine->setUserAgent( $ua );
	}
	public function getEditToken() {
		return $this->engine->getEditToken();
	}

	/**
		@brief Don't throw exception when HTTP request returns $code.
	*/
	public function ignoreHttpError( $code ) {
		$this->engine->ignoreHttpError( $code );
	}

	/**
		@brief Re-enable throwing an exception when HTTP request returns $code.
	*/
	public function stopIgnoringHttpError( $code ) {
		$this->engine->stopIgnoringHttpError( $code );
	}

	#
	# Functions for parsing Special:Moderation.
	#
	private $lastFetchedSpecial = [];

	public $new_entries;
	public $deleted_entries;

	public function getSpecialURL( $query = [] )
	{
		$title = Title::newFromText( 'Moderation', NS_SPECIAL )->fixSpecialName();
		return wfAppendQuery( $title->getLocalURL(), $query );
	}

	/**
		@brief Delete the results of previous fetchSpecial().
			If fetchSpecial() is then called, all entries
			in this folder will be considered new entries.
	*/
	public function assumeFolderIsEmpty( $folder = 'DEFAULT' )
	{
		$this->lastFetchedSpecial[$folder] = [];
	}

	/**
		@brief Download and parse Special:Moderation. Diff its current
			state with the previously downloaded/parsed state, and
			populate the arrays \b $new_entries, \b $old_entries.

		@remark Logs in as $moderator.
	*/
	public function fetchSpecial( $folder = 'DEFAULT' )
	{
		if(!$this->isModerator()) { /* Don't relogin in testModeratorNotAutomoderated() */
			$this->loginAs( $this->moderator );
		}

		$query = [ 'limit' => 150 ];
		if ( $folder != 'DEFAULT' ) {
			$query['folder'] = $folder;
		}
		$url = $this->getSpecialURL( $query );

		$html = $this->html->loadFromURL( $url );
		$spans = $html->getElementsByTagName( 'span' );

		$entries = [];
		foreach ( $spans as $span )
		{
			if ( strpos( $span->getAttribute( 'class' ), 'modline' ) !== false ) {
				$e = new ModerationTestsuiteEntry( $span );
				$entries[$e->id] = $e;
			}
		}

		if ( array_key_exists( $folder, $this->lastFetchedSpecial ) ) {
			$before = $this->lastFetchedSpecial[$folder];
		}
		else {
			$before = [];
		}
		$after = $entries;

		$this->new_entries = array_values( array_diff_key( $after, $before ) );
		$this->deleted_entries = array_values( array_diff_key( $before, $after ) );

 		$this->lastFetchedSpecial[$folder] = $entries;
	}

	#
	# Database-related functions.
	#
	private function createTestUser( $name, $groups = [] )
	{
		$user = User::createNew( $name );
		$user->setPassword( self::TEST_PASSWORD );

		# With "qqx" language selected, messages are replaced with
		# their names, so parsing process is translation-independent.
		$user->setOption( 'language', 'qqx' );

		$user->saveSettings();

		foreach ( $groups as $g ) {
			$user->addGroup( $g );
		}

		return $user;
	}

	/**
		@brief Create controlled environment before each test.
		(as in "Destroy everything on testsuite's path")
	*/
	private function prepareDbForTests()
	{
		if ( $this->mwVersionCompare( '1.28', '>=' ) ) {
			/*
				This is a workaround for the following problem:
				https://gerrit.wikimedia.org/r/328718

				Since MediaWiki 1.28, MediaWikiTestCase class
				started to aggressively isolate us from the real database.

				However this entire testsuite does the blackbox testing
				on the site, making HTTP queries as the users would do,
				so we need to check/modify the real database.

				Therefore we escape the "test DB" jail installed by MediaWikiTestCase.
			*/
			if ( class_exists( 'MediaWikiTestCase' ) ) { /* Won't be defined if called from benchmark scripts */
				MediaWikiTestCase::teardownTestDB();
			}
		}

		$dbw = wfGetDB( DB_MASTER );

		/* Make sure the database is in a consistent state
			(after messy tests like RollbackResistantQueryTest.php) */
		if ( $dbw->writesOrCallbacksPending() ) {
			$dbw->commit( __METHOD__, 'flush' );
		}

		$dbw->begin( __METHOD__ );
		$dbw->delete( 'moderation', '*', __METHOD__ );
		$dbw->delete( 'moderation_block', '*', __METHOD__ );
		$dbw->delete( 'user', '*', __METHOD__ );
		$dbw->delete( 'user_groups', '*', __METHOD__ );
		$dbw->delete( 'user_properties', '*', __METHOD__ );
		$dbw->delete( 'page', '*', __METHOD__ );
		$dbw->delete( 'revision', '*', __METHOD__ );
		$dbw->delete( 'logging', '*', __METHOD__ );
		$dbw->delete( 'text', '*', __METHOD__ );
		$dbw->delete( 'image', '*', __METHOD__ );
		$dbw->delete( 'uploadstash', '*', __METHOD__ );
		$dbw->delete( 'recentchanges', '*', __METHOD__ );
		$dbw->delete( 'watchlist', '*', __METHOD__ );
		$dbw->delete( 'abuse_filter', '*', __METHOD__ );
		$dbw->delete( 'abuse_filter_action', '*', __METHOD__ );
		$dbw->delete( 'change_tag', '*', __METHOD__ );
		$dbw->delete( 'tag_summary', '*', __METHOD__ );

		if ( $dbw->tableExists( 'ip_changes' ) ) {
			$dbw->delete( 'ip_changes', '*', __METHOD__ );
		}

		if ( $dbw->tableExists( 'cu_changes' ) ) {
			$dbw->delete( 'cu_changes', '*', __METHOD__ );
		}

		$this->moderator =
			$this->createTestUser( 'User 1', [ 'moderator', 'automoderated' ] );
		$this->moderatorButNotAutomoderated =
			$this->createTestUser( 'User 2', [ 'moderator' ] );
		$this->automoderated =
			$this->createTestUser( 'User 3', [ 'automoderated' ] );
		$this->rollback =
			$this->createTestUser( 'User 4', [ 'rollback' ] );
		$this->unprivilegedUser =
			$this->createTestUser( 'User 5', [] );
		$this->unprivilegedUser2 =
			$this->createTestUser( 'User 6', [] );
		$this->moderatorAndCheckuser =
			$this->createTestUser( 'User 7', [ 'moderator', 'checkuser' ] );

		$dbw->commit( __METHOD__ );

		$this->purgeTagCache();
	}

	/** @brief Prevent tags set by the previous test from affecting the current test */
	public function purgeTagCache() {
		ChangeTags::purgeTagCacheAll(); /* For RealHttpEngine tests */

		if ( class_exists( 'AbuseFilter' ) ) {
			AbuseFilter::$tagsToSet = []; /* For InternalInvocationEngine tests */
		}
	}

	#
	# High-level test functions.
	#
	public $moderator;
	public $moderatorButNotAutomoderated;
	public $rollback;
	public $automoderated;
	public $unprivilegedUser;
	public $unprivilegedUser2;
	public $moderatorAndCheckuser;

	protected $currentUser = null; /**< User object */

	public function loggedInAs() {
		return $this->currentUser;
	}

	public function isModerator() {
		if ( !$this->currentUser ) {
			return false;
		}

		$userId = $this->currentUser->getId();
		return ( $userId == $this->moderator->getId() ) ||
			( $userId == $this->moderatorButNotAutomoderated->getId() );
	}

	public function loginAs( User $user )
	{
		if ( $this->currentUser && $user->getId() == $this->currentUser->getId() ) {
			return; /* Nothing to do, already logged in */
		}

		if ( $user->isAnon() ) {
			$this->logout();
		}

		$this->engine->loginAs( $user );
		$this->currentUser = $user;
	}

	public function logout() {
		$this->engine->logout();
		$this->currentUser = $this->engine->loggedInAs();
	}

	/**
		@brief Create an account and return User object.
		@note Will not login automatically (loginAs must be called).
	*/
	public function createAccount( $username ) {
		return $this->engine->createAccount( $username );
	}

	/**
		@brief Move the page via API.
		@returns API response
	*/
	public function apiMove( $oldTitle, $newTitle, $reason = '', array $extraParams = [] )
	{
		$ret = $this->query( [
			'action' => 'move',
			'from' => $oldTitle,
			'to' => $newTitle,
			'reason' => $reason,
			'token' => null
		] + $extraParams );

		$this->setLastEdit( $oldTitle, $reason, [ 'NewTitle' => $newTitle ] );
		return $ret;
	}

	/**
		@brief Place information about newly made change into lastEdit[] array.
	*/
	protected function setLastEdit( $title, $summary, array $extraData = [] ) {
		$this->lastEdit = $extraData + [
			'User' => $this->loggedInAs()->getName(),
			'Title' => $title,
			'Summary' => $summary
		];
	}

	/**
		@brief via the usual interface, as real users do.
		@returns ModerationTestsuiteSubmitResult object.
	*/
	public function nonApiMove( $oldTitle, $newTitle, $reason = '', array $extraParams = [] )
	{
		$newTitleObj = Title::newFromText( $newTitle );

		$req = $this->httpPost( wfScript( 'index' ), $extraParams + [
			'title' => 'Special:MovePage',
			'wpOldTitle' => $oldTitle,
			'wpNewTitleMain' => $newTitleObj->getText(),
			'wpNewTitleNs' => $newTitleObj->getNamespace(),
			'wpMove' => 'Move',
			'wpReason' => $reason
		] );

		$this->setLastEdit( $oldTitle, $reason, [ 'NewTitle' => $newTitle ] );
		return ModerationTestsuiteSubmitResult::newFromResponse( $req, $this );
	}


	/**
		@brief Make an edit via API.
		@warning Several side-effects can't be tested this way,
			for example HTTP redirect after editing or
			session cookies (used for anonymous preloading)
		@returns API response
	*/
	public function apiEdit( $title, $text, $summary, array $extraParams = [] )
	{
		return $this->query( [
			'action' => 'edit',
			'title' => $title,
			'text' => $text,
			'summary' => $summary,
			'token' => null
		] + $extraParams );
	}

	public $editViaAPI = false;
	public $uploadViaAPI = false;

	/**
		@brief Make an edit via the usual interface, as real users do.
		@returns ModerationTestsuiteResponse object.
	*/
	public function nonApiEdit( $title, $text, $summary, array $extraParams = [] )
	{
		$params = $extraParams + [
			'action' => 'submit',
			'title' => $title,
			'wpTextbox1' => $text,
			'wpSummary' => $summary,
			'wpEditToken' => $this->getEditToken(),
			'wpSave' => 'Save',
			'wpIgnoreBlankSummary' => '',
			'wpRecreate' => ''
		];

		if ( defined( 'EditPage::UNICODE_CHECK' ) ) { // MW 1.30+
			$params['wpUnicodeCheck'] = EditPage::UNICODE_CHECK;
		}

		/* Determine wpEdittime (timestamp of the current revision of $title),
			otherwise edit conflict will occur. */
		$rev = $this->getLastRevision( $title );
		$params['wpEdittime'] = $rev ? wfTimestamp( TS_MW, $rev['timestamp'] ) : '';

		return $this->httpPost( wfScript( 'index' ), $params );
	}

	public function doTestEdit( $title = null, $text = null, $summary = null, $section = '' )
	{
		if ( !$title ) {
			$title = $this->generateRandomTitle();
		}

		if ( !$text ) {
			$text = $this->generateRandomText();
		}

		if ( !$summary ) {
			$summary = $this->generateEditSummary();
		}

		# TODO: ensure that page $title doesn't already contain $text
		# (to avoid extremely rare test failures due to random collisions)

		$extraParams = [];
		if ( $this->editViaAPI ) {
			if ( $section !== '' ) {
				$extraParams['section'] = $section;
			}
			$ret = $this->apiEdit( $title, $text, $summary, $extraParams );
		}
		else {
			if ( $section !== '' ) {
				$extraParams['wpSection'] = $section;
			}
			$ret = $this->nonApiEdit( $title, $text, $summary, $extraParams );
		}

		/* TODO: check if successful */

		$this->setLastEdit( $title, $summary, [ 'Text' => $text ] );
		return $ret;
	}

	public $TEST_EDITS_COUNT = 3; /* See doNTestEditsWith() */

	/**
		@brief Do 2*N alternated edits - N by $user1 and N by $user2.
			Number of edits is $TEST_EDITS_COUNT.
			If $user2 is null, only makes N edits by $user1.
	*/
	public function doNTestEditsWith( $user1, $user2 = null,
		$prefix1 = 'Page', $prefix2 = 'AnotherPage'
	) {
		for ( $i = 0; $i < $this->TEST_EDITS_COUNT; $i ++ )
		{
			$this->loginAs( $user1 );
			$this->doTestEdit( $prefix1 . $i );

			if ( $user2 ) {
				$this->loginAs( $user2 );
				$this->doTestEdit( $prefix2 . $i );
			}
		}
	}

	/**
		@brief Makes one edit and returns its correct entry.
		@remark Logs in as $moderator.
	*/
	public function getSampleEntry( $title = null )
	{
		$this->fetchSpecial();
		$this->loginAs( $this->unprivilegedUser );
		$this->doTestEdit( $title );
		$this->fetchSpecial();

		return $this->new_entries[0];
	}

	public function generateRandomTitle()
	{
		/* Simple string, no underscores */

		return "Test page 1"; /* TODO: randomize */
	}

	private function generateRandomText()
	{
		return "Hello, World!"; /* TODO: randomize */
	}

	private function generateEditSummary()
	{
		/*
			NOTE: No wikitext! Plaintext only.
			Otherwise we'll have to run it through the parser before
			comparing to what's shown on Special:Moderation.
		*/

		return "Edit by the Moderation Testsuite";
	}

	/**
		@brief Perform a test upload.
		@returns MediaWiki error message code (e.g. "(emptyfile)").
		@retval null Upload succeeded (no errors found).
	*/
	public function doTestUpload( $title = null, $source_filename = null, $text = null, array $extraParams = [] )
	{
		if ( !$title ) {
			$title = $this->generateRandomTitle() . '.png';
		}

		if ( is_null( $text ) ) { # Empty string (no description) is allowed
			$text = $this->generateRandomText();
		}

		if ( !$source_filename ) {
			$source_filename = "image100x100.png";
		}

		if ( substr( $source_filename, 0, 1 ) != '/' ) {
			$source_filename = __DIR__ . "/../../resources/" . $source_filename;
		}

		$source_filename = realpath( $source_filename );

		if ( $this->uploadViaAPI ) {
			$error = $this->apiUpload( $title, $source_filename, $text, $extraParams );
		}
		else {
			$error = $this->nonApiUpload( $title, $source_filename, $text, $extraParams );
		}

		$this->setLastEdit(
			Title::newFromText( $title, NS_FILE )->getFullText(),
			'', /* Summary wasn't used */
			[
				'Text' => $text,
				'SHA1' => sha1_file( $source_filename ),
				'Source' => $source_filename
			]
		);
		return $error;
	}

	/**
		@brief Make an upload via the usual Special:Upload, as real users do.
		@returns ModerationTestsuiteSubmitResult object.
	*/
	public function nonApiUpload( $title, $source_filename, $text, array $extraParams = [] )
	{
		$req = $this->httpPost( wfScript( 'index' ), $extraParams + [
			'title' => 'Special:Upload',
			'wpUploadFile' => curl_file_create( $source_filename ),
			'wpDestFile' => $title,
			'wpIgnoreWarning' => '1',
			'wpEditToken' => $this->getEditToken(),
			'wpUpload' => 'Upload',
			'wpUploadDescription' => $text
		] );

		return ModerationTestsuiteSubmitResult::newFromResponse( $req, $this );
	}

	/**
		@brief Make an upload via API.
		@returns Error code (e.g. '(emptyfile)') or null.
	*/
	public function apiUpload( $title, $source_filename, $text )
	{
		$ret = $this->query( [
			'action' => 'upload',
			'filename' => $title,
			'text' => $text,
			'token' => null,
			'file' => curl_file_create( $source_filename ),
			'ignorewarnings' => 1
		] );

		if ( isset( $ret['error']['code'] ) ) {
			return '(' . $ret['error']['code'] . ')';
		}

		return null; /* No errors */
	}

	/**
		@brief Get up to $count moderation log entries via API
			(most recent first).
	*/
	public function apiLogEntries( $count = 100 )
	{
		$ret = $this->query( [
			'action' => 'query',
			'list' => 'logevents',
			'letype' => 'moderation',
			'lelimit' => $count
		] );
		return $ret['query']['logevents'];
	}

	/**
		@brief Get up to $count moderation log entries NOT via API
			(most recent first).
	*/
	public function nonApiLogEntries( $count = 100 )
	{
		$title = Title::newFromText( 'Log/moderation', NS_SPECIAL )->fixSpecialName();
		$url = wfAppendQuery( $title->getLocalURL(), [
			'limit' => $count
		] );
		$html = $this->html->loadFromURL( $url );

		$events = [];
		$list_items = $html->getElementsByTagName( 'li' );
		foreach ( $list_items as $li )
		{
			$class = $li->getAttribute( 'class' );
			if ( strpos( $class, 'mw-logline-moderation' ) !== false )
			{
				$matches = null;
				if ( preg_match( '/\(logentry-moderation-([^:]+): (.*)\)\s*$/',
					$li->textContent, $matches ) )
				{
					$events[] = [
						'type' => $matches[1],
						'params' => explode( ', ', $matches[2] )
					];
				}
			}
		}
		return $events;
	}

	/**
		@brief Get the last revision of page $title via API.
	*/
	public function getLastRevision( $title )
	{
		$ret = $this->query( [
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'user|timestamp|comment|content|ids',
			'titles' => $title
		] );
		$retPage = array_shift( $ret['query']['pages'] );
		return isset( $retPage['missing'] ) ? false : $retPage['revisions'][0];
	}

	/**
		@brief Remove "token=" from URL and return its new HTML title.
	*/
	public function noTokenTitle( $url )
	{
		$bad_url = preg_replace( '/token=[^&]*/', '', $url );
		return $this->html->getTitle( $bad_url );
	}

	/**
		@brief Corrupt "token=" in URL and return its new HTML title.
	*/
	public function badTokenTitle( $url )
	{
		$bad_url = preg_replace( '/(token=)([^&]*)/', '\1WRONG\2', $url );
		return $this->html->getTitle( $bad_url );
	}

	/**
		@brief Wait for "recentchanges" table to be updated by DeferredUpdates.
		@param $numberOfEdits How many recent revisions must be in RecentChanges.
		@returns Array of revision IDs.

		This function is needed before testing cu_changes or tags:
		they are updated in RecentChange_save hook,
		so they might not yet exist when we return from doTestEdit().

		Usage:
			$waiter = $t->waitForRecentChangesToAppear();
			// Do something that should create N recentchanges entries
			$waiter( N );
	*/
	public function waitForRecentChangesToAppear() {
		$dbw = wfGetDB( DB_MASTER );
		$lastRcId = $dbw->selectField( 'recentchanges', 'rc_id', '', __METHOD__,
			[ 'ORDER BY' => 'rc_timestamp DESC' ]
		);

		return function( $numberOfEdits ) use ( $dbw, $lastRcId ) {
			$pollTimeLimitSeconds = 5; /* Polling will fail after these many seconds */
			$pollRetryPeriodSeconds = 0.2; /* How often to check recentchanges */

			/* Wait for all $revisionIds to appear in recentchanges table */
			$maxTime = time() + $pollTimeLimitSeconds;
			do {
				$rcRowsFound = $dbw->selectRowCount(
					'recentchanges', 'rc_id',
					[ 'rc_id > ' . $dbw->addQuotes( $lastRcId ) ],
					__METHOD__,
					[ 'LIMIT' => $numberOfEdits ]
				);
				if ( $rcRowsFound >= $numberOfEdits ) {
					return; /* Success */
				}

				/* Continue polling */
				usleep( $pollRetryPeriodSeconds * 1000 * 1000 );
			} while( time() < $maxTime );

			throw new MWException( "waitForRecentChangesToAppear(): new $numberOfEdits entries haven't appeared in $pollTimeLimitSeconds seconds." );
		};
	}

	/**
		@brief Queue an edis that would cause an edit conflict when approved.
		@returns ModerationEntry
	*/
	public function causeEditConflict( $title, $origText, $textOfUser1, $textOfUser2 ) {
		$this->loginAs( $this->automoderated );
		$this->doTestEdit( $title, $origText );

		$this->loginAs( $this->unprivilegedUser );
		$this->doTestEdit( $title, $textOfUser1 );

		$this->loginAs( $this->automoderated );
		$this->doTestEdit( $title, $textOfUser2 );

		$this->fetchSpecial();
		return $this->new_entries[0];
	}

	/**
		@brief Get cuc_agent of the last entry in "cu_changes" table.
		@returns User-agent (string).
	*/
	public function getCUCAgent() {
		$agents = $this->getCUCAgents( 1 );
		return array_pop( $agents );
	}

	/**
		@brief Get cuc_agent of the last entries in "cu_changes" table.
		@param $limit How many entries to select.
		@returns Array of user-agents.
	*/
	public function getCUCAgents( $limit ) {
		$dbw = wfGetDB( DB_MASTER );
		return $dbw->selectFieldValues(
			'cu_changes', 'cuc_agent', '',
			__METHOD__,
			[
				'ORDER BY' => 'cuc_id DESC',
				'LIMIT' => $limit
			]
		);
	}

	/**
		@brief Create AbuseFilter rule that will assign tags to all edits.
		@returns ID of the newly created filter.
	*/
	public function addTagAllAbuseFilter( array $tags ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'abuse_filter',
			[
				'af_pattern' => 'true',
				'af_user' => 0,
				'af_user_text' => 'MediaWiki default',
				'af_timestamp' => wfTimestampNow(),
				'af_enabled' => 1,
				'af_comments' => '',
				'af_public_comments' => 'Assign tags to all edits',
				'af_hidden' => 0,
				'af_hit_count' => 0,
				'af_throttled' => 0,
				'af_deleted' => 0,
				'af_actions' => 'tag',
				'af_global' => 0,
				'af_group' => 'default'
			],
			__METHOD__
		);
		$filterId = $dbw->insertId();

		$dbw->insert( 'abuse_filter_action',
			[
				'afa_filter' => $filterId,
				'afa_consequence' => 'tag',
				'afa_parameters' => join( "\n", $tags )
			],
			__METHOD__
		);

		return $filterId;
	}

	/**
		@brief Disable AbuseFilter rule #$filterId.
	*/
	public function disableAbuseFilter( $filterId ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'abuse_filter', [ 'af_enabled' => 0 ], [ 'af_id' => $filterId ], __METHOD__ );
		$this->purgeTagCache();
	}

	/**
		@brief Assert that API response $ret contains error $expectedErrorCode.
	*/
	public function assertApiError( $expectedErrorCode, array $ret, MediaWikiTestCase $tcase ) {
		$tcase->assertArrayHasKey( 'error', $ret );

		if ( $this->mwVersionCompare( '1.29', '>=' ) ) {
			# MediaWiki 1.29+
			$tcase->assertEquals( $expectedErrorCode, $ret['error']['code'] );
		} else {
			# MediaWiki 1.28 and older displayed "unknownerror" status code
			# for some custom hook-returned errors (e.g. from PageContentSave).
			$tcase->assertEquals( 'unknownerror', $ret['error']['code'] );
			$tcase->assertContains( $expectedErrorCode, $ret['error']['info'] );
		}
	}

	/**
		@brief Call version_compare on $wgVersion.
	*/
	public static function mwVersionCompare( $compareWith, $operator ) {
		global $wgVersion;
		return version_compare( $wgVersion, $compareWith, $operator );
	}
}


class ModerationTestsuiteException extends Exception {};
class ModerationTestsuiteHttpError extends ModerationTestsuiteException {};
