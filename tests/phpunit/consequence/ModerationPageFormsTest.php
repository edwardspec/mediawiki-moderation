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
 * Unit test of ModerationPageForms
 */

use MediaWiki\Moderation\PendingEdit;

require_once __DIR__ . "/autoload.php";

class ModerationPageFormsTest extends ModerationUnitTestCase {
	/**
	 * Ensure that PageForms-specific hooks are installed by ModerationPlugins::install().
	 * @param string $hookName
	 * @param callable $expectedHandler
	 * @dataProvider dataProviderHookInstalled
	 * @covers ModerationPageForms
	 * @covers ModerationPlugins
	 * @requires function PFHooks::initialize
	 */
	public function testHookInstalled( $hookName, $expectedHandler ) {
		ModerationPlugins::install();

		$this->assertContains( $expectedHandler, Hooks::getHandlers( $hookName ),
			"Handler of $hookName hook is not installed." );
		$this->assertTrue( is_callable( $expectedHandler ),
			"Handler of $hookName hook is not callable." );
	}

	/**
	 * Provide datasets for testHookInstalled() runs.
	 * @return array
	 */
	public function dataProviderHookInstalled() {
		return [
			[ 'ModerationContinueEditingLink', 'ModerationPageForms::onModerationContinueEditingLink' ],
			[ 'PageForms::EditFormPreloadText', 'ModerationPageForms::preloadText' ],
			[ 'PageForms::EditFormInitialText', 'ModerationPageForms::preloadText' ]
		];
	}

	/**
	 * Ensure that returnto= link after the edit points back to Special:FormEdit or action=formedit.
	 * @param array $requestParams
	 * @param string|null $expectedReturnTo
	 * @param array $expectedReturnToQuery
	 * @dataProvider dataProviderContinueEditingLinkHook
	 * @covers ModerationPageForms
	 * @requires function PFHooks::initialize
	 */
	public function testContinueEditingLinkHook( array $requestParams,
		$expectedReturnTo, array $expectedReturnToQuery
	) {
		ModerationPageForms::install();

		$context = new RequestContext;
		$context->setRequest( new FauxRequest( $requestParams, true ) );

		$title = Title::newFromText( "Doesn't matter" );
		$context->setTitle( $title );

		$returnto = null;
		$returntoquery = [];

		$hookResult = Hooks::run( 'ModerationContinueEditingLink',
			[ &$returnto, &$returntoquery, $title, $context ] );
		$this->assertTrue( $hookResult,
			'Handler of ModerationContinueEditingLink hook should return true.' );

		$this->assertSame( $expectedReturnTo, $returnto, 'returnto' );
		$this->assertSame( $expectedReturnToQuery, $returntoquery, 'returntoquery' );
	}

	/**
	 * Provide datasets for testContinueEditingLinkHook() runs.
	 * @return array
	 */
	public function dataProviderContinueEditingLinkHook() {
		return [
			'editing URL had action=formedit, so returntoquery should have it too' => [
				[ 'action' => 'formedit' ],
				null,
				[ 'action' => 'formedit' ]
			],
			'editing URL pointed to Special:FormEdit, so returnto should point to it too' => [
				[ 'title' => 'Special:FormEdit' ],
				'Special:FormEdit',
				[]
			],
			'editing URL points to neither action=formedit nor Special:FormEdit' => [
				[ 'title' => 'Some article', 'action' => 'edit' ],
				null,
				[]
			],
			'some editing URL without even the title= parameter' => [
				[ 'unusual' => 'parameters' ],
				null,
				[]
			]
		];
	}

	/**
	 * Ensure that preloadText() does nothing when visiting Special:FormEdit without target page.
	 * @covers ModerationPageForms
	 * @requires function PFHooks::initialize
	 */
	public function testPreloadNoTargetTitle() {
		$text = $oldText = 'Unmodified text';
		ModerationPageForms::preloadText( $text, null, Title::newFromText( "Doesn't matter" ) );

		$this->assertSame( $oldText, $text, "Text shouldn't be modified when targetTitle is null." );
	}

	/**
	 * Ensure that preloadText() provides preloaded text.
	 * @covers ModerationPageForms
	 * @requires function PFHooks::initialize
	 */
	public function testPreload() {
		$targetTitle = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$expectedText = 'This text should be preloaded';

		// Mock ModerationPreload service to find a pending edit.
		$pendingEdit = $this->createMock( PendingEdit::class );
		$pendingEdit->expects( $this->once() )->method( 'getSectionText' )->willReturn( $expectedText );

		$preload = $this->createMock( ModerationPreload::class );
		$preload->expects( $this->any() )->method( 'findPendingEdit' )->with(
			$this->identicalTo( $targetTitle )
		)->willReturn( $pendingEdit );
		$this->setService( 'Moderation.Preload', $preload );

		$text = '';
		ModerationPageForms::preloadText( $text, $targetTitle, Title::newFromText( "Doesn't matter" ) );

		$this->assertSame( $expectedText, $text, "Expected text wasn't preloaded." );
	}

	/**
	 * Cleanup after the tests that call ModerationPlugins::install().
	 */
	protected function tearDown() : void {
		Hooks::clear( 'ModerationContinueEditingLink' );
		parent::tearDown();
	}
}
