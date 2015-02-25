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

class ModerationTestsuite
{
	function __construct() {
		$this->PrepareAPIForTests();
		$this->PrepareDbForTests();
	}

	#
	# Part 1. Api-related methods.
	#
	private $apiUrl;
	private $editToken = false;
	private $cookie_jar; # Cookie storage (from login() and anonymous preloading)

	private $TEST_PASSWORD = '123456';

	public function getScriptPath()
	{
		global $wgServer, $wgScriptPath;
		return wfExpandUrl($wgServer, PROTO_HTTP) . '/' . $wgScriptPath;
	}
	private function PrepareAPIForTests()
	{
		$this->apiUrl = $this->getScriptPath() . "/api.php";
		$this->cookie_jar = new CookieJar;
		$this->getEditToken();
	}
	private function getEditToken() {
		$ret = $this->query(array(
			'action' => 'tokens',
			'type' => 'edit'
		));

		$this->editToken = $ret['tokens']['edittoken'];
	}

	public function makeHttpRequest($url, $method = 'POST')
	{
		$req = MWHttpRequest::factory($url, array('method' => $method));
		$req->setCookieJar($this->cookie_jar);

		return $req;
	}

	public function query($query = array()) {
		$query['format'] = 'json';

		if(array_key_exists('token', $query))
			$query['token'] = $this->editToken;

		$req = $this->makeHttpRequest($this->apiUrl);
		$req->setData($query);
		$status = $req->execute();
		if(!$status->isOK())
			return false;

		return FormatJson::decode($req->getContent(), true);
	}

	public function login($username) {
		# Step 1. Get the token.
		$q = array(
			'action' => 'login',
			'lgname' => $username,
			'lgpassword' => $this->TEST_PASSWORD
		);
		$ret = $this->query($q);

		# Step 2. Actual login.
		$q['lgtoken'] = $ret['login']['token'];
		$ret = $this->query($q);

		if($ret['login']['result'] == 'Success') {
			$this->getEditToken(); # It's different for a logged-in user
			return true;
		}

		return false;
	}

	public function logout() {
		$this->cookie_jar = new CookieJar; # Just delete all cookies
		$this->getEditToken();
	}

	#
	# Part 2. Functions for parsing Special:Moderation.
	#
	private $lastFetchedSpecial = array();

	public $new_entries;
	public $deleted_entries;

	public $lastFetchedDocument = null; # DOMDocument

	public function getSpecialURL()
	{
		global $wgServer, $wgScriptPath;

		# When "uselang=qqx" is specified, messages are replaced with
		# their names, so parsing process is translation-independent.
		# (see ModerationTestsuiteEntry::fromDOMElement)

		$url = $this->getScriptPath() .
			'/index.php?title=Special:Moderation&uselang=qqx';
		return $url;
	}

	public function fetchSpecial($folder = 'DEFAULT')
	{
		$this->loginAs($this->moderator);

		$url = $this->getSpecialURL() . "&limit=150";
		if($folder != 'DEFAULT')
			$url .= '&folder=' . $folder;

		$req = $this->makeHttpRequest($url, 'GET');
		$status = $req->execute();
		if(!$status->isOK())
			return false;

		$text = $req->getContent();
		$entries = array();

		$html = DOMDocument::loadHTML($text);
		$spans = $html->getElementsByTagName('span');

		foreach($spans as $span)
		{
			if($span->getAttribute('class') == 'modline')
				$entries[] = ModerationTestsuiteEntry::fromDOMElement($span);
		}

		$this->lastFetchedSpecial[$folder] = $entries;
		$this->lastFetchedDocument = $html;
		return $entries;
	}
	public function fetchSpecialAndDiff($folder = 'DEFAULT')
	{
		$before = $this->lastFetchedSpecial[$folder];
		$after = $this->fetchSpecial($folder);

		$this->new_entries = ModerationTestsuiteEntry::entriesInANotInB($after, $before);
		$this->deleted_entries = ModerationTestsuiteEntry::entriesInANotInB($before, $after);
	}

	public function getHtmlTitleByURL($url)
	{
		$req = $this->makeHttpRequest($url, 'GET');
		$status = $req->execute();
		if(!$status->isOK())
			return null;

		$html = DOMDocument::loadHTML($req->getContent());
		$this->lastFetchedDocument = $html;
		return $html->getElementsByTagName('title')->item(0)->textContent;
	}

	#
	# Part 3. Database-related functions.
	#
	private function createTestUser($name, $groups = array())
	{
		$user = User::createNew($name);
		$user->setPassword($this->TEST_PASSWORD);
		$user->saveSettings();

		foreach($groups as $g)
			$user->addGroup($g);

		return $user;
	}
	private function PrepareDbForTests()
	{
		/* Controlled environment
			as in "Destroy everything on testsuite's path" */

		$dbw = wfGetDB(DB_MASTER);
		$dbw->begin();
		$dbw->delete('moderation', array('1'), __METHOD__);
		$dbw->delete('moderation_block', array('1'), __METHOD__);
		$dbw->delete('user', array('1'), __METHOD__);
		$dbw->delete('user_groups', array('1'), __METHOD__);
		$dbw->delete('page', array('1'), __METHOD__);
		$dbw->delete('revision', array('1'), __METHOD__);
		$dbw->delete('logging', array('1'), __METHOD__);
		$dbw->delete('text', array('1'), __METHOD__);

		$this->moderator =
			$this->createTestUser('User 1', array('moderator', 'automoderated'));
		$this->moderatorButNotAutomoderated =
			$this->createTestUser('User 2', array('moderator'));
		$this->automoderated =
			$this->createTestUser('User 3', array('automoderated'));
		$this->rollback =
			$this->createTestUser('User 4', array('rollback'));
		$this->unprivilegedUser =
			$this->createTestUser('User 5', array());
		$this->unprivilegedUser2 =
			$this->createTestUser('User 6', array());

		$dbw->commit();
	}

	#
	# Part 4. High-level test functions.
	#
	public $moderator;
	public $moderatorButNotAutomoderated;
	public $rollback;
	public $automoderated;
	public $unprivilegedUser;
	public $unprivilegedUser2;

	private $t_loggedInAs;

	public function loggedInAs()
	{
		return $this->t_loggedInAs;
	}

	public function loginAs($user)
	{
		$this->login($user->getName());
		$this->t_loggedInAs = $user;
	}

	public function doTestEdit($title = null, $text = null)
	{
		if(!$title)
			$title = $this->generateRandomTitle();

		if(!$text)
			$text = $this->generateRandomText();

		$summary = $this->generateEditSummary();

		# TODO: ensure that page $title doesn't already contain $text
		# (to avoid extremely rare test failures due to random collisions)

		$ret = $this->query(array(
			'action' => 'edit',
			'title' => $title,
			'text' => $text,
			'summary' => $summary,
			'token' => null
		));

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
	public function doNTestEditsWith($user1, $user2 = null)
	{
		for($i = 0; $i < $this->TEST_EDITS_COUNT; $i ++)
		{
			$this->loginAs($user1);
			$this->doTestEdit('Page' . $i);

			if($user2) {
				$this->loginAs($user2);
				$this->doTestEdit('AnotherPage' . $i);
			}
		}
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
	public function doTestUpload()
	{
		$title = $this->generateRandomTitle() . '.png';
		$text = $this->generateRandomText();

		$SOURCE_FILENAME = __DIR__ . "/resources/sample_image.png";
		$url = $this->getScriptPath() .
			'/index.php?title=Special:Upload&uselang=qqx';
		$req = $this->makeHttpRequest($url, 'POST');

		$req->setHeader('Content-Type', 'multipart/form-data');
		$req->setData(array(
			'wpUploadFile' => '@' . $SOURCE_FILENAME,
			'wpDestFile' => $title,
			'wpIgnoreWarning' => '1',
			'wpEditToken' => $this->editToken,
			'wpUpload' => 'Upload',
			'wpUploadDescription' => $text
		));

		$status = $req->execute();
		if(!$status->isOK())
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
			Title::newFromText($title, NS_FILE)->getFullText();
		$this->lastEdit['SHA1'] = sha1_file($SOURCE_FILENAME);

		if($req->getResponseHeader('Location'))
			return null; # No errors

		$html = DOMDocument::loadHTML($req->getContent());
		$divs = $html->getElementsByTagName('div');

		foreach($divs as $div)
		{
			# Note: the message can have parameters,
			# so we won't remove the braces around it.

			if($div->getAttribute('class') == 'error')
				return $div->textContent; /* Error found */
		}

		return null; # No errors
	}
}

/**
	@class ModerationTestsuiteEntry
	@brief Represents one line on [[Special:Moderation]]
*/
class ModerationTestsuiteEntry
{
	public $id = null;
	public $user = null;
	public $comment = null; /* TODO */
	public $title = null;

	public $showLink = null;
	public $approveLink = null;
	public $approveAllLink = null;
	public $rejectLink = null;
	public $rejectAllLink = null;
	public $blockLink = null;
	public $unblockLink = null;

	public $rejected_by_user = null;
	public $rejected_batch = false;
	public $rejected_auto = false;

	static public function fromDOMElement($span)
	{
		$e = new ModerationTestsuiteEntry;

		foreach($span->childNodes as $child)
		{
			$text = $child->textContent;
			if(strpos($text, '(moderation-rejected-auto)') != false)
				$e->rejected_auto = true;

			if(strpos($text, '(moderation-rejected-batch)') != false)
				$e->rejected_batch = true;
		}

		$links = $span->getElementsByTagName('a');
		foreach($links as $link)
		{
			if(strpos($link->getAttribute('class'), 'mw-userlink') != false)
			{
				$text = $link->textContent;

				# This is
				# 1) either the user who made an edit,
				# 2) or the moderator who rejected it.
				# Let's check the text BEFORE this link for
				# the presence of 'moderation-rejected-by'.

				if(strpos($link->previousSibling->textContent,
					"moderation-rejected-by") != false)
				{
					$e->rejected_by_user = $text;
				}
				else
				{
					$e->user = $text;
				}

				continue;
			}

			$href = $link->getAttribute('href');
			switch($link->nodeValue)
			{
				case '(moderation-show)':
					$e->showLink = $href;
					break;

				case '(moderation-approve)':
					$e->approveLink = $href;
					break;

				case '(moderation-approveall)':
					$e->approveAllLink = $href;
					break;

				case '(moderation-reject)':
					$e->rejectLink = $href;
					break;

				case '(moderation-rejectall)':
					$e->rejectAllLink = $href;
					break;

				case '(moderation-block)':
					$e->blockLink = $href;
					break;

				case '(moderation-unblock)':
					$e->blockLink = $href;
					break;

				default:
					$e->title = $link->textContent;
			}
		}

		$matches = null;
		preg_match('/modid=([0-9]+)/', $e->showLink, $matches);
		$e->id = $matches[1];

		return $e;
	}

	static public function entriesInANotInB($array_A, $array_B)
	{
		# NOTE: the code below doesn't handle the situation when there
		# are 150 entries, 1 new entry is added and one existing entry
		# is moved to another page.

		$diff = array();
		foreach($array_A as $e)
		{
			$found = 0;
			foreach($array_B as $b)
			{
				if($e->id == $b->id)
				{
					$found = 1;
					break;
				}
			}

			if(!$found)
				$diff[] = $e;
		}
		return $diff;
	}

	static public function findById($array, $id)
	{
		foreach($array as $e)
		{
			if($e->id == $id)
				return $e;
		}
		return null;
	}

	static public function findByUser($array, $user)
	{
		if(get_class($user) == 'User')
			$user = $user->getName();

		$entries = [];
		foreach($array as $entry)
		{
			if($entry->user == $user)
				$entries[] = $entry;
		}
		return $entries;
	}

	/**
		@brief Populates both $e->blockLink and $e->unblockLink,
			even though only one link exists on Special:Moderation
	*/
	public function fakeBlockLink()
	{
		$bl = $this->blockLink;
		$ul = $this->unblockLink;

		if(($bl && $ul) || (!$bl && !$ul))
			return; /* Nothing to do */

		if($bl)
			$this->unblockLink = preg_replace('/modaction=block/', 'modaction=unblock', $bl);
		else
			$this->blockLink = preg_replace('/modaction=unblock/', 'modaction=block', $ul);
	}

	/**
		@brief Returns the URL of modaction=showimg.
	*/
	public function getShowImgLink()
	{
		if(!$this->showLink)
			return null;

		return preg_replace('/modaction=show/', 'modaction=showimg', $this->showLink);
	}
}
