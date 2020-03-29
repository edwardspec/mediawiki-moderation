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
 * Unit test of ModerationNotifyModerator.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\EntryFactory;

require_once __DIR__ . "/autoload.php";

class ModerationNotifyModeratorTest extends ModerationUnitTestCase {
	/**
	 * Test that invalidateCache() clears the cache of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testInvalidateCache() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-newest-pending-timestamp' )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'delete' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->invalidatePendingTime();
	}

	/**
	 * Test that setPendingTime() populates "getPendingTime cache" of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testSetPendingTime() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );

		$timestamp = '20100102030405';

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-newest-pending-timestamp' )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'set' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $timestamp ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 24 * 60 * 60 )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->setPendingTime( $timestamp );
	}

	/**
	 * Test that setPendingTime() populates "getSeen cache" of ModerationNotifyModerator.
	 * @covers ModerationNotifyModerator
	 */
	public function testSetSeen() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );
		$user = $this->createMock( User::class );

		$userId = 654321;
		$timestamp = '20100102030405';

		$user->expects( $this->once() )->method( 'getId' )->willReturn( $userId );

		$cache->expects( $this->once() )->method( 'makeKey' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 'moderation-seen-timestamp' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( "$userId" )
		)->willReturn( '{MockedCacheKey}' );
		$cache->expects( $this->once() )->method( 'set' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( '{MockedCacheKey}' ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $timestamp ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( 7 * 24 * 60 * 60 )
		);

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';
		'@phan-var User $user';

		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$notify->setSeen( $user, $timestamp );
	}

	/**
	 * Check the handler of "GetNewMessagesAlert" hook (which is called by MediaWiki core).
	 * @param array $newtalks Parameter of GetNewMessagesAlert hook.
	 * @param bool $expectShown If true, our notification is expected to be shown.
	 * @param bool|null $thirdPartyHookResult If not null, another hook handler will return this value.
	 * @dataProvider dataProviderNotifyHook
	 *
	 * @covers ModerationNotifyModerator
	 */
	public function testNotifyHook( array $newtalks, $expectShown, $thirdPartyHookResult ) {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$entryFactory = $this->createMock( EntryFactory::class );
		$cache = $this->createMock( BagOStuff::class );
		$user = $this->createMock( User::class );
		$out = $this->createMock( OutputPage::class );

		if ( $expectShown ) {
			$out->expects( $this->once() )->method( 'msg' )->with(
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$this->identicalTo( 'moderation-new-changes-appeared' )
			)->willReturn( new RawMessage( '{TextOfNotificationLink}' ) );

			$linkRenderer->expects( $this->once() )->method( 'makeLink' )->with(
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$this->IsInstanceOf( Title::class ),
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$this->identicalTo( '{TextOfNotificationLink}' )
			)->will( $this->returnCallback( function ( Title $title, $text ) {
				$this->assertTrue( $title->isSpecial( 'Moderation' ),
					"Link in the notification doesn't point to Special:Moderation." );
				return '{NotificationLink}';
			} ) );
		}

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var EntryFactory $entryFactory';
		'@phan-var BagOStuff $cache';
		'@phan-var User $user';
		'@phan-var OutputPage $out';

		// This test checks whether this method correctly works as a hook, so install it as a hook.
		$notify = new ModerationNotifyModerator( $linkRenderer, $entryFactory, $cache );
		$this->setService( 'Moderation.NotifyModerator', $notify );
		$this->setTemporaryHook( 'GetNewMessagesAlert',
			'ModerationNotifyModerator::onGetNewMessagesAlert' );

		$newMessagesAlert = '{AnotherExistingNotification}';

		if ( $thirdPartyHookResult !== null ) {
			// Install third-party handler of GetNewMessagesAlert hook,
			// simulating situation when another extension (like Echo) has such a handler.
			$hookArgs = [ &$newMessagesAlert, $newtalks, $user, $out ];
			$this->setTemporaryHook( ModerationNotifyModerator::SAVED_HOOK_NAME,
				function ( &$newMessagesAlert2, array $newtalks2,
					User $user2, OutputPage $out2 ) use ( $hookArgs, $thirdPartyHookResult )
				{
					$this->assertSame( $hookArgs[0], $newMessagesAlert2 );
					$this->assertSame( $hookArgs[1], $newtalks2 );
					$this->assertSame( $hookArgs[2], $user2 );
					$this->assertSame( $hookArgs[3], $out2 );

					$newMessagesAlert2 = '{Notification From Third-Party Hook}';

					// Additionally test that return value of this third-party hook
					// is returned by ModerationNotifyModerator::onGetNewMessagesAlert().
					return $thirdPartyHookResult;
				}
			);
		}

		$result = Hooks::run( 'GetNewMessagesAlert',
			[ &$newMessagesAlert, $newtalks, $user, $out ] );

		$this->assertEquals( $thirdPartyHookResult ?? true, $result,
			"Return value of GetNewMessagesAlert hook doesn't match expected." );

		if ( $expectShown ) {
			$this->assertSame( '{AnotherExistingNotification}{NotificationLink}', $newMessagesAlert,
				"Our notification wasn't added." );
		} else {
			if ( $thirdPartyHookResult === null ) {
				$this->assertSame( '{AnotherExistingNotification}', $newMessagesAlert,
					"Text of \$newMessagesAlert was modified when we didn't intend to add our notification." );
			} else {
				$this->assertSame( '{Notification From Third-Party Hook}', $newMessagesAlert,
					"Text of \$newMessagesAlert wasn't modified by third-party hook." );
			}
		}
	}

	/**
	 * Provide datasets for testNotifyHook() runs.
	 * @return array
	 */
	public function dataProviderNotifyHook() {
		return [

			// Situation 1: "You have new messages" notification (from user's talkpage) doesn't exist.
			// Then ModerationNotifyModerator::onGetNewMessagesAlert() must add its own notification.
			'no "You have new messages", should show our notification' => [ [], true, null ],

			// Situation 2: "You have new messages" notification already exists.
			// Because it is more important than "new changes are awaiting moderation",
			// in this case we purposely don't show notification of ModerationNotifyModerator.
			'existing "You have new messages" should suppress our notification' =>
				[ [ 'something' => 'here' ], false, null ],

			// Situation 3: both "You have new messages" AND third-party handlers of this hook exist
			// (for example, Extension:Echo adds a handler of GetNewMessagesAlert hook too), and they
			// were saved under ModerationNotifyModerator::SAVED_HOOK_NAME by onBeforeInitialize.
			// In this case these saved handlers should be called,
			// and their return value (true or false) should be returned by our own handler.
			'existing "You have new messages", no notification. Third-party hook returns true' =>
				[ [ 'something' => 'here' ], false, true ],
			'existing "You have new messages",  no notification. Third-party hook returns false' =>
				[ [ 'something' => 'here' ], false, false ]
		];
	}
}
