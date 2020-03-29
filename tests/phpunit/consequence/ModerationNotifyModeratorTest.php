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
}
