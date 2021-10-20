<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2021 Edward Chernenko.

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
 * Implements modaction=preview on [[Special:Moderation]].
 */

class ModerationActionPreview extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function requiresWrite() {
		return false;
	}

	public function outputResult( array $result, OutputPage $out ) {
		$out->setPageTitle( $this->msg(
			'moderation-preview-title',
			$result['title']
		) );
		$out->addHTML( $result['html'] );
		$out->addCategoryLinks( $result['categories'] );
	}

	public function execute() {
		$entry = $this->entryFactory->findViewableEntry( $this->id );
		$title = $entry->getTitle();

		$renderedRevision = $this->revisionRenderer->getRenderedRevision( $entry->getPendingRevision() );
		$pout = $renderedRevision->getRevisionParserOutput();

		return [
			'title' => $title->getPrefixedText(),
			'html' => $pout->getText( [ 'enableSectionEditLinks' => false ] ),
			'categories' => $pout->getCategories()
		];
	}
}
