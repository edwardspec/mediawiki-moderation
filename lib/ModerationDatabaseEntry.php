<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@brief Methods to manage "moderation" SQL table.
*/

class ModerationDatabaseEntry {

	const MOD_TYPE_EDIT = 'edit';
	const MOD_TYPE_MOVE = 'move';

	protected $type = null; /**< One of MOD_TYPE_* values */
	protected $summary = null; /**< Edit summary (string) */
	protected $section = ''; /**< Index of the edited section (integer) or the string 'new' */
	protected $sectionText = null; /**< Text of edited section, if any */
	protected $isMinor = null; /**< True if marked as minor edit, false otherwise */
	protected $isBot = null; /** True if marked as bot edit, false otherwise */
	protected $wikiPage = null; /**< WikiPage object (page to be edited) */
	protected $newContent = null; /**< Content object (new text of the page) */
	protected $newTitle = null; /**< Title object (title of the destination when moving the page) */
	protected $id = null; /**< mod_id (integer) when updating existing row, null when creating a new row */

	protected $title; /**< Title object (page to be edited) */
	protected $user; /**< User object (author of the edit) */

	public function __construct( Title $title, User $user ) {
		$this->title = $title;
		$this->user = $user;
	}

	public function edit( WikiPage $wikiPage, Content $newContent ) {
		return $this
			->setType( self::MOD_TYPE_EDIT )
			->setWikiPage( $wikiPage )
			->setNewContent( $newContent );
	}

	public function move( Title $newTitle ) {
		return $this
			->setType( self::MOD_TYPE_MOVE )
			->setNewTitle( $newTitle );
	}

	public function setMinor( $isMinor ) {
		$this->isMinor = $isMinor;
		return $this;
	}

	public function setBot( $isBot ) {
		$this->isBot = $isBot;
		return $this;
	}

	public function setSummary( $summary ) {
		$this->summary = $summary;
		return $this;
	}

	public function setSection( $section, $sectionText ) {
		$this->section = $section;
		$this->sectionText = $sectionText;
		return $this;
	}

	/*-------------------------------------------------------------------*/

	protected function setWikiPage( WikiPage $wikiPage ) {
		$this->wikiPage = $wikiPage;
		return $this;
	}

	protected function setNewContent( Content $newContent ) {
		$this->newContent = $newContent;
		return $this;
	}

	protected function setNewTitle( Title $newTitle ) {
		$this->newTitle = $newTitle;
		return $this;
	}

	protected function setType( $type ) {
		$this->type = $type;
		return $this;
	}

	protected function setId( $modid ) {
		$this->id = $modid;
		return $this;
	}

	/*-------------------------------------------------------------------*/

	/**
		@brief Replace things like "~~~~" in $content.
		@returns Text after transformation (string).
	*/
	protected function preSaveTransform( Content $content ) {
		global $wgContLang;
		$popts = ParserOptions::newFromUserAndLang( $this->user, $wgContLang );

		return $content->preSaveTransform(
			$this->title,
			$this->user,
			$popts
		)->getNativeData();
	}

	protected function getPreload() {
		return ModerationPreload::singleton();
	}

	protected function loadUnmoderatedEdit() {
		/* FIXME: should do $preload->setUser( $this->user ),
			because $this->user may be different from $wgUser */
		$row = $this->getPreload()->loadUnmoderatedEdit( $this->title );
		if ( $row ) {
			$this->setId( $row->id );
		}

		return $row;
	}

	/**
		@brief Utility function: construct Content object from $text.
		@returns Content object.
	*/
	protected function makeContent( $text ) {
		return ContentHandler::makeContent(
			$text,
			$this->title,
			$this->newContent ? $this->newContent->getModel() : null
		);
	}

	/**
		@brief Calculate all mod_* fields for database INSERT.
		@returns array (as expected by $dbw->insert())
	*/
	protected function getFields() {
		$request = $this->user->getRequest();
		$dbr = wfGetDB( DB_SLAVE ); /* Only for $dbr->timestamp(), won't do any SQL queries */

		$fields = [
			'mod_timestamp' => $dbr->timestamp(),
			'mod_user' => $this->user->getId(),
			'mod_user_text' => $this->user->getName(),
			'mod_namespace' => $this->title->getNamespace(),
			'mod_title' => ModerationVersionCheck::getModTitleFor( $this->title ),
			'mod_comment' => $this->summary,
			'mod_minor' => $this->isMinor,
			'mod_bot' => $this->isBot,
			'mod_ip' => $request->getIP(),
			'mod_header_xff' => $request->getHeader( 'X-Forwarded-For' ),
			'mod_header_ua' => $request->getHeader( 'User-Agent' ),
			'mod_preload_id' => $this->getPreload()->getId( true ),
			'mod_preloadable' => 1
		];

		if ( $this->wikiPage ) {
			/* Not relevant to page moves */
			$fields += [
				'mod_cur_id' => $this->wikiPage->getId(),
				'mod_new' => $this->wikiPage->exists() ? 0 : 1,
				'mod_last_oldid' => $this->wikiPage->getLatest()
			];

			$oldContent = $this->wikiPage->getContent( Revision::RAW ); // current revision's content
			if ( $oldContent ) {
				$fields['mod_old_len'] = $oldContent->getSize();
			}
		}

		if ( class_exists( 'AbuseFilter' )
			&& !empty( AbuseFilter::$tagsToSet )
			&& ModerationVersionCheck::areTagsSupported()
		) {
			/* AbuseFilter wants to assign some tags to this edit.
				Let's store them (they will be used in modaction=approve).
			*/
			$afActionID = join( '-', [
				$this->title->getPrefixedText(),
				$this->user->getName(),
				'edit' /* TODO: does this need special handling for uploads? */
			] );

			if ( isset( AbuseFilter::$tagsToSet[$afActionID] ) ) {
				$fields['mod_tags'] = join( "\n", AbuseFilter::$tagsToSet[$afActionID] );
			}
		}

		if ( ModerationBlockCheck::isModerationBlocked( $this->user ) ) {
			$fields['mod_rejected'] = 1;
			$fields['mod_rejected_by_user'] = 0;
			$fields['mod_rejected_by_user_text'] = wfMessage( 'moderation-blocker' )->inContentLanguage()->text();
			$fields['mod_rejected_auto'] = 1;
			$fields['mod_preloadable'] = 1; # User can still edit this change, so that spammers won't notice that they are blocked
		}

		if ( !$this->newContent ) {
			/* Non-edit action (e.g. page move), no need to populate mod_text, etc. */
			return $fields;
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		$row = $this->loadUnmoderatedEdit();
		if ( $row && $this->section !== '' ) {
			#
			# We must recalculate $fields['mod_text'] here.
			# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
			# then only modification to the last one will be saved,
			# because $this->newContent is [old content] PLUS [modified section from the edit].
			#
			# $newSectionContent is exactly what the user just wrote in the edit form (one section only).
			$newSectionContent = $this->makeContent( $this->sectionText );

			# $savedContent is mod_text which is currently written in the "moderation" table of DB.
			$savedContent = $this->makeContent( $row->text );

			# We set $this->newContent to $savedContent with replaced section.
			$this->newContent = $savedContent->replaceSection( $this->section, $newSectionContent, '' );
		}

		$fields['mod_text'] = $this->preSaveTransform( $this->newContent );
		$fields['mod_new_len'] = $this->newContent->getSize();

		return $fields;
	}

	/**
		@brief Queue edit for moderation.
		@returns $fields array.
	*/
	public function queue() {
		$dbw = wfGetDB( DB_MASTER );
		$fields = $this->getFields();

		if ( $this->id ) {
			RollbackResistantQuery::update( $dbw, [
				'moderation',
				$fields,
				[ 'mod_id' => $this->id ],
				__METHOD__
			] );
			$fields['mod_id'] = $this->id;
		}
		else {
			RollbackResistantQuery::insert( $dbw, [
				'moderation',
				$fields,
				__METHOD__
			] );
			$fields['mod_id'] = $dbw->insertId();
		}

		return $fields;
	}
}
