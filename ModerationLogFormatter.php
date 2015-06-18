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
		$entry_params = $this->entry->getParameters();

		if($type === 'approve')
		{
			$revid = $entry_params['revid'];
			$link = Linker::linkKnown(
				$this->entry->getTarget(),
				wfMessage('moderation-log-diff', $revid)->text(),
				array('title' => wfMessage('tooltip-moderation-approved-diff')),
				array('diff' => $revid)
			);
			$params[4] = Message::rawParam($link);
		}
		elseif($type === 'reject')
		{
			$modid = $entry_params['modid'];

			$link = Linker::linkKnown(
				Title::makeTitle( NS_SPECIAL, "Moderation" ),
				wfMessage('moderation-log-change', $modid)->text(),
				array('title' => wfMessage('tooltip-moderation-rejected-change')),
				array('modaction' => 'show', 'modid' => $modid)
			);
			$params[4] = Message::rawParam($link);

			$userlink = Linker::userLink($entry_params['user'], $entry_params['user_text']);
			$params[5] = Message::rawParam($userlink);
		}
		elseif($type === 'merge')
		{
			$revid = $entry_params['revid'];
			$modid = $entry_params['modid'];

			$link = Linker::linkKnown(
				Title::makeTitle( NS_SPECIAL, "Moderation" ),
				wfMessage('moderation-log-change', $modid)->text(),
				array('title' => wfMessage('tooltip-moderation-rejected-change')),
				array('modaction' => 'show', 'modid' => $modid)
			);
			$params[4] = Message::rawParam($link);

			$link = Linker::linkKnown(
				$this->entry->getTarget(),
				wfMessage('moderation-log-diff', $revid)->text(),
				array('title' => wfMessage('tooltip-moderation-approved-diff')),
				array('diff' => $revid)
			);
			$params[5] = Message::rawParam($link);
		}
		elseif($type === 'approveall' || $type === 'rejectall' || $type === 'block' || $type === 'unblock')
		{
			$title = $this->entry->getTarget();

			$user_id = User::idFromName($title->getText());
			$link = Linker::userLink($user_id, $title->getText());

			$params[2] = Message::rawParam($link);
		}

		return $params;
	}
}
