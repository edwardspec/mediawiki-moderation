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
 * Formatter for displaying entry in modaction=show.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\MediaWikiServices;

class ModerationViewableEntry extends ModerationEntry {
	/** @var LinkRenderer */
	protected $linkRenderer;

	/**
	 * @param object $row
	 * @param LinkRenderer $linkRenderer
	 */
	public function __construct( $row, LinkRenderer $linkRenderer ) {
		parent::__construct( $row );

		$this->linkRenderer = $linkRenderer;
	}

	/**
	 * Get the list of fields needed for selecting $row from database.
	 * @return array
	 */
	public static function getFields() {
		return array_merge( parent::getFields(), [
			'mod_last_oldid AS last_oldid',
			'mod_new AS new',
			'mod_text AS text',
			'mod_stash_key AS stash_key'
		] );
	}

	/**
	 * True if this is an upload, false otherwise.
	 * @return bool
	 */
	public function isUpload() {
		$row = $this->getRow();
		return $row->stash_key ? true : false;
	}

	/**
	 * Returns HTML of the diff.
	 * @param IContextSource $context Any object that contains current context.
	 * @return string
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
				$this->linkRenderer->makeLink( $title ),
				$this->linkRenderer->makeLink( $this->getPage2Title() )
			)->parseAsBlock();
		}

		$model = $title->getContentModel();
		$handler = ContentHandler::getForModelID( $model );

		$oldContent = $handler->makeEmptyContent();
		if ( !$row->new ) {
			// Page existed at the moment when this edit was queued
			$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
			$rec = $revisionLookup->getRevisionById( $row->last_oldid );

			// Note: $rec may be null if page was deleted.
			if ( $rec ) {
				// B/C: SlotRecord::MAIN wasn't defined in MW 1.31.
				$oldContent = $rec->getSlot( 'main', Revision::RAW )->getContent();
			}
		}

		$de = $handler->createDifferenceEngine( $context, $row->last_oldid, 0, 0, false, false );
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
	 * @return bool
	 */
	protected function isImage() {
		$row = $this->getRow();
		$stash = ModerationUploadStorage::getStash();

		try {
			$meta = $stash->getMetadata( $row->stash_key );
			$type = $meta['us_media_type'];
		} catch ( UploadStashException $_ ) {
			return false; /* File not found. */
		}

		return ( $type == 'BITMAP' || $type == 'DRAWING' );
	}

	/**
	 * Returns URL of modaction=showimg for this upload.
	 * @param bool $isThumb True for thumbnail, false for full-sized image.
	 * @return string
	 */
	public function getImageURL( $isThumb = false ) {
		$row = $this->getRow();

		$q = [
			'modaction' => 'showimg',
			'modid' => (string)$row->id
		];
		if ( $isThumb ) {
			$q['thumb'] = '1';
		}

		$specialTitle = SpecialPage::getTitleFor( 'Moderation' );
		return $specialTitle->getLinkURL( $q );
	}

	/**
	 * Returns HTML of the image thumbnail.
	 * @return string
	 */
	public function getImageThumbHTML() {
		if ( !$this->isUpload() ) {
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
