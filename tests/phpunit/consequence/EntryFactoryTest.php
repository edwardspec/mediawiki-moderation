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
	 * Test that EntryFactory can create ModerationEntryFormatter, ModerationViewableEntry, etc.
	 * @covers MediaWiki\Moderation\EntryFactory
	 */
	public function testFactory() {
		$linkRenderer = $this->createMock( LinkRenderer::class );
		$actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$timestampFormatter = $this->createMock( TimestampFormatter::class );
		$context = $this->createMock( IContextSource::class );

		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var ActionLinkRenderer $actionLinkRenderer';
		'@phan-var TimestampFormatter $timestampFormatter';
		'@phan-var IContextSource $context';

		$factory = new EntryFactory( $linkRenderer, $actionLinkRenderer, $timestampFormatter );

		// Test makeFormatter()
		$row = (object)[ 'param1' => 'value1', 'param2' => 'value2' ];
		$formatter = $factory->makeFormatter( $row, $context );
		$this->assertInstanceOf( ModerationEntryFormatter::class, $formatter );

		// Test makeViewableEntry()
		$row = (object)[ 'param1' => 'value1', 'param2' => 'value2' ];
		$viewableEntry = $factory->makeViewableEntry( $row );
		$this->assertInstanceOf( ModerationViewableEntry::class, $viewableEntry );

		// Test makeApprovableEntry()
		$row = (object)[ 'type' => 'move', 'stash_key' => null ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryMove::class, $approvableEntry );

		$row = (object)[ 'type' => 'edit', 'stash_key' => null ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryEdit::class, $approvableEntry );

		$row = (object)[ 'type' => 'edit', 'stash_key' => 'some non-empty stash key' ];
		$approvableEntry = $factory->makeApprovableEntry( $row );
		$this->assertInstanceOf( ModerationEntryUpload::class, $approvableEntry );
	}
}
