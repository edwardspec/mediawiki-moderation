<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
 * Verifies that modaction=editchange works as expected.
 * @note modaction=editchangesubmit is tested in ModerationActionTest.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationActionEditChange
 * @covers ModerationEditChangePage
 */
class ModerationEditChangeTest extends ModerationTestCase {

	/**
	 * Check the edit form of modaction=editchange.
	 * @covers ModerationActionEditChange
	 */
	public function testEditChangeForm( ModerationTestsuite $t ) {
		$t->setMwConfig( 'ModerationEnableEditChange', true );

		$entry = $t->getSampleEntry();
		$t->html->loadFromURL( $entry->editChangeLink );

		$form = $t->html->getElementByXPath( '//form[contains(@action,"Special:Moderation")]' );
		$this->assertNotNull( $form, 'testEditChangeForm(): <form> not found' );

		$expectedAction = SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( [
			'modid' => $entry->id,
			'modaction' => 'editchangesubmit'
		] );
		$this->assertEquals( $expectedAction, $form->getAttribute( 'action' ),
			"testEditChangeForm(): Incorrect action= attribute in the edit form."
		);

		$textarea = $t->html->getElementById( 'wpTextbox1' );
		$this->assertNotNull( $textarea,
			"testEditChangeForm(): <textarea> not found." );
		$this->assertEquals( $t->lastEdit['Text'], trim( $textarea->textContent ),
			"testEditChangeForm(): <textarea> doesn't contain the current text of pending change." );

		$summaryInput = $t->html->getElementByXPath( '//input[@name="wpSummary"]' );
		$this->assertEquals( $t->lastEdit['Summary'], $summaryInput->getAttribute( 'value' ),
			"testEditChangeForm(): Preloaded summary doesn't match." );

		$tokenInput = $t->html->getElementByXPath( '//input[@name="token"]' );
		$this->assertNotNull( $tokenInput, "testEditChangeForm(): [input name='token'] is missing." );

		$this->assertNull( $t->html->getElementById( 'wpPreview' ),
			"testEditChangeForm(): unexpected Preview button found (not yet implemented)." );
		$this->assertNull( $t->html->getElementById( 'wpDiff' ),
			"testEditChangeForm(): unexpected Diff button found (not yet implemented)." );
	}
}
