<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

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
 * Trait with setUp() that makes N rows in "moderation" table and populates $this->ids.
 */

/**
 * @method mixed getName($a=true)
 */
trait ModifySeveralDbRowsTestTrait {
	/** @var int[] Array of mod_id of existing rows in "moderation" table */
	protected $ids;

	/** @var User */
	protected $authorUser;

	/**
	 * How many rows to create.
	 * @return int
	 */
	public function getModerationRowsCount() {
		return 6;
	}

	/**
	 * Create several rows in "moderation" SQL table.
	 */
	public function setUp() : void {
		// @phan-suppress-next-line PhanTraitParentReference
		parent::setUp();

		$name = $this->getName();
		if ( $name == 'testValidCovers' || $name == 'testMediaWikiTestCaseParentSetupCalled' ) {
			return;
		}

		$this->authorUser = User::newFromName( "127.0.0.1", false );
		$content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );

		$ids = [];
		for ( $i = 0; $i < $this->getModerationRowsCount(); $i++ ) {
			$title = Title::newFromText( "Some page $i" );
			$page = WikiPage::factory( $title );

			$change = new ModerationNewChange( $title, $this->authorUser );
			$ids[] = $change->edit( $page, $content, '', '' )->queue();
		}

		$this->ids = $ids;
	}
}
