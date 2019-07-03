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
 * Checks consequences of the moderation actions on Special:Moderation.
 */

require_once __DIR__ . "/../../framework/ModerationTestsuite.php";

/**
 * @covers ModerationAction
 */
class ModerationActionTest extends ModerationTestCase {
	/**
	 * @dataProvider dataProvider
	 */
	public function testAction( array $options ) {
		ModerationActionTestSet::run( $options, $this );
	}

	/**
	 * Provide datasets for testAction() runs.
	 */
	public function dataProvider() {
		global $wgModerationTimeToOverrideRejection;

		$sets = [
			'successful Reject' => [ [
				'modaction' => 'reject',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true
			] ],

			'successful RejectAll' => [ [
				'modaction' => 'rejectall',
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true,
				'expectedFields' => [ 'mod_rejected_batch' => 1 ],
				'expectedLogTargetIsAuthor' => true
			] ],

			'successful Approve (newly created page)' => [ [
				'modaction' => 'approve',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (edit on the existing page)' => [ [
				'modaction' => 'approve',
				'existing' => true, // edit in existing page
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (previously rejected change)' => [ [
				'modaction' => 'approve',
				'mod_rejected' => 1,
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve should preserve the timestamp of original edit' => [ [
				'modaction' => 'approve',
				'mod_timestamp' => '-6 hours',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (when author\'s user account was deleted from the database)' => [ [
				'modaction' => 'approve',
				'simulateDeletedAuthor' => true,
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (upload)' => [ [
				'modaction' => 'approve',
				'filename' => 'image100x100.png',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (reupload)' => [ [
				'modaction' => 'approve',
				'existing' => true, // reupload
				'filename' => 'image100x100.png',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true
			] ],
			'successful Approve (move)' => [ [
				'modaction' => 'approve',
				'mod_type' => 'move',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true,
				'expectedLogAction' => 'approve-move'
			] ],

			'successful ApproveAll' => [ [
				'modaction' => 'approveall',
				'expectedOutput' => '(moderation-approved-ok: 1)',
				'expectApproved' => true,
				'expectedLogTargetIsAuthor' => true
			] ],

			'successful Reject (edit with the conflict)' => [ [
				'modaction' => 'reject',
				'mod_conflict' => 1,
				'expectedOutput' => '(moderation-rejected-ok: 1)',
				'expectRejected' => true
			] ],

			// Actions show/preview/merge/block/unblock/editchange shouldn't change the row
			'Show (null edit)' => [ [
				'modaction' => 'show',
				'nullEdit' => true,
				'expectedOutput' => '(moderation-diff-no-changes)',
				'expectActionLinks' => [ 'approve' => false, 'reject' => true ]
			] ],
			'ShowImage (100x100 PNG image)' => [ [
				'modaction' => 'showimg',
				'filename' => 'image100x100.png',
				'expectedContentType' => 'image/png',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Image100x100.png",
				'expectOutputToEqualUploadedFile' => true
			] ],
			'EditChange' => [ [ 'modaction' => 'editchange', 'enableEditChange' => true ] ],
			'Merge' => [ [ 'mod_conflict' => 1, 'modaction' => 'merge' ] ],

			// Check block/unblock
			'successful Block' => [ [
				'modaction' => 'block',
				'expectModblocked' => true,
				'expectedOutput' => 'moderation-block-ok',
				'expectedLogTargetIsAuthor' => true
			] ],
			'successful Unblock' => [ [
				'modaction' => 'unblock',
				'modblocked' => true,
				'expectModblocked' => false,
				'expectedOutput' => 'moderation-unblock-ok',
				'expectedLogTargetIsAuthor' => true
			] ],
			'duplicate attempt to Block (already blocked)' => [ [
				// Can happen if moderator clicked twice on "Mark as spammer" link.
				// Should report success, but shouldn't create a new LogEntry.
				'modaction' => 'block',
				'modblocked' => true,
				'expectedOutput' => 'moderation-block-ok',
				'expectLogEntry' => false
			] ],
			'duplicate attempt to Unblock (already unblocked)' => [ [
				// Should report success, but shouldn't create a new LogEntry.
				'modaction' => 'unblock',
				'expectedOutput' => 'moderation-unblock-ok',
				'expectLogEntry' => false
			] ],

			'Show (normal edit)' => [ [
				'modaction' => 'show',
				'mod_title' => 'Test_page_1',
				'mod_text' => "This text is '''very bold''' and ''most italic''.\n",
				'mod_new_len' => 49,
				'expectActionLinks' => [ 'approve' => true, 'reject' => true ],
				'expectedHtmlTitle' => '(difference-title: Test page 1)',

				// Shouldn't render any HTML: modaction=show displays wikitext
				'expectedOutputHtml' => "This text is '''very bold''' and ''most italic''."
			] ],

			// modaction=preview
			'Preview (normal edit)' => [ [
				'modaction' => 'preview',
				'mod_title' => 'Test_page_1',
				'mod_text' => "This text is '''very bold''' and ''most italic''.\n",
				'mod_new_len' => 49,
				'expectedOutputHtml' => 'This text is <b>very bold</b> and <i>most italic</i>.',
				'expectedHtmlTitle' => '(moderation-preview-title: Test page 1)'
			] ],

			// Check modaction=show for uploads/moves
			// TODO: check download link (for non-images) and full image link (for images)
			'Show (100x100 PNG image, no description)' => [ [
				'modaction' => 'show',
				'filename' => 'image100x100.png',
				'nullEdit' => true,
				'expectedOutput' => '(moderation-diff-upload-notext)'
			] ],
			'Show (100x100 PNG image, with description)' => [ [
				'modaction' => 'show',
				'filename' => 'image100x100.png',
				'mod_text' => 'Funny description',
				'mod_new_len' => 17,
				'expectedOutput' => 'Funny description'
			] ],
			'Show (non-image upload: OGG audio file, no description)' => [ [
				'modaction' => 'show',
				'filename' => 'sound.ogg',
				'nullEdit' => true,
				'expectedOutput' => '(moderation-diff-upload-notext)'
			] ],
			'Show (move)' => [ [
				'modaction' => 'show',
				'mod_type' => 'move',
				'mod_namespace' => NS_TEMPLATE,
				'mod_title' => 'OrigTitle',
				'mod_page2_namespace' => NS_CATEGORY,
				'mod_page2_title' => 'NewTitle',
				'expectedOutput' => '(movepage-page-moved: Template:OrigTitle, Category:NewTitle)'
			] ],

			// Errors printed by actions:
			'Error: unknown modaction' => [ [
				'modaction' => 'makesandwich',
				'expectedError' => '(moderation-unknown-modaction)'
			] ],
			'Error: attempt to Reject already rejected edit' => [ [
				'modaction' => 'reject',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-already-rejected)'
			] ],
			'Error: attempt to Approve edit which was rejected long ago' => [ [
				'modaction' => 'approve',
				'mod_rejected' => 1,
				'mod_timestamp' => '-' . ( $wgModerationTimeToOverrideRejection + 1 ) . ' seconds',
				'expectedError' => '(moderation-rejected-long-ago)'
			] ],
			'Error: attempt to Approve edit which should cause an error in doEditContent' => [ [
				// approve: handing of situation when doEditContent() results in an error
				'modaction' => 'approve',
				'simulateInvalidJsonContent' => true,
				'expectedOutput' => '(invalid-content-data)'
			] ],
			'Error: attempt to ApproveAll when there is nothing to approve' => [ [
				'modaction' => 'approveall',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-nothing-to-approveall)'
			] ],
			'Error: attempt to RejectAll when there is nothing to reject' => [ [
				'modaction' => 'rejectall',
				'mod_rejected' => 1,
				'expectedError' => '(moderation-nothing-to-rejectall)'
			] ],
			'Error: attempt to Merge when there is no edit conflict' => [ [
				'modaction' => 'merge',
				'expectedError' => '(moderation-merge-not-needed)'
			] ],
			'Error: attempt to Merge by non-automoderated moderator' => [ [
				'modaction' => 'merge',
				'mod_conflict' => 1,
				'notAutomoderated' => true,
				'expectedError' => '(moderation-merge-not-automoderated)'
			] ],

			// editchange{,submit} shouldn't be available without $wgModerationEnableEditChange
			'Error: attempt to EditChange without $wgModerationEnableEditChange' => [ [

				'modaction' => 'editchange',
				'expectedError' => '(moderation-unknown-modaction)'
			] ],
			'Error: attempt to EditChangeSubmit without $wgModerationEnableEditChange' => [ [
				'modaction' => 'editchangesubmit',
				'expectedError' => '(moderation-unknown-modaction)'
			] ],
			'Error: attempt to use EditChange on non-text change (page move)' => [ [
				'modaction' => 'editchange',
				'enableEditChange' => true,
				'mod_type' => 'move',
				'expectedError' => '(moderation-editchange-not-edit)'
			] ],
			'Error: attempt to use EditChangeSubmit on non-text change (page move)' => [ [
				'modaction' => 'editchangesubmit',
				'enableEditChange' => true,
				'mod_type' => 'move',
				'expectedError' => '(moderation-edit-not-found)'
			] ],

			// Actions that don't modify anything shouldn't throw ReadOnlyError
			'Show (when wiki is readonly)' => [ [ 'modaction' => 'show', 'readonly' => true ] ],
			'ShowImage (when wiki is readonly)' => [ [
				'modaction' => 'showimg',
				'filename' => 'image100x100.png',
				'readonly' => true,
				'expectedContentType' => 'image/png',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Image100x100.png",
				'expectOutputToEqualUploadedFile' => true
			] ],
			'Preview (when wiki is readonly)' => [ [
				'modaction' => 'preview',
				'readonly' => true
			] ],

			'successful EditChangeSubmit' => [ [
				'modaction' => 'editchangesubmit',
				'enableEditChange' => true,
				'mod_user' => 0,
				'mod_user_text' => '127.1.2.3',
				'postData' => [
					'wpTextbox1' => 'Modified text ~~~', // "~~~" is to test PreSaveTransform
					'wpSummary' => 'Modified edit summary'
				],
				'expectedOutput' => '(moderation-editchange-ok)',
				'expectedFields' => [
					'mod_text' => 'Modified text [[Special:Contributions/127.1.2.3|127.1.2.3]]',
					'mod_new_len' => 59,
					'mod_comment' => 'Modified edit summary'
				],
				'expectedLogAction' => 'editchange'
			] ],
			'no-op EditChangSubmit (the original text hasnt\'t been changed)' => [ [
				'modaction' => 'editchangesubmit',
				'enableEditChange' => true,
				'mod_text' => 'Original Text 1',
				'mod_comment' => 'Original Summary 1',
				'mod_new_len' => 15,
				'postData' => [
					'wpTextbox1' => 'Original Text 1',
					'wpSummary' => 'Original Summary 1'
				],
				'expectedOutput' => '(moderation-editchange-ok)',
				'expectLogEntry' => false
			] ],

			/*
				modaction=showimg.
				NOTE: when testing thumbnails, we check two images:
				one smaller than thumbnail's width, one larger,
				because they are handled differently.
				First test is on image640x50.png (large image),
				second on image100x100.png (smaller image).

				TODO: move testMissingStashedImage() here? (error 404 "image is missing from stash")
			*/
			'ShowImage (650x50 PNG image)' => [ [
				'modaction' => 'showimg',
				'filename' => 'image640x50.png',
				'mod_title' => 'Image_name_with_spaces.png',
				'expectedContentType' => 'image/png',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Image_name_with_spaces.png",
				'expectOutputToEqualUploadedFile' => true
			] ],
			'ShowImage: thumbnail of 650x50 PNG image (larger than thumbnail width)' => [ [
				'modaction' => 'showimg',
				'filename' => 'image640x50.png',
				'expectedContentType' => 'image/png',
				'expectedContentDisposition' =>
					"inline;filename*=UTF-8''" .
					ModerationActionShowImage::THUMB_WIDTH .
					"px-Image640x50.png",
				'showThumb' => true,
				'expectOutputToEqualUploadedFile' => false, // Thumbnail, not original image
				'expectedImageWidth' => ModerationActionShowImage::THUMB_WIDTH
			] ],
			'ShowImage: thumbnail of 100x100 PNG image (smaller than thumbnail width)' => [ [
				'modaction' => 'showimg',
				'filename' => 'image100x100.png',
				'expectedContentType' => 'image/png',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Image100x100.png",
				'showThumb' => true,

				// This image is not wide enough,
				// its thumbnail will be the same as the original image.
				'expectOutputToEqualUploadedFile' => true
			] ],
			'ShowImage (non-image upload: OGG audio file)' => [ [
				'modaction' => 'showimg',
				'filename' => 'sound.ogg',
				'expectedContentType' => 'application/ogg',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Sound.ogg",
				'expectOutputToEqualUploadedFile' => true
			] ],
			'ShowImage: thumbnail mode for non-image (OGG audio file)' => [ [
				'modaction' => 'showimg',
				'filename' => 'sound.ogg',
				'expectedContentType' => 'application/ogg',
				'expectedContentDisposition' => "inline;filename*=UTF-8''Sound.ogg",
				'showThumb' => true,

				// OGG is not an image, thumbnail will be the same as the original file.
				'expectOutputToEqualUploadedFile' => true
			] ]
		];

		// "Already merged" error
		foreach ( [ 'approve', 'reject', 'merge' ] as $action ) {
			$sets["Error: modaction=$action used on edit which is already merged"] = [ [
				'modaction' => $action,
				'mod_conflict' => 1,
				'mod_merged_revid' => 12345,
				'expectedError' => '(moderation-already-merged)'
			] ];
		}

		// ReadOnlyError exception from non-readonly actions
		$nonReadOnlyActions = [ 'approve', 'approveall', 'reject', 'rejectall',
			'block', 'unblock', 'merge', 'editchange', 'editchangesubmit' ];
		foreach ( $nonReadOnlyActions as $action ) {
			$sets["Error: ReadOnlyError exception from non-readonly modaction=$action"] = [ [
				'modaction' => $action, 'readonly' => true, 'expectReadOnlyError' => true
			] ];
		}

		// 'moderation-edit-not-found' from everything
		$allActions = array_merge( $nonReadOnlyActions, [ 'show', 'showimg', 'preview' ] );
		foreach ( $allActions as $action ) {
			$options = [
				'modaction' => $action,
				'simulateNoSuchEntry' => true,
				'expectedError' => '(moderation-edit-not-found)'
			];
			if ( $action == 'editchange' || $action == 'editchangesubmit' ) {
				$options['enableEditChange'] = true;
			}

			$sets["Error: \"edit not found\" from modaction=$action"] = [ $options ];
		}

		return $sets;
	}
}

/**
 * Represents one TestSet for testAction().
 */
class ModerationActionTestSet extends ModerationTestsuitePendingChangeTestSet {

	const READONLY_REASON = 'Simulated ReadOnly mode';

	/**
	 * @var string Name of action, e.g. 'approve' or 'rejectall'.
	 */
	protected $modaction = null;

	/**
	 * @var bool If true, $wgModerationEnableEditChange is enabled.
	 */
	protected $enableEditChange = false;

	/**
	 * @var array Expected field values after the action.
	 * Field that are NOT in this array are expected to be unmodified.
	 */
	protected $expectedFields = [];

	/**
	 * @var string|null Error that should be printed by this action, e.g. "(sessionfailure)".
	 */
	protected $expectedError = null;

	/**
	 * @var string|null Expected subtype of LogEntry. If null, assumed to be same as $modaction.
	 * Example of non-default value: 'approve-move' for modaction=approve.
	 */
	protected $expectedLogAction = null;

	/**
	 * @var bool If true, new LogEntry is expected to appear after the action.
	 * If false, LogEntry is expected to NOT appear. If null, auto-detect.
	 */
	protected $expectLogEntry = null;

	/**
	 * @var bool If true, userpage of author of the change is the expected target of LogEntry.
	 */
	protected $expectedLogTargetIsAuthor = false;

	/**
	 * @var string|null Expected value of Content-Disposition header (if any) or null.
	 */
	protected $expectedContentDisposition = null;

	/**
	 * @var string|null If not null, we expect response to have this Content-Type (e.g. image/png).
	 */
	protected $expectedContentType = null;

	/**
	 * @var int|null If not null, we expect response to be an image with this width.
	 */
	protected $expectedImageWidth = null;

	/**
	 * @var string|null If not null, we expect <h1> tag to contain this string.
	 */
	protected $expectedHtmlTitle = null;

	/**
	 * @var bool|null If true/false, author of change is expected to become (not) modblocked.
	 * If null, blocked status is expected to remain the same.
	 */
	protected $expectModblocked = null;

	/**
	 * @var array Action links to expect and NOT expect on the result page.
	 * Example: [ 'approve' => true, 'reject' => false ].
	 * Not listed actions are not checked for existence/nonexistence.
	 */
	protected $expectActionLinks = [];

	/**
	 * @var bool If true, binary output of this modaction must be the same as content of $filename.
	 */
	protected $expectOutputToEqualUploadedFile = false;

	/**
	 * @var bool If true, we expect ReadOnlyError exception to be thrown.
	 */
	protected $expectReadOnlyError = false;

	/**
	 * @var bool If true, rejection fields will be added to $expectedFields.
	 */
	protected $expectRejected = false;

	/**
	 * @var bool If true, post-approve consequences are tested (e.g. that database row is deleted).
	 * ($expectedFields are ignored).
	 */
	protected $expectApproved = false;

	/**
	 * @var string Plaintext that should be present in the output of modaction.
	 */
	protected $expectedOutput = '';

	/**
	 * @var string Raw HTML that should be present in the output of modaction.
	 * @see $expectedOutput
	 */
	protected $expectedOutputHtml = '';

	/**
	 * @var array Request body to send with POST request.
	 */
	protected $postData = [];

	/**
	 * @var bool If true, the wiki will be in readonly mode.
	 */
	protected $readonly = false;

	/**
	 * @var bool If true, author of this change will be deleted from the database.
	 * This mimicks situation when the user account was deleted via Extension:UserMerge.
	 * This is used in the test "can we still Approve a pending edit by deleted author?"
	 */
	protected $simulateDeletedAuthor = false;

	/**
	 * @var bool If true, page will be of JSON model and content of this edit will be invalid JSON.
	 * This is used for test "does modaction=approve detect errors from doEditContent?"
	 */
	protected $simulateInvalidJsonContent = false;

	/**
	 * @var bool If true, incorrect modid will be used in the action URL.
	 */
	protected $simulateNoSuchEntry = false;

	/**
	 * @var bool If true, thumb=1 will be added to action URL (for modaction=showimg).
	 */
	protected $showThumb = false;

	/**
	 * Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {
				case 'enableEditChange':
				case 'expectedContentType':
				case 'expectedContentDisposition':
				case 'expectedFields':
				case 'expectedError':
				case 'expectedImageWidth':
				case 'expectedHtmlTitle':
				case 'expectedLogAction':
				case 'expectLogEntry':
				case 'expectedLogTargetIsAuthor':
				case 'expectModblocked':
				case 'expectedOutput':
				case 'expectedOutputHtml':
				case 'expectActionLinks':
				case 'expectOutputToEqualUploadedFile':
				case 'expectReadOnlyError':
				case 'expectRejected':
				case 'expectApproved':
				case 'modaction':
				case 'postData':
				case 'readonly':
				case 'simulateDeletedAuthor':
				case 'simulateInvalidJsonContent':
				case 'simulateNoSuchEntry':
				case 'showThumb':
					$this->$key = $value;
					unset( $options[$key] );
			}
		}

		if ( !$this->modaction ) {
			throw new MWException( __CLASS__ . ": parameter 'modaction' is required" );
		}

		if ( $this->expectLogEntry === null ) {
			// Auto-detect whether the log entry is needed.
			$this->expectLogEntry = false;
			if ( $this->expectedFields ||
				$this->expectRejected ||
				$this->expectApproved ||
				$this->expectModblocked !== null
			) {
				$this->expectLogEntry = true;
			}
		}

		parent::applyOptions( $options );

		if ( $this->simulateInvalidJsonContent ) {
			$this->fields['mod_text'] = 'NOT A VALID JSON';
			$this->fields['mod_new_len'] = 16;

			$this->getTestsuite()->setMwConfig( 'NamespaceContentModels',
				[ $this->fields['mod_namespace'] => CONTENT_MODEL_JSON ]
			);
		}

		$this->expectedFields += $this->fields;

		if ( !$this->expectedLogAction ) {
			// Default: $expectedLogAction is the same as $modaction
			// (e.g. modaction=reject creates 'moderation/reject' log entries).
			$this->expectedLogAction = $this->modaction;
		}

		if ( $this->expectedContentType == 'application/ogg' ) {
			// Allow OGG files (music, i.e. not images) to be uploaded.
			global $wgFileExtensions;
			$this->getTestsuite()->setMwConfig( 'FileExtensions',
				array_merge( $wgFileExtensions, [ 'ogg' ] ) );
		}
	}

	/**
	 * Returns the expected target of LogEntry created by this modaction.
	 * @return Title
	 */
	protected function getExpectedLogTarget() {
		if ( $this->expectedLogTargetIsAuthor ) {
			// For actions like 'block' or 'rejectall':
			// target is the author's userpage.
			return Title::makeTitle( NS_USER, $this->fields['mod_user_text'] );
		}

		// Default (for actions like 'approve' or 'reject'):
		// target is the page affected by this change.
		return $this->getExpectedTitleObj();
	}

	/**
	 * Returns the User who will perform this modaction.
	 * @return User
	 */
	protected function getModerator() {
		$t = $this->getTestsuite();
		return $this->notAutomoderated ?
			$t->moderatorButNotAutomoderated :
			$t->moderator;
	}

	/**
	 * Utility function to check if CACHE_MEMCACHED actually works.
	 * This is used to skip ReadOnly tests when memcached is unavailable
	 * (because CACHE_DB doesn't work in ReadOnly mode).
	 * @return bool
	 **/
	private function doesMemcachedWork() {
		$cache = wfGetCache( CACHE_MEMCACHED );

		$testKey = $cache->makeKey( 'moderation-testsuite-check-memcached-availability' );
		$testVal = 'it works ' . rand();

		// Check whether the stored entry has actually been saved.
		$cache->set( $testKey, $testVal );
		return ( $cache->get( $testKey ) == $testVal );
	}

	/**
	 * Assert the consequences of the action.
	 */
	protected function assertResults( ModerationTestCase $testcase ) {
		// Add rejection-related fields to $this->expectedFields.
		// It was too early to do in applyOptions(), because $this->fields['mod_id'] was unknown.
		if ( $this->expectRejected ) {
			$this->expectedFields = array_merge( $this->expectedFields, [
				'mod_rejected' => 1,
				'mod_rejected_by_user' => $this->getModerator()->getId(),
				'mod_rejected_by_user_text' => $this->getModerator()->getName(),
				'mod_preloadable' => $this->fields['mod_id']
			] );
		}

		$t = $this->getTestsuite();

		if ( $this->readonly ) {
			// We can't use CACHE_DB for SessionCache in ReadOnly mode,
			// because the database won't be writable.
			$t->setMwConfig( 'SessionCacheType', CACHE_MEMCACHED );

			if ( !$this->doesMemcachedWork() ) {
				// No way to login when both Memcached and CACHE_DB are unavailable.
				$testcase->markTestSkipped(
					'Test skipped: Memcached is unavailable (ReadOnly tests need it for login to work)' );
			}
		}

		$t->loginAs( $this->getModerator() );

		if ( $this->readonly ) {
			$t->setMwConfig( 'ReadOnly', self::READONLY_REASON );
		}

		if ( $this->enableEditChange ) {
			$t->setMwConfig( 'ModerationEnableEditChange', true );
		}

		if ( $this->simulateDeletedAuthor ) {
			global $wgActorTableSchemaMigrationStage;
			if ( isset( $wgActorTableSchemaMigrationStage ) && defined( 'SCHEMA_COMPAT_NEW' ) ) {
				// Approving edits of deleted users is not supported with SCHEMA_COMPAT_NEW mode
				// (which is default in MW 1.33+),
				// because User::getActorId() won't allow a non-registered user to have a usable username.
				// FIXME: Can probably workaround this by appending a non-allowed symbol to username.
				if ( $wgActorTableSchemaMigrationStage == SCHEMA_COMPAT_NEW ) {
					$testcase->markTestSkipped(
						'Test skipped: approving edits of deleted users is not supported in MediaWiki 1.33+' );
				}
			}

			// Delete the author from the database (similar to Extension:UserMerge)
			$dbw = wfGetDB( DB_MASTER );
			$dbw->delete( 'user', [
				'user_id' => $this->fields['mod_user']
			], __METHOD__ );

			User::purge( wfWikiID(), $this->fields['mod_user'] );
		}

		if ( $this->existing && $this->filename && $this->expectApproved ) {
			/* Wait up to 1 second to avoid archived name collision */
			$t->sleepUntilNextSecond();
		}

		// Execute the action, check HTML printed by the action
		$url = $this->getActionURL();
		$req = ( $this->modaction == 'editchangesubmit' ) ?
			$t->httpPost( $url, $this->postData ) :
			$t->httpGet( $url );

		// Clear the link cache, so that methods like $title->getLatestRevID()
		// wouldn't return stale data after modaction=approve.
		Title::clearCaches();

		if ( $this->expectedContentType ) {
			$this->assertBinaryOutput( $testcase, $req );
		} else {
			$this->assertHtmlOutput( $testcase, $t->html->loadFromReq( $req ) );
		}

		// Check the mod_* fields in the database after the action.
		$this->assertDatabaseChanges( $testcase );
		$this->assertApproved( $testcase );
		$this->assertBlockedStatus( $testcase );
		$this->assertLogEntry( $testcase );
	}

	/**
	 * Check HTML output printed by the action URL.
	 * @see assertBinaryOutput
	 */
	protected function assertHtmlOutput(
		ModerationTestCase $testcase,
		ModerationTestsuiteHTML $html
	) {
		if ( $this->expectedHtmlTitle ) {
			$testcase->assertEquals(
				'(pagetitle: ' . $this->expectedHtmlTitle . ')',
				$html->getTitle(),
				"modaction={$this->modaction}: unexpected HTML title."
			);
		}

		if ( $this->expectedOutputHtml ) {
			$testcase->assertContains(
				$this->expectedOutputHtml,
				$html->saveHTML( $html->getMainContent() ),
				"modaction={$this->modaction}: unexpected HTML output."
			);
		}

		$output = $html->getMainText();
		if ( $this->expectedOutput ) {
			$testcase->assertContains( $this->expectedOutput, $output,
				"modaction={$this->modaction}: unexpected output." );
		}

		$expectedReadOnlyText = '(readonlytext: ' . self::READONLY_REASON . ')';
		if ( $this->expectReadOnlyError ) {
			$testcase->assertContains( $expectedReadOnlyText, $output,
				"modaction={$this->modaction}: no ReadOnlyError exception in ReadOnly mode." );
		} else {
			$testcase->assertNotContains( $expectedReadOnlyText, $output,
				"modaction={$this->modaction}: unexpected ReadOnlyError exception." );
		}

		$error = $html->getModerationError();
		if ( $this->expectedError ) {
			$testcase->assertEquals( $this->expectedError, $error,
				"modaction={$this->modaction}: expected error not shown." );
		} else {
			$testcase->assertNull( $this->expectedError,
				"modaction={$this->modaction}: unexpected error." );
		}

		foreach ( $this->expectActionLinks as $action => $isExpected ) {
			$link = $html->getElementByXPath( '//a[contains(@href,"modaction=' . $action . '")]' );
			$testcase->assertEquals(
				[ "action link [$action] exists" => $isExpected ],
				[ "action link [$action] exists" => (bool)$link ]
			);
		}
	}

	/**
	 * Check non-HTML output printed by the action URL.
	 * @see assertHtmlOutput
	 */
	protected function assertBinaryOutput(
		ModerationTestCase $testcase,
		ModerationTestsuiteResponse $req
	) {
		$testcase->assertEquals(
			$this->expectedContentType,
			$req->getResponseHeader( 'Content-Type' ),
			"modaction={$this->modaction}: wrong Content-Type header."
		);

		if ( $this->filename ) {
			$origFile = file_get_contents( $this->findSourceFilename() );
			$downloadedFile = $req->getContent();

			$testedMetric = "output matches contents of [{$this->filename}]";
			$testcase->assertEquals(
				[ $testedMetric => $this->expectOutputToEqualUploadedFile ],
				[ $testedMetric => ( $origFile == $downloadedFile ) ]
			);

			if ( isset( $this->expectedImageWidth ) ) {
				// Determine width/height of image in $req->getContent().
				list( $width, $height ) = $this->getImageSize( $downloadedFile );

				$testcase->assertEquals( $this->expectedImageWidth, $width,
					"modaction={$this->modaction}: thumbnail's width doesn't match expected" );

				// Has the ratio been preserved?
				list( $origWidth, $origHeight ) = $this->getImageSize( $origFile );

				$testcase->assertEquals(
					round( $origWidth / $origHeight, 2 ),
					round( $width / $height, 2 ),
					"modaction={$this->modaction}: thumbnail's ratio doesn't match original" );
			}
		}

		if ( $this->expectedContentDisposition ) {
			$testcase->assertEquals(
				$this->expectedContentDisposition,
				$req->getResponseHeader( 'Content-Disposition' ),
				"modaction={$this->modaction}: wrong Content-Disposition header."
			);
		}
	}

	/**
	 * Determine width/height of downloaded image.
	 * @param string $contents
	 * @return array Array of two integers (width and height).
	 */
	private function getImageSize( $contents ) {
		$path = tempnam( sys_get_temp_dir(), 'modtest_thumb' );
		file_put_contents( $path, $contents );
		$size = getimagesize( $path );
		unlink( $path );

		return $size;
	}

	/**
	 * Check whether/how was the database row modified by this action.
	 */
	protected function assertDatabaseChanges( ModerationTestCase $testcase ) {
		$dbw = wfGetDB( DB_MASTER );
		if ( $this->expectApproved ) {
			$row = $dbw->selectRow(
				'moderation',
				'*',
				[ 'mod_id' => $this->fields['mod_id'] ],
				__METHOD__
			);
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: database row wasn't deleted" );
		} else {
			$this->assertRowEquals( $this->expectedFields );
		}
	}

	/**
	 * Check the necessary consequences of modaction=approve(all).
	 */
	protected function assertApproved( ModerationTestCase $testcase ) {
		if ( !$this->expectApproved ) {
			return; // Not an Approve operation.
		}

		if ( $this->fields['mod_type'] == 'move' ) {
			// TODO: test consequences of the move
			return;
		}

		$rev = $this->getTestsuite()->getLastRevision( $this->getExpectedTitle() );

		$testcase->assertEquals( $this->fields['mod_user_text'], $rev['user'] );
		$testcase->assertEquals( $this->fields['mod_text'], $rev['*'] );

		$isReupload = $this->existing && $this->filename;
		if ( !$isReupload ) {
			// Reuploads don't place edit comment into rev_comment.
			$testcase->assertEquals( $this->fields['mod_comment'], $rev['comment'] );

			// Changing rev_timestamp in ApproveHook is not yet implemented for reuploads.
			$expectedTimestamp = wfTimestamp( TS_ISO_8601, $this->fields['mod_timestamp'] );
			$testcase->assertEquals( $expectedTimestamp, $rev['timestamp'] );
		}

		if ( $this->filename ) {
			$file = wfFindFile( $this->getExpectedTitleObj() );
			$testcase->assertTrue( $file->exists(), "Approved file doesn't exist in FileRepo" );

			$srcPath = ModerationTestsuite::findSourceFilename( $this->filename );
			$expectedContents = file_get_contents( $srcPath );

			$contents = file_get_contents( $file->getLocalRefPath() );

			$this->getTestcase()->assertEquals( $expectedContents, $contents,
				"Approved file is different from uploaded file" );
		}

		// Check that recentchanges (unlike page history) contain the timestamp of approval,
		// not timestamp of the original edit.
		$dbw = wfGetDB( DB_MASTER );
		$rcTimestamp = $dbw->selectField( 'recentchanges', 'rc_timestamp', [], __METHOD__,
			[ 'ORDER BY' => 'rc_timestamp DESC' ] );
		$this->assertTimestampIsRecent( $rcTimestamp );
	}

	/**
	 * Check whether the moderation block was added/deleted.
	 */
	protected function assertBlockedStatus( ModerationTestCase $testcase ) {
		$expectedBlocker = $this->getModerator();
		if ( $this->expectModblocked === null ) {
			// Default: block status shouldn't change.
			$this->expectModblocked = $this->modblocked;

			// If the user was already blocked via [ 'modblocked' => true ],
			// we should expect getModeratorWhoBlocked() to tell who did it.
			$expectedBlocker = $this->getModeratorWhoBlocked();
		}

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow(
			'moderation_block',
			[
				'mb_user',
				'mb_by',
				'mb_by_text',
				'mb_timestamp'
			],
			[ 'mb_address' => $this->fields['mod_user_text'] ],
			__METHOD__
		);

		if ( $this->expectModblocked ) {
			$fields = get_object_vars( $row );

			// Not-strict check that 'mb_timestamp' is not too far from "now"
			$this->assertTimestampIsRecent( $fields['mb_timestamp'] );
			unset( $fields['mb_timestamp'] );

			$expectedFields = [
				'mb_user' => $this->fields['mod_user'],
				'mb_by' => $expectedBlocker->getId(),
				'mb_by_text' => $expectedBlocker->getName()
			];
			$testcase->assertEquals( $expectedFields, $fields );
		} else {
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: Author is unexpectedly blacklisted as spammer." );
		}
	}

	/**
	 * Check the log entry created by this action (if any).
	 */
	protected function assertLogEntry( ModerationTestCase $testcase ) {
		// Check the LogEntry, if any
		$queryInfo = DatabaseLogEntry::getSelectQueryData();
		$queryInfo['conds']['log_type'] = 'moderation';
		$queryInfo['options']['ORDER BY'] = 'log_id DESC';

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow(
			$queryInfo['tables'],
			$queryInfo['fields'],
			$queryInfo['conds'],
			__METHOD__,
			$queryInfo['options'],
			$queryInfo['join_conds']
		);

		if ( !$this->expectLogEntry ) {
			$testcase->assertFalse( $row,
				"modaction={$this->modaction}: unexpected LogEntry appeared after readonly action" );
		} else {
			$testcase->assertNotFalse( $row,
				"modaction={$this->modaction}: logging table is empty after the action" );

			$logEntry = DatabaseLogEntry::newFromRow( $row );

			$testcase->assertEquals( 'moderation', $logEntry->getType(),
				"modaction={$this->modaction}: incorrect LogEntry type" );
			$testcase->assertEquals( $this->expectedLogAction, $logEntry->getSubtype(),
				"modaction={$this->modaction}: incorrect LogEntry subtype" );
			$testcase->assertEquals(
				$this->getModerator()->getName(),
				$logEntry->getPerformer()->getName(),
				"modaction={$this->modaction}: incorrect name of moderator in LogEntry" );
			$testcase->assertEquals( $this->getExpectedLogTarget(), $logEntry->getTarget(),
				"modaction={$this->modaction}: incorrect LogEntry target" );

			$this->assertTimestampIsRecent( $logEntry->getTimestamp() );

			$expectedParams = [];
			switch ( $this->expectedLogAction ) {
				case 'reject':
					$expectedParams = [
						'modid' => $this->fields['mod_id'],
						'user' => $this->fields['mod_user'],
						'user_text' => $this->fields['mod_user_text']
					];
					break;

				case 'rejectall':
				case 'approveall':
					$expectedParams = [
						'4::count' => 1
					];
					break;

				case 'approve':
					$expectedParams = [
						'revid' => $this->getExpectedTitleObj()->getLatestRevID()
					];
					break;

				case 'approve-move':
					$expectedParams = [
						'4::target' => $this->getExpectedPage2Title(),
						'user' => $this->fields['mod_user'],
						'user_text' => $this->fields['mod_user_text']
					];
					break;

				case 'editchange':
					$expectedParams = [
						'modid' => $this->fields['mod_id']
					];
			}

			$testcase->assertEquals( $expectedParams, $logEntry->getParameters(),
				"modaction={$this->modaction}: incorrect LogEntry parameters" );
		}
	}

	/**
	 * Calculates the URL of modaction requested by this TestSet.
	 */
	protected function getActionURL() {
		$q = [
			'modid' => $this->fields['mod_id'],
			'modaction' => $this->modaction
		];
		if ( !in_array( $this->modaction, [ 'show', 'showimg', 'preview', 'editchange' ] ) ) {
			$q['token'] = $this->getTestsuite()->getEditToken();
		}

		if ( $this->showThumb ) {
			// modaction=showimg&thumb=1
			$q['thumb'] = 1;
		}

		if ( $this->simulateNoSuchEntry ) {
			$q['modid'] = 0; // Entry with ID=0 never exists
		}

		return SpecialPage::getTitleFor( 'Moderation' )->getLocalURL( $q );
	}
}
