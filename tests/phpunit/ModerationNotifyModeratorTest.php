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
 * Verifies that moderators see "New changes await moderation" notice.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationNotifyModerator
 */
class ModerationNotifyModeratorTest extends ModerationTestCase {
	/**
	 * Ensure that moderator is notified about new pending changes.
	 */
	public function testNotifyModerator() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		/* Notification "New changes await!" is shown to moderator on all pages... */
		$t->loginAs( $t->moderator );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$this->getNotice( $t ),
			"testNotifyModerator(): Notification not found"
		);

		/* ... but not shown to non-moderators */
		$t->loginAs( $t->unprivilegedUser );
		$this->assertNull(
			$this->getNotice( $t ),
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
			$this->getNotice( $t ),
			"testNotifyModerator(): Notification still shown after Special:Moderation has " .
			"already been visited"
		);

		/* ... but still shown to another moderator */
		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$this->getNotice( $t ),
			"testNotifyModerator(): Notification not shown to the second moderator"
		);

		/* ... if rejected by one moderator, not shown to another moderator. */
		$t->assumeFolderIsEmpty();
		$t->fetchSpecial();

		$t->httpGet( $t->new_entries[0]->rejectLink );
		$this->assertNull(
			$this->getNotice( $t ),
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
			$this->getNotice( $t ),
			"testNotifyModerator(): Notification still shown after all changes were approved"
		);
	}

	/**
	 * Ensure that moderator is NOT notified about new changes in the Spam folder.
	 */
	public function testNotifyModeratorExceptSpam() {
		$t = new ModerationTestsuite();
		$t->modblock( $t->unprivilegedUser );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit(); // This edit is rejected automatically

		/* Notification "New changes await!" is not shown */
		$t->loginAs( $t->moderator );
		$this->assertNull(
			$this->getNotice( $t ),
			"testNotifyModeratorExceptSpam(): Notification was shown for change in Spam folder."
		);
	}

	/**
	 * Find NewMessages notice in HTML of some randomly chosen page.
	 * @return DomElement|null
	 */
	protected function getNotice( ModerationTestsuite $t ) {
		$randomPageUrl = Title::newFromText( 'Can_Be_Any_Page' )->getFullURL();
		return $t->html->getNewMessagesNotice( $randomPageUrl );
	}
}
