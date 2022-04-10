<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2022 Edward Chernenko.

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
 * Trait that allows to mock ModerationAction object that will be returned by ActionFactory.
 */

use MediaWiki\Moderation\ActionFactory;

/**
 * @method static \PHPUnit\Framework\MockObject\Stub\ReturnCallback returnCallback($a)
 */
trait MockModerationActionTrait {
	/**
	 * Make a mock for ModerationAction class and make ActionFactory always return it.
	 * @param string $actionName
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function addMockedAction( $actionName ) {
		$actionMock = $this->getMockBuilder( ModerationAction::class )
			->disableOriginalConstructor()
			->disableProxyingToOriginalMethods()
			->setMethods( [ 'requiresEditToken', 'execute' ] )
			->getMockForAbstractClass();

		$factoryMock = $this->createMock( ActionFactory::class );
		$factoryMock->method( 'makeAction' )->will( $this->returnCallback(
			static function ( IContextSource $context ) use ( $actionMock, $actionName ) {
				if ( $context->getRequest()->getVal( 'modaction' ) !== $actionName ) {
					throw new MWException(
						"This mocked ActionFactory only supports modaction=$actionName." );
				}

				$actionMock->setContext( $context );
				return $actionMock;
			}
		) );

		$this->setService( 'Moderation.ActionFactory', $factoryMock );
		return $actionMock;
	}

	// These methods are in MediaWikiIntegrationTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function setService( $name, $service );

	/** @inheritDoc */
	abstract protected function createMock( $originalClassName );

	/** @inheritDoc */
	abstract protected function getMockBuilder( $originalClassName );
}
