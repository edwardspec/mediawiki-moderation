<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015 Edward Chernenko.

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

require_once( __DIR__ . '/ModerationTestsuiteAPI.php' );
require_once( __DIR__ . '/ModerationTestsuiteEntry.php' );
require_once( __DIR__ . '/ModerationTestsuiteHTML.php' );
require_once( __DIR__ . '/ModerationTestsuiteHTTP.php' );

class ModerationTestsuite
{
	private $http;
	private $api;
	public $html;

	function __construct() {
		$this->prepareDbForTests();

		$this->http = new ModerationTestsuiteHTTP( $this );
		$this->api = new ModerationTestsuiteAPI( $this );
		$this->html = new ModerationTestsuiteHTML( $this );
	}
	public $TEST_PASSWORD = '123456';
	public function query( $query = array() ) {
		return $this->api->query( $query );
	}

	public function httpGet( $url ) {
		return $this->executeHttpRequest( $url, 'GET', array() );
	}
	public function httpPost( $url, $post_data = array() ) {
		return $this->executeHttpRequest( $url, 'POST', $post_data );
	}
	public function setUserAgent( $ua ) {
		$this->http->userAgent = $ua;
	}
	public function deleteAllCookies() {
		$this->http->resetCookieJar();
	}

	public $ignoreHttpError = array();

	private function executeHttpRequest( $url, $method, $post_data ) {
		$req = $this->http->makeRequest( $url, $method );
		$req->setData( $post_data );

		$status = $req->execute();

		if ( !$status->isOK() &&
			!in_array( $req->getStatus(), $this->ignoreHttpError )
		) {
			throw new ModerationTestsuiteHttpError;
		}

		return $req;
	}

	#
	# Functions for parsing Special:Moderation.
	#
	private $lastFetchedSpecial = array();

	public $new_entries;
	public $deleted_entries;

	public function getSpecialURL( $query = array() )
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
		$this->lastFetchedSpecial[$folder] = array();
	}

	/**
		@brief Download and parse Special:Moderation. Diff its current
			state with the previously downloaded/parsed state, and
			populate the arrays \b $new_entries, \b $old_entries.

		@remark Logs in as $moderator.
	*/
	public function fetchSpecial( $folder = 'DEFAULT' )
	{
		$this->loginAs( $this->moderator );

		$query = array( 'limit' => 150 );
		if ( $folder != 'DEFAULT' ) {
			$query['folder'] = $folder;
		}
		$url = $this->getSpecialURL( $query );

		$html = $this->html->loadFromURL( $url );
		$spans = $html->getElementsByTagName( 'span' );

		$entries = array();
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
			$before = array();
		}
		$after = $entries;

		$this->new_entries = array_values( array_diff_key( $after, $before ) );
		$this->deleted_entries = array_values( array_diff_key( $before, $after ) );

 		$this->lastFetchedSpecial[$folder] = $entries;
	}

	#
	# Database-related functions.
	#
	private function createTestUser( $name, $groups = array() )
	{
		$user = User::createNew( $name );
		$user->setPassword( $this->TEST_PASSWORD );

		# With "qqx" language selected, messages are replaced with
		# their names, so parsing process is translation-independent.
		$user->setOption( 'language', 'qqx' );

		$user->saveSettings();

		foreach ( $groups as $g )
			$user->addGroup( $g );

		return $user;
	}
	private function prepareDbForTests()
	{
		/* Controlled environment
			as in "Destroy everything on testsuite's path" */

		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();
		$dbw->delete( 'moderation', array( '1' ), __METHOD__ );
		$dbw->delete( 'moderation_block', array( '1' ), __METHOD__ );
		$dbw->delete( 'user', array( '1' ), __METHOD__ );
		$dbw->delete( 'user_groups', array( '1' ), __METHOD__ );
		$dbw->delete( 'page', array( '1' ), __METHOD__ );
		$dbw->delete( 'revision', array( '1' ), __METHOD__ );
		$dbw->delete( 'logging', array( '1' ), __METHOD__ );
		$dbw->delete( 'text', array( '1' ), __METHOD__ );
		$dbw->delete( 'image', array( '1' ), __METHOD__ );
		$dbw->delete( 'uploadstash', array( '1' ), __METHOD__ );
		$dbw->delete( 'recentchanges', array( '1' ), __METHOD__ );

		if ( $dbw->tableExists( 'cu_changes' ) )
			$dbw->delete( 'cu_changes', array( '1' ), __METHOD__ );

		$this->moderator =
			$this->createTestUser( 'User 1', array( 'moderator', 'automoderated' ) );
		$this->moderatorButNotAutomoderated =
			$this->createTestUser( 'User 2', array( 'moderator' ) );
		$this->automoderated =
			$this->createTestUser( 'User 3', array( 'automoderated' ) );
		$this->rollback =
			$this->createTestUser( 'User 4', array( 'rollback' ) );
		$this->unprivilegedUser =
			$this->createTestUser( 'User 5', array() );
		$this->unprivilegedUser2 =
			$this->createTestUser( 'User 6', array() );
		$this->moderatorAndCheckuser =
			$this->createTestUser( 'User 7', array( 'moderator', 'checkuser' ) );

		$dbw->commit();
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

	private $t_loggedInAs = null;

	public function loggedInAs()
	{
		return $this->t_loggedInAs;
	}

	public function loginAs( User $user )
	{
		$this->api->apiLogin( $user->getName() );
		$this->t_loggedInAs = $user;
	}

	public function logout() {
		$this->api->apiLogout();
		$this->t_loggedInAs =
			User::newFromName( $this->api->apiLoggedInAs(), false );
	}

	/**
		@brief Create an account and return User object.
		@note Will not login automatically (loginAs must be called).
	*/
	public function createAccount( $username ) {
		if ( !$this->api->apiCreateAccount( $username ) ) {
			return false;
		}
		return User::newFromName( $username, false );
	}

	/**
		@brief Make an edit via API.
		@warning Several side-effects can't be tested this way,
			for example HTTP redirect after editing or
			session cookies (used for anonymous preloading)
		@returns API response
	*/
	public function apiEdit( $title, $text, $summary )
	{
		return $this->query( array(
			'action' => 'edit',
			'title' => $title,
			'text' => $text,
			'summary' => $summary,
			'token' => null
		) );
	}

	public $editViaAPI = false;

	/**
		@brief Make an edit via the usual interface, as real users do.
		@returns Completed request of type MWHttpRequest.
	*/
	public function nonApiEdit( $title, $text, $summary, $extra_params = array() )
	{
		return $this->httpPost( wfScript( 'index' ), array(
			'action' => 'submit',
			'title' => $title,
			'wpTextbox1' => $text,
			'wpSummary' => $summary,
			'wpEditToken' => $this->api->editToken,
			'wpSave' => 'Save',
			'wpIgnoreBlankSummary' => '',
			'wpRecreate' => '',
			'wpEdittime' => wfTimestampNow()
		) + $extra_params );
	}

	public function doTestEdit( $title = null, $text = null, $summary = null )
	{
		if ( !$title )
			$title = $this->generateRandomTitle();

		if ( !$text )
			$text = $this->generateRandomText();

		if ( !$summary )
			$summary = $this->generateEditSummary();

		# TODO: ensure that page $title doesn't already contain $text
		# (to avoid extremely rare test failures due to random collisions)

		if ( $this->editViaAPI ) {
			$ret = $this->apiEdit( $title, $text, $summary );
		}
		else {
			$ret = $this->nonApiEdit( $title, $text, $summary );
		}

		/* TODO: check if successful */

		$this->lastEdit = array();
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
		if ( !$title )
			$title = $this->generateRandomTitle() . '.png';

		if ( is_null( $text ) ) # Empty string (no description) is allowed
			$text = $this->generateRandomText();

		if ( !$source_filename ) {
			$source_filename = __DIR__ . "/resources/image100x100.png";
		}
		$source_filename = realpath( $source_filename );

		$req = $this->http->makeRequest( wfScript( 'index' ), 'POST' );

		$req->setHeader( 'Content-Type', 'multipart/form-data' );
		$req->setData( array(
			'title' => 'Special:Upload',
			'wpUploadFile' => curl_file_create( $source_filename ),
			'wpDestFile' => $title,
			'wpIgnoreWarning' => '1',
			'wpEditToken' => $this->api->editToken,
			'wpUpload' => 'Upload',
			'wpUploadDescription' => $text
		) );

		$status = $req->execute();
		if ( !$status->isOK() )
		{
			# HTTP error found.
			# Braces '(', ')' are needed so that return value would
			# have the same format as in HTML output below.

			return '(' . $status->errors[0]['message'] . ')';
		}

		$this->lastEdit = array();
		$this->lastEdit['Text'] = $text;
		$this->lastEdit['User'] = $this->loggedInAs()->getName();
		$this->lastEdit['Title'] =
			Title::newFromText( $title, NS_FILE )->getFullText();
		$this->lastEdit['SHA1'] = sha1_file( $source_filename );
		$this->lastEdit['Source'] = $source_filename;

		if ( $req->getResponseHeader( 'Location' ) )
			return null; # No errors

		$html = DOMDocument::loadHTML( $req->getContent() );
		$divs = $html->getElementsByTagName( 'div' );

		foreach ( $divs as $div )
		{
			# Note: the message can have parameters,
			# so we won't remove the braces around it.

			if ( $div->getAttribute( 'class' ) == 'error' )
				return $div->textContent; /* Error found */
		}

		return null; # No errors
	}

	/**
		@brief Get up to $count moderation log entries via API
			(most recent first).
	*/
	public function apiLogEntries( $count = 100 )
	{
		$ret = $this->query( array(
			'action' => 'query',
			'list' => 'logevents',
			'letype' => 'moderation',
			'lelimit' => $count
		) );
		return $ret['query']['logevents'];
	}

	/**
		@brief Get up to $count moderation log entries NOT via API
			(most recent first).
	*/
	public function nonApiLogEntries( $count = 100 )
	{
		$title = Title::newFromText( 'Log/moderation', NS_SPECIAL )->fixSpecialName();
		$url = wfAppendQuery( $title->getLocalURL(), array(
			'limit' => $count
		) );
		$html = $this->html->loadFromURL( $url );

		$events = array();
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
					$events[] = array(
						'type' => $matches[1],
						'params' => explode( ', ', $matches[2] )
					);
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
		$ret = $this->query( array(
			'action' => 'query',
			'prop' => 'revisions',
			'rvlimit' => 1,
			'rvprop' => 'user|timestamp|comment|content|ids',
			'titles' => $title
		) );
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
