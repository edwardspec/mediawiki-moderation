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
 * Implements modaction=preview on [[Special:Moderation]].
 */

class ModerationActionPreview extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function requiresWrite() {
		return false;
	}

	public function outputResult( array $result, OutputPage &$out ) {
		$out->setPageTitle( $this->msg(
			'moderation-preview-title',
			$result['title']
		) );
		$out->addHTML( $result['html'] );
		$out->addCategoryLinks( $result['categories'] );
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( 'moderation',
			[
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_text AS text'
			],
			[ 'mod_id' => $this->id ],
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );

		$content = ContentHandler::makeContent( $row->text, null, $title->getContentModel() );
		$pout = $content->getParserOutput( $title, 0, $this->getParserOptions(), true );

		return [
			'title' => $title->getPrefixedText(),
			'html' => $pout->getText( [ 'enableSectionEditLinks' => false ] ),
			'categories' => $pout->getCategories()
		];
	}

	/**
	 * Returns ParserOptions object to be used for parsing.
	 */
	protected function getParserOptions() {
		global $wgVersion;

		$popts = $this->getOutput()->parserOptions();
		if ( version_compare( $wgVersion, '1.31.0', '<' ) ) {
			/* Legacy approach, MediaWiki 1.30 and earlier */
			$popts->setEditSection( false );
		}
		return $popts;
	}
}
