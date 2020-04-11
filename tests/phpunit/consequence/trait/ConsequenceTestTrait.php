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
 * Trait that provides assertConsequencesEqual(), which is useful for Consequence tests.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\MockConsequenceManager;

/**
 * @method static \PHPUnit\Framework\MockObject\Rule\AnyInvokedCount any()
 * @codingStandardsIgnoreStart
 * @method static assertEquals($a, $b, string $message='', float $d=0.0, int $e=10, bool $f=false, bool $g=false)
 * @codingStandardsIgnoreEnd
 * @method static assertNotEquals($a, $b, string $message='', $d=0.0, $e=10, $f=false, $g=false)
 * @method static assertSame($a, $b, string $message='')
 * @method static assertCount(int $a, $b, string $message='')
 * @method static assertInstanceOf(string $a, $b, string $message='')
 * @method static TestUser getTestUser($groups=null)
 */
trait ConsequenceTestTrait {

	/**
	 * @var string|null
	 * Error message (e.g. "moderation-edit-conflict") thrown during getConsequences(), if any.
	 */
	protected $thrownError = null;

	/**
	 * @var string
	 * Text written into OutputPage object during getConsequences().
	 */
	protected $outputText = '';

	/**
	 * @var mixed
	 * Return value of $action->run() during during getConsequences().
	 */
	protected $result = null;

	/**
	 * @var User|null
	 * Moderator that will perform the action during getConsequences().
	 */
	protected $moderatorUser = null;

	/**
	 * Get an array of consequences after running $modaction on an edit that was queued in setUp().
	 * @param int $modid
	 * @param string $modaction
	 * @param array[]|null $mockedResults Parameters to pass to calls to $manager->mockResult().
	 * @param array $extraParams Additional HTTP request parameters when running ModerationAction.
	 * @return IConsequence[]
	 *
	 * @phan-param list<array{0:class-string,1:mixed}>|null $mockedResults
	 */
	public function getConsequences( $modid, $modaction,
		array $mockedResults = null, $extraParams = []
	) {
		if ( !$this->moderatorUser || !$this->moderatorUser->loadFromDatabase() ) {
			$this->moderatorUser =
				self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();
		}

		// Replace real ConsequenceManager with a mock.
		$manager = $this->mockConsequenceManager();

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $modid
		] + $extraParams );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( SpecialPage::getTitleFor( 'Moderation' ) );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

		// With "qqx" language selected, messages are replaced with
		// their names, so parsing process is translation-independent.
		$context->setLanguage( 'qqx' );

		if ( $mockedResults ) {
			foreach ( $mockedResults as $result ) {
				$manager->mockResult( ...$result );
			}
		}

		$this->thrownError = null;
		$this->result = null;
		$this->outputText = '';

		$out = $context->getOutput();
		$out->setContext( $context );

		try {
			$actionFactory = MediaWikiServices::getInstance()->getService( 'Moderation.ActionFactory' );
			$action = $actionFactory->makeAction( $context );
			$this->result = $action->run();
		} catch ( ModerationError $error ) {
			$this->thrownError = $error->status->getMessage()->getKey();
		} catch ( MWException $e ) {
			$this->thrownError = 'exceptionClass:' . get_class( $e );
		}

		$this->assertSame( '', $out->getHTML(),
			'ModerationAction::run() is not allowed to print anything, but it did.' );

		if ( $this->result ) {
			if ( $modaction == 'showimg' && isset( $this->result['missing'] ) ) {
				// This error is printed directly to stdout (not to OutputPage object),
				// so we need to test this elsewhere.
				// Should probably make HTTPFileStreamer::send404Message() into a Consequence.
				$this->outputText = '{{ HTTPFileStreamer::send404Message }}';
			} else {
				$action->outputResult( $this->result, $out );
				$this->outputText = Parser::stripOuterParagraph( $out->getHTML() );

				$this->assertNotEquals( '', $this->outputText,
					"ModerationAction::outputResult() didn't print anything." );
			}
		}

		return $manager->getConsequences();
	}

	/**
	 * Replace ApproveHook service with the mock that will return $revid from getLastRevId().
	 * @param int $revid
	 */
	public function mockApproveHook( $revid ) {
		$approveHook = $this->createMock( ModerationApproveHook::class );
		$approveHook->expects( $this->any() )->method( 'getLastRevId' )->willReturn( $revid );

		$this->setService( 'Moderation.ApproveHook', $approveHook );
	}

	/**
	 * Install new MockConsequenceManager for the duration of the test.
	 * @return MockConsequenceManager
	 */
	public function mockConsequenceManager() {
		$manager = new MockConsequenceManager;
		$this->setService( 'Moderation.ConsequenceManager', $manager );

		return $manager;
	}

	// This method is in MediaWikiTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function setService( $name, $service );

	/** @inheritDoc */
	abstract protected function createMock( $originalClassName );
}
