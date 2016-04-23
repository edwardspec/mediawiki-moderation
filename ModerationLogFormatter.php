<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@brief Defines the format of [[Special:Log/moderation]]
*/

class ModerationLogFormatter extends LogFormatter {
	public function getMessageParameters() {
		$params = parent::getMessageParameters();

		$type = $this->entry->getSubtype();
		$entryParams = $this->entry->getParameters();

		if ( $type === 'approve' ) {
			$revId = $entryParams['revid'];
			$link = Linker::linkKnown(
				$this->entry->getTarget(),
				wfMessage( 'moderation-log-diff', $revId )->text(),
				array( 'title' => wfMessage( 'tooltip-moderation-approved-diff' ) ),
				array( 'diff' => $revId )
			);
			$params[4] = Message::rawParam( $link );
		} elseif ( $type === 'reject' ) {
			$modId = $entryParams['modid'];

			$link = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Moderation' ),
				wfMessage( 'moderation-log-change', $modId )->text(),
				array( 'title' => wfMessage( 'tooltip-moderation-rejected-change' ) ),
				array( 'modaction' => 'show', 'modid' => $modId )
			);
			$params[4] = Message::rawParam( $link );

			$userLink = Linker::userLink( $entryParams['user'], $entryParams['user_text'] );
			$params[5] = Message::rawParam( $userLink );
		} elseif ( $type === 'merge' ) {
			$revId = $entryParams['revid'];
			$modId = $entryParams['modid'];

			$link = Linker::linkKnown(
				SpecialPage::getTitleFor( 'Moderation' ),
				wfMessage( 'moderation-log-change', $modId )->text(),
				array( 'title' => wfMessage( 'tooltip-moderation-rejected-change' ) ),
				array( 'modaction' => 'show', 'modid' => $modId )
			);
			$params[4] = Message::rawParam( $link );

			$link = Linker::linkKnown(
				$this->entry->getTarget(),
				wfMessage( 'moderation-log-diff', $revId )->text(),
				array( 'title' => wfMessage( 'tooltip-moderation-approved-diff' ) ),
				array( 'diff' => $revId )
			);
			$params[5] = Message::rawParam( $link );
		} elseif ( $type === 'approveall' || $type === 'rejectall' || $type === 'block' || $type === 'unblock' ) {
			$title = $this->entry->getTarget();

			$userId = User::idFromName( $title->getText() );
			$link = Linker::userLink( $userId, $title->getText() );

			$params[2] = Message::rawParam( $link );
		}

		return $params;
	}
}
