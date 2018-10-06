<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2017 Edward Chernenko.

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
 * API to invoke moderation actions like Approve, Reject, etc.
 */

class ApiModeration extends ApiBase {

	public function execute() {
		if ( !$this->getUser()->isAllowed( 'moderation' ) ) {
			$this->dieUsageMsg( 'badaccess-groups' );
		}

		$A = ModerationAction::factory( $this->getContext() );

		try {
			$result = $A->run();
		}
		catch ( ModerationError $e ) {
			$this->dieStatus( $e->status );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	public function mustBePosted() {
		return true;
	}

	public function isWriteMode() {
		return true;
	}

	public function needsToken() {
		return 'csrf';
	}

	public function getAllowedParams() {
		return [
			'modaction' => [
				ApiBase::PARAM_TYPE => [
					'approve',
					'approveall',
					'reject',
					'rejectall',
					'block',
					'unblock',
					'show'
					// 'showimg',
					// 'merge'
				],
				ApiBase::PARAM_REQUIRED => true
			],
			'modid' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true
			]
		];
	}

	protected function getExamplesMessages() {
		return [
			'action=moderation&modaction=approve&modid=123'
				=> 'apihelp-moderation-approve-example'
		];
	}
}
