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
 */

use MediaWiki\Moderation\AddLogEntryConsequence;
use MediaWiki\Moderation\ApproveEditConsequence;
use MediaWiki\Moderation\DeleteRowFromModerationTableConsequence;
use MediaWiki\Moderation\InstallApproveHookConsequence;
use MediaWiki\Moderation\InvalidatePendingTimeCacheConsequence;
use MediaWiki\Moderation\ModifyPendingChangeConsequence;
use MediaWiki\Moderation\RejectBatchConsequence;

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
	 * Test consequences of modaction=approveall.
	 * @covers ModerationActionApprove::executeApproveAll
	 */
	public function testApproveAllOneEdit() {
		$expectedRevId = rand( 1, 100000 );
		$this->mockApproveHook( $expectedRevId );

		$actual = $this->getConsequences( $this->modid, 'approveall',
			[ [ ApproveEditConsequence::class, Status::newGood() ] ]
		);
		$expected = [
			new InstallApproveHookConsequence( $this->title, $this->authorUser, 'edit', [
				'ip' => $this->ip,
				'xff' => $this->xff,
				'ua' => $this->userAgent,
				'tags' => $this->tags,
				'timestamp' => $this->timestamp
			] ),
			new ApproveEditConsequence(
				$this->authorUser,
				$this->title,
				$this->text,
				$this->summary,
				false, // isBot
				false, // isMinor
				0 // $baseRevId
			),
			new AddLogEntryConsequence(
				'approve',
				$this->moderatorUser,
				$this->title,
				[ 'revid' => $expectedRevId ],
				true // ApproveHook enabled
			),
			new DeleteRowFromModerationTableConsequence( $this->modid ),
			new AddLogEntryConsequence(
				'approveall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => 1
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];

		$this->assertConsequencesEqual( $expected, $actual );

		$this->assertSame( $this->result, [
			'approved' => [ $this->modid => '' ],
			'failed' => []
		] );
		$this->assertEquals( $this->outputText, '(moderation-approved-ok: 1)' );
	}

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
	 * Test consequences of modaction=rejectall.
	 * @covers ModerationActionReject::executeRejectAll
	 */
	public function testRejectAllOneEdit() {
		$expected = [
			new RejectBatchConsequence( [ $this->modid ], $this->moderatorUser ),
			new AddLogEntryConsequence(
				'rejectall',
				$this->moderatorUser,
				$this->authorUser->getUserPage(),
				[
					'4::count' => 1
				]
			),
			new InvalidatePendingTimeCacheConsequence()
		];
		$actual = $this->getConsequences( $this->modid, 'rejectall',
			[ [ RejectBatchConsequence::class, 1 ] ] );

		$this->assertConsequencesEqual( $expected, $actual );

		$this->assertSame( $this->result, [ 'rejected-count' => 1 ] );
		$this->assertEquals( $this->outputText, '(moderation-rejected-ok: 1)' );
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

		// TODO: additionally check entries without any tags
		// (important for testing InstallApproveHookConsequence)
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
