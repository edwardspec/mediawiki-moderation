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
 * Parent class for all moderation actions.
 */

abstract class ModerationAction extends ContextSource {
	protected $id;

	public $actionName;
	public $moderator;

	protected function __construct( IContextSource $context ) {
		$this->setContext( $context );

		$this->moderator = $this->getUser();

		$request = $this->getRequest();
		$this->actionName = $request->getVal( 'modaction' );
		$this->id = $request->getInt( 'modid' );
	}

	final public function run() {
		if ( $this->requiresWrite() ) {
			if ( wfReadOnly() ) {
				throw new ReadOnlyError;
			}

			/* Suppress default assertion from $wgTrxProfilerLimits
				("no non-readonly SQL queries during GET request") */
			$trxProfiler = Profiler::instance()->getTransactionProfiler();
			$trxLimits = $this->getConfig()->get( 'TrxProfilerLimits' );

			$trxProfiler->resetExpectations();
			$trxProfiler->setExpectations( $trxLimits['POST'], __METHOD__ );
		}

		return $this->execute();
	}

	/* The following methods can be overridden in the subclass */

	/** Whether the URL of this action must contain CSRF token */
	public function requiresEditToken() {
		return true;
	}

	/** Whether this action requires the wiki not to be locked */
	public function requiresWrite() {
		return true;
	}

	/**
	 * Function called when the action is invoked.
	 * @return Array containing API response.
	 * @throws ModerationError
	 */
	abstract public function execute();

	/**
	 * Print the result of execute() in a human-readable way.
	 * @param array $result Value returned by execute().
	 * @param OutputPage &$out OutputPage object.
	 */
	abstract public function outputResult( array $result, OutputPage &$out );

	/**
	 * Utility function. Get userpage of user who made this edit.
	 * @return Title object or false.
	 */
	protected function getUserpageOfPerformer() {
		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag
		$username = $dbw->selectField( 'moderation', 'mod_user_text',
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( strval( $username ) == '' ) {
			return false;
		}

		return Title::makeTitle( NS_USER, $username );
	}

	/** Construct new ModerationAction */
	public static function factory( IContextSource $context ) {
		$request = $context->getRequest();
		$action = $request->getVal( 'modaction' );
		switch ( $action ) {
			case 'showimg':
				return new ModerationActionShowImage( $context );

			case 'show':
				return new ModerationActionShow( $context );

			case 'preview':
				return new ModerationActionPreview( $context );

			case 'editchange':
				return new ModerationActionEditChange( $context );

			case 'editchangesubmit':
				return new ModerationActionEditChangeSubmit( $context );

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
