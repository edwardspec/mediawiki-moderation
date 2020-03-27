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
 * Factory that can construct ModerationEntry objects from Context.
 */

namespace MediaWiki\Moderation;

use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use ModerationApprovableEntry;
use ModerationEntryEdit;
use ModerationEntryFormatter;
use ModerationEntryMove;
use ModerationEntryUpload;
use ModerationError;
use ModerationNewChange;
use ModerationViewableEntry;

class EntryFactory {
	/** @var LinkRenderer */
	protected $linkRenderer;

	/** @var ActionLinkRenderer */
	protected $actionLinkRenderer;

	/** @var TimestampFormatter */
	protected $timestampFormatter;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param ActionLinkRenderer $actionLinkRenderer
	 */
	public function __construct( LinkRenderer $linkRenderer,
		ActionLinkRenderer $actionLinkRenderer, TimestampFormatter $timestampFormatter
	) {
		$this->linkRenderer = $linkRenderer;
		$this->actionLinkRenderer = $actionLinkRenderer;
		$this->timestampFormatter = $timestampFormatter;
	}

	/**
	 * Construct new ModerationEntryFormatter.
	 * @param object $row
	 * @param IContextSource $context
	 * @return ModerationEntryFormatter
	 */
	public function makeFormatter( $row, IContextSource $context ) {
		return new ModerationEntryFormatter(
			$row,
			$context,
			$this->linkRenderer,
			$this->actionLinkRenderer,
			$this->timestampFormatter
		);
	}

	/**
	 * Construct new ModerationViewableEntry from $row.
	 * @param object $row
	 * @return ModerationViewableEntry
	 */
	public function makeViewableEntry( $row ) {
		return new ModerationViewableEntry(
			$row,
			$this->linkRenderer
		);
	}

	/**
	 * Construct new ModerationViewableEntry from mod_id of the change.
	 * @param int $id
	 * @return ModerationViewableEntry
	 */
	public function findViewableEntry( $id ) {
		return $this->makeViewableEntry(
			$this->loadRowOrThrow( $id, ModerationViewableEntry::getFields(), DB_REPLICA )
		);
	}

	/**
	 * Construct new ModerationApprovableEntry from $row.
	 * @param object $row
	 * @return ModerationApprovableEntry
	 */
	public function makeApprovableEntry( $row ) {
		if ( isset( $row->type ) && $row->type == ModerationNewChange::MOD_TYPE_MOVE ) {
			return new ModerationEntryMove( $row );
		}

		if ( $row->stash_key ) {
			return new ModerationEntryUpload( $row );
		}

		return new ModerationEntryEdit( $row );
	}

	/**
	 * Construct new ModerationApprovableEntry from mod_id of the change.
	 * @param int $id
	 * @return ModerationApprovableEntry
	 */
	public function findApprovableEntry( $id ) {
		return $this->makeApprovableEntry(
			$this->loadRowOrThrow( $id, ModerationApprovableEntry::getFields() )
		);
	}

	/**
	 * Load $row from the database by its mod_id.
	 * @param int $id
	 * @param string[] $fields
	 * @param int $dbType DB_MASTER or DB_REPLICA.
	 * @return object|false
	 */
	public function loadRow( $id, array $fields, $dbType = DB_MASTER ) {
		$db = wfGetDB( $dbType );
		$row = $db->selectRow( 'moderation', $fields, [ 'mod_id' => $id ], __METHOD__ );
		if ( !$row ) {
			return false;
		}

		$row->id = $id;
		return $row;
	}

	/**
	 * Load $row from the database by its mod_id. Throws an exception if the row wasn't found.
	 * @param int $id
	 * @param string[] $fields
	 * @param int $dbType DB_MASTER or DB_REPLICA.
	 * @return object
	 * @throws ModerationError
	 */
	public function loadRowOrThrow( $id, array $fields, $dbType = DB_MASTER ) {
		$row = $this->loadRow( $id, $fields, $dbType );
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		return $row;
	}
}
