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
 * Unit test of various hooks like onwgQueryPages that are not covered by other tests.
 */

use MediaWiki\MediaWikiServices;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class HooksTest extends ModerationUnitTestCase {
	/**
	 * @covers ModerationApiHooks::onwgQueryPages
	 */
	public function testQueryPageListed() {
		$this->assertContains(
			[ SpecialModeration::class, 'Moderation' ],
			QueryPage::getPages(),
			"Special:Moderation isn't listed in the list of query pages."
		);
	}

	/**
	 * @covers ModerationEditHooks::onListDefinedTags
	 */
	public function testDefinedTagListed() {
		$this->assertContains( 'moderation-merged', ChangeTags::listDefinedTags(),
			"Tag 'moderation-merged' isn't listed in the list of defined change tags." );
	}

	/**
	 * Ensure that action=revert is not allowed to non-automoderated users.
	 * @see testRevertImageRestrictedViaApi - same, but via API.
	 * @param bool $isAutomoderated
	 * @param string $mwActionName Name of MediaWiki action (NOT modaction!), e.g. "revert".
	 * @dataProvider dataProviderRevertImageRestrictedViaUI
	 * @covers ModerationUploadHooks::ongetUserPermissionsErrors
	 */
	public function testRevertImageRestrictedViaUI( $isAutomoderated, $mwActionName ) {
		$title = Title::newFromText( 'File:Something.png' );
		$user = self::getTestUser()->getUser();

		// Mock the result of canUploadSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $mwActionName == 'revert' ? $this->once() : $this->never() )
			->method( 'canUploadSkip' )->with(
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$this->identicalTo( $user )
			)->willReturn( $isAutomoderated );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$globalContext = RequestContext::getMain();
		$globalContext->getRequest()->setVal( 'action', $mwActionName );
		$globalContext->setTitle( $title );

		if ( method_exists( MediaWikiServices::class, 'getPermissionManager' ) ) {
			// MediaWiki 1.33+
			$permManager = MediaWikiServices::getInstance()->getPermissionManager();
			$permissionErrors = $permManager->getPermissionErrors( 'upload', $user, $title );
		} else {
			// MediaWiki 1.31-1.32
			$permissionErrors = $title->getUserPermissionsErrors( 'upload', $user );
		}

		if ( !$isAutomoderated && $mwActionName == 'revert' ) {
			$this->assertSame( [ [ 'moderation-revert-not-allowed' ] ], $permissionErrors,
				"User who can't bypass moderation of uploads was allowed to use action=revert." );
		} else {
			$this->assertEmpty( $permissionErrors,
				"Using action=$mwActionName wasn't allowed when it shouldn't have been restricted." );
		}
	}

	/**
	 * Provide datasets for testRevertImageRestrictedViaUI() runs.
	 * @return array
	 */
	public function dataProviderRevertImageRestrictedViaUI() {
		return [
			'action=revert, automoderated (should be allowed)' => [ true, 'revert' ],
			'action=revert, NOT automoderated (should be disallowed)' => [ false, 'revert' ],
			'action=view, automoderated (should be allowed)' => [ true, 'view' ],
			'action=view, NOT automoderated (should be disallowed)' => [ false, 'view' ],
		];
	}

	/**
	 * Ensure that api.php?action=filerevert is not allowed to non-automoderated users.
	 * @see testRevertImageRestrictedViaUI - same, but via UI
	 * @param bool $isAutomoderated
	 * @param string $apiActionName Name of API action (NOT modaction!), e.g. "filerevert".
	 * @dataProvider dataProviderRevertImageRestrictedViaApi
	 * @covers ModerationApiHooks::onApiCheckCanExecute
	 */
	public function testRevertImageRestrictedViaApi( $isAutomoderated, $apiActionName ) {
		$context = new RequestContext();
		$context->getRequest()->setVal( 'action', $apiActionName );
		$context->getRequest()->setVal( 'filename', 'irrelevant' );
		$context->getRequest()->setVal( 'archivename', 'irrelevant' );
		$context->getRequest()->setVal( 'token', $context->getUser()->getEditToken() );

		// Mock the result of canUploadSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canUploadSkip' )->with(
				// @phan-suppress-next-line PhanTypeMismatchArgument
				$this->identicalTo( $context->getUser() )
			)->willReturn( $isAutomoderated );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$apiMain = new ApiMain( $context, true );

		$wrapper = TestingAccessWrapper::newFromObject( $apiMain );
		$wrapper->setupExecuteAction();

		$thrownStatus = Status::newGood();
		try {
			$wrapper->checkExecutePermissions( $wrapper->setupModule() );
		} catch ( ApiUsageException $e ) {
			$thrownStatus = Status::wrap( $e->getStatusValue() );
		}

		if ( !$isAutomoderated && $apiActionName == 'filerevert' ) {
			$this->assertFalse( $thrownStatus->isOK(),
				"Non-automoderated user wasn't disallowed to revert image to previous revision." );
			$this->assertEquals( 'moderation-revert-not-allowed', $thrownStatus->getMessage()->getKey() );
		} else {
			$this->assertTrue( $thrownStatus->isOK(),
				"Using action=$apiActionName wasn't allowed when it shouldn't have been restricted." );
		}
	}

	/**
	 * Provide datasets for testRevertImageRestrictedViaApi() runs.
	 * @return array
	 */
	public function dataProviderRevertImageRestrictedViaApi() {
		return [
			'action=filerevert, automoderated (should be allowed)' => [ true, 'filerevert' ],
			'action=filerevert, NOT automoderated (should be disallowed)' => [ false, 'filerevert' ],
			'action=query, automoderated (should be allowed)' => [ true, 'query' ],
			'action=query, NOT automoderated (should be disallowed)' => [ false, 'query' ],
		];
	}
}
