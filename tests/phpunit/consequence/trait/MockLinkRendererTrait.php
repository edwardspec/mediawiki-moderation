<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2024 Edward Chernenko.

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
 * Trait that helps to mock the result of LinkRenderer::makeLink()
 */

use MediaWiki\Linker\LinkTarget;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Linker\LinkRendererFactory;

/**
 * @method static \PHPUnit\Framework\MockObject\Rule\InvokedCount any()
 * @method static \PHPUnit\Framework\MockObject\Rule\InvokedCount once()
 * @method static \PHPUnit\Framework\Constraint\IsIdentical identicalTo($a)
 */
trait MockLinkRenderer {
	/**
	 * Mocks LinkRendererFactory service to return $linkText instead of link to $title.
	 * @param LinkTarget $title
	 * @param $mockedText
	 */
	public function mockLinkRenderer( LinkTarget $title, $mockedText ) {
		// Mock LinkRendererFactory service to ensure that OutputPage::addReturnTo() added expected link.
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$linkRenderer->expects( $this->once() )->method( 'makeLink' )->with(
			$this->identicalTo( $title )
		)->willReturn( $mockedText );
		$linkRenderer->expects( $this->any() )->method( 'getLinkClasses' )->willReturn( '' );

		$lrFactory = $this->createMock( LinkRendererFactory::class );
		$lrFactory->expects( $this->any() )->method( 'create' )
			->willReturn( $linkRenderer );
		$lrFactory->expects( $this->any() )->method( 'createFromLegacyOptions' )
			->willReturn( $linkRenderer );
		$this->setService( 'LinkRendererFactory', $lrFactory );
	}

	// These methods are in MediaWikiIntegrationTestCase (this trait is used by its subclasses).

	/** @inheritDoc */
	abstract protected function setService( string $name, $service );

	/** @inheritDoc */
	abstract protected function createMock( string $originalClassName );
}
