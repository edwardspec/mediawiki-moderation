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
 * Consequence that modifies text and edit summary of one change in the moderation queue.
 */

namespace MediaWiki\Moderation;

use ContentHandler;
use ModerationCompatTools;
use ParserOptions;
use Title;
use User;

class ModifyPendingChangeConsequence implements IConsequence {
	/** @var int */
	protected $modid;

	/** @var string */
	protected $newText;

	/** @var string */
	protected $newComment;

	/** @var string */
	protected $oldText;

	/** @var string */
	protected $oldComment;

	/** @var Title */
	protected $title;

	/** @var User */
	protected $originalAuthor;

	/**
	 * @param int $modid
	 * @param string $newText
	 * @param string $newComment
	 * @param string $oldText
	 * @param string $oldComment
	 * @param Title $title
	 * @param User $originalAuthor
	 */
	public function __construct( $modid, $newText, $newComment,
		// FIXME: these parameters are only used to avoid an extra SQL query.
		// Maybe just move select() from ModerationActionEditChangeSubmit to this Consequence?
		$oldText, $oldComment,
		Title $title, User $originalAuthor
	) {
		$this->modid = $modid;
		$this->newText = $newText;
		$this->newComment = $newComment;
		$this->oldText = $oldText;
		$this->oldComment = $oldComment;
		$this->title = $title;
		$this->originalAuthor = $originalAuthor;
	}

	/**
	 * Execute the consequence.
	 * @return bool True if something changed, false otherwise.
	 */
	public function run() {
		/* Apply preSaveTransform to the submitted text */
		$newContent = ContentHandler::makeContent( $this->newText, $this->title );
		$pstContent = $newContent->preSaveTransform(
			$this->title,
			$this->originalAuthor,
			ParserOptions::newFromUserAndLang(
				$this->originalAuthor,
				ModerationCompatTools::getContentLanguage()
			)
		);

		$newText = $pstContent->getNativeData();
		if ( $newText == $this->oldText && $this->newComment == $this->oldComment ) {
			// Nothing to do
			return false;
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'moderation',
			[
				'mod_text' => $newText,
				'mod_new_len' => $pstContent->getSize(),
				'mod_comment' => $this->newComment
			],
			[
				'mod_id' => $this->modid
			],
			__METHOD__
		);

		// NOTE: MediaWiki sets MYSQLI_CLIENT_FOUND_ROWS flag, so affectedRows() is always 1,
		// except for situation when the row that needs to be updated can't be found.
		return ( $dbw->affectedRows() > 0 );
	}
}
