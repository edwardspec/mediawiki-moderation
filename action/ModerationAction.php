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
	@brief Parent class for all moderation actions.
*/

abstract class ModerationAction extends ContextSource {
	protected $id;

	public $actionName;
	public $moderator;

	protected function __construct( IContextSource $context ) {
		$this->setContext( $context );

		$this->moderator = $this->getUser();
		$this->actionName = $this->getRequest()->getVal( 'modaction' );
	}

	final public function run() {
		if ( $this->requiresWrite() ) {
			if( wfReadOnly() ) {
				throw new ReadOnlyError;
			}

			/* Suppress default assertion from $wgTrxProfilerLimits
				("no non-readonly SQL queries during GET request") */
			$profiler = Profiler::instance();
			if ( method_exists( $profiler, 'getTransactionProfiler' ) ) {
				$trxProfiler = $profiler->getTransactionProfiler();
				$trxLimits = $this->getConfig()->get( 'TrxProfilerLimits' );

				$trxProfiler->resetExpectations();
				$trxProfiler->setExpectations( $trxLimits['POST'], __METHOD__ );
			}
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

	/* The following methods can be overridden in the subclass */

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
		$username = $dbw->selectField( 'moderation', 'mod_user_text',
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		return $username ? Title::makeTitle( NS_USER, $username ) : false;
	}

	/** @brief Construct new ModerationAction */
	public static function factory( IContextSource $context )
	{
		$action = $context->getRequest()->getVal( 'modaction' );
		if ( !$action ) {
			return false;
		}

		switch ( $action ) {
			case 'showimg':
				return new ModerationActionShowImage( $context );

			case 'show':
				return new ModerationActionShow( $context );

			case 'preview':
				return new ModerationActionPreview( $context );

			case 'approve':
			case 'approveall':
				return new ModerationActionApprove( $context );

			case 'reject':
			case 'rejectall':
				return new ModerationActionReject( $context );

			case 'merge':
				return new ModerationActionMerge( $context );

			case 'block':
			case 'unblock':
				return new ModerationActionBlock( $context );
		}

		throw new ModerationError( 'moderation-unknown-modaction' );
	}
}
