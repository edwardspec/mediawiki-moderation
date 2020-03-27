<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2014-2019 Edward Chernenko.

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
		$row = $this->entryFactory->loadRowOrThrow( $this->id, [
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_text AS text'
		], DB_REPLICA );

		$title = Title::makeTitle( $row->namespace, $row->title );

		$content = ContentHandler::makeContent( $row->text, null, $title->getContentModel() );
		$popts = $this->getOutput()->parserOptions();

		$pout = $content->getParserOutput( $title, 0, $popts, true );

		return [
			'title' => $title->getPrefixedText(),
			'html' => $pout->getText( [ 'enableSectionEditLinks' => false ] ),
			'categories' => $pout->getCategories()
		];
	}
}
