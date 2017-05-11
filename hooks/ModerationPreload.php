<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2017 Edward Chernenko.

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
	@brief Hooks/methods to preload edits which are pending moderation.
*/

/*
	Calculating 'mod_preload_id':
	1) For anonymous user: ']' + hex string in the session.
	2) For registered user: '[' + username.

	Note: ']' and '[' are used because they aren't allowed in usernames.
*/


class ModerationPreload extends ContextSource {

	protected static $singleton = null; /**< Singleton instance */
	protected $editPage = null; /**< EditPage object, passed by onAlternateEdit() to onEditFormPreloadText() */

	protected function __construct( IContextSource $context ) {
		$this->setContext( $context );
	}

	/**
	 * Return a singleton instance of ModerationPreload
	 * @return ModerationPreload
	 */
	public static function singleton() {
		if ( is_null( self::$singleton ) ) {
			self::$singleton = new self( RequestContext::getMain() );
		}

		return self::$singleton;
	}

	/**
		@brief Calculate value of mod_preload_id for the current user.
		@param $create If true, new preload ID will be generated for first-time anonymous editors.
		@returns Preload ID (string).
		@retval false Current user is anonymous AND hasn't edited before AND $create is false.
	*/
	public function getId( $create = false ) {
		$user = $this->getUser();
		if ( $user->isLoggedIn() ) {
			return '[' . $user->getName();
		}

		return $this->getAnonId( $create );
	}

	/**
		@brief Calculate mod_preload_id for anonymous user.
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
		@brief Forget the fact that this user edited anonymously.
		Used in LocalUserCreated hook, when user becomes registered and no longer needs anonymous preload.
	*/
	protected function forgetAnonId() {
		$this->getRequest()->setSessionData( 'anon_id', '' );
	}

	/** @brief Make sure that results of $request->setSessionData() won't be lost */
	protected function makeSureSessionExists() {
		if ( method_exists( 'MediaWiki\Session\SessionManager', 'getGlobalSession' ) ) {
			$session = MediaWiki\Session\SessionManager::getGlobalSession();
			$session->persist();
		}
		else {
			/* MediaWiki 1.26 and older */
			if ( session_id() == '' ) {
				wfSetupSession();
			}
		}
	}

	/*
		onLocalUserCreated() - called when user creates an account.

		If the user did some anonymous edits before registering,
		this hook makes them non-anonymous, so that they could
		be preloaded.
	*/
	public static function onLocalUserCreated( $user, $autocreated ) {
		$preload = self::singleton();
		$preload->getContext()->setUser( $user );

		$anonId = $preload->getAnonId( false );
		if ( !$anonId ) { # This visitor never saved any edits
			return;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array(
				'mod_user' => $user->getId(),
				'mod_user_text' => $user->getName(),
				'mod_preload_id' => $preload->getId()
			),
			array(
				'mod_preload_id' => $anonId,
				'mod_preloadable' => 1
			),
			__METHOD__,
			array( 'USE INDEX' => 'moderation_signup' )
		);

		$preload->forgetAnonId();

		return true;
	}

	/**
		@brief Legacy version of onLocalUserCreated(). Used for MediaWiki 1.25 and lower.
	*/
	public static function onAddNewAccount( $user, $byEmail ) {
		if ( !class_exists( 'MediaWiki\\Auth\\AuthManager' ) ) {
			self::onLocalUserCreated( $user, false );
		}

		return true;
	}

	# loadUnmoderatedEdit() - check if there is a pending-moderation edit of this user to this page,
	# and if such edit exists, then load its text and edit comment
	public function loadUnmoderatedEdit( $title ) {
		$id = $this->getId();
		if ( !$id ) { # This visitor never saved any edits
			return;
		}

		$where = array(
			'mod_preloadable' => 1,
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getText(),
			'mod_preload_id' => $id
		);

		# Sequential edits are often done with small intervals of time between
		# them, so we shouldn't wait for replication: DB_MASTER will be used.
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_id AS id',
				'mod_comment AS comment',
				'mod_text AS text'
			),
			$where,
			__METHOD__,
			array( 'USE INDEX' => 'moderation_load' )
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

		$out = $preload->getOutput();
		$out->addModules( 'ext.moderation.edit' );
		$out->wrapWikiMsg( '<div id="mw-editing-your-version">$1</div>', array( 'moderation-editing-your-version' ) );

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
	public static function onAlternateEdit( $editPage )
	{
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

	/*
		onApiBeforeMain()
		Make sure that api.php?action=edit&appendtext=... will append to the pending version.
	*/
	public static function onApiBeforeMain( &$main ) {
		$request = $main->getRequest();
		if ( $request->getVal( 'action' ) != 'edit' ) {
			return true; /* Nothing to do */
		}

		$prepend = $request->getVal( 'prependtext', '' );
		$append = $request->getVal( 'appendtext', '' );
		if ( !$prepend && !$append ) {
			return true; /* Usual api.php?action=edit&text= works correctly with Moderation */
		}

		$section = $request->getVal( 'section' );
		if ( $section && $section == 'new' ) {
			return true; /* Creating a new section: doesn't require preloading */
		}

		$pageObj = $main->getTitleOrPageId( $request->getValues( 'title', 'pageid' ) );
		$title = $pageObj->getTitle();

		$row = self::singleton()->loadUnmoderatedEdit( $title );
		if ( !$row ) {
			return true; /* No pending version - ApiEdit will handle this correctly */
		}

		$content = ContentHandler::makeContent( $row->text, $title );
		if ( $section ) {
			$content = $content->getSection( $section );
		}

		$text = "";
		if ( $content ) {
			$text = $content->getNativeData();
		}

		/* Now we remove appendtext/prependtext from WebRequest object
			and make ApiEdit think that this is a usual action=edit&text=... call.

			Otherwise ApiEdit will attempt to prepend/append to the last revision
			of the page, not to the preloaded revision.
		*/
		$query = $request->getValues();
		$query['text'] = $prepend . $text . $append;
		unset( $query['prependtext'] );
		unset( $query['appendtext'] );

		$req = new DerivativeRequest( $request, $query, true );
		$main->getContext()->setRequest( $req );

		/* Let ApiEdit handle the rest */
		return true;
	}
}
