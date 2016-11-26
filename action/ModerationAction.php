<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2016 Edward Chernenko.

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
	@brief Parent class for all moderation actions.
*/

abstract class ModerationAction extends ContextSource {
	protected $id;

	public $actionName;
	public $moderator;

	public function __construct( IContextSource $context ) {
		$this->setContext( $context );

		$this->moderator = $this->getUser();
		$this->actionName = $this->getRequest()->getVal( 'modaction' );
	}

	final public function run() {
		if ( $this->requiresWrite() && wfReadOnly() ) {
			throw new ReadOnlyError;
		}

		$request = $this->getRequest();

		$token = $request->getVal( 'token' );
		$this->id = $request->getVal( 'modid' );

		if (
			$this->requiresEditToken() &&
			!$this->moderator->matchEditToken( $token, $this->id )
		)
		{
			throw new ErrorPageError( 'sessionfailure-title', 'sessionfailure' );
		}

		$this->execute();
		$this->getOutput()->addReturnTo( SpecialPage::getTitleFor( 'Moderation' ) );
	}

	/* The following methods can be overriden in the subclass */

	/** @brief Whether the URL of this action must contain CSRF token */
	public function requiresEditToken() {
		return true;
	}

	/** @brief Whether this action requires the wiki not to be locked */
	public function requiresWrite() {
		return true;
	}

	abstract public function execute();

	/**
		@brief Utility function. Get userpage of user who made this edit.
		@returns Title object or false.
	*/
	protected function getUserpageOfPerformer() {
		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$row = $dbw->selectRow( 'moderation',
			array(
				'mod_user_text AS user_text'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		return $row ? Title::makeTitle( NS_USER, $row->user_text ) : false;
	}
}
