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
 * Unit test of ModerationEntryFormatter.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\ActionLinkRenderer;
use MediaWiki\Moderation\TimestampFormatter;

require_once __DIR__ . "/autoload.php";

class ModerationEntryFormatterTest extends ModerationUnitTestCase {
	/**
	 * @var mixed
	 */
	private $linkRenderer;

	/**
	 * @var mixed
	 */
	private $actionLinkRenderer;

	/**
	 * @var mixed
	 */
	private $timestampFormatter;

	/**
	 * @var mixed
	 */
	private $context;

	/**
	 * @var mixed
	 */
	private $canSkip;

	/**
	 * Test that ModerationEntryFormatter::getModerator() returns current User from $context.
	 * @covers ModerationEntryFormatter
	 */
	public function testGetModerator() {
		$expectedUser = self::getTestUser()->getUser();
		$this->context->expects( $this->once() )->method( 'getUser' )->willReturn( $expectedUser );

		$formatter = $this->makeTestFormatter();
		$this->assertSame( $expectedUser, $formatter->getModerator() );
	}

	/**
	 * Test that ModerationEntryFormatter::msg() is a shortcut for $context->msg().
	 * @covers ModerationEntryFormatter
	 */
	public function testMsg() {
		$expectedResult = 'Result ' . rand( 0, 100000 );
		$this->context->expects( $this->once() )->method( 'msg' )
			->with(
				$this->identicalTo( 'param1' ),
				$this->identicalTo( 'param2' ),
				$this->identicalTo( 'param3' )
			)
			->willReturn( $expectedResult );

		$formatter = $this->makeTestFormatter();
		$result = $formatter->msg( 'param1', 'param2', 'param3' );
		$this->assertSame( $expectedResult, $result );
	}

	/**
	 * Test the return value of ModerationEntryFormatter::getFields().
	 * @param bool $isCheckUser
	 * @dataProvider dataProviderFields
	 *
	 * @covers ModerationEntryFormatter
	 */
	public function testFields( $isCheckUser ) {
		$expectedUser = self::getTestUser( $isCheckUser ? [ 'checkuser' ] : [] )->getUser();
		RequestContext::getMain()->setUser( $expectedUser );

		$expectedFields = [
			'mod_user AS user',
			'mod_user_text AS user_text',
			'mod_namespace AS namespace',
			'mod_title AS title',
			'mod_type AS type',
			'mod_page2_namespace AS page2_namespace',
			'mod_page2_title AS page2_title',
			'mod_id AS id',
			'mod_timestamp AS timestamp',
			'mod_comment AS comment',
			'mod_minor AS minor',
			'mod_bot AS bot',
			'mod_new AS new',
			'mod_old_len AS old_len',
			'mod_new_len AS new_len',
			'mod_rejected AS rejected',
			'mod_rejected_by_user AS rejected_by_user',
			'mod_rejected_by_user_text AS rejected_by_user_text',
			'mod_rejected_batch AS rejected_batch',
			'mod_rejected_auto AS rejected_auto',
			'mod_conflict AS conflict',
			'mod_merged_revid AS merged_revid',
			'mb_id AS blocked'
		];
		if ( $isCheckUser ) {
			$expectedFields[] = 'mod_ip AS ip';
		}

		$fields = ModerationEntryFormatter::getFields();
		$this->assertEquals( $expectedFields, $fields );
	}

	/**
	 * Provide datasets for testFields() runs.
	 * @return array
	 */
	public function dataProviderFields() {
		return [
			'checkuser' => [ true ],
			'not a checkuser' => [ false ]
		];
	}

	/**
	 * Test that the result of getHTML() contains all the necessary text, action links, etc.
	 * @param array $options
	 * @dataProvider dataProviderGetHTML
	 *
	 * @covers ModerationEntryFormatter
	 */
	public function testGetHTML( array $options ) {
		$authorUser = self::getTestUser()->getUser();
		$row = (object)( ( $options['fields'] ?? [] ) + [
			'user' => $authorUser->getId(),
			'user_text' => $authorUser->getName(),
			'namespace' => rand( 0, 1 ),
			'title' => 'Test_page_' . rand( 0, 100000 ),
			'type' => 'edit',
			'page2_namespace' => 0,
			'page2_title' => 'Test_page_2_' . rand( 0, 100000 ),
			'id' => 12345,
			'timestamp' => wfGetDB( DB_REPLICA )->timestamp(),
			'comment' => 'Some reason ' . rand( 0, 100000 ),
			'minor' => 0,
			'bot' => 0,
			'new' => 0,
			'old_len' => 2345,
			'new_len' => 9876,
			'rejected' => 0,
			'rejected_by_user' => 0,
			'rejected_by_user_text' => null,
			'rejected_batch' => 0,
			'rejected_auto' => 0,
			'conflict' => 0,
			'merged_revid' => 0,
			'blocked' => 0
		] );

		$this->setMwGlobals( $options['globals'] ?? [] );

		$moderator = self::getTestUser( [ 'moderator' ] )->getUser();
		$lang = Language::factory( 'qqx' );

		$this->context->expects( $this->any() )->method( 'getUser' )->willReturn( $moderator );
		$this->context->expects( $this->any() )->method( 'getLanguage' )->willReturn( $lang );
		$this->context->expects( $this->any() )->method( 'getConfig' )
			->willReturn( RequestContext::getMain()->getConfig() );

		// Mock all calls to msg(), makeLink() and TimestampFormatter:format().
		$this->actionLinkRenderer->expects( $this->any() )->method( 'makeLink' )
			->willReturnCallback( function ( $action, $id ) use ( $row ) {
				$this->assertEquals( $row->id, $id );
				return "{ActionLink:$action}";
			} );
		$this->linkRenderer->expects( $this->any() )
			->method( 'makeLink' )->willReturnCallback( static function ( Title $title ) {
				return '{PageLink:' . $title->getNamespace() . '|' . $title->getDBKey() . '}';
			} );
		$this->linkRenderer->expects( $this->any() )
			->method( 'makePreloadedLink' )->with(
				// This is "merged revision" link: makePreloadedLink() isn't used for anything else.
				$this->isInstanceOf( Title::class ),
				$this->identicalTo( '(moderation-merged-link)' ),
				$this->identicalTo( '' ),
				$this->identicalTo( [ 'title' => '(tooltip-moderation-merged-link)' ] ),
				$this->logicalAnd( $this->arrayHasKey( 'diff' ), $this->countOf( 1 ) )
			)->willReturnCallback(
				static function ( Title $title, $text, $classes, array $extraAttribs, array $query ) {
					return '{MergedRevisionLink:' . $title->getNamespace() . '|' .
						$title->getDBKey() . '|' . ( $query['diff'] ?? 'unknown' ) . '}';
				} );
		$this->context->expects( $this->any() )->method( 'msg' )
			->willReturnCallback( static function ( ...$args ) use ( $lang ) {
				return wfMessage( ...$args )->inLanguage( $lang );
			} );
		$this->timestampFormatter->expects( $this->once() )->method( 'format' )->with(
			$this->identicalTo( $row->timestamp )
		)->willReturn( '{FormattedTime}' );

		if ( isset( $options['moderatorIsAutomoderated'] ) ) {
			// Edit conflict test: $canSkip will be used to confirm that moderator is automoderated.
			$this->canSkip->expects( $this->once() )->method( 'canEditSkip' )->with(
				$this->identicalTo( $moderator ),
				$this->identicalTo( $row->namespace )
			)->willReturn( $options['moderatorIsAutomoderated'] );
		} else {
			$this->canSkip->expects( $this->never() )->method( 'canEditSkip' );
		}

		$expectedResult = str_replace( [ '{AuthorUserLink}', '{CharDiff}' ],
			[
				Linker::userLink( $row->user, $row->user_text ),
				ChangesList::showCharacterDifference(
					$row->old_len,
					$row->new_len,
					$this->context
				)
			],
			$options['expectedResult']
		);
		$expectedResult = preg_replace_callback(
			'/\{Row:([^}]+)\}/', static function ( $matches ) use ( $row ) {
				$field = $matches[1];
				return $row->$field;
			},
			$expectedResult
		);
		$expectedResult = preg_replace_callback(
			'/\{WhoisLink:([^}]+)\}/', static function ( $matches ) {
				$ip = $matches[1];
				return '<sup class="whois plainlinks">[' . Linker::makeExternalLink(
					'(moderation-whois-link-url: ' . $ip . ')',
					'(moderation-whois-link-text)'
				) . ']</sup>';
			},
			$expectedResult
		);
		$expectedResult = preg_replace_callback(
			'/\{UserLink:([^}]+)\}/', static function ( $matches )  {
				list( $userId, $username ) = explode( '|', $matches[1] );
				return Linker::userLink( (int)$userId, $username );
			},
			$expectedResult
		);
		$formatter = $this->makeTestFormatter( $row );
		$this->assertEquals( $expectedResult, $formatter->getHTML() );
	}

	/**
	 * Provide datasets for testGetHTML() runs.
	 * @return array
	 */
	public function dataProviderGetHTML() {
		// TODO: result of "can reapprove rejected?" should be mockable,
		// and this should be tested elsewhere.
		global $wgModerationTimeToOverrideRejection;

		// @phan-suppress-next-line PhanPossiblyUndeclaredVariable
		$timeToOverride = $wgModerationTimeToOverrideRejection;

		$ts = new MWTimestamp();
		$ts->timestamp->modify( '-' . ( $timeToOverride + 1 ) . ' seconds' );
		$longAgo = $ts->getTimestamp( TS_MW ); // Can't reapprove rejected edit with this timestamp

		$ts = new MWTimestamp();
		$ts->timestamp->modify( '-' . ( $timeToOverride - 600 ) . ' seconds' );
		$notLongAgoEnough = $ts->getTimestamp( TS_MW );

		// phpcs:disable Generic.Files.LineLength.TooLong
		return [
			'pending edit' => [ [
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, PreviewLink=true' => [ [
				'globals' => [ 'wgModerationPreviewLink' => true ],
				'expectedResult' => '<span class="modline">({ActionLink:show} | {ActionLink:preview}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, EnableEditChange=true' => [ [
				'globals' => [ 'wgModerationEnableEditChange' => true ],
				'expectedResult' => '<span class="modline">({ActionLink:show} | {ActionLink:editchange}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, PreviewLink=true and EnableEditChange=true' => [ [
				'globals' => [
					'wgModerationPreviewLink' => true,
					'wgModerationEnableEditChange' => true
				],
				'expectedResult' => '<span class="modline">({ActionLink:show} | {ActionLink:preview} | {ActionLink:editchange}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'minor edit' => [ [
				'fields' => [ 'minor' => 1 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . . (minoreditletter) {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'bot edit' => [ [
				'fields' => [ 'bot' => 1 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . . (boteditletter) {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'new article creation' => [ [
				'fields' => [ 'new' => 1 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . . (newpageletter) {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'minor+bot+new edit' => [ [
				'fields' => [ 'minor' => 1, 'bot' => 1, 'new' => 1 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . . (minoreditletter)(boteditletter)(newpageletter) {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending move' => [ [
				'fields' => [
					'type' => 'move',
					'page2_namespace' => 4,
					'page2_title' => 'New_pagename'
				],
				'expectedResult' => '<span class="modline"> (moderation-move: {PageLink:{Row:namespace}|{Row:title}}, {PageLink:{Row:page2_namespace}|{Row:page2_title}}) {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, logged-in author, moderator is checkuser (should see Whois link)' => [ [
				'fields' => [ 'ip' => '10.11.12.13' ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}{WhoisLink:10.11.12.13}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, anonymous author (should see Whois link)' => [ [
				'fields' => [ 'user' => 0, 'user_text' => '10.20.30.40' ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}{WhoisLink:10.20.30.40}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, edit conflict, moderator is automoderated' => [ [
				'moderatorIsAutomoderated' => true,
				'fields' => [ 'conflict' => 1 ],
				'expectedResult' => '<span class="modline modconflict">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:merge} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, edit conflict, moderator is NOT automoderated' => [ [
				'moderatorIsAutomoderated' => false,
				'fields' => [ 'conflict' => 1 ],
				'expectedResult' => '<span class="modline modconflict">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [(moderation-no-merge-link-not-automoderated) . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>'
			] ],
			'pending edit, modblocked user' => [ [
				'fields' => [ 'blocked' => 1 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:unblock}]</span>'
			] ],
			'merged edit' => [ [
				'fields' => [ 'conflict' => 1, 'merged_revid' => 12345 ],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{MergedRevisionLink:{Row:namespace}|{Row:title}|12345}] . . [{ActionLink:block}]</span>'
			] ],
			'rejected edit' => [ [
				'fields' => [
					'rejected' => 1,
					'rejected_by_user' => 12345,
					'rejected_by_user_text' => 'Name of moderator',
					'timestamp' => $notLongAgoEnough
				],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve}] . . [{ActionLink:block}] . . (moderation-rejected-by: {UserLink:12345|Name of moderator}, {Row:rejected_by_user_text})</span>'
			] ],
			'rejected edit, too long ago to approve' => [ [
				'fields' => [
					'rejected' => 1,
					'rejected_by_user' => 12345,
					'rejected_by_user_text' => 'Name of moderator',
					'timestamp' => $longAgo
				],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [] . . [{ActionLink:block}] . . (moderation-rejected-by: {UserLink:12345|Name of moderator}, {Row:rejected_by_user_text})</span>'
			] ],
			'rejected edit, rejected via "Reject all"' => [ [
				'fields' => [
					'rejected' => 1,
					'rejected_batch' => 1,
					'rejected_by_user' => 12345,
					'rejected_by_user_text' => 'Name of moderator'
				],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve}] . . [{ActionLink:block}] . . (moderation-rejected-by: {UserLink:12345|Name of moderator}, {Row:rejected_by_user_text}) . . (moderation-rejected-batch)</span>'
			] ],
			'spam edit (automatically rejected)' => [ [
				'fields' => [
					'rejected' => 1,
					'rejected_by_user' => 0,
					'rejected_auto' => 1
				],
				'expectedResult' => '<span class="modline">({ActionLink:show}) . .  {PageLink:{Row:namespace}|{Row:title}} {FormattedTime} . . {CharDiff} . . {AuthorUserLink}  <span class="comment">({Row:comment})</span> [{ActionLink:approve}] . . [{ActionLink:block}] . . (moderation-rejected-auto)</span>'
			] ],

			// TODO: check absence of Approve link when $entry->canReapproveRejected() is false.
		];
		// phpcs:enable Generic.Files.LineLength.TooLong
	}

	/**
	 * Make formatter for $row with mocks that were created in setUp().
	 * @param stdClass|null $row
	 * @return ModerationEntryFormatter
	 */
	private function makeTestFormatter( $row = null ) {
		return new ModerationEntryFormatter( $row ?? new stdClass, $this->context,
			$this->linkRenderer, $this->actionLinkRenderer,
			$this->timestampFormatter, $this->canSkip );
	}

	/**
	 * Precreate new mocks for all dependencies before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->linkRenderer = $this->createMock( LinkRenderer::class );
		$this->actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$this->timestampFormatter = $this->createMock( TimestampFormatter::class );
		$this->context = $this->createMock( IContextSource::class );
		$this->canSkip = $this->createMock( ModerationCanSkip::class );
	}
}
