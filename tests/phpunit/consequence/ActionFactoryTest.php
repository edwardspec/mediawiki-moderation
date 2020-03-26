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
 * Unit test of ActionFactory.
 */

use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class ActionFactoryTest extends ModerationUnitTestCase {
	/**
	 * Test ModerationAction::factory() on all known actions,
	 * as well as requiresWrite() and requiresEditToken() of these actions.
	 * @param string $modaction
	 * @param string $expectedClass
	 * @param bool $requiresWrite
	 * @param bool $requiresEditToken
	 * @dataProvider dataProviderFactory
	 *
	 * @phan-param class-string $expectedClass
	 *
	 * @covers MediaWiki\Moderation\ActionFactory
	 * @covers ModerationAction
	 * @covers ModerationActionEditChange::requiresEditToken
	 * @covers ModerationActionPreview::requiresEditToken
	 * @covers ModerationActionPreview::requiresWrite
	 * @covers ModerationActionShowImage::requiresEditToken
	 * @covers ModerationActionShowImage::requiresWrite
	 * @covers ModerationActionShow::requiresEditToken
	 * @covers ModerationActionShow::requiresWrite
	 */
	public function testFactory( $modaction, $expectedClass, $requiresWrite, $requiresEditToken ) {
		$user = User::newFromName( '10.11.12.13', false );
		$modid = 12345;

		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $modid
		] ) );
		$context->setUser( $user );

		$action = ModerationAction::factory( $context );
		$this->assertInstanceof( $expectedClass, $action );

		$this->assertEquals( $requiresWrite, $action->requiresWrite(),
			'Incorrect return value of requiresWrite()' );
		$this->assertEquals( $requiresEditToken, $action->requiresEditToken(),
			'Incorrect return value of requiresEditToken()' );

		$this->assertEquals( $modaction, $action->actionName,
			'Incorrect value of $action->actionName' );
		$this->assertEquals( $user, $action->moderator,
			'Incorrect return value of $action->moderator' );

		$actionWrapper = TestingAccessWrapper::newFromObject( $action );
		$this->assertEquals( $modid, $actionWrapper->id,
			'Incorrect return value of requiresEditToken()' );
	}

	/**
	 * Test ModerationAction::factory() on unknown action.
	 * @covers MediaWiki\Moderation\ActionFactory
	 */
	public function testFactoryUnknownAction() {
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setRequest( new FauxRequest( [ 'modaction' => 'makesandwich' ] ) );

		$this->expectExceptionObject( new ModerationError( 'moderation-unknown-modaction' ) );
		ModerationAction::factory( $context );
	}

	/**
	 * Provide datasets for testFactory() runs.
	 * @return array
	 */
	public function dataProviderFactory() {
		return [
			'approveall' => [ 'approveall', ModerationActionApprove::class, true, true ],
			'approve' => [ 'approve', ModerationActionApprove::class, true, true ],
			'block' => [ 'block', ModerationActionBlock::class, true, true ],
			'editchange' => [ 'editchange', ModerationActionEditChange::class, true, false ],
			'editchangesubmit' =>
				[ 'editchangesubmit', ModerationActionEditChangeSubmit::class, true, true ],
			'merge' => [ 'merge', ModerationActionMerge::class, true, true ],
			'preview' => [ 'preview', ModerationActionPreview::class, false, false ],
			'rejectall' => [ 'rejectall', ModerationActionReject::class, true, true ],
			'reject' => [ 'reject', ModerationActionReject::class, true, true ],
			'show' => [ 'show', ModerationActionShow::class, false, false ],
			'showimg' => [ 'showimg', ModerationActionShowImage::class, false, false ],
			'unblock' => [ 'unblock', ModerationActionBlock::class, true, true ]
		];
	}
}
