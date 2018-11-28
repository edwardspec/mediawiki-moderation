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
	public function testModeratorIsNotified( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		/* Notification "New changes await!" is shown to moderator on all pages... */
		$t->loginAs( $t->moderator );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$this->getNotice( $t ),
			"Notification not shown to moderator"
		);
	}

	/**
	 * Ensure that notification is not shown to non-moderators.
	 **/
	public function testNonModeratorIsNotNotified( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->unprivilegedUser );
		$this->assertNull(
			$this->getNotice( $t ),
			"Notification shown to non-moderator"
		);
	}

	/**
	 * Ensure that "You have new messages" (which is more important) suppresses this notification.
	 */
	public function testNoNotificationIfHasMessages( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		// Make sure that moderator has "You have new messages" notification (from MediaWiki core)
		$t->loginAs( $t->automoderated );
		$t->doTestEdit( 'User_talk:' . $t->moderator->getName(), "Hello, moderator! ~~~~" );

		$t->loginAs( $t->moderator );

		$noticeText = $this->getNotice( $t );
		$this->assertNotEquals(
			'(moderation-new-changes-appeared)',
			$noticeText,
			"Notification shown even when moderator should get \"You have new messages\" instead"
		);

		return $noticeText; // Pass to testEchoHookCalledIfHasMessages()
	}

	/**
	 * Ensure that GetNewMessagesAlert hook of Extension:Echo is not suppressed
	 * when showing "You have new messages" instead of our notification.
	 * @depends testNoNotificationIfHasMessages
	 */
	public function testEchoHookCalledIfHasMessages( $noticeText ) {
		if ( !class_exists( 'EchoHooks' ) ) {
			$this->markTestSkipped( 'Test skipped: Echo extension must be installed to run it.' );
		}

		// Extension:Echo suppresses "You have new messages" notice in GetNewMessagesAlert hook,
		// so if the hook handlers were correctly invoked, then $noticeText will be null.
		$this->assertNull(
			$noticeText,
			"GetNewMessagesAlert hook handlers weren't called for \"You have new messages\" notice"
		);
	}

	/**
	 * Ensure that notification isn't shown on Special:Moderation itself.
	 */
	public function testNoNotificationOnSpecialModeration( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->fetchSpecial();

		$this->assertNull(
			$t->html->getNewMessagesNotice(), /* Look on the current page, which is Special:Moderation */
			"Notification shown when already on Special:Moderation"
		);
	}

	/**
	 * Ensure that notification isn't shown to moderator who already was on Special:Moderation.
	 */
	public function testNoNotificationAfterSpecialModeration( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->fetchSpecial(); // Open Special:Moderation

		$this->assertNull(
			$this->getNotice( $t ),
			"Notification still shown after Special:Moderation has already been visited"
		);
	}

	/**
	 * Ensure that visiting Special:Moderation doesn't hide the notice for other moderators.
	 */
	public function testNotificationHiddenOnlyForThisModerator( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->fetchSpecial(); // Moderator #1 opened Special:Moderation

		/* ... notification should still be shown to another moderator #2 */
		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertEquals(
			'(moderation-new-changes-appeared)',
			$this->getNotice( $t ),
			"Notification not shown to the second moderator"
		);
	}

	/**
	 * Ensure that other moderators aren't notified if this new change has already been rejected.
	 */
	public function testNoNotificationIfRejected( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->fetchSpecial();
		$t->httpGet( $t->new_entries[0]->rejectLink );

		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertNull(
			$this->getNotice( $t ),
			"Notification still shown after all changes were rejected"
		);
	}

	/**
	 * Ensure that other moderators aren't notified if this new change has already been approved.
	 */
	public function testNoNotificationIfApproved( ModerationTestsuite $t ) {
		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		$t->loginAs( $t->moderator );
		$t->fetchSpecial();
		$t->httpGet( $t->new_entries[0]->approveLink );

		$t->loginAs( $t->moderatorButNotAutomoderated );
		$this->assertNull(
			$this->getNotice( $t ),
			"Notification still shown after all changes were approved"
		);
	}

	/**
	 * Ensure that moderator is NOT notified about new changes in the Spam folder.
	 */
	public function testNoNotificationIfSpam( ModerationTestsuite $t ) {
		$t->modblock( $t->unprivilegedUser );

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit(); // This edit is rejected automatically

		/* Notification "New changes await!" is not shown */
		$t->loginAs( $t->moderator );
		$this->assertNull(
			$this->getNotice( $t ),
			"Notification was shown for change in Spam folder"
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
