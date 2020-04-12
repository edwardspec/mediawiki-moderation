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
 * Trait that helps to create ModerationAction object with all mocked dependencies.
 */

use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\IConsequenceManager;

/**
 * @method static \PHPUnit\Framework\MockObject\Rule\InvokedCount never()
 */
trait ActionTestTrait {
	/**
	 * Create ModerationAction using $setupMocks() callback, which receives all mocked dependencies.
	 * @param string $class Name of the ModerationAction subclass.
	 * @param callable|null $setupMocks Callback that can configure MockObject dependencies.
	 * @return ModerationAction
	 *
	 * @phan-param class-string $class
	 * @codingStandardsIgnoreStart
	 * @phan-param ?callable(RequestContext,PHPUnit\Framework\MockObject\MockObject,PHPUnit\Framework\MockObject\MockObject):void $setupMocks
	 * @codingStandardsIgnoreEnd
	 */
	private function makeActionForTesting( $class, callable $setupMocks = null ) {
		$context = new RequestContext();
		$entryFactory = $this->createMock( EntryFactory::class );
		$manager = $this->createMock( IConsequenceManager::class );

		$context->setLanguage( 'qqx' );

		if ( $setupMocks ) {
			$setupMocks( $context, $entryFactory, $manager );
		} else {
			// Since we are not configuring a mock of ConsequenceManager,
			// it means that we expect no consequences to be added.
			$manager->expects( $this->never() )->method( 'add' );
		}

		'@phan-var EntryFactory $entryFactory';
		'@phan-var IConsequenceManager $manager';

		return new $class( $context, $entryFactory, $manager );
	}

	// These methods are in MediaWikiTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function createMock( $originalClassName );
}
