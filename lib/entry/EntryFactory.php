<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
use MediaWiki\Content\IContentHandlerFactory;
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

	/** @var IContentHandlerFactory */
	protected $contentHandlerFactory;

	/**
	 * @param LinkRenderer $linkRenderer
	 * @param ActionLinkRenderer $actionLinkRenderer
	 * @param TimestampFormatter $timestampFormatter
	 * @param IConsequenceManager $consequenceManager
	 * @param ModerationCanSkip $canSkip
	 * @param ModerationApproveHook $approveHook
	 * @param IContentHandlerFactory $contentHandlerFactory
	 */
	public function __construct( LinkRenderer $linkRenderer,
		ActionLinkRenderer $actionLinkRenderer,
		TimestampFormatter $timestampFormatter,
		IConsequenceManager $consequenceManager,
		ModerationCanSkip $canSkip,
		ModerationApproveHook $approveHook,
		IContentHandlerFactory $contentHandlerFactory
	) {
		$this->linkRenderer = $linkRenderer;
		$this->actionLinkRenderer = $actionLinkRenderer;
		$this->timestampFormatter = $timestampFormatter;
		$this->consequenceManager = $consequenceManager;
		$this->canSkip = $canSkip;
		$this->approveHook = $approveHook;
		$this->contentHandlerFactory = $contentHandlerFactory;
	}

	/**
	 * Construct new ModerationEntryFormatter.
	 * @param \stdClass $row
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
	 * @param \stdClass $row
	 * @return ModerationViewableEntry
	 */
	public function makeViewableEntry( $row ) {
		return new ModerationViewableEntry(
			$row,
			$this->linkRenderer,
			$this->contentHandlerFactory
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
	 * @param \stdClass $row
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
	 * Get an array of ModerationApprovableEntry objects for all pending edits by $username,
	 * which is already sorted in an optimal order for ApproveAll operation.
	 * This purposely doesn't include non-pending edits (e.g. rejected edits that can be reapproved).
	 * @param string $username
	 * @return ModerationApprovableEntry[]
	 */
	public function findAllApprovableEntries( $username ) {
		$dbw = wfGetDB( DB_MASTER ); # Need latest data without lag

		$orderBy = [];

		# Page moves are approved last, so that situation
		# "user A (1) changed page B and (2) renamed B to C"
		# wouldn't result in newly created redirect B being edited instead of the page.
		$orderBy[] = 'mod_type=' . $dbw->addQuotes( ModerationNewChange::MOD_TYPE_MOVE );

		# Images are approved first. Otherwise the page can be rendered with the image redlink,
		# because the image didn't exist when the edit to this page was approved.
		$orderBy[] = 'mod_stash_key IS NULL';

		if ( $dbw->getType() == 'postgres' ) {
			# Earlier edits are approved first.
			# This is already a default sorting order for MySQL, so only PostgreSQL needs this.
			$orderBy[] = 'mod_id';
		}

		$res = $dbw->select( 'moderation',
			ModerationApprovableEntry::getFields(),
			[
				'mod_user_text' => $username,
				'mod_rejected' => 0, // Previously rejected edits are not approved by "Approve all"
				'mod_conflict' => 0 // No previously detected conflicts (they need manual merging).
			],
			__METHOD__,
			[
				'ORDER BY' => $orderBy,
				'USE INDEX' => 'moderation_approveall'
			]
		);
		// @codeCoverageIgnoreStart
		if ( !$res ) {
			// In practice (the way DB::select() is implemented) this never happens.
			return [];
		}
		// @codeCoverageIgnoreEnd

		$entries = [];
		foreach ( $res as $row ) {
			$entries[] = $this->makeApprovableEntry( $row );
		}

		return $entries;
	}

	/**
	 * Find an edit that awaits moderation and was made by user $preloadId in page $title.
	 * @param string $preloadId
	 * @param Title $title
	 * @return PendingEdit|false
	 */
	public function findPendingEdit( $preloadId, Title $title ) {
		$where = [
			'mod_preloadable' => 0,
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getDBKey(),
			'mod_preload_id' => $preloadId,
			'mod_type' => ModerationNewChange::MOD_TYPE_EDIT
		];

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

		return new PendingEdit( $title, (int)$row->id, $row->text, $row->comment );
	}

	/**
	 * Select $row from the "moderation" table by either its mod_id or $where array.
	 * @param int|array $where
	 * @param string[] $fields
	 * @param int $dbType DB_MASTER or DB_REPLICA
	 * @param array $options This parameter is passed to DB::select().
	 * @return \stdClass|false
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
	 * @return \stdClass
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
