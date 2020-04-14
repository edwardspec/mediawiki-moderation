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
 * Verifies that ModerationAction subclasses have consequences like AddLogEntryConsequence.
 *
 * TODO: dismantle this test in favor of per-action unit tests.
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ModifyPendingChangeConsequence;

require_once __DIR__ . "/autoload.php";

/**
 * @group Database
 */
class ActionsHaveConsequencesTest extends ModerationUnitTestCase {
	use ConsequenceTestTrait;
	use ModifyDbRowTestTrait;

	/** @var int */
	protected $modid;

	/** @var User */
	protected $authorUser;

	/** @var Title */
	protected $title;

	/** @var string */
	protected $text;

	/** @var string */
	protected $summary;

	/** @var string|null */
	protected $ip;

	/** @var string|null */
	protected $xff;

	/** @var string|null */
	protected $userAgent;

	/** @var string|null */
	protected $tags;

	/** @var string Value of mod_timestamp field. */
	protected $timestamp;

	/** @var string[] */
	protected $tablesUsed = [ 'user', 'moderation' ];

	/**
	 * Test consequences of modaction=editchangesubmit.
	 * @covers ModerationActionEditChangeSubmit
	 */
	public function testEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		// No "~~~" or other PST transformations for simplicity
		$newText = $row->mod_text . ' plus some additional text';
		$newComment = 'Some new summary';
		$newLen = strlen( $newText );

		$expected = [
			new ModifyPendingChangeConsequence(
				$this->modid,
				$newText,
				$newComment,
				$newLen
			),
			new AddLogEntryConsequence(
				'editchange',
				$this->moderatorUser,
				$this->title,
				[
					'modid' => $this->modid
				]
			)
		];

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( $this->modid, 'editchangesubmit',
			[ [ ModifyPendingChangeConsequence::class, true ] ],
			[
				'wpTextbox1' => $newText,
				'wpSummary' => $newComment
			]
		);

		$this->assertConsequencesEqual( $expected, $actual );

		$this->assertSame( $this->result, [
			'id' => $this->modid,
			'success' => true,
			'noop' => false
		] );
		$this->assertEquals( $this->outputText, '(moderation-editchange-ok)' );
	}

	/**
	 * Test consequences of modaction=editchangesubmit when both text and summary are unchanged.
	 * @covers ModerationActionEditChangeSubmit
	 */
	public function testNoopEditChangeSubmit() {
		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', [ 'mod_text', 'mod_comment' ], '', __METHOD__ );

		$this->setMwGlobals( 'wgModerationEnableEditChange', true );
		$actual = $this->getConsequences( $this->modid, 'editchangesubmit', null,
			[
				// Same values as already present in the database.
				'wpTextbox1' => $row->mod_text,
				'wpSummary' => $row->mod_comment
			]
		);

		// Nothing changed, so ModifyPendingChangeConsequence wasn't added.
		$this->assertConsequencesEqual( [], $actual );

		$this->assertSame( $this->result, [
			'id' => $this->modid,
			'success' => true,
			'noop' => true
		] );
		$this->assertEquals( $this->outputText, '(moderation-editchange-ok)' );
	}

	/**
	 * Queue an edit for moderation. Populate all fields ($this->modid, etc.) used by actual tests.
	 */
	public function setUp() : void {
		parent::setUp();

		$name = $this->getName();
		if ( $name == 'testValidCovers' || $name == 'testMediaWikiTestCaseParentSetupCalled' ) {
			return;
		}

		$this->moderatorUser = self::getTestUser( [ 'moderator', 'automoderated' ] )->getUser();

		$this->title = Title::newFromText( 'UTPage-' . rand( 0, 100000 ) );
		$this->text = 'Some text ' . rand( 0, 100000 );
		$this->summary = 'Sample edit summary ' . rand( 0, 100000 );
		$this->userAgent = 'SampleUserAgent/1.0.' . rand( 0, 100000 );
		$this->ip = '10.20.30.40';
		$this->xff = '10.11.12.13';
		$this->timestamp = $this->db->timestamp(
			wfTimestamp( TS_MW, (int)wfTimestamp() - rand( 100, 100000 ) ) );
		$this->tags = "Sample tag1\nSample tag2";

		$this->modid = $this->makeDbRow( [
			'mod_title' => $this->title->getDBKey(),
			'mod_namespace' => $this->title->getNamespace(),
			'mod_comment' => $this->summary,
			'mod_text' => $this->text,
			'mod_ip' => $this->ip,
			'mod_header_ua' => $this->userAgent,
			'mod_header_xff' => $this->xff,
			'mod_tags' => $this->tags,
			'mod_timestamp' => $this->timestamp
		] );
	}
}
