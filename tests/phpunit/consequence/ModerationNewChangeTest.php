<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2021 Edward Chernenko.

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
 * Unit test of ModerationNewChange.
 */

use MediaWiki\Moderation\Hook\HookRunner;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class ModerationNewChangeTest extends ModerationUnitTestCase {

	/**
	 * Check values of all fields after ModerationNewChange object has just been constructed.
	 * @param bool $isBlocked True if the user is marked as spammer, false otherwise.
	 * @dataProvider dataProviderConstructor
	 * @covers ModerationNewChange
	 */
	public function testConstructor( $isBlocked ) {
		$title = Title::makeTitle( NS_HELP_TALK, 'UTPage-' . rand( 0, 100000 ) );
		$user = self::getTestUser()->getUser();
		$preloadId = '{MockedPreloadId}';

		$change = $this->makeNewChange( $title, $user,
			function ( $consequenceManager, $preload, $hookRunner, $notifyModerator, $blockCheck )
			use ( $isBlocked, $preloadId, $user ) {
				$blockCheck->expects( $this->any() )->method( 'isModerationBlocked' )->willReturn( $isBlocked );
				$preload->expects( $this->once() )->method( 'setUser' )->with(
					$this->identicalTo( $user )
				);
				$preload->expects( $this->once() )->method( 'getId' )->with(
					$this->identicalTo( true )
				)->willReturn( $preloadId );
			}
		);

		$this->assertSame( $user->getId(), $change->getField( 'mod_user' ) );
		$this->assertSame( $user->getName(), $change->getField( 'mod_user_text' ) );
		$this->assertSame( $title->getNamespace(), $change->getField( 'mod_namespace' ) );
		$this->assertSame( $title->getDBKey(), $change->getField( 'mod_title' ) );
		$this->assertSame( $preloadId, $change->getField( 'mod_preload_id' ) );
		$this->assertSame( 'edit', $change->getField( 'mod_type' ) );

		$this->assertSame( '', $change->getField( 'mod_comment' ) );
		$this->assertSame( '', $change->getField( 'mod_text' ) );
		$this->assertSame( '', $change->getField( 'mod_page2_title' ) );

		$this->assertSame( 0, $change->getField( 'mod_cur_id' ) );
		$this->assertSame( 0, $change->getField( 'mod_minor' ) );
		$this->assertSame( 0, $change->getField( 'mod_bot' ) );
		$this->assertSame( 0, $change->getField( 'mod_new' ) );
		$this->assertSame( 0, $change->getField( 'mod_last_oldid' ) );
		$this->assertSame( 0, $change->getField( 'mod_old_len' ) );
		$this->assertSame( 0, $change->getField( 'mod_new_len' ) );
		$this->assertSame( 0, $change->getField( 'mod_rejected_by_user' ) );
		$this->assertSame( 0, $change->getField( 'mod_rejected_batch' ) );
		$this->assertSame( 0, $change->getField( 'mod_preloadable' ) );
		$this->assertSame( 0, $change->getField( 'mod_conflict' ) );
		$this->assertSame( 0, $change->getField( 'mod_merged_revid' ) );
		$this->assertSame( 0, $change->getField( 'mod_page2_namespace' ) );

		$this->assertSame( $isBlocked ? 1 : 0, $change->getField( 'mod_rejected' ) );
		$this->assertSame( $isBlocked ? 1 : 0, $change->getField( 'mod_rejected_auto' ) );

		$this->assertNull( $change->getField( 'mod_stash_key' ) );

		if ( $isBlocked ) {
			// TODO: mod_rejected_by_user_text
		} else {
			$this->assertNull( $change->getField( 'mod_rejected_by_user_text' ) );
		}

		// TODO: mod_timestamp, mod_ip, mod_header_xff, mod_header_ua
	}

	/**
	 * Provide datasets for testConstructor() runs.
	 * @return array
	 */
	public function dataProviderConstructor() {
		return [
			'not a spammer' => [ false ],
			'marked as spammer' => [ true ]
		];
	}

	/**
	 * Check that setters like setBot() modify the necessary database fields.
	 * @param string $method One of "setMinor", "setBot" or "setSummary".
	 * @param string $dbKeyField Database field name, e.g. "mod_comment".
	 * @param array $testValues Map: [ argumentToSetter => expectedDatabaseFieldValue ].
	 * @dataProvider dataProviderSetters
	 * @covers ModerationNewChange
	 */
	public function testSetters( $method, $dbKeyField, $testValues ) {
		$change = $this->makeNewChange();

		foreach ( $testValues as $argument => $expectedFieldValue ) {
			$change->$method( $argument );
			$this->assertSame( $expectedFieldValue, $change->getField( $dbKeyField ),
				"Field $dbKeyField is not $expectedFieldValue after $method($argument)." );
		}
	}

	/**
	 * Provide datasets for testSetters() runs.
	 * @return array
	 */
	public function dataProviderSetters() {
		return [
			'setMinor()' => [ 'setMinor', 'mod_minor', [ true => 1, false => 0, true => 1 ] ],
			'setBot()' => [ 'setBot', 'mod_bot', [ true => 1, false => 0, true => 1 ] ],
			'setSummary()' => [ 'setSummary', 'mod_comment', [
				'Some comment' => 'Some comment',
				'Another comment' => 'Another comment',
				'' => ''
			] ],
		];
	}

	/**
	 * Check that move() sets the necessary database fields.
	 * @covers ModerationNewChange
	 */
	public function testMove() {
		$newTitle = Title::makeTitle( NS_PROJECT, 'UTRenamedPage-' . rand( 0, 100000 ) );

		$change = $this->makeNewChange( null, null, null, [ 'addChangeTags' ] );
		$change->expects( $this->once() )->method( 'addChangeTags' )->with(
			$this->identicalTo( 'move' )
		);

		// Run the tested method.
		$change->move( $newTitle );

		$this->assertSame( 'move', $change->getField( 'mod_type' ) );
		$this->assertSame( $newTitle->getNamespace(), $change->getField( 'mod_page2_namespace' ) );
		$this->assertSame( $newTitle->getDBKey(), $change->getField( 'mod_page2_title' ) );
	}

	/**
	 * Check that upload() sets the necessary database fields.
	 * @covers ModerationNewChange
	 */
	public function testUpload() {
		$change = $this->makeNewChange( null, null, null, [ 'addChangeTags' ] );
		$change->expects( $this->once() )->method( 'addChangeTags' )->with(
			$this->identicalTo( 'upload' )
		);

		$stashKey = 12345;

		// Run the tested method.
		$change->upload( $stashKey );

		$this->assertSame( $stashKey, $change->getField( 'mod_stash_key' ) );
	}

	/**
	 * Check that addChangeTags() sets the necessary database fields.
	 * @param string $expectedFieldValue Correct value of mod_tags field.
	 * @param string[] $foundTags Mocked return value of findAbuseFilterTags().
	 * @dataProvider dataProviderAddChangeTags
	 * @covers ModerationNewChange
	 */
	public function testAddChangeTags( $expectedFieldValue, array $foundTags ) {
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$user = self::getTestUser()->getUser();
		$action = 'makesandwich';

		$change = $this->makeNewChange( $title, $user, null, [ 'findAbuseFilterTags' ] );
		$change->expects( $this->once() )->method( 'findAbuseFilterTags' )->with(
			$this->identicalTo( $title ),
			$this->identicalTo( $user ),
			$this->identicalTo( $action )
		)->willReturn( $foundTags );

		// Run the tested method.
		$wrapper = TestingAccessWrapper::newFromObject( $change );
		$wrapper->addChangeTags( $action );

		$this->assertSame( $expectedFieldValue, $change->getField( 'mod_tags' ) );
	}

	/**
	 * Provide datasets for testAddChangeTags() runs.
	 * @return array
	 */
	public function dataProviderAddChangeTags() {
		return [
			'no tags' => [ null, [] ],
			'one tag' => [ 'Only tag', [ 'Only tag' ] ],
			'many tags' => [ "First tag\nTag #2\nThird tag", [ 'First tag', 'Tag #2', 'Third tag' ] ]
		];
	}

	/**
	 * Check that queue() performs expected actions.
	 * @covers ModerationNewChange
	 */
	public function testQueue() {
		$modid = 12345;
		$mockedFields = [ 'mod_field' => 'some value', 'mod_another_field' => 'another value' ];

		$change = $this->makeNewChange( null, null,
			function ( $consequenceManager, $preload, $hookRunner ) use ( $modid, $mockedFields ) {
				$hookRunner->expects( $this->once() )->method( 'onModerationPending' )->with(
					$this->identicalTo( $mockedFields ),
					$this->identicalTo( $modid )
				);
			},
			[ 'insert', 'notify', 'getFields' ]
		);
		$change->expects( $this->once() )->method( 'insert' )->willReturn( $modid );
		$change->expects( $this->once() )->method( 'getFields' )->willReturn( $mockedFields );
		$change->expects( $this->once() )->method( 'notify' )->with(
			$this->identicalTo( $modid )
		);

		// Run the tested method.
		$result = $change->queue();

		$this->assertSame( $modid, $result );
	}

	/**
	 * Check that insert() performs expected actions.
	 * @covers ModerationNewChange
	 */
	public function testInsert() {
		$modid = 12345;
		$mockedFields = [ 'mod_field' => 'some value', 'mod_another_field' => 'another value' ];

		$change = $this->makeNewChange( null, null,
			function ( $consequenceManager ) use ( $modid, $mockedFields ) {
				$consequenceManager->expects( $this->once() )->method( 'add' )->with(
					$this->consequenceEqualTo( new InsertRowIntoModerationTableConsequence( $mockedFields ) )
				)->willReturn( $modid );
			},
			[ 'getFields' ]
		);
		$change->expects( $this->once() )->method( 'getFields' )->willReturn( $mockedFields );

		// Run the tested method.
		$wrapper = TestingAccessWrapper::newFromObject( $change );
		$result = $wrapper->insert();

		$this->assertSame( $modid, $result );
	}

	/**
	 * Check that notify() performs expected actions.
	 * @covers ModerationNewChange
	 */
	public function testNotify() {
		$modid = 12345;
		$timestamp = '20100101001600';
		$mockedFields = [ 'mod_field' => 'some value', 'mod_another_field' => 'another value' ];

		$change = $this->makeNewChange( null, null,
			function ( $consequenceManager, $preload, $hookRunner, $notifyModerator ) use ( $timestamp ) {
				$notifyModerator->expects( $this->once() )->method( 'setPendingTime' )->with(
					$this->identicalTo( $timestamp )
				);
			},
			[ 'getField', 'sendNotificationEmail' ]
		);

		$change->method( 'getField' )->will( $this->returnValueMap( [
			[ 'mod_rejected_auto', 0 ],
			[ 'mod_timestamp', $timestamp ]
		] ) );

		$change->expects( $this->once() )->method( 'sendNotificationEmail' )->with(
			$this->identicalTo( $modid )
		);

		// Run the tested method.
		$wrapper = TestingAccessWrapper::newFromObject( $change );
		$wrapper->notify( $modid );
	}

	/**
	 * Check that notify() doesn't do anything if mod_rejected_auto=1.
	 * @covers ModerationNewChange
	 */
	public function testNotifySkippedRejectedAuto() {
		$modid = 12345;
		$timestamp = '20100101001600';
		$mockedFields = [ 'mod_field' => 'some value', 'mod_another_field' => 'another value' ];

		$change = $this->makeNewChange( null, null,
			function ( $consequenceManager, $preload, $hookRunner, &$notifyModerator ) {
				$notifyModerator = $this->createNoopMock( ModerationNotifyModerator::class );
			},
			[ 'getField', 'sendNotificationEmail' ]
		);
		$change->expects( $this->once() )->method( 'getField' )->with(
			$this->identicalTo( 'mod_rejected_auto' )
		)->willReturn( 1 );

		$change->expects( $this->never() )->method( 'sendNotificationEmail' );

		// Run the tested method.
		$wrapper = TestingAccessWrapper::newFromObject( $change );
		$wrapper->notify( $modid );
	}

	/**
	 * Create ModerationNewChange using callback that receives all mocked dependencies.
	 * @param Title|null $title
	 * @param User|null $user
	 * @param callable|null $setupMocks Callback that can configure MockObject dependencies.
	 * @param string[] $methods Array of method names to mock (for MockBuilder::setMethods()).
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function makeNewChange( Title $title = null, User $user = null,
		callable $setupMocks = null, array $methods = []
	) {
		$manager = $this->createMock( IConsequenceManager::class );
		$blockCheck = $this->createMock( ModerationBlockCheck::class );
		$mocks = [
			$manager,
			$this->createMock( ModerationPreload::class ),
			$this->createMock( HookRunner::class ),
			$this->createMock( ModerationNotifyModerator::class ),
			$this->createMock( ModerationBlockCheck::class ),
			$this->createMock( Language::class )
		];

		$arguments = [
			$title ?? Title::newFromText( 'UTPage-' . rand( 0, 100000 ) ),
			$user ?? self::getTestUser()->getUser()
		];
		array_push( $arguments, ...$mocks );

		if ( $setupMocks ) {
			$setupMocks( ...$mocks );
		} else {
			// Since we are not configuring a mock of ConsequenceManager,
			// it means that we expect no consequences to be added.
			$manager->expects( $this->never() )->method( 'add' );
			$blockCheck->expects( $this->any() )->method( 'isModerationBlocked' )->willReturn( false );
		}

		return $this->getMockBuilder( ModerationNewChange::class )
			->setConstructorArgs( $arguments )
			->onlyMethods( $methods )
			->getMock();
	}
}
