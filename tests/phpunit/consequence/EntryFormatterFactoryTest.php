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
 * Unit test of EntryFormatterFactory.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\EntryFormatterFactory;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class EntryFormatterFactoryTest extends ModerationUnitTestCase {
	/**
	 * Test that EntryFormatterFactory can create a valid ModerationEntryFormatter.
	 * @covers MediaWiki\Moderation\EntryFormatterFactory
	 * @covers ModerationEntryFormatter::create
	 */
	public function testFactory() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$context = $this->createMock( IContextSource::class );
		$sampleRow = (object)[ 'mod_id' => 12345, 'mod_title' => 'something' ];

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var ActionLinkRenderer $actionLinkRenderer';
		'@phan-var IContextSource $context';

		$factory = new EntryFormatterFactory( $linkRenderer, $actionLinkRenderer );

		$formatter = $factory->makeFormatter( $sampleRow, $context );
		$this->assertInstanceOf( ModerationEntryFormatter::class, $formatter );

		$wrapper = TestingAccessWrapper::newFromObject( $formatter );
		$this->assertSame( $sampleRow, $wrapper->row );
		$this->assertSame( $context, $wrapper->context );
		$this->assertSame( $linkRenderer, $wrapper->linkRenderer );
		$this->assertSame( $actionLinkRenderer, $wrapper->actionLinkRenderer );
	}
}
