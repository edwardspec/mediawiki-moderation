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
 * Trait with setUp() that makes 1 row in "moderation" table and populates $this->modid.
 */

trait ModifyDbRowTestTrait {
	/** @var int */
	protected $modid;

	/** @var User */
	protected $authorUser;

	/**
	 * Create a row in "moderation" SQL table.
	 */
	public function setUp() {
		// @phan-suppress-next-line PhanTraitParentReference
		parent::setUp();

		$name = $this->getName();
		if ( $name == 'testValidCovers' || $name == 'testMediaWikiTestCaseParentSetupCalled' ) {
			return;
		}

		$this->authorUser = User::newFromName( "127.0.0.1", false );
		$title = Title::newFromText( "Some page" );
		$page = WikiPage::factory( $title );
		$content = ContentHandler::makeContent( 'Some text', null, CONTENT_MODEL_WIKITEXT );

		$change = new ModerationNewChange( $title, $this->authorUser );
		$this->modid = $change->edit( $page, $content, '', '' )->queue();
	}

	/* This abstract method is provided by PHPUnit-related classes. */

	abstract public function getName( $withDataSet = true );
}
