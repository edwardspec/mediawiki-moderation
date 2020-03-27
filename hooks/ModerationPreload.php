<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2020 Edward Chernenko.

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
 * Hooks/methods to preload edits which are pending moderation.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\ConsequenceUtils;
use MediaWiki\Moderation\ForgetAnonIdConsequence;
use MediaWiki\Moderation\GiveAnonChangesToNewUserConsequence;
use MediaWiki\Moderation\PendingEdit;
use MediaWiki\Moderation\RememberAnonIdConsequence;

/*
	Calculating 'mod_preload_id':
	1) For anonymous user: ']' + hex string in the session.
	2) For registered user: '[' + username.

	Note: ']' and '[' are used because they aren't allowed in usernames.
*/

class ModerationPreload {

	/** @var ModerationPreload Singleton instance */
	protected static $instance = null;

	/** @var EditPage|null Editor object passed from onAlternateEdit() to onEditFormPreloadText() */
	protected $editPage = null;

	/** @var User|null Current user. If not set, $wgUser will be used. */
	private $user = null;

	protected function __construct() {
	}

	/**
	 * Return a singleton instance of ModerationPreload
	 * @return ModerationPreload
	 */
	public static function singleton() {
		if ( self::$instance === null ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Get the request.
	 * @return WebRequest
	 */
	protected function getRequest() {
		return RequestContext::getMain()->getRequest();
	}

	/**
	 * Get the user.
	 * @return User
	 */
	protected function getUser() {
		if ( $this->user ) {
			return $this->user;
		}

		return RequestContext::getMain()->getUser();
	}

	/**
	 * Override the current user: preload for $user instead.
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * Calculate value of mod_preload_id for the current user.
	 * @param bool $create If true, new preload ID will be generated for first-time anonymous editors.
	 * @return string|false Preload ID (string).
	 * Returns false if current user is anonymous AND hasn't edited before AND $create is false.
	 */
	public function getId( $create = false ) {
		$user = $this->getUser();
		if ( $user->isLoggedIn() ) {
			return '[' . $user->getName();
		}

		return $this->getAnonId( $create );
	}

	/**
	 * Calculate mod_preload_id for anonymous user.
	 * @param bool $create If true, new preload ID will be generated for first-time anonymous editors.
	 * @return string|false Preload ID (string), if already existed or just created.
	 */
	protected function getAnonId( $create ) {
		$anonToken = $this->getRequest()->getSessionData( 'anon_id' );
		if ( !$anonToken ) {
			if ( !$create ) {
				return false;
			}

			$manager = ConsequenceUtils::getManager();
			$anonToken = $manager->add( new RememberAnonIdConsequence() );
		}

		return ']' . $anonToken;
	}

	/**
	 * LocalUserCreated hook handler - called when user creates an account.
	 * If the user did some anonymous edits before registering,
	 * this hook makes them non-anonymous, so that they could be preloaded.
	 * @param User $user
	 * @param bool $autocreated
	 * @return true
	 */
	public static function onLocalUserCreated( $user, $autocreated ) {
		$preload = self::singleton();
		$preload->setUser( $user );

		$anonId = $preload->getAnonId( false );
		if ( !$anonId ) { # This visitor never saved any edits
			return true;
		}

		$manager = ConsequenceUtils::getManager();
		$manager->add( new GiveAnonChangesToNewUserConsequence(
			$user, $anonId, $preload->getId()
		) );

		// Forget the fact that this user edited anonymously:
		// this user is now registered and no longer needs anonymous preload.
		$manager->add( new ForgetAnonIdConsequence() );

		return true;
	}

	/**
	 * Check if there is a pending-moderation edit of this user to this page,
	 * and if such edit exists, then load its text and edit comment.
	 * @param Title $title
	 * @return PendingEdit|false
	 */
	public function loadUnmoderatedEdit( Title $title ) {
		$id = $this->getId();
		if ( !$id ) { # This visitor never saved any edits
			return false;
		}

		$entryFactory = MediaWikiServices::getInstance()->getService( 'Moderation.EntryFactory' );
		return $entryFactory->findPendingEdit( $id, $title );
	}

	/**
	 * If there is an edit (currently pending moderation) made by the
	 * current user, inform EditPage object of its Text and Summary,
	 * so that the user can continue editing its own revision.
	 * @param string &$text
	 * @param Title $title
	 * @param EditPage|null $editPage
	 */
	public static function showUnmoderatedEdit( &$text, $title, $editPage ) {
		$preload = self::singleton();
		$section = $preload->getRequest()->getVal( 'section', '' );
		if ( $section == 'new' ) {
			# Nothing to preload if new section is being created
			return;
		}

		$pendingEdit = $preload->loadUnmoderatedEdit( $title );
		if ( !$pendingEdit ) {
			return;
		}

		$out = RequestContext::getMain()->getOutput();
		$out->addModules( 'ext.moderation.edit' );
		$out->wrapWikiMsg( '<div id="mw-editing-your-version">$1</div>',
			[ 'moderation-editing-your-version' ] );

		$text = $pendingEdit->getText();
		if ( $editPage ) {
			$editPage->summary = $pendingEdit->getComment();
		}

		if ( $section !== '' ) {
			$fullContent = ContentHandler::makeContent( $text, $title );
			$sectionContent = $fullContent->getSection( $section );

			if ( $sectionContent ) {
				$text = $sectionContent->getNativeData();
			}
		}
	}

	/**
	 * AlternateEdit hook handler.
	 * Remember EditPage object, which will then be used in onEditFormPreloadText.
	 * @param EditPage $editPage
	 * @return true
	 */
	public static function onAlternateEdit( $editPage ) {
		self::singleton()->editPage = $editPage;

		return true;
	}

	/**
	 * EditFormPreloadText hook handler.
	 * Preloads text/summary when the article doesn't exist yet.
	 * @param string &$text
	 * @param Title &$title
	 * @return true
	 */
	public static function onEditFormPreloadText( &$text, &$title ) {
		self::showUnmoderatedEdit( $text, $title, self::singleton()->editPage );

		return true;
	}

	/**
	 * EditFormPreloadText hook handler.
	 * Preloads text/summary when the article already exists.
	 * @param EditPage $editPage
	 * @return true
	 */
	public static function onEditFormInitialText( $editPage ) {
		self::showUnmoderatedEdit( $editPage->textbox1, $editPage->getTitle(), $editPage );

		return true;
	}
}
