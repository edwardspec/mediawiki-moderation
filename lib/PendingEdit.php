<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2020 Edward Chernenko.

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
 * Object that represents one preloadable edit that is currently awaiting moderation.
 */

namespace MediaWiki\Moderation;

use ModerationNewChange;
use ModerationVersionCheck;
use Title;

class PendingEdit {
	/**
	 * @var int
	 * mod_id of this pending edit.
	 */
	protected $id;

	/** @var string */
	protected $text;

	/** @var string */
	protected $comment;

	/**
	 * @param int $id
	 * @param string $text
	 * @param string $comment
	 */
	protected function __construct( $id, $text, $comment ) {
		$this->id = $id;
		$this->text = $text;
		$this->comment = $comment;
	}

	/**
	 * Get mod_id of this pending edit.
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Get text of this pending edit.
	 * @return string
	 */
	public function getText() {
		return $this->text;
	}

	/**
	 * Get edit summary of this pending edit.
	 * @return string
	 */
	public function getComment() {
		return $this->comment;
	}

	/**
	 * Find an edit that awaits moderation and was made by user $preloadId in page $title.
	 * @param string $preloadId
	 * @param Title $title
	 * @return PendingEdit|false
	 */
	public static function find( $preloadId, Title $title ) {
		$where = [
			'mod_preloadable' => ModerationVersionCheck::preloadableYes(),
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => ModerationVersionCheck::getModTitleFor( $title ),
			'mod_preload_id' => $preloadId
		];

		if ( ModerationVersionCheck::hasModType() ) {
			$where['mod_type'] = ModerationNewChange::MOD_TYPE_EDIT;
		}

		# Sequential edits are often done with small intervals of time between
		# them, so we shouldn't wait for replication: DB_MASTER will be used.
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation',
			[
				'mod_id AS id',
				'mod_comment AS comment',
				'mod_text AS text'
			],
			$where,
			__METHOD__,
			[ 'USE INDEX' => 'moderation_load' ]
		);
		if ( !$row ) {
			return false;
		}

		return new self( $row->id, $row->text, $row->comment );
	}
}
