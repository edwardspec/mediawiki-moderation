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
	@brief Parent class for objects that represent one row in the 'moderation' SQL table.
*/

abstract class ModerationEntry implements IModerationEntry {
	private $row;

	private $user = null; /**< Author of this change (User object) */
	private $title = null; /**< Page affected by this change (Title object) */

	protected function getRow() {
		return $this->row;
	}

	protected function __construct( $row ) {
		if ( !isset( $row->type ) ) { // !ModerationVersionCheck::hasModType()
			$row->type = ModerationNewChange::MOD_TYPE_EDIT;
		}

		if ( !isset( $row->tags ) ) { // !ModerationVersionCheck::areTagsSupported()
			$row->tags = false;
		}

		$this->row = $row;
	}

	/**
		@brief Returns author of this change (User object).
	*/
	protected function getUser() {
		if ( is_null( $this->user ) ) {
			$row = $this->getRow();
			$user = $row->user ?
				User::newFromId( $row->user ) :
				User::newFromName( $row->user_text, false );

			/* User could have been recently renamed or deleted.
				Make sure we have the correct data. */
			$user->load( User::READ_LATEST );
			if ( $user->getId() == 0 && $row->user != 0 ) {
				/* User was deleted,
					e.g. via [maintenance/removeUnusedAccounts.php] */
				$user->setName( $row->user_text );
			}

			$this->user = $user;
		}

		return $this->user;
	}

	/**
		@brief Returns Title of the page affected by this change.
	*/
	public function getTitle() {
		if ( is_null( $this->title ) ) {
			$row = $this->getRow();
			$this->title = Title::makeTitle( $row->namespace, $row->title );
		}

		return $this->title;
	}

	/**
		@brief Returns Title of the second affected page (if any).
		E.g. new name of the article when renaming it.
		@retval null Not applicable (e.g. mod_type=edit).
	*/
	public function getPage2Title() {
		$row = $this->getRow();
		if ( !$row->page2_title ) {
			return null;
		}

		return Title::makeTitle( $row->page2_namespace, $row->page2_title );
	}

	/**
		@brief Load ModerationEntry from the database by mod_id.
		@throws ModerationError
	*/
	public static function newFromId( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			static::getFields(),
			[ 'mod_id' => $id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		return static::newFromRow( $row );
	}

	/**
		@brief Construct new ModerationEntry from $row.
		@throws ModerationError
	*/
	public static function newFromRow( $row ) {
		return new static( $row );
	}
}
