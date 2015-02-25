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

	public function requiresEditToken() {
		return false;
	}

	public function send404ImageNotFound()
	{
		$this->mSpecial->getOutput()->disable(); # No HTML output
		StreamFile::prepareForStream(null, null, null, true); # send 404 Not Found
	}

	public function execute() {
		$out = $this->mSpecial->getOutput();

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'moderation',
			array(
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_stash_key AS stash_key'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if(!$row)
		{
			$out->addWikiMsg( 'moderation-show-not-found' );
			return;
		}

		$user = $row->user ?
			User::newFromId($row->user) :
			User::newFromName($row->user_text, false);

		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash($user);

		try {
			$file = $stash->getFile($row->stash_key);
		} catch(MWException $e) {
			return $this->send404ImageNotFound();
		}

		$is_thumb = $this->mSpecial->getRequest()->getVal('thumb');
		if(!$is_thumb)
		{
			$path = $file->getPath();
		}
		else
		{
			$file_new = null;
	
			$thumb = $file->transform(array('width' => 320), File::RENDER_NOW);
			if($thumb)
			{
				if($thumb->fileIsSource()) {
					$file_new = $file;
				}
				else {
					$path = $thumb->getStoragePath();
					if($path)
					{
						$file_new = new UnregisteredLocalFile(
							false,
							$stash->repo,
							$thumb->getStoragePath(),
							false
						);
					}
				}
			}

			if(!$file_new)
				return $this->send404ImageNotFound();

			$path = $file_new->getPath();
		}

		$out->disable(); # No HTML output (image only)
		$file->getRepo()->streamFile($path,
			array('Content-Transfer-Encoding: binary')
		);

	}
}
