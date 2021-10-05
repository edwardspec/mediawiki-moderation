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
 * Consequence that inserts new row into the "moderation" SQL table.
 */

namespace MediaWiki\Moderation;

use MediaWiki\MediaWikiServices;

class InsertRowIntoModerationTableConsequence implements IConsequence {
	/** @var array */
	protected $fields;

	/**
	 * @param array $fields
	 */
	public function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Execute the consequence.
	 * @return int mod_id of affected row.
	 */
	public function run() {
		$uniqueFields = [
			'mod_preloadable',
			'mod_namespace',
			'mod_title',
			'mod_preload_id',
			'mod_type'
		];

		$dbw = wfGetDB( DB_MASTER );

		$rrQuery = MediaWikiServices::getInstance()->getService( 'Moderation.RollbackResistantQuery' );
		$rrQuery->perform( function () use ( $dbw, $uniqueFields ) {
			$dbw->upsert(
				'moderation',
				$this->fields,
				[ $uniqueFields ],
				$this->fields,
				'InsertRowIntoModerationTableConsequence::run'
			);
		} );

		if ( $dbw->getType() == 'postgres' ) {
			// It's a bit of a shame to do an extra SELECT query just for that,
			// but we can't rely on $dbw->insertId() for PostgreSQL, because $dbw->upsert()
			// doesn't use native UPSERT, and instead does UPDATE and then INSERT IGNORE.
			// Since values of PostgreSQL sequences always increase (even after no-op
			// INSERT IGNORE), insertId() will return the sequence number after INSERT.
			// But if changes were caused by UPDATE (not by INSERT), then this number
			// won't be correct (we would want insertId() from UPDATE,
			// which is lost during $dbw->upsert()).
			//
			// A better solution would probably be to require PosgreSQL 9.5 or later and
			// use the native UPSERT (MediaWiki core doesn't do so, as it keeps backward
			// compatibility with PostgreSQL 9.2).
			$where = [];
			foreach ( $uniqueFields as $field ) {
				$where[$field] = $this->fields[$field];
			}

			return (int)$dbw->selectField( 'moderation', 'mod_id', $where, __METHOD__ );
		}

		return $dbw->insertId();
	}
}
