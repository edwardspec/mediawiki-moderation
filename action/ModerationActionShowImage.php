<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2018 Edward Chernenko.

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
 * Implements modaction=showimg on [[Special:Moderation]].
 */

class ModerationActionShowImage extends ModerationAction {

	const THUMB_WIDTH = 320;

	public function requiresEditToken() {
		return false;
	}

	public function requiresWrite() {
		return false;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->disable(); # No HTML output (image only)

		if ( isset( $result['missing'] ) ) {
			// Send 404 Not Found
			if ( method_exists( 'StreamFile', 'send404Message' ) ) { /* MediaWiki 1.28+ */
				StreamFile::send404Message( '' );
			} else { /* MediaWiki 1.27 */
				StreamFile::prepareForStream( null, null, null, true );
			}

			return;
		}

		$headers = [];
		$headers[] = 'Content-Disposition: ' .
			FileBackend::makeContentDisposition( 'inline', $result['thumb-filename'] );

		$repo = RepoGroup::singleton()->getLocalRepo();
		$repo->streamFileWithStatus( $result['thumb-path'], $headers );
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( 'moderation',
			[
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_title AS title',
				'mod_stash_key AS stash_key'
			],
			[ 'mod_id' => $this->id ],
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
			return [ 'missing' => '' ];
		}

		$isThumb = $this->getRequest()->getVal( 'thumb' );
		if ( $isThumb ) {
			$thumb = $file->transform( [ 'width' => self::THUMB_WIDTH ], File::RENDER_NOW );
			if ( $thumb ) {
				$storagePath = $thumb->getStoragePath();
				if ( $thumb->fileIsSource() || !$storagePath ) {
					$isThumb = false;
				} else {
					$file = new UnregisteredLocalFile(
						false,
						$stash->repo,
						$storagePath,
						false
					);
				}
			}
		}

		if ( !$file ) {
			return [ 'missing' => '' ];
		}

		$thumbFilename = '';
		if ( $isThumb ) {
			$thumbFilename .= $file->getWidth() . 'px-';
		}
		$thumbFilename .= $row->title;

		return [
			'thumb-path' => $file->getPath(),
			'thumb-filename' => $thumbFilename
		];
	}
}
