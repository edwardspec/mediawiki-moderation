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
 * Factory that can construct ModerationEntry objects from database rows.
 */

namespace MediaWiki\Moderation;

use IContextSource;
use MediaWiki\Linker\LinkRenderer;
use ModerationApprovableEntry;
use ModerationApproveHook;
use ModerationCanSkip;
use ModerationEntryEdit;
use ModerationEntryFormatter;
use ModerationEntryMove;
use ModerationEntryUpload;
use ModerationError;
use ModerationNewChange;
use ModerationVersionCheck;
use ModerationViewableEntry;
use Title;

class EntryFactory {
	/** @var LinkRenderer */
	protected $linkRenderer;

	/** @var ActionLinkRenderer */
	protected $actionLinkRenderer;

	/** @var TimestampFormatter */
	protected $timestampFormatter;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/** @var ModerationCanSkip */
	protected $canSkip;

	/** @var ModerationApproveHook */
	protected $approveHook;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param ActionLinkRenderer $actionLinkRenderer
	 * @param TimestampFormatter $timestampFormatter
	 * @param IConsequenceManager $consequenceManager
	 * @param ModerationCanSkip $canSkip
	 * @param ModerationApproveHook $approveHook
	 */
	public function __construct( LinkRenderer $linkRenderer,
		ActionLinkRenderer $actionLinkRenderer,
		TimestampFormatter $timestampFormatter,
		IConsequenceManager $consequenceManager,
		ModerationCanSkip $canSkip,
		ModerationApproveHook $approveHook
	) {
		$this->linkRenderer = $linkRenderer;
		$this->actionLinkRenderer = $actionLinkRenderer;
		$this->timestampFormatter = $timestampFormatter;
		$this->consequenceManager = $consequenceManager;
		$this->canSkip = $canSkip;
		$this->approveHook = $approveHook;
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
			$this->timestampFormatter,
			$this->canSkip
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
		$args = [ $row, $this->consequenceManager, $this->approveHook ];

		if ( isset( $row->type ) && $row->type == ModerationNewChange::MOD_TYPE_MOVE ) {
			return new ModerationEntryMove( ...$args );
		}

		if ( $row->stash_key ) {
			return new ModerationEntryUpload( ...$args );
		}

		return new ModerationEntryEdit( ...$args );
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
	 * Find an edit that awaits moderation and was made by user $preloadId in page $title.
	 * @param string $preloadId
	 * @param Title $title
	 * @return PendingEdit|false
	 */
	public function findPendingEdit( $preloadId, Title $title ) {
		$where = [
			'mod_preloadable' => ModerationVersionCheck::preloadableYes(),
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => ModerationVersionCheck::getModTitleFor( $title ),
			'mod_preload_id' => $preloadId
		];

		if ( ModerationVersionCheck::hasModType() ) {
			$where['mod_type'] = ModerationNewChange::MOD_TYPE_EDIT;
		}

		$row = $this->loadRow( $where,
			[
				'mod_id AS id',
				'mod_comment AS comment',
				'mod_text AS text'
			],
			# Sequential edits are often done with small intervals of time between
			# them, so we shouldn't wait for replication: DB_MASTER will be used.
			DB_MASTER,
			[ 'USE INDEX' => 'moderation_load' ]
		);
		if ( !$row ) {
			return false;
		}

		return new PendingEdit( (int)$row->id, $row->text, $row->comment );
	}

	/**
	 * Select $row from the "moderation" table by either its mod_id or $where array.
	 * @param int|array $where
	 * @param string[] $fields
	 * @param int $dbType DB_MASTER or DB_REPLICA
	 * @param array $options This parameter is passed to DB::select().
	 * @return object|false
	 */
	public function loadRow( $where, array $fields, $dbType = DB_MASTER, array $options = [] ) {
		if ( !is_array( $where ) ) {
			$where = [ 'mod_id' => $where ];
		}

		$db = wfGetDB( $dbType );
		$row = $db->selectRow( 'moderation', $fields, $where, __METHOD__, $options );
		if ( !$row ) {
			return false;
		}

		$row->id = (int)( $row->id ?? $where['mod_id'] ?? 0 );
		return $row;
	}

	/**
	 * Same as loadRow(), but throws an exception if the row wasn't found.
	 * @param int|array $where
	 * @param string[] $fields
	 * @param int $dbType DB_MASTER or DB_REPLICA
	 * @param array $options
	 * @return object
	 * @throws ModerationError
	 */
	public function loadRowOrThrow( $where, array $fields, $dbType = DB_MASTER,
		array $options = []
	) {
		$row = $this->loadRow( $where, $fields, $dbType, $options );
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		return $row;
	}
}
