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
	@file
	@brief Implements modaction=show on [[Special:Moderation]].
*/

class ModerationActionShow extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function requiresWrite() {
		return false;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addModuleStyles( 'mediawiki.action.history.diff' );
		$out->setPageTitle( wfMessage( 'difference-title', $result['title'] ) );

		if ( isset ( $result['image-thumb-html'] ) ) {
			$out->addHTML( Xml::tags( 'a', [
				'href' => $result['image-url'],
			], $result['image-thumb-html'] ) );
		}

		if ( isset ( $result['diff-html'] ) ) {
			$out->addHTML( $result['diff-html'] );
		}
		else {
			$out->addWikiMsg( $result['nodiff-reason'] );
		}

		$approveLink = SpecialModeration::makeModerationLink( 'approve', $this->id );
		$rejectLink = SpecialModeration::makeModerationLink( 'reject', $this->id );

		if ( !isset( $result['null-edit'] ) ) {
			$out->addHTML( $approveLink );
			$out->addHTML( ' / ' );
		}
		$out->addHTML( $rejectLink );
	}

	public function execute() {
		$result = [];

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'moderation',
			[
				'mod_user AS user',
				'mod_user_text AS user_text',
				'mod_last_oldid AS last_oldid',
				'mod_cur_id AS cur_id',
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_text AS text',
				'mod_stash_key AS stash_key'
			],
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );
		$model = $title->getContentModel();

		$oldContent = false;
		if ( $row->cur_id != 0 ) {
			# Existing page
			$rev = Revision::newFromId( $row->last_oldid );
			if ( $rev ) {
				$oldContent = $rev->getContent( Revision::RAW );
				$model = $oldContent->getModel();
			}
		}

		if ( !$oldContent ) { # New or previously deleted page
			$oldContent = ContentHandler::makeContent( '', null, $model );
		}

		if ( $row->stash_key ) {
			$urlParams = [
				'modaction' => 'showimg',
				'modid' => $this->id
			];
			$specialTitle = SpecialPage::getTitleFor( 'Moderation' );
			$urlFull = $specialTitle->getLinkURL( $urlParams );

			# Check if this file is not an image (e.g. OGG file)
			$isImage = 1;

			$user = $row->user ?
				User::newFromId( $row->user ) :
				User::newFromName( $row->user_text, false );
			$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );

			try {
				$meta = $stash->getMetadata( $row->stash_key );

				if (
					$meta['us_media_type'] != 'BITMAP' &&
					$meta['us_media_type'] != 'DRAWING'
				)
				{
					$isImage = 0;
				}

			} catch ( MWException $e ) {
				# If we can't find it, thumbnail won't work either
				$isImage = 0;
			}

			if ( $isImage ) {
				$urlParams['thumb'] = 1;
				$url_thumb = $specialTitle->getLinkURL( $urlParams );
				$htmlImg = Xml::element( 'img', [
					'src' => $url_thumb
				] );
			} else {
				# Not an image, so no thumbnail is needed.
				# Just print a filename.
				$htmlImg = $title->getFullText();
			}

			$result['image-url'] = $urlFull;
			$result['image-thumb-html'] = $htmlImg;
		}

		$de = ContentHandler::getForModelID( $model )->createDifferenceEngine(
			$this,
			$row->last_oldid, 0, 0, 0, 0
		);
		$diff = '';
		if ( !$row->stash_key || !$title->exists() ) { # Not a reupload ($row->text is always blank for reuploads, and they don't change the page text)
			$newContent = ContentHandler::makeContent( $row->text, null, $model );
			$diff = $de->generateContentDiffBody( $oldContent, $newContent );
		}

		if ( $diff ) {
			// TODO: add more information into headers (username, timestamp etc.), as in usual diffs

			$headerBefore = wfMessage( 'moderation-diff-header-before' )->text();
			$headerAfter = wfMessage( 'moderation-diff-header-after' )->text();

			$result['diff-html'] = $de->addHeader( $diff, $headerBefore, $headerAfter );
		} else {
			if ( $row->stash_key ) {
				$result['nodiff-reason'] = $title->exists() ?
					'moderation-diff-reupload' :
					'moderation-diff-upload-notext';
			}
			else {
				$result['nodiff-reason'] = 'moderation-diff-no-changes';
				$result['null-edit'] = '';
			}
		}

		$result['title'] = $title->getPrefixedText();
		return $result;
	}
}
