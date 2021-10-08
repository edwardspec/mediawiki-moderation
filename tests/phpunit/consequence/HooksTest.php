<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020-2021 Edward Chernenko.

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
use MediaWiki\Moderation\EditFormOptions;
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
				$this->identicalTo( $user )
			)->willReturn( $isAutomoderated );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$globalContext = RequestContext::getMain();
		$globalContext->getRequest()->setVal( 'action', $mwActionName );
		$globalContext->setTitle( $title );

		$permManager = MediaWikiServices::getInstance()->getPermissionManager();
		$permissionErrors = $permManager->getPermissionErrors( 'upload', $user, $title );

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

	/**
	 * Ensure that EditPage::showEditForm:fields hook adds merge-related fields to the EditPage form.
	 * @param int $mergeID
	 * @dataProvider dataProviderMergeFieldsInEditForm
	 * @covers ModerationEditHooks::onEditPage__showEditForm_fields
	 */
	public function testMergeFieldsInEditForm( $mergeID ) {
		// Mock the EditFormOptions service.
		$editFormOptions = $this->createMock( EditFormOptions::class );
		$editFormOptions->expects( $this->once() )->method( 'getMergeID' )->willReturn( $mergeID );
		$this->setService( 'Moderation.EditFormOptions', $editFormOptions );

		// Show the EditPage form.
		$title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$context = new RequestContext;
		$context->setTitle( $title );

		$editPage = new EditPage( Article::newFromTitle( $title, $context ) );
		$editPage->setContextTitle( $title );
		$editPage->showEditForm();

		$html = new ModerationTestHTML;
		$html->loadString( $editPage->getContext()->getOutput()->getHTML() );

		$mergeInput = $html->getElementByXPath( '//input[@name="wpMergeID"]' );
		$blankSummaryInput = $html->getElementByXPath( '//input[@name="wpIgnoreBlankSummary"]' );

		if ( $mergeID ) {
			$this->assertNotNull( $mergeInput, 'wpMergeID field not found.' );
			$this->assertEquals( $mergeID, $mergeInput->getAttribute( 'value' ), 'wpMergeID.value' );

			$this->assertNotNull( $blankSummaryInput, 'wpIgnoreBlankSummary field not found.' );
			$this->assertSame( "1", $blankSummaryInput->getAttribute( 'value' ),
				'wpIgnoreBlankSummary.value' );
		} else {
			$this->assertNull( $mergeInput, 'wpMergeID field found where it is not needed.' );
			$this->assertNull( $blankSummaryInput,
				'wpIgnoreBlankSummary field found where it is not needed.' );
		}
	}

	/**
	 * Provide datasets for testMergeFieldsInEditForm() runs.
	 * @return array
	 */
	public function dataProviderMergeFieldsInEditForm() {
		return [
			'getMergeID() returned 12345' => [ 12345 ],
			'Not in the process of merging: getMergeID() returned 0' => [ 0 ]
		];
	}

	/**
	 * Ensure that BeforePageDisplay hook adds CSS/JS modules of postedit notifications, etc.
	 * @param bool $isAutomoderated
	 * @dataProvider dataProviderBeforePageDisplayHook
	 * @covers ModerationEditHooks::onBeforePageDisplay
	 */
	public function testBeforePageDisplayHook( $isAutomoderated ) {
		$title = Title::newFromText( 'Project:UTPage-' . rand( 0, 100000 ) );
		$user = self::getTestUser()->getUser();

		// Mock the result of canEditSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->once() )->method( 'canEditSkip' )->with(
			$this->identicalTo( $user ),
			$this->identicalTo( $title->getNamespace() )
		)->willReturn( $isAutomoderated );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		$context = new RequestContext();
		$context->setUser( $user );
		$context->setTitle( $title );

		$out = $context->getOutput();

		// Call OutputPage::output(), which is what triggers BeforePageDisplay hook.
		ob_start();
		$out->output();
		ob_end_clean();

		// Check if ResourceLoader modules were added to $out, but only for non-automoderated users.
		$modules = $out->getModules();
		if ( $isAutomoderated ) {
			$this->assertNotContains( 'ext.moderation.notify', $modules, 'getModules()' );
			$this->assertNotContains( 'ext.moderation.notify.desktop', $modules, 'getModules()' );
		} else {
			$this->assertContains( 'ext.moderation.notify', $modules, 'getModules()' );
			$this->assertContains( 'ext.moderation.notify.desktop', $modules, 'getModules()' );
		}

		// TODO: double-check that ModerationAjaxHook::add() was called.
		// Should it be made into a service just to simplify the test?
	}

	/**
	 * Provide datasets for testBeforePageDisplayHook() runs.
	 * @return array
	 */
	public function dataProviderBeforePageDisplayHook() {
		return [
			'is automoderated' => [ true ],
			'is NOT automoderated' => [ false ]
		];
	}
}
