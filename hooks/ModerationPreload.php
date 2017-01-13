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


class ModerationPreload {
	protected static $editPage = null; /**< EditPage object, passed by onAlternateEdit() to onEditFormPreloadText() */

	protected static function AnonId_to_PreloadId( $anon_id ) {
		return ']' . $anon_id;
	}

	protected static function User_to_PreloadId( $user ) {
		return '[' . $user->getName();
	}

	/** @brief Make sure that results of $request->setSessionData() won't be lost */
	protected static function makeSureSessionExists() {
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
		If preload ID was never generated for this anonymous user:
		1) with $create=true: new preload ID is generated and returned,
		2) with $create=false: false is returned.
	*/
	protected static function getPreloadId( $create_if_not_exists ) {
		global $wgUser, $wgRequest;

		if ( !$wgUser->isAnon() ) {
			return self::User_to_PreloadId( $wgUser );
		}

		self::makeSureSessionExists();

		$anon_id = $wgRequest->getSessionData( 'anon_id' );
		if ( !$anon_id ) {
			if ( !$create_if_not_exists ) {
				return false;
			}

			$anon_id = MWCryptRand::generateHex( 32 );
			$wgRequest->setSessionData( 'anon_id', $anon_id );
		}
		return self::AnonId_to_PreloadId( $anon_id );
	}

	public static function generatePreloadId() {
		return self::getPreloadId( true );
	}

	public static function findPreloadIdOrFail() {
		return self::getPreloadId( false );
	}

	/*
		onLocalUserCreated() - called when user creates an account.

		If the user did some anonymous edits before registering,
		this hook makes them non-anonymous, so that they could
		be preloaded.
	*/
	public static function onLocalUserCreated( $user, $autocreated ) {
		$request = $user->getRequest();
		$anon_id = $request->getSessionData( 'anon_id' );

		if ( !$anon_id ) { # This visitor never saved any edits
			return;
		}

		$old_preload_id = self::AnonId_to_PreloadId( $anon_id );
		$new_preload_id = self::User_to_PreloadId( $user );

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			array(
				'mod_user' => $user->getId(),
				'mod_user_text' => $user->getName(),
				'mod_preload_id' => $new_preload_id
			),
			array(
				'mod_preload_id' => $old_preload_id,
				'mod_preloadable' => 1
			),
			__METHOD__,
			array( 'USE INDEX' => 'moderation_signup' )
		);
		$request->setSessionData( 'anon_id', '' );

		return true;
	}

	/**
		@brief Legacy version of onLocalUserCreated(). Used for MediaWiki 1.25 and lower.
	*/
	public static function onAddNewAccount( $user, $byEmail ) {
		if(!class_exists('MediaWiki\\Auth\\AuthManager')) {
			self::onLocalUserCreated( $user, false );
		}

		return true;
	}

	# loadUnmoderatedEdit() - check if there is a pending-moderation edit of this user to this page,
	# and if such edit exists, then load its text and edit comment
	public static function loadUnmoderatedEdit( $title ) {
		$preload_id = self::findPreloadIdOrFail();
		if ( !$preload_id ) { # This visitor never saved any edits
			return;
		}

		$where = array(
			'mod_preloadable' => 1,
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getText(),
			'mod_preload_id' => $preload_id
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
		global $wgRequest, $wgOut;

		$section = $wgRequest->getVal( 'section', '' );
		if ( $section == 'new' ) {
			# Nothing to preload if new section is being created
			return;
		}

		$row = self::loadUnmoderatedEdit( $title );
		if ( !$row ) {
			return;
		}

		$wgOut->addModules( 'ext.moderation.edit' );
		$wgOut->wrapWikiMsg( '<div id="mw-editing-your-version">$1</div>', array( 'moderation-editing-your-version' ) );

		$text = $row->text;
		if ( $editPage ) {
			$editPage->summary = $row->comment;
		}

		if ( $section != false ) {
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
		self::$editPage = $editPage;

		return true;
	}

	/*
		onEditFormPreloadText()
		Preloads text/summary when the article doesn't exist yet.
	*/
	public static function onEditFormPreloadText( &$text, &$title ) {
		self::showUnmoderatedEdit( $text, $title, self::$editPage );

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
