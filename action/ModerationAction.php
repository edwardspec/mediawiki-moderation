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
 * Parent class for all moderation actions.
 */

abstract class ModerationAction extends ContextSource {
	/**
	 * @var int Value of modid= request parameter.
	 */
	protected $id;

	/**
	 * @var string Name of modaction, e.g. "reject" or "approveall".
	 */
	public $actionName;

	/**
	 * @var User
	 * Moderator who is enacting this action.
	 */
	public $moderator;

	/**
	 * List of all known modactions and their PHP classes.
	 * @var array
	 *
	 * @phan-var array<string,class-string>
	 */
	protected static $knownActions = [
		'approveall' => ModerationActionApprove::class,
		'approve' => ModerationActionApprove::class,
		'block' => ModerationActionBlock::class,
		'editchange' => ModerationActionEditChange::class,
		'editchangesubmit' => ModerationActionEditChangeSubmit::class,
		'merge' => ModerationActionMerge::class,
		'preview' => ModerationActionPreview::class,
		'rejectall' => ModerationActionReject::class,
		'reject' => ModerationActionReject::class,
		'show' => ModerationActionShow::class,
		'showimg' => ModerationActionShowImage::class,
		'unblock' => ModerationActionBlock::class
	];

	/**
	 * Construct new ModerationAction.
	 * @param IContextSource $context
	 * @return ModerationAction
	 * @throws ModerationError
	 */
	public static function factory( IContextSource $context ) {
		$action = $context->getRequest()->getVal( 'modaction' );
		$class = self::$knownActions[$action] ?? null;

		if ( !$class ) {
			throw new ModerationError( 'moderation-unknown-modaction' );
		}

		return new $class( $context );
	}

	/**
	 * @param IContextSource $context
	 */
	protected function __construct( IContextSource $context ) {
		$this->setContext( $context );

		$this->moderator = $this->getUser();

		$request = $this->getRequest();
		$this->actionName = $request->getVal( 'modaction' );
		$this->id = $request->getInt( 'modid' );
	}

	/**
	 * @return array Action-specific API-friendly response, e.g. [ 'rejected' => '3' ].
	 *
	 * @phan-return array<string,mixed>
	 */
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

	/**
	 * Whether the URL of this action must contain CSRF token
	 * @return bool
	 */
	public function requiresEditToken() {
		return true;
	}

	/**
	 * Whether this action requires the wiki not to be locked
	 * @return bool
	 */
	public function requiresWrite() {
		return true;
	}

	/**
	 * Function called when the action is invoked.
	 * @return array Array containing API response.
	 * @throws ModerationError
	 *
	 * @phan-return array<string,mixed>
	 */
	abstract public function execute();

	/**
	 * Print the result of execute() in a human-readable way.
	 * @param array $result Value returned by execute().
	 * @param OutputPage $out OutputPage object.
	 *
	 * @phan-param array<string,mixed> $result
	 */
	abstract public function outputResult( array $result, OutputPage $out );

	/**
	 * Utility function. Get userpage of user who made this edit.
	 * @return Title|false
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
}
