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

	protected $title; /**< Title object (page to be edited) */
	protected $user; /**< User object (author of the edit) */
	protected $fields = []; /**< All database fields (array) */

	private $pendingChange = null; /**< False if no pending change, array( 'mod_id' => ..., 'mod_text' => ... ) otherwise */

	public function __construct( Title $title, User $user ) {
		$this->title = $title;
		$this->user = $user;

		$isBlocked = ModerationBlockCheck::isModerationBlocked( $user );

		$request = $user->getRequest();
		$dbr = wfGetDB( DB_SLAVE ); /* Only for $dbr->timestamp(), won't do any SQL queries */

		/* Prepare known values of $fields */
		$this->fields = [
			'mod_timestamp' => $dbr->timestamp(),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => 0, # Unknown, set by edit()
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => ModerationVersionCheck::getModTitleFor( $title ),
			'mod_comment' => '', # Unknown, set by setSummary()
			'mod_minor' => false, # Unknown, set by setMinor()
			'mod_bot' => false, # Unknown, set by setBot()
			'mod_new' => false, # Unknown, set by edit()
			'mod_last_oldid' => 0, # Unknown, set by edit()
			'mod_ip' => $request->getIP(),
			'mod_old_len' => 0, # Unknown, set by edit()
			'mod_new_len' => 0, # Unknown, set by edit()
			'mod_header_xff' => $request->getHeader( 'X-Forwarded-For' ),
			'mod_header_ua' => $request->getHeader( 'User-Agent' ),
			'mod_preload_id' => $this->getPreload()->getId( true ),
			'mod_rejected' => $isBlocked ? 1 : 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => $isBlocked ?
				wfMessage( 'moderation-blocker' )->inContentLanguage()->text() :
				null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => $isBlocked ? 1 : 0,
			'mod_preloadable' => ModerationVersionCheck::preloadableYes(),
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => '', # Unknown, set by edit()
			'mod_stash_key' => '', # Never set during queue() - added via UPDATE later
		];

		/* If update.php hasn't been run for a while,
			newly added fields might not be present */
		if ( ModerationVersionCheck::areTagsSupported() ) {
			$this->fields['mod_tags'] = $this->getChangeTags();
		}

		if ( ModerationVersionCheck::hasModType() ) {
			$this->fields['mod_type'] = self::MOD_TYPE_EDIT; # Default, can be changed by move()
			$this->fields['mod_page2_namespace'] = ''; # Unknown, set by move()
			$this->fields['mod_page2_title'] = ''; # Unknown, set by move()
		}
	}

	public function edit( WikiPage $wikiPage, Content $newContent, $section, $sectionText ) {
		$this->fields['mod_cur_id'] = $wikiPage->getId();
		$this->fields['mod_new'] = $wikiPage->exists() ? 0 : 1;
		$this->fields['mod_last_oldid'] = $wikiPage->getLatest();

		$oldContent = $wikiPage->getContent( Revision::RAW ); // current revision's content
		if ( $oldContent ) {
			$this->fields['mod_old_len'] = $oldContent->getSize();
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		if ( $section !== '' ) {
			$row = $this->getPendingChange();
			if ( $row ) {
				#
				# We must recalculate $this->fields['mod_text'] here.
				# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
				# then only modification to the last one will be saved,
				# because $this->newContent is [old content] PLUS [modified section from the edit].
				#
				$model = $newContent->getModel();
				$newContent = $this->makeContent( $row->text, $model )->replaceSection(
					$section,
					$this->makeContent( $sectionText, $model ),
					''
				);
			}
		}

		$this->fields['mod_text'] = $this->preSaveTransform( $newContent );
		$this->fields['mod_new_len'] = $newContent->getSize();

		return $this;
	}

	public function move( Title $newTitle ) {
		$this->fields['mod_type'] = self::MOD_TYPE_MOVE;
		$this->fields['mod_page2_namespace'] = $newTitle->getNamespace();
		$this->fields['mod_page2_title'] = $newTitle->getDBKey();
		return $this;
	}

	public function setMinor( $isMinor ) {
		$this->fields['mod_minor'] = (int)$isMinor;
		return $this;
	}

	public function setBot( $isBot ) {
		$this->fields['mod_bot'] = (int)$isBot;
		return $this;
	}

	public function setSummary( $summary ) {
		$this->fields['mod_comment'] = $summary;
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

	/**
		@brief Calculate the value of mod_tags.
	*/
	protected function getChangeTags() {
		if ( !class_exists( 'AbuseFilter' ) || empty( AbuseFilter::$tagsToSet ) ) {
			return null; /* No tags */
		}

		/* AbuseFilter wants to assign some tags to this edit.
			Let's store them (they will be used in modaction=approve).
		*/
		$afActionID = join( '-', [
			$this->title->getPrefixedText(),
			$this->user->getName(),
			'edit' /* TODO: does this need special handling for uploads? */
		] );

		if ( isset( AbuseFilter::$tagsToSet[$afActionID] ) ) {
			return join( "\n", AbuseFilter::$tagsToSet[$afActionID] );
		}
	}

	protected function getPreload() {
		$preload = ModerationPreload::singleton();
		$preload->setUser( $this->user );
		return $preload;
	}

	protected function getPendingChange() {
		if ( is_null( $this->pendingChange ) ) {
			$this->pendingChange = $this->getPreload()
				->loadUnmoderatedEdit( $this->title );
		}

		return $this->pendingChange;
	}

	/**
		@brief Utility function: construct Content object from $text.
		@returns Content object.
	*/
	protected function makeContent( $text, $model = null ) {
		return ContentHandler::makeContent(
			$text,
			$this->title,
			$model
		);
	}

	/**
		@brief Returns all mod_* fields for database INSERT.
		@returns array (as expected by $dbw->insert())
	*/
	public function getFields() {
		return $this->fields;
	}

	/**
		@brief Returns one of the fields for database INSERT.
		@param $fieldName String, e.g. "mod_timestamp".
		@returns Value (string) or false.
	*/
	public function getField( $fieldName ) {
		return isset( $this->fields[$fieldName] ) ? $this->fields[$fieldName] : false;
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

		$uniqueFields = [
			'mod_preloadable',
			'mod_namespace',
			'mod_title',
			'mod_preload_id'
		];
		if ( ModerationVersionCheck::hasModType() ) {
			$uniqueFields[] = 'mod_type';
		}

		$dbw = wfGetDB( DB_MASTER );
		RollbackResistantQuery::upsert( $dbw, [
			'moderation',
			$fields,
			$uniqueFields,
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
			$wgModerationEmail;

		if ( !$wgModerationNotificationEnable || !$wgModerationEmail ) {
			return; /* Disabled */
		}

		if ( $wgModerationNotificationNewOnly && $this->getField( 'mod_new' ) == 0 ) {
			/* We only need to notify about new pages,
				and this is an edit in existing page */
			return;
		}

		/* Sending may be slow, defer it
			until the user receives HTTP response */
		DeferredUpdates::addCallableUpdate( [
			$this,
			'sendNotificationEmailNow'
		] );
	}

	/**
		@brief Deliver the deferred letter from sendNotificationEmail().
	*/
	public function sendNotificationEmailNow() {
		global $wgModerationEmail, $wgPasswordSender;

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
