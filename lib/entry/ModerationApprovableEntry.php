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
 * Parent class for all entry types (edit, upload, move, etc.).
 */

abstract class ModerationApprovableEntry extends ModerationEntry {
	/**
	 * Get the list of fields needed for selecting $row, as expected by newFromRow().
	 * @return array ($fields parameter for $db->select()).
	 */
	public static function getFields() {
		$fields = [
			'mod_id AS id',
			'mod_timestamp AS timestamp',
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_cur_id AS cur_id',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_comment AS comment',
			'mod_minor AS minor',
			'mod_bot AS bot',
			'mod_last_oldid AS last_oldid',
			'mod_ip AS ip',
			'mod_header_xff AS header_xff',
			'mod_header_ua AS header_ua',
			'mod_text AS text',
			'mod_merged_revid AS merged_revid',
			'mod_rejected AS rejected',
			'mod_stash_key AS stash_key'
		];

		if ( ModerationVersionCheck::areTagsSupported() ) {
			$fields[] = 'mod_tags AS tags';
		}

		if ( ModerationVersionCheck::hasModType() ) {
			$fields = array_merge( $fields, [
				'mod_type AS type',
				'mod_page2_namespace AS page2_namespace',
				'mod_page2_title AS page2_title'
			] );
		}

		return $fields;
	}

	/**
	 * Construct new ModerationApprovableEntry from $row.
	 * @throws ModerationError
	 */
	public static function newFromRow( $row ) {
		if ( isset( $row->type ) && $row->type == ModerationNewChange::MOD_TYPE_MOVE ) {
			return new ModerationEntryMove( $row );
		}

		if ( $row->stash_key ) {
			return new ModerationEntryUpload( $row );
		}

		return new ModerationEntryEdit( $row );
	}

	protected function getUser( $flags = 0 ) {
		/* User could have been recently renamed or deleted.
			Make sure we have the correct data when approving. */
		return parent::getUser( User::READ_LATEST );
	}

	/**
	 * Install hooks which affect postedit behavior of doEditContent().
	 */
	protected function installApproveHook() {
		$row = $this->getRow();
		$user = $this->getUser();

		ModerationApproveHook::install( $this->getTitle(), $user, $row->type, [
			# For CheckUser extension to work properly, IP, XFF and UA
			# should be set to the correct values for the original user
			# (not from the moderator)
			'ip' => $row->ip,
			'xff' => $row->header_xff,
			'ua' => $row->header_ua,
			'tags' => $row->tags,

			# Here we set the timestamp of this edit to $row->timestamp
			# (this is needed because doEditContent() always uses current timestamp).
			#
			# NOTE: timestamp in recentchanges table is not updated on purpose:
			# users would want to see new edits as they appear,
			# without the edits surprisingly appearing somewhere in the past.
			'timestamp' => $row->timestamp
		] );
	}

	/**
	 * Approve this change.
	 * @throws ModerationError
	 */
	final public function approve( User $moderator ) {
		$row = $this->getRow();

		/* Can this change be approved? */
		if ( $row->merged_revid ) {
			throw new ModerationError( 'moderation-already-merged' );
		}
		if ( $row->rejected && !$this->canReapproveRejected() ) {
			throw new ModerationError( 'moderation-rejected-long-ago' );
		}

		# Disable moderation hook (ModerationEditHooks::onPageContentSave),
		# so that it won't queue this edit again.
		ModerationCanSkip::enterApproveMode();

		# Install hooks to modify CheckUser database after approval, etc.
		$this->installApproveHook();

		# Do the actual approval.
		$status = $this->doApprove( $moderator );
		if ( !$status->isGood() ) {
			/* Uniform handling of errors from doEditContent(), etc.:
				throw the ModerationError exception */
			throw new ModerationError( $status->getMessage() );
		}

		# Create post-approval log entry ("successfully approved").
		$logEntry = new ManualLogEntry( 'moderation', $this->getApproveLogSubtype() );
		$logEntry->setPerformer( $moderator );
		$logEntry->setTarget( $this->getTitle() );
		$logEntry->setParameters( $this->getApproveLogParameters() );
		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		ModerationApproveHook::checkLogEntry( $logid, $logEntry );

		# Approved edits are removed from "moderation" table,
		# because they already exist in page history, recentchanges etc.

		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'moderation', [ 'mod_id' => $row->id ], __METHOD__ );
	}

	/**
	 * Post-approval log subtype. May be overridden in subclass.
	 * @return String (e.g. "approve" for "moderation/approve" log).
	 */
	protected function getApproveLogSubtype() {
		return 'approve';
	}

	/**
	 * Parameters for post-approval log.
	 * @return array
	 */
	protected function getApproveLogParameters() {
		return [ 'revid' => ModerationApproveHook::getLastRevId() ];
	}

	/**
	 * Approve this change.
	 * @return Status
	 */
	abstract public function doApprove( User $moderator );
}
