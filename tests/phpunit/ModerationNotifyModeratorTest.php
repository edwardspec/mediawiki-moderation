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
 * @brief Verifies that moderators see "New changes await moderation" notice.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationNotifyModerator
 */
class ModerationNotifyModeratorTest extends MediaWikiTestCase {
	public function testNotifyModerator() {
		$t = new ModerationTestsuite();
		$randomPageUrl = Title::newFromText( 'Can_Be_Any_Page' )->getFullURL();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		/* Notification "New changes await!" is shown to moderator on all pages... */
		$t->loginAs( $t->moderator );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification not found"
		);

		/* ... but not shown to non-moderators */
		$t->loginAs( $t->unprivilegedUser );
		$this->assertNull(
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification shown to non-moderator"
		);

		/* ... but not shown to moderators on Special:Moderation itself */
		$t->loginAs( $t->moderator );
		$t->fetchSpecial();

		$this->assertNull(
			$t->html->getNewMessagesNotice(), /* Look on the current page, which is Special:Moderation */
			"testNotifyModerator(): Notification shown when already on Special:Moderation"
		);

		/* ... and not shown to this moderator after Special:Moderation has already been visited */
		$this->assertNull(
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification still shown after Special:Moderation has " .
			"already been visited"
		);

		/* ... but still shown to another moderator */
		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification not shown to the second moderator"
		);

		/* ... if rejected by one moderator, not shown to another moderator. */
		$t->assumeFolderIsEmpty();
		$t->fetchSpecial();

		$t->httpGet( $t->new_entries[0]->rejectLink );
		$this->assertNull(
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification still shown after all changes were rejected"
		);

		/* ... same if approved. */
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->assumeFolderIsEmpty();
		$t->fetchSpecial();
		$t->httpGet( $t->new_entries[0]->approveLink );

		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertNull(
			$t->html->getNewMessagesNotice( $randomPageUrl ),
			"testNotifyModerator(): Notification still shown after all changes were approved"
		);
	}
}
