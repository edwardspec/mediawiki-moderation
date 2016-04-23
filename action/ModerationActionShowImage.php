<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2015 Edward Chernenko.

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
	@brief Implements modaction=showimg on [[Special:Moderation]].
*/

class ModerationActionShowImage extends ModerationAction {

	const THUMB_WIDTH = 320;

	public function requiresEditToken() {
		return false;
	}

	public function send404ImageNotFound() {
		$this->mSpecial->getOutput()->disable(); # No HTML output
		StreamFile::prepareForStream( null, null, null, true ); # send 404 Not Found
	}

	public function execute() {
		$out = $this->mSpecial->getOutput();

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'moderation',
			array(
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_title AS title',
				'mod_stash_key AS stash_key'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$user = $row->user ?
			User::newFromId( $row->user ) :
			User::newFromName( $row->user_text, false );

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );

		try {
			$file = $stash->getFile( $row->stash_key );
		} catch ( MWException $e ) {
			return $this->send404ImageNotFound();
		}

		$isThumb = $this->mSpecial->getRequest()->getVal( 'thumb' );
		if ( $isThumb ) {
			$thumb = $file->transform( array( 'width' => self::THUMB_WIDTH ), File::RENDER_NOW );
			if ( $thumb ) {
				if ( $thumb->fileIsSource() ) {
					$isThumb = false;
				} else {
					$file = new UnregisteredLocalFile(
						false,
						$stash->repo,
						$thumb->getStoragePath(),
						false
					);
				}
			}
		}

		if ( !$file ) {
			return $this->send404ImageNotFound();
		}

		$thumbFilename = '';
		if ( $isThumb ) {
			$thumbFilename .= $file->getWidth() .  'px-';
		}
		$thumbFilename .= $row->title;

		$headers = array();
		$headers[] = 'Content-Disposition: ' .
			FileBackend::makeContentDisposition( 'inline', $thumbFilename );

		$out->disable(); # No HTML output (image only)
		$file->getRepo()->streamFile( $file->getPath(), $headers );
	}
}
