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
 * Implements modaction=show on [[Special:Moderation]].
 */

class ModerationActionShow extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function requiresWrite() {
		return false;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->addModuleStyles( [
			'mediawiki.diff.styles', // MediaWiki 1.28+
			'mediawiki.action.history.diff' // legacy MediaWiki 1.27 (LTS)
		] );
		$out->setPageTitle( $this->msg( 'difference-title', $result['title'] ) );

		if ( isset( $result['image-thumb-html'] ) ) {
			$out->addHTML( Xml::tags( 'a', [
				'href' => $result['image-url'],
			], $result['image-thumb-html'] ) );
		}

		if ( isset( $result['diff-html'] ) ) {
			$out->addHTML( $result['diff-html'] );
		} else {
			$out->addWikiMsg( $result['nodiff-reason'] );
		}

		$approveLink = ModerationEntryFormatter::makeModerationLink( 'approve', $this->id );
		$rejectLink = ModerationEntryFormatter::makeModerationLink( 'reject', $this->id );

		if ( !isset( $result['null-edit'] ) ) {
			$out->addHTML( $approveLink );
			$out->addHTML( ' / ' );
		}
		$out->addHTML( $rejectLink );
	}

	public function execute() {
		$result = [];

		$entry = ModerationViewableEntry::newFromId( $this->id, DB_REPLICA );
		$title = $entry->getTitle();

		if ( $entry->isUpload() ) {
			$result['image-url'] = $entry->getImageURL();
			$result['image-thumb-html'] = $entry->getImageThumbHTML();
		}

		$diff = $entry->getDiffHTML( $this->getContext() );
		if ( $diff ) {
			$result['diff-html'] = $diff;
		} else {
			if ( $entry->isUpload() ) {
				$result['nodiff-reason'] = $title->exists() ?
					'moderation-diff-reupload' :
					'moderation-diff-upload-notext';
			} else {
				$result['nodiff-reason'] = 'moderation-diff-no-changes';
				$result['null-edit'] = '';
			}
		}

		$result['title'] = $title->getPrefixedText();
		return $result;
	}
}
