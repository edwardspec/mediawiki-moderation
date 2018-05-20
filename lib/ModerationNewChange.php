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

class ModerationNewChange {

	public static $LastInsertId = null; /**< mod_id of the last inserted row */

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

	private $pendingChange = null; /**< False if no pending change, array( 'mod_id' => ..., 'mod_text' => ... ) otherwise */
	private $fields = null; /**< All database fields (array), see getFields() */

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

	protected function getPendingChange() {
		if ( is_null( $this->pendingChange ) ) {
			$preload = $this->getPreload();
			$preload->setUser( $this->user );

			$this->pendingChange = $preload->loadUnmoderatedEdit( $this->title );
		}

		return $this->pendingChange;
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
		@brief Returns all mod_* fields for database INSERT.
		@returns array (as expected by $dbw->insert())
	*/
	public function getFields() {
		$this->calculateFields();
		return $this->fields;
	}

	/**
		@brief Returns one of the fields for database INSERT.
		@param $fieldName String, e.g. "mod_timestamp".
		@returns Value (string) or false.
	*/
	public function getField( $fieldName ) {
		$this->calculateFields();
		return isset( $this->fields[$fieldName] ) ? $this->fields[$fieldName] : false;
	}

	/**
		@brief Calculate all mod_* fields for database INSERT.
		Shouldn't be called outside of getFields()/getField().
	*/
	protected function calculateFields() {
		if ( $this->fields ) {
			return; /* Already calculated */
		}

		$request = $this->user->getRequest();
		$dbr = wfGetDB( DB_SLAVE ); /* Only for $dbr->timestamp(), won't do any SQL queries */

		$this->fields = [
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
			'mod_preloadable' => ModerationVersionCheck::preloadableYes()
		];

		if ( ModerationVersionCheck::hasModType() ) {
			/* This may be a non-edit change (e.g. page move) */
			$this->fields['mod_type'] = $this->type;

			if ( $this->newTitle ) {
				$this->fields += [
					'mod_page2_namespace' => $this->newTitle->getNamespace(),
					'mod_page2_title' => $this->newTitle->getDBKey()
				];
			}
		}

		if ( $this->wikiPage ) {
			/* Not relevant to page moves */
			$this->fields += [
				'mod_cur_id' => $this->wikiPage->getId(),
				'mod_new' => $this->wikiPage->exists() ? 0 : 1,
				'mod_last_oldid' => $this->wikiPage->getLatest()
			];

			$oldContent = $this->wikiPage->getContent( Revision::RAW ); // current revision's content
			if ( $oldContent ) {
				$this->fields['mod_old_len'] = $oldContent->getSize();
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
				$this->fields['mod_tags'] = join( "\n", AbuseFilter::$tagsToSet[$afActionID] );
			}
		}

		if ( ModerationBlockCheck::isModerationBlocked( $this->user ) ) {
			$this->fields['mod_rejected'] = 1;
			$this->fields['mod_rejected_by_user'] = 0;
			$this->fields['mod_rejected_by_user_text'] = wfMessage( 'moderation-blocker' )->inContentLanguage()->text();
			$this->fields['mod_rejected_auto'] = 1;

			# Note: we don't disable $this->fields['mod_preloadable'],
			# so that the spammers won't notice that they are blocked
			# (they can continue editing this change,
			# even though the change will be in the Spam folder)
		}

		if ( !$this->newContent ) {
			/* Non-edit action (e.g. page move), no need to populate mod_text, etc. */
			return;
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		if ( $this->section !== '' ) {
			$row = $this->getPendingChange();
			if ( $row ) {
				#
				# We must recalculate $this->fields['mod_text'] here.
				# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
				# then only modification to the last one will be saved,
				# because $this->newContent is [old content] PLUS [modified section from the edit].
				#
				$this->newContent = $this->makeContent( $row->text )->replaceSection(
					$this->section,
					$this->makeContent( $this->sectionText ),
					''
				);
			}
		}

		$this->fields['mod_text'] = $this->preSaveTransform( $this->newContent );
		$this->fields['mod_new_len'] = $this->newContent->getSize();
	}

	/**
		@brief Queue edit for moderation.
	*/
	public function queue() {
		self::$LastInsertId = ModerationVersionCheck::hasUniqueIndex() ?
			$this->insert() :
			$this->insertOld();

		// Run hook to allow other extensions be notified about pending changes
		Hooks::run( 'ModerationPending', [
			$this->getFields(),
			self::$LastInsertId
		] );

		// Notify administrator about pending changes
		$this->sendNotificationEmail();

		// Enable in-wiki notification "New changes await moderation" for moderators
		ModerationNotifyModerator::setPendingTime(
			$this->getField( 'mod_timestamp' )
		);
	}

	/**
		@brief Insert this change into the moderation SQL table.
		@returns mod_id of affected row.
	*/
	protected function insert() {
		$fields = $this->getFields();

		$dbw = wfGetDB( DB_MASTER );
		RollbackResistantQuery::upsert( $dbw, [
			'moderation',
			$fields,
			[
				'mod_preloadable',
				'mod_type',
				'mod_namespace',
				'mod_title',
				'mod_preload_id'
			],
			$fields,
			__METHOD__
		] );
		return $dbw->insertId();
	}

	/**
		@brief Legacy version of insert() for old databases without UNIQUE INDEX.
		@returns mod_id of affected row.
	*/
	protected function insertOld() {
		$row = $this->getPendingChange();
		$id = $row ? $row->id : false;

		$dbw = wfGetDB( DB_MASTER );
		if ( $id ) {
			RollbackResistantQuery::update( $dbw, [
				'moderation',
				$this->getFields(),
				[ 'mod_id' => $id ],
				__METHOD__
			] );
		}
		else {
			RollbackResistantQuery::insert( $dbw, [
				'moderation',
				$this->getFields(),
				__METHOD__
			] );
			$id = $dbw->insertId();
		}

		return $id;
	}

	/**
		@brief Send email to moderators that new change has appeared.
	*/
	public function sendNotificationEmail() {
		global $wgModerationNotificationEnable,
			$wgModerationNotificationNewOnly,
			$wgModerationEmail,
			$wgPasswordSender;

		if ( !$wgModerationNotificationEnable || !$wgModerationEmail ) {
			return; /* Disabled */
		}

		if ( $wgModerationNotificationNewOnly && $this->getField( 'mod_new' ) == 0 ) {
			/* We only need to notify about new pages,
				and this is an edit in existing page */
			return;
		}

		$mailer = new UserMailer();
		$to = new MailAddress( $wgModerationEmail );
		$from = new MailAddress( $wgPasswordSender );
		$subject = wfMessage( 'moderation-notification-subject' )->inContentLanguage()->text();
		$content = wfMessage( 'moderation-notification-content',
			$this->title->getPrefixedText(),
			$this->user->getName(),
			SpecialPage::getTitleFor( 'Moderation' )->getCanonicalURL( [
				'modaction' => 'show',
				'modid' => self::$LastInsertId
			] )
		)->inContentLanguage()->text();

		$mailer->send( $to, $from, $subject, $content );
	}
}
