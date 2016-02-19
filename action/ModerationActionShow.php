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
	@brief Implements modaction=show on [[Special:Moderation]].
*/

class ModerationActionShow extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function execute() {
		$out = $this->mSpecial->getOutput();
		$out->addModuleStyles( 'mediawiki.action.history.diff' );

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'moderation',
			array(
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_last_oldid AS last_oldid',
				'mod_cur_id AS cur_id',
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_text AS text',
				'mod_stash_key AS stash_key'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if(!$row)
			throw new ModerationError('moderation-edit-not-found');

		$title = Title::makeTitle( $row->namespace, $row->title );
		$model = $title->getContentModel();

		$out->setPageTitle(wfMessage('difference-title', $title->getPrefixedText()));

		$old_content = false;
		if($row->cur_id != 0)
		{
			# Existing page
			$rev = Revision::newFromId($row->last_oldid);
			if($rev)
			{
				$old_content = $rev->getContent(Revision::RAW);
				$model = $old_content->getModel();
			}
		}

		if(!$old_content) # New or previously deleted page
			$old_content = ContentHandler::makeContent( "", null, $model );

		if($row->stash_key)
		{
			$url_params = array(
				'modaction' => 'showimg',
				'modid' => $this->id
			);
			$url_full = $this->mSpecial->getTitle()->getLinkURL($url_params);

			# Check if this file is not an image (e.g. OGG file)
			$is_image = 1;

			$user = $row->user ?
				User::newFromId($row->user) :
				User::newFromName($row->user_text, false);
			$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash($user);

			try {
				$meta = $stash->getMetadata($row->stash_key);

				if($meta['us_media_type'] != 'BITMAP' &&
					$meta['us_media_type'] != 'DRAWING')
				{
					$is_image = 0;
				}

			} catch(MWException $e) {
				# If we can't find it, thumbnail won't work either
				$is_image = 0;
			}

			if($is_image) {
				$url_params['thumb'] = 1;
				$url_thumb = $this->mSpecial->getTitle()->getLinkURL($url_params);
				$html_img = Xml::element('img', array(
					'src' => $url_thumb
				));
			}
			else {
				# Not an image, so no thumbnail is needed.
				# Just print a filename.
				$html_img = $title->getFullText();
			}

			$html_a = Xml::tags('a', array(
				'href' => $url_full
			), $html_img);

			$out->addHTML($html_a);
		}

		$de = ContentHandler::getForModelID($model)->createDifferenceEngine(
			$this->mSpecial->getContext(),
			$row->last_oldid, 0, 0, 0, 0
		);
		$diff = '';
		if(!$row->stash_key || !$title->exists()) # Not a reupload ($row->text is always blank for reuploads, and they don't change the page text)
		{
			$new_content = ContentHandler::makeContent( $row->text, null, $model );
			$diff = $de->generateContentDiffBody($old_content, $new_content);
		}

		if($diff)
		{
			// TODO: add more information into headers (username, timestamp etc.), as in usual diffs

			$header_before = wfMessage('moderation-diff-header-before')->text();
			$header_after = wfMessage('moderation-diff-header-after')->text();
			$out->addHTML($de->addHeader($diff, $header_before, $header_after));

			$approveLink = Linker::link(
				$this->getSpecial()->getPageTitle(),
				wfMessage('moderation-approve')->escaped(),
				array( 'title' => wfMessage('tooltip-moderation-approve')->escaped() ),
				array(
					'modaction' => 'approve',
					'modid' => $this->id,
					'token' => $this->getSpecial()->getUser()->getEditToken($this->id)
				),
				array('known', 'noclasses')
			);

			$rejectLink = Linker::link(
				$this->getSpecial()->getPageTitle(),
				wfMessage('moderation-reject')->escaped(),
				array( 'title' => wfMessage('tooltip-moderation-reject')->escaped() ),
				array(
					'modaction' => 'reject',
					'modid' => $this->id,
					'token' => $this->getSpecial()->getUser()->getEditToken($this->id)
				),
				array('known', 'noclasses')
			);

			$out->addHTML( $approveLink );
			$out->addHTML(' / ');
			$out->addHTML( $rejectLink );

		}
		else
		{
			$out->addWikiMsg($row->stash_key ?
				($title->exists() ? 'moderation-diff-reupload' : 'moderation-diff-upload-notext')
				: 'moderation-diff-no-changes');
		}
	}
}
