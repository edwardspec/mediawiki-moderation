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
 * Unit test of EntryFactory.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\TimestampFormatter;

require_once __DIR__ . "/autoload.php";

class EntryFactoryTest extends ModerationUnitTestCase {
	/**
	 * Test that EntryFactory can create a valid ModerationEntryFormatter.
	 * @covers MediaWiki\Moderation\EntryFactory
	 */
	public function testFactory() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$timestampFormatter = $this->createMock( TimestampFormatter::class );
		$context = $this->createMock( IContextSource::class );
		$sampleRow = (object)[ 'mod_id' => 12345, 'mod_title' => 'something' ];

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var ActionLinkRenderer $actionLinkRenderer';
		'@phan-var TimestampFormatter $timestampFormatter';
		'@phan-var IContextSource $context';

		$factory = new EntryFactory( $linkRenderer, $actionLinkRenderer, $timestampFormatter );

		$formatter = $factory->makeFormatter( $sampleRow, $context );
		$this->assertInstanceOf( ModerationEntryFormatter::class, $formatter );
	}
}
