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
 * Unit test of ModerationAjaxHook
 */

use MediaWiki\Moderation\PendingEdit;
use Wikimedia\ScopedCallback;

require_once __DIR__ . "/autoload.php";

class ModerationAjaxHookTest extends ModerationUnitTestCase {
	/**
	 * Verify that ModerationAjaxHook::add() adds the necessary modules to OutputPage.
	 * Note: because return value of things like class_exists() can't really be mocked,
	 * we can only test the current configuration. (depending on whether MobileFrontend
	 * and VisualEditor are installed during the test)
	 * @param array $opt
	 * @dataProvider dataProviderAjaxHook
	 * @covers ModerationAjaxHook
	 */
	public function testAjaxHook( array $opt ) {
		$expectedModules = $opt['expectedModules'] ?? [];
		$installedExtensions = $opt['installedExtensions'] ?? [];

		$extensionRegistry = ExtensionRegistry::getInstance();
		foreach ( $installedExtensions as $name => $shouldBeLoaded ) {
			if ( $shouldBeLoaded !== $extensionRegistry->isLoaded( $name ) ) {
				$this->markTestSkipped(
					"Test skipped: Extension:$name: shouldBeLoaded (" . (int)$shouldBeLoaded . ") != isLoaded" );
			}
		}

		if ( isset( $opt['mwVersion'] ) ) {
			$this->setMwGlobals( 'wgVersion', $opt['mwVersion'] );
		}

		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		if ( $opt['pageExists'] ?? false ) {
			$title->resetArticleId( 12345 );
		}

		if ( isset( $opt['hasPendingEdit'] ) ) {
			// Mock findPendingEdit() in the Moderation.Preload service.
			$preload = $this->createMock( ModerationPreload::class );
			$preload->expects( $this->any() )->method( 'findPendingEdit' )->with(
				 $this->identicalTo( $title )
			)->willReturn( $opt['hasPendingEdit'] ? $this->createMock( PendingEdit::class ) : false );
			$this->setService( 'Moderation.Preload', $preload );
		}

		$context = new RequestContext();
		$context->setTitle( $title );
		$out = $context->getOutput();

		if ( isset( $opt['isMobileView'] ) ) {
			// This setMwGlobals() is to restore the original value of this variable after the test,
			// because MobileFrontend sets it to true in mobile mode, and it's otherwise not reset.
			$this->setMwGlobals( 'wgUseMediaWikiUIEverywhere', false );

			// Override "should display mobile view?" logic
			$context->getRequest()->setVal( 'useformat', $opt['isMobileView'] ? 'mobile' : 'desktop' );
			MobileContext::singleton()->setContext( $context );

			// @phan-suppress-next-line PhanUnusedVariable
			$cleanupScope = new ScopedCallback( function () {
				MobileContext::resetInstanceForTesting();
			} );
		}

		ModerationAjaxHook::add( $out );

		if ( isset( $opt['expectedModules'] ) ) {
			$this->assertSame( $expectedModules, $out->getModules(),
				"List of added modules doesn't match expected." );
		} elseif ( isset( $opt['expectFakeArticleId'] ) ) {
			$jsVars = $out->getJSVars();
			$this->assertArrayHasKey( 'wgArticleId', $jsVars );

			if ( $opt['expectFakeArticleId'] ) {
				$this->assertSame( -1, $jsVars['wgArticleId'],
					'PendingEdit exists, so wgArticleId should be set to -1 (to pretend that page exists).' );
			} else {
				$this->assertSame( $title->getArticleID(), $jsVars['wgArticleId'], 'wgArticleId' );
			}
		} else {
			throw new MWException( 'No-op test: has neither expectedModules nor expectFakeArticleId' );
		}
	}

	/**
	 * Provide datasets for testAjaxHook() runs.
	 * @return array
	 */
	public function dataProviderAjaxHook() {
		return [
			'only VE is installed' => [ [
				'installedExtensions' => [ 'VisualEditor' => true, 'MobileFrontend' => false ],
				'expectedModules' => [ 'ext.moderation.ve', 'ext.moderation.ajaxhook' ]
			] ],
			'both VE and MF are installed, NOT in mobile view' => [ [
				'installedExtensions' => [ 'VisualEditor' => true, 'MobileFrontend' => true ],
				'isMobileView' => false,
				'expectedModules' => [ 'ext.moderation.ve', 'ext.moderation.ajaxhook' ]
			] ],
			'both VE and MF are installed, using Mobile view, MW 1.33+' => [ [
				'installedExtensions' => [ 'VisualEditor' => true, 'MobileFrontend' => true ],
				'isMobileView' => true,
				'mwVersion' => '1.33.0',
				'expectedModules' => [
					'ext.moderation.mf.notify',
					'ext.moderation.mf.preload33',
					'ext.moderation.ajaxhook'
				]
			] ],
			'only MF is installed, using Mobile view, MW 1.33+' => [ [
				'installedExtensions' => [ 'VisualEditor' => false, 'MobileFrontend' => true ],
				'isMobileView' => true,
				'mwVersion' => '1.33.0',
				'expectedModules' => [
					'ext.moderation.mf.notify',
					'ext.moderation.mf.preload33',
					'ext.moderation.ajaxhook'
				]
			] ],
			'both VE and MF are installed, using Mobile view, MW 1.32' => [ [
				'installedExtensions' => [ 'VisualEditor' => true, 'MobileFrontend' => true ],
				'isMobileView' => true,
				'mwVersion' => '1.32.0',
				'expectedModules' => [
					'ext.moderation.mf.notify',
					'ext.moderation.mf.preload31',
					'ext.moderation.ajaxhook'
				]
			] ],
			'only MF is installed, using Mobile view, MW 1.32' => [ [
				'installedExtensions' => [ 'VisualEditor' => false, 'MobileFrontend' => true ],
				'isMobileView' => true,
				'mwVersion' => '1.32.0',
				'expectedModules' => [
					'ext.moderation.mf.notify',
					'ext.moderation.mf.preload31',
					'ext.moderation.ajaxhook'
				]
			] ],
			'MF is installed, page doesn\'t exist, no pending edit' => [ [
				'installedExtensions' => [ 'MobileFrontend' => true ],
				'isMobileView' => true,
				'pageExists' => false,
				'hasPendingEdit' => false,
				'expectFakeArticleId' => false
			] ],
			'MF is installed, page exists, no pending edit' => [ [
				'installedExtensions' => [ 'MobileFrontend' => true ],
				'isMobileView' => true,
				'pageExists' => true,
				'hasPendingEdit' => false,
				'expectFakeArticleId' => false
			] ],
			'MF is installed, page exists, have pending edit' => [ [
				'installedExtensions' => [ 'MobileFrontend' => true ],
				'isMobileView' => true,
				'pageExists' => true,
				'hasPendingEdit' => true,
				'expectFakeArticleId' => false
			] ],
			'MF is installed, page doesn\'t exist, have pending edit, MW 1.32' => [ [
				'installedExtensions' => [ 'MobileFrontend' => true ],
				'mwVersion' => '1.32.0',
				'isMobileView' => true,
				'pageExists' => true,
				'hasPendingEdit' => true,
				'expectFakeArticleId' => false
			] ],
			'Need wgArticleId=-1: MF is installed, page doesn\'t exist, have pending edit, MW 1.33+' => [ [
				'installedExtensions' => [ 'MobileFrontend' => true ],
				'mwVersion' => '1.33.0',
				'isMobileView' => true,
				'pageExists' => false,
				'hasPendingEdit' => true,
				// Only in this case should wgArticleId be set to -1.
				// Otherwise MobileFrontend won't preload the pending text.
				'expectFakeArticleId' => true
			] ]
		];
	}
}
