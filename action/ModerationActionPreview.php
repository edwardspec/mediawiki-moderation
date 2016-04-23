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
	@brief Implements modaction=preview on [[Special:Moderation]].
*/

class ModerationActionPreview extends ModerationAction {

	public function requiresEditToken() {
		return false;
	}

	public function execute() {
		$out = $this->mSpecial->getOutput();

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'moderation',
			array(
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_text AS text'
			),
			array( 'mod_id' => $this->id ),
			__METHOD__
		);
		if ( !$row ) {
			throw new ModerationError( 'moderation-edit-not-found' );
		}

		$title = Title::makeTitle( $row->namespace, $row->title );

		$popts = $out->parserOptions();
		$popts->setEditSection( false );

		$content = ContentHandler::makeContent( $row->text, null, $title->getContentModel() );
		$pout = $content->getParserOutput( $title, 0, $popts, true );

		$out->setPageTitle( wfMessage( 'moderation-preview-title', $title->getPrefixedText() ) );
		$out->addParserOutput( $pout );
	}
}
