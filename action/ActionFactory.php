<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Factory that can construct ModerationAction from Context.
 */

namespace MediaWiki\Moderation;

use IContextSource;
use ModerationAction;
use ModerationActionApprove;
use ModerationActionBlock;
use ModerationActionEditChange;
use ModerationActionEditChangeSubmit;
use ModerationActionMerge;
use ModerationActionPreview;
use ModerationActionReject;
use ModerationActionShow;
use ModerationActionShowImage;
use ModerationError;

class ActionFactory {
	/** @var EntryFactory */
	protected $entryFactory;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/**
	 * @param EntryFactory $entryFactory
	 * @param IConsequenceManager $consequenceManager
	 */
	public function __construct( EntryFactory $entryFactory,
		IConsequenceManager $consequenceManager
	) {
		$this->entryFactory = $entryFactory;
		$this->consequenceManager = $consequenceManager;
	}

	/**
	 * List of all known modactions and their PHP classes.
	 * @var array
	 *
	 * @phan-var array<string,class-string>
	 */
	protected $knownActions = [
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
	public function makeAction( IContextSource $context ) {
		$action = $context->getRequest()->getVal( 'modaction' );
		$class = $this->knownActions[$action] ?? null;

		if ( !$class ) {
			throw new ModerationError( 'moderation-unknown-modaction' );
		}

		return new $class( $context, $this->entryFactory, $this->consequenceManager );
	}
}
