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

require_once( __DIR__ . '/ModerationTestsuiteApiMain.php' );
require_once( __DIR__ . '/IModerationTestsuiteEngine.php' );
require_once( __DIR__ . '/ModerationTestsuiteEngine.php' );
require_once( __DIR__ . '/ModerationTestsuiteEntry.php' );
require_once( __DIR__ . '/ModerationTestsuiteHTML.php' );
require_once( __DIR__ . '/ModerationTestsuiteRealHttpEngine.php' );
require_once( __DIR__ . '/ModerationTestsuiteResponse.php' );
require_once( __DIR__ . '/ModerationTestsuiteInternalInvocationEngine.php' );

class ModerationTestsuite
{
	const TEST_PASSWORD = '123456';
	const DEFAULT_USER_AGENT = 'MediaWiki Moderation Testsuite';

	protected $engine; /**< ModerationTestsuiteEngine class */
	public $html; /**< ModerationTestsuiteHTML class */

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
	public function deleteAllCookies() {
		$this->engine->deleteAllCookies();
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
		global $wgVersion;
		if ( version_compare( $wgVersion, '1.28', '>=' ) ) {
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
			MediaWikiTestCase::teardownTestDB();
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();
		$dbw->delete( 'moderation', [ '1' ], __METHOD__ );
		$dbw->delete( 'moderation_block', [ '1' ], __METHOD__ );
		$dbw->delete( 'user', [ '1' ], __METHOD__ );
		$dbw->delete( 'user_groups', [ '1' ], __METHOD__ );
		$dbw->delete( 'user_properties', [ '1' ], __METHOD__ );
		$dbw->delete( 'page', [ '1' ], __METHOD__ );
		$dbw->delete( 'revision', [ '1' ], __METHOD__ );
		$dbw->delete( 'logging', [ '1' ], __METHOD__ );
		$dbw->delete( 'text', [ '1' ], __METHOD__ );
		$dbw->delete( 'image', [ '1' ], __METHOD__ );
		$dbw->delete( 'uploadstash', [ '1' ], __METHOD__ );
		$dbw->delete( 'recentchanges', [ '1' ], __METHOD__ );
		$dbw->delete( 'watchlist', [ '1' ], __METHOD__ );
		$dbw->delete( 'abuse_filter', [ '1' ], __METHOD__ );
		$dbw->delete( 'abuse_filter_action', [ '1' ], __METHOD__ );
		$dbw->delete( 'change_tag', [ '1' ], __METHOD__ );
		$dbw->delete( 'tag_summary', [ '1' ], __METHOD__ );

		if ( $dbw->tableExists( 'cu_changes' ) ) {
			$dbw->delete( 'cu_changes', [ '1' ], __METHOD__ );
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

		$dbw->commit();

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
		if ( defined( 'EditPage::UNICODE_CHECK' ) ) { // MW 1.30+
			$extraParams['wpUnicodeCheck'] = EditPage::UNICODE_CHECK;
		}

		return $this->httpPost( wfScript( 'index' ), [
			'action' => 'submit',
			'title' => $title,
			'wpTextbox1' => $text,
			'wpSummary' => $summary,
			'wpEditToken' => $this->getEditToken(),
			'wpSave' => 'Save',
			'wpIgnoreBlankSummary' => '',
			'wpRecreate' => '',
			'wpEdittime' => wfTimestampNow()
		] + $extraParams );
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

		if ( $this->editViaAPI ) {
			$ret = $this->apiEdit( $title, $text, $summary,
				array_filter( [ 'section' => $section ] )
			);
		}
		else {
			$ret = $this->nonApiEdit( $title, $text, $summary,
				array_filter( [ 'wpSection' => $section ] )
			);
		}

		/* TODO: check if successful */

		$this->lastEdit = [];
		$this->lastEdit['User'] = $this->loggedInAs()->getName();
		$this->lastEdit['Title'] = $title;
		$this->lastEdit['Text'] = $text;
		$this->lastEdit['Summary'] = $summary;

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
	public function doTestUpload( $title = null, $source_filename = null, $text = null )
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
			$error = $this->apiUpload( $title, $source_filename, $text );
		}
		else {
			$error = $this->nonApiUpload( $title, $source_filename, $text );
		}

		$this->lastEdit = [];
		$this->lastEdit['Text'] = $text;
		$this->lastEdit['User'] = $this->loggedInAs()->getName();
		$this->lastEdit['Title'] =
			Title::newFromText( $title, NS_FILE )->getFullText();
		$this->lastEdit['SHA1'] = sha1_file( $source_filename );
		$this->lastEdit['Source'] = $source_filename;

		return $error;
	}

	/**
		@brief Make an upload via the usual Special:Upload, as real users do.
		@returns Error code (e.g. '(emptyfile)') or null.
	*/
	public function nonApiUpload( $title, $source_filename, $text )
	{
		$req = $this->httpPost( wfScript( 'index' ), [
			'title' => 'Special:Upload',
			'wpUploadFile' => curl_file_create( $source_filename ),
			'wpDestFile' => $title,
			'wpIgnoreWarning' => '1',
			'wpEditToken' => $this->getEditToken(),
			'wpUpload' => 'Upload',
			'wpUploadDescription' => $text
		] );

		if ( $req->getResponseHeader( 'Location' ) ) {
			return null; # No errors
		}

		/* Find HTML error, if any */
		$html = $this->html->loadFromReq( $req );
		$divs = $html->getElementsByTagName( 'div' );

		foreach ( $divs as $div ) {
			# Note: the message can have parameters,
			# so we won't remove the braces around it.

			if ( $div->getAttribute( 'class' ) == 'error' ) {
				return trim( $div->textContent ); /* Error found */
			}
		}

		return null; /* No errors */
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
			'file' => curl_file_create( $source_filename )
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
		$ret_page = array_shift( $ret['query']['pages'] );
		return $ret_page['revisions'][0];
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
}


class ModerationTestsuiteException extends Exception {};
class ModerationTestsuiteHttpError extends ModerationTestsuiteException {};
