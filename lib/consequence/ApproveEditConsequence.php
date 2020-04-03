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
 * Consequence that approves one pending normal edit (not an upload, etc.).
 */

namespace MediaWiki\Moderation;

use ContentHandler;
use MediaWiki\MediaWikiServices;
use Revision;
use Status;
use Title;
use User;
use WikiPage;

class ApproveEditConsequence implements IConsequence {
	/** @var User */
	protected $user;

	/** @var Title */
	protected $title;

	/** @var string */
	protected $newText;

	/** @var string */
	protected $comment;

	/** @var bool */
	protected $isBot;

	/** @var bool */
	protected $isMinor;

	/** @var int */
	protected $baseRevId;

	/**
	 * @param User $user
	 * @param Title $title
	 * @param string $newText
	 * @param string $comment
	 * @param bool $isBot
	 * @param bool $isMinor
	 * @param int $baseRevId
	 */
	public function __construct( User $user, Title $title, $newText, $comment,
		$isBot, $isMinor, $baseRevId
	) {
		$this->user = $user;
		$this->title = $title;
		$this->newText = $newText;
		$this->comment = $comment;
		$this->isBot = $isBot;
		$this->isMinor = $isMinor;
		$this->baseRevId = $baseRevId;
	}

	/**
	 * Execute the consequence.
	 * @return Status
	 */
	public function run() {
		$flags = EDIT_AUTOSUMMARY;
		if ( $this->isBot && $this->user->isAllowed( 'bot' ) ) {
			$flags |= EDIT_FORCE_BOT;
		}
		if ( $this->isMinor ) { # doEditContent() checks the right
			$flags |= EDIT_MINOR;
		}

		$model = $this->title->getContentModel();
		$newContent = ContentHandler::makeContent( $this->newText, null, $model );

		$page = new WikiPage( $this->title );
		if ( !$page->exists() ) {
			# New page. No need to check for edit conflicts.
			return $page->doEditContent(
				$newContent,
				$this->comment,
				$flags,
				false,
				$this->user
			);
		}

		# Existing page
		$latest = $page->getLatest();
		if ( $latest == $this->baseRevId ) {
			# Page hasn't changed since this edit was queued for moderation.
			return $page->doEditContent(
				$newContent,
				$this->comment,
				$flags,
				$latest,
				$this->user
			);
		}

		# Page has changed! (edit conflict)
		# Let's try to merge this automatically (resolve the conflict),
		# as MediaWiki does in private EditPage::mergeChangesIntoContent().

		$handler = ContentHandler::getForModelID( $model );
		$baseContent = $handler->makeEmptyContent();

		if ( $this->baseRevId ) {
			$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
			$rec = $revisionLookup->getRevisionById( $this->baseRevId );

			// Note: $rec may be null if page was deleted.
			if ( $rec ) {
				// B/C: SlotRecord::MAIN wasn't defined in MW 1.31.
				$baseContent = $rec->getSlot( 'main', Revision::RAW )->getContent();
			}
		}

		$latestContent = $page->getContent( Revision::RAW );
		$mergedContent = $handler->merge3( $baseContent, $newContent, $latestContent );

		if ( $mergedContent ) {
			return $page->doEditContent(
				$mergedContent,
				$this->comment,
				$flags,
				$latest, # Because $mergedContent goes after $latest
				$this->user
			);
		}

		return Status::newFatal( 'moderation-edit-conflict' );
	}
}
