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
 * Unit test of ModerationActionMerge.
 */

use MediaWiki\Moderation\EditFormOptions;
use MediaWiki\Moderation\EntryFactory;
use MediaWiki\Moderation\IConsequenceManager;

require_once __DIR__ . "/autoload.php";

class ModerationActionMergeTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;

	/**
	 * Verify that execute() returns expected result.
	 * @param array $opt
	 * @dataProvider dataProviderExecute
	 * @covers ModerationActionMerge
	 */
	public function testExecute( array $opt ) {
		$expectedError = $opt['expectedError'] ?? null;
		$isModeratorAutomoderated = $opt['isModeratorAutomoderated'] ?? true;
		$isConflict = $opt['isConflict'] ?? true;
		$isAlreadyMerged = $opt['isAlreadyMerged'] ?? false;

		$user = self::getTestUser()->getUser();
		$row = (object)[
			'namespace' => rand( 0, 1 ),
			'title' => 'UTPage_' . rand( 0, 100000 ),
			'user_text' => $user->getName(),
			'text' => 'Some text ' . rand( 0, 100000 ),
			'conflict' => $isConflict ? 1 : 0,
			'merged_revid' => $isAlreadyMerged ? 56789 : 0
		];

		// Mock EntryFactory that will return $row
		$modid = 12345;
		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [ 'modid' => $modid ] ) );

		$moderator = User::newFromName( '127.0.0.30', false );
		$context->setUser( $moderator );

		// $result['summary'] should have a message in ContentLanguage
		$this->setContentLang( 'qqx' );

		$entryFactory = $this->createMock( EntryFactory::class );
		$entryFactory->expects( $this->once() )->method( 'loadRowOrThrow' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $modid ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( [
				'mod_namespace AS namespace',
				'mod_title AS title',
				'mod_user_text AS user_text',
				'mod_text AS text',
				'mod_conflict AS conflict',
				'mod_merged_revid AS merged_revid'
			] )
		)->willReturn( $row );

		// Mock the result of canEditSkip()
		$canSkip = $this->createMock( ModerationCanSkip::class );
		$canSkip->expects( $this->any() )->method( 'canEditSkip' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $moderator ),
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$this->identicalTo( $row->namespace )
		)->willReturn( $isModeratorAutomoderated );
		$this->setService( 'Moderation.CanSkip', $canSkip );

		// This is a readonly action. Ensure that it has no consequences.
		$manager = $this->createMock( IConsequenceManager::class );
		$manager->expects( $this->never() )->method( 'add' );

		'@phan-var EntryFactory $entryFactory';
		'@phan-var IConsequenceManager $manager';

		$action = new ModerationActionMerge( $context, $entryFactory, $manager );

		if ( $expectedError ) {
			$this->expectExceptionObject( new ModerationError( $expectedError ) );
		}
		$result = $action->execute();

		$expectedResult = [
			'id' => $modid,
			'namespace' => $row->namespace,
			'title' => $row->title,
			'text' => $row->text,
			'summary' => '(moderation-merge-comment: ' . $row->user_text . ')'
		];
		$this->assertSame( $expectedResult, $result, "Result of execute() doesn't match expected." );
	}

	/**
	 * Provide datasets for testExecute() runs.
	 * @return array
	 */
	public function dataProviderExecute() {
		return [
			'successful merge' => [ [] ],
			'error: merge not needed' => [ [
				'expectedError' => 'moderation-merge-not-needed',
				'isConflict' => false
			] ],
			'error: moderator is not automoderated (can\'t merge)' => [ [
				'expectedError' => 'moderation-merge-not-automoderated',
				'isModeratorAutomoderated' => false
			] ],
			'error: already merged' => [ [
				'expectedError' => 'moderation-already-merged',
				'isAlreadyMerged' => true
			] ]
		];
	}

	/**
	 * Verify that outputResult() correctly shows the EditPage form in "edit conflict" mode.
	 * @covers ModerationActionMerge
	 */
	public function testOutputResult() {
		$modid = 12345;
		$executeResult = [
			'id' => $modid,
			'namespace' => rand( 0, 1 ),
			'title' => 'UTPage_' . rand( 0, 100000 ),
			'text' => '{MockedText}',
			'summary' => '{MockedComment}'
		];
		$title = Title::makeTitle( $executeResult['namespace'], $executeResult['title'] );

		// Expected behavior of outputResult() is to display the form of EditPage,
		// so let's install the hook that can monitor this situation.
		$hookFired = false;
		$hookName = 'EditPage::showEditForm:initial';
		$this->setTemporaryHook( $hookName,
			function ( EditPage $editPage, OutputPage $out ) use ( &$hookFired, $title ) {
				$hookFired = true;

				$this->assertTrue( $editPage->isConflict, 'EditPage.isConflict' );
				$this->assertSame( $title->getFullText(), $editPage->getContextTitle()->getFullText(),
					'EditPage.ContextTitle' );
				$this->assertSame( '{MockedText}', $editPage->textbox1, 'EditPage.textbox1' );
				$this->assertSame( '{MockedComment}', $editPage->summary, 'EditPage.summary' );
			}
		);

		$context = new RequestContext();
		$context->setRequest( new FauxRequest( [ 'modid' => $modid ] ) );
		$context->setTitle( $title );
		$context->setLanguage( 'qqx' );

		// Mock EditFormOptions service to ensure that setMergeID() will be called.
		$editFormOptions = $this->createMock( EditFormOptions::class );
		$editFormOptions->expects( $this->once() )->method( 'setMergeID' )->with(
			// @phan-suppress-next-line PhanTypeMismatchArgument
			$modid
		);
		$this->setService( 'Moderation.EditFormOptions', $editFormOptions );

		// This is a readonly action. Ensure that it has no consequences.
		$manager = $this->createMock( IConsequenceManager::class );
		$manager->expects( $this->never() )->method( 'add' );

		$entryFactory = $this->createMock( EntryFactory::class );

		'@phan-var EntryFactory $entryFactory';
		'@phan-var IConsequenceManager $manager';

		$action = new ModerationActionMerge( $context, $entryFactory, $manager );

		// Note: EditPage class (that is used by ActionMerge) obtains output from $context->getOutput(),
		// so it makes no sense to clone $output for testing purposes (see ActionShowTest for details).
		$action->outputResult( $executeResult, $context->getOutput() );
		$this->assertTrue( $hookFired, "outputResult() didn't cause $hookName hook to be fired." );
	}
}
