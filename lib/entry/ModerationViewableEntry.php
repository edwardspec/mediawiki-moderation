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
 * Formatter for displaying entry in modaction=show.
 */

class ModerationViewableEntry extends ModerationEntry {
	/**
	 * Get the list of fields needed for selecting $row, as expected by newFromRow().
	 * @return array ($fields parameter for $db->select()).
	 */
	public static function getFields() {
		$fields = [
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_last_oldid AS last_oldid',
			'mod_new AS new',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_text AS text',
			'mod_stash_key AS stash_key'
		];

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
	 * Returns HTML of the diff.
	 * @param IContextSource $context Any object that contains current context.
	 */
	public function getDiffHTML( IContextSource $context ) {
		$row = $this->getRow();
		$title = $this->getTitle();

		if ( $row->stash_key && $title->exists() ) {
			// Reupload ($row->text is always blank for reuploads,
			// and they don't change the page text)
			return '';
		}

		if ( $this->isMove() ) {
			// "Page A moved into B"
			return $context->msg( 'movepage-page-moved' )->rawParams(
				Linker::link( $title ),
				Linker::link( $this->getPage2Title() )
			)->parseAsBlock();
		}

		$model = $title->getContentModel();

		$oldContent = false;
		if ( !$row->new ) {
			# Page existed at the moment when this edit was queued
			$rev = Revision::newFromId( $row->last_oldid );
			if ( $rev ) {
				$oldContent = $rev->getContent( Revision::RAW );
				$model = $oldContent->getModel();
			}
		}

		if ( !$oldContent ) { # New or previously deleted page
			$oldContent = ContentHandler::makeContent( '', null, $model );
		}

		$de = ContentHandler::getForModelID( $model )->createDifferenceEngine(
			$context,
			$row->last_oldid, 0, 0, 0, 0
		);
		$newContent = ContentHandler::makeContent( $row->text, null, $model );

		$diff = $de->generateContentDiffBody( $oldContent, $newContent );
		if ( !$diff ) {
			return '';
		}

		// TODO: add more information into headers (username, timestamp etc.), as in usual diffs

		return $de->addHeader( $diff,
			$context->msg( 'moderation-diff-header-before' )->text(),
			$context->msg( 'moderation-diff-header-after' )->text()
		);
	}

	/**
	 * Returns false if this file is not an image (e.g. OGG file), true otherwise.
	 */
	protected function isImage() {
		$row = $this->getRow();

		$user = $this->getUser();
		$stash = RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );

		try {
			$meta = $stash->getMetadata( $row->stash_key );
			$type = $meta['us_media_type'];
		} catch ( UploadStashException $e ) {
			return false; /* File not found. */
		}

		return ( $type == 'BITMAP' || $type == 'DRAWING' );
	}

	/**
	 * Returns URL of modaction=showimg for this upload.
	 * @param bool $isThumb True for thumbnail, false for full-sized image.
	 */
	public function getImageURL( $isThumb = false ) {
		$row = $this->getRow();

		$q = [
			'modaction' => 'showimg',
			'modid' => $row->id
		];
		if ( $isThumb ) {
			$q['thumb'] = 1;
		}

		$specialTitle = SpecialPage::getTitleFor( 'Moderation' );
		return $specialTitle->getLinkURL( $q );
	}

	/**
	 * Returns HTML of the image thumbnail.
	 */
	public function getImageThumbHTML() {
		$row = $this->getRow();
		if ( !$row->stash_key ) {
			return ''; /* Not an upload */
		}

		if ( !$this->isImage() ) {
			# Not an image, so no thumbnail is needed.
			# Just print a filename.
			return $this->getTitle()->getFullText();
		}

		return Xml::element( 'img', [
			'src' => $this->getImageURL( true )
		] );
	}
}
