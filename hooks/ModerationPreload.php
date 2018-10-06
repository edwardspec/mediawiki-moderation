<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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

/*
	Calculating 'mod_preload_id':
	1) For anonymous user: ']' + hex string in the session.
	2) For registered user: '[' + username.

	Note: ']' and '[' are used because they aren't allowed in usernames.
*/

class ModerationPreload {

	/** @var ModerationPreload Singleton instance */
	protected static $instance = null;

	/** @var EditPage Editor object passed from onAlternateEdit() to onEditFormPreloadText() */
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
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Get the request.
	 * @return WebRequest object.
	 */
	protected function getRequest() {
		return RequestContext::getMain()->getRequest();
	}

	/**
	 * Get the user.
	 * @return User object.
	 */
	protected function getUser() {
		if ( $this->user ) {
			return $this->user;
		}

		return RequestContext::getMain()->getUser();
	}

	/**
	 * Override the current user: preload for $user instead.
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
	 */
	protected function getAnonId( $create ) {
		$anonToken = $this->getRequest()->getSessionData( 'anon_id' );
		if ( !$anonToken ) {
			if ( !$create ) {
				return false;
			}

			$anonToken = MWCryptRand::generateHex( 32 );

			$this->makeSureSessionExists();
			$this->getRequest()->setSessionData( 'anon_id', $anonToken );
		}

		return ']' . $anonToken;
	}

	/**
	 * Forget the fact that this user edited anonymously.
	 * Used in LocalUserCreated hook, when user becomes registered and
	 * no longer needs anonymous preload.
	 */
	protected function forgetAnonId() {
		$this->getRequest()->setSessionData( 'anon_id', '' );
	}

	/** Make sure that results of $request->setSessionData() won't be lost */
	protected function makeSureSessionExists() {
		$session = MediaWiki\Session\SessionManager::getGlobalSession();
		$session->persist();
	}

	/*
		onLocalUserCreated() - called when user creates an account.

		If the user did some anonymous edits before registering,
		this hook makes them non-anonymous, so that they could
		be preloaded.
	*/
	public static function onLocalUserCreated( $user, $autocreated ) {
		$preload = self::singleton();
		$preload->setUser( $user );

		$anonId = $preload->getAnonId( false );
		if ( !$anonId ) { # This visitor never saved any edits
			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_user' => $user->getId(),
				'mod_user_text' => $user->getName(),
				'mod_preload_id' => $preload->getId()
			],
			[
				'mod_preload_id' => $anonId,
				'mod_preloadable' => ModerationVersionCheck::preloadableYes()
			],
			__METHOD__,
			[ 'USE INDEX' => 'moderation_signup' ]
		);

		$preload->forgetAnonId();

		return true;
	}

	/**
	 * Check if there is a pending-moderation edit of this user
	 * to this page, and if such edit exists, then load its text and
	 * edit comment.
	 */
	public function loadUnmoderatedEdit( $title ) {
		$id = $this->getId();
		if ( !$id ) { # This visitor never saved any edits
			return;
		}

		$where = [
			'mod_preloadable' => ModerationVersionCheck::preloadableYes(),
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => ModerationVersionCheck::getModTitleFor( $title ),
			'mod_preload_id' => $id
		];

		if ( ModerationVersionCheck::hasModType() ) {
			$where['mod_type'] = ModerationNewChange::MOD_TYPE_EDIT;
		}

		# Sequential edits are often done with small intervals of time between
		# them, so we shouldn't wait for replication: DB_MASTER will be used.
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_id AS id',
				'mod_comment AS comment',
				'mod_text AS text'
			],
			$where,
			__METHOD__,
			[ 'USE INDEX' => 'moderation_load' ]
		);
		return $row;
	}

	/*
		If there is an edit (currently pending moderation) made by the
		current user, inform EditPage object of its Text and Summary,
		so that the user can continue editing its own revision.
	*/
	public static function showUnmoderatedEdit( &$text, &$title, &$editPage ) {
		$preload = self::singleton();
		$section = $preload->getRequest()->getVal( 'section', '' );
		if ( $section == 'new' ) {
			# Nothing to preload if new section is being created
			return;
		}

		$row = $preload->loadUnmoderatedEdit( $title );
		if ( !$row ) {
			return;
		}

		$out = RequestContext::getMain()->getOutput();
		$out->addModules( 'ext.moderation.edit' );
		$out->wrapWikiMsg( '<div id="mw-editing-your-version">$1</div>',
			[ 'moderation-editing-your-version' ] );

		$text = $row->text;
		if ( $editPage ) {
			$editPage->summary = $row->comment;
		}

		if ( $section !== '' ) {
			$fullContent = ContentHandler::makeContent( $text, $title );
			$sectionContent = $fullContent->getSection( $section );

			if ( $sectionContent ) {
				$text = $sectionContent->getNativeData();
			}
		}
	}

	/*
		onAlternateEdit()
		Remember EditPage object, which will then be used in onEditFormPreloadText.
	*/
	public static function onAlternateEdit( $editPage ) {
		self::singleton()->editPage = $editPage;

		return true;
	}

	/*
		onEditFormPreloadText()
		Preloads text/summary when the article doesn't exist yet.
	*/
	public static function onEditFormPreloadText( &$text, &$title ) {
		self::showUnmoderatedEdit( $text, $title, self::singleton()->editPage );

		return true;
	}

	/*
		onEditFormPreloadText()
		Preloads text/summary when the article already exists.
	*/
	public static function onEditFormInitialText( $editPage ) {
		self::showUnmoderatedEdit( $editPage->textbox1, $editPage->mTitle, $editPage );

		return true;
	}
}
