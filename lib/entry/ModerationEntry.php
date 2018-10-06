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
 * @file
 * Parent class for objects that represent one row in the 'moderation' SQL table.
 */

abstract class ModerationEntry implements IModerationEntry {
	/** @var stdClass Return value of Database::selectRow() */
	private $row;

	/** @var User Author of this change */
	private $user = null;

	/** @var Title Page affected by this change */
	private $title = null;

	/** @var bool Cache used by canReapproveRejected() */
	protected static $earliestReapprovableTimestamp = null;

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
	 * Returns true if this is a move, false otherwise.
	 */
	public function isMove() {
		return $this->row->type == ModerationNewChange::MOD_TYPE_MOVE;
	}

	/**
	 * Returns true if this is an upload, false otherwise.
	 */
	public function isUpload() {
		return $this->row->stash_key ? true : false;
	}

	/**
	 * Returns true if this edit is recent enough to be reapproved after rejection.
	 */
	public function canReapproveRejected() {
		if ( self::$earliestReapprovableTimestamp === null ) {
			global $wgModerationTimeToOverrideRejection;

			$ts = new MWTimestamp();
			$ts->timestamp->modify( '-' . intval( $wgModerationTimeToOverrideRejection ) . ' seconds' );
			self::$earliestReapprovableTimestamp = $ts->getTimestamp( TS_MW );
		}
		return $this->row->timestamp > self::$earliestReapprovableTimestamp;
	}

	/**
	 * Returns author of this change (User object).
	 * @param int $flags User::READ_* constant bitfield.
	 */
	protected function getUser( $flags = 0 ) {
		if ( is_null( $this->user ) ) {
			$row = $this->getRow();
			$user = $row->user ?
				User::newFromId( $row->user ) :
				User::newFromName( $row->user_text, false );

			/* User could have been recently renamed or deleted.
				Make sure we have the correct data. */
			$user->load( $flags );
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
	 * Returns Title of the page affected by this change.
	 */
	public function getTitle() {
		if ( is_null( $this->title ) ) {
			$row = $this->getRow();
			$this->title = Title::makeTitle( $row->namespace, $row->title );
		}

		return $this->title;
	}

	/**
	 * Returns Title of the second affected page (if any) or null (for mod_type=edit, etc.).
	 * E.g. new name of the article when renaming it.
	 * @return Title|null
	 */
	public function getPage2Title() {
		$row = $this->getRow();
		if ( !$row->page2_title ) {
			return null;
		}

		return Title::makeTitle( $row->page2_namespace, $row->page2_title );
	}

	/**
	 * Load ModerationEntry from the database by mod_id.
	 * @param int $id
	 * @param int $dbType DB_MASTER or DB_REPLICA.
	 * @throws ModerationError
	 */
	public static function newFromId( $id, $dbType = DB_MASTER ) {
		$dbw = wfGetDB( $dbType );
		$row = $dbw->selectRow( 'moderation',
			static::getFields(),
			[ 'mod_id' => $id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$row->id = $id;
		return static::newFromRow( $row );
	}

	/**
	 * Construct new ModerationEntry from $row.
	 * @throws ModerationError
	 */
	public static function newFromRow( $row ) {
		return new static( $row );
	}
}
