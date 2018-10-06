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
 * File upload that awaits moderation.
 */

class ModerationEntryUpload extends ModerationApprovableEntry {
	/**
	 * Approve this upload.
	 * @return Status object.
	 */
	public function doApprove( User $moderator ) {
		$row = $this->getRow();
		$user = $this->getUser();

		# This is the upload from stash.

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );
		$upload = new UploadFromStash( $user, $stash );

		try {
			$upload->initialize( $row->stash_key, $this->getTitle()->getText() );
		} catch ( UploadStashFileNotFoundException $e ) {
			return Status::newFatal( 'moderation-missing-stashed-image' );
		}

		return $upload->performUpload( $row->comment, $row->text, 0, $user );
	}
}
