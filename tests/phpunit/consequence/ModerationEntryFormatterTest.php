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
 * Unit test of ModerationEntryFormatter.
 */

use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Moderation\ActionLinkRenderer;

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
	private $context;

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
	 * @param $options
	 * @dataProvider dataProviderGetHTML
	 *
	 * @covers ModerationEntryFormatter
	 */
	public function testGetHTML( array $options ) {
		$authorUser = self::getTestUser()->getUser();
		$row = (object)( ( $options['fields'] ?? [] ) + [
			'user' => $authorUser->getId(),
			'user_text' => $authorUser->getName(),
			'namespace' => 0,
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

		$groups = array_merge( $options['groups'] ?? [], [ 'moderator' ] );
		$moderator = self::getTestUser( $groups )->getUser();
		$lang = Language::factory( 'qqx' );

		$this->context->expects( $this->any() )->method( 'getUser' )->willReturn( $moderator );
		$this->context->expects( $this->any() )->method( 'getLanguage' )->willReturn( $lang );
		$this->context->expects( $this->any() )->method( 'getConfig' )
			->willReturn( RequestContext::getMain()->getConfig() );

		// Determine which calls to msg() and makeLink() should be expected, then mock them.
		$expectedMessages = [];
		$expectedActionLinks = [];
		$expectedPageLinks = [];

		if ( $row->type != 'move' ) {
			$expectedActionLinks[] = 'show';
			if ( $options['globals']['wgModerationPreviewLink'] ?? false ) {
				$expectedActionLinks[] = 'preview';
			}

			if ( $options['globals']['wgModerationEnableEditChange'] ?? false ) {
				$expectedActionLinks[] = 'editchange';
			}
		}

		if ( $row->minor ) {
			$expectedMessages[] = 'minoreditletter';
		}
		if ( $row->bot ) {
			$expectedMessages[] = 'boteditletter';
		}
		if ( $row->minor ) {
			$expectedMessages[] = 'newpageletter';
		}

		$expectedPageLinks[] = Title::makeTitle( $row->namespace, $row->title );

		if ( $row->type == 'move' ) {
			$expectedMessages[] = 'moderation-move';
			$expectedPageLinks[] = Title::makeTitle( $row->page2_namespace,
				$row->page2_mod_title );
		}

		$expectedMessages[] = 'rc-change-size-new';
		$expectedMessages[] = 'rc-change-size';

		if ( ( $row->ip ?? null ) || IP::isValid( $row->user_text ) ) {
			$expectedMessages[] = 'moderation-whois-link-url';
			$expectedMessages[] = 'moderation-whois-link-text';
		}

		if ( !$row->merged_revid ) {
			if ( $row->conflict ) {
				if ( in_array( 'automoderated', $moderator->getEffectiveGroups() ) ) {
					$expectedActionLinks[] = 'merge';
				} else {
					$expectedMessages[] = 'moderation-no-merge-link-not-automoderated';
				}
			} else {
				if ( !$row->rejected || ( $options['canReapprove'] ?? true ) ) {
					$expectedActionLinks[] = 'approve';
				}

				if ( !$row->rejected ) {
					$expectedActionLinks[] = 'approveall';
				}
			}

			if ( !$row->rejected ) {
				$expectedActionLinks[] = 'reject';
				$expectedActionLinks[] = 'rejectall';
			}
		} else {
			// TODO: need to mock $linkRenderer->makePreloadedLink() with parameters
		}

		$expectedActionLinks[] = $row->blocked ? 'unblock' : 'block';

		if ( $row->rejected ) {
			if ( $row->rejected_by_user ) {
				$expectedMessages[] = 'moderation-rejected-by';
			} elseif ( $row->rejected_auto ) {
				$expectedMessages[] = 'moderation-rejected-auto';
			}

			if ( $row->rejected_batch ) {
				$expectedMessages[] = 'moderation-rejected-batch';
			}
		}

		// Start mocking.
		$returnValueMap = [];
		foreach ( $expectedActionLinks as $action ) {
			$returnValueMap[] = [ $action, $row->id, "{ActionLink:$action}" ];
		}
		$this->actionLinkRenderer->expects( $this->exactly( count( $expectedActionLinks ) ) )
			->method( 'makeLink' )->will( $this->returnValueMap( $returnValueMap ) );

		$this->linkRenderer->expects( $this->exactly( count( $expectedPageLinks ) ) )
			->method( 'makeLink' )->willReturnCallback( function ( Title $title ) {
				return '{PageLink:' . $title->getNamespace() . '|' . $title->getDBKey() . '}';
			} );

		// TODO: can willReturnCallback() be used with sequential checks of $expectedMessages?
		$this->context->expects( $this->any() )->method( 'msg' )
			->willReturnCallback( function ( ...$args ) {
				return new RawMessage( '{msg:' . implode( '|', $args ) . '}' );
			} );

		$expectedTime = ( new MWTimestamp( $row->timestamp ) )->timestamp->format( 'H:i' );
		$expectedCharDiff = ChangesList::showCharacterDifference(
			$row->old_len, $row->new_len, $this->context );

		// phpcs:ignore Generic.Files.LineLength.TooLong
		$expectedResult = "<span class=\"modline\">({ActionLink:show}) . .  {PageLink:{$row->namespace}|{$row->title}} {$expectedTime} . . {$expectedCharDiff} . . " . Linker::userLink( $row->user, $row->user_text ) . "  <span class=\"comment\">({$row->comment})</span> [{ActionLink:approve} {ActionLink:approveall} . . {ActionLink:reject} {ActionLink:rejectall}] . . [{ActionLink:block}]</span>";

		$formatter = $this->makeTestFormatter( $row );
		$this->assertEquals( $expectedResult, $formatter->getHTML() );
	}

	/**
	 * Provide datasets for testGetHTML() runs.
	 * @return array
	 */
	public function dataProviderGetHTML() {
		return [
			[ [] ]
		];
	}

	/**
	 * Make formatter for $row with mocks that were created in setUp().
	 * @param object|null $row
	 */
	private function makeTestFormatter( $row = null ) {
		/*
		'@phan-var LinkRenderer $linkRenderer';
		'@phan-var ActionLinkRenderer $actionLinkRenderer';
		'@phan-var IContextSource $context';
		*/

		return new ModerationEntryFormatter( $row ?? new stdClass, $this->context,
			$this->linkRenderer, $this->actionLinkRenderer );
	}

	/**
	 * Precreate new mocks for $linkRenderer, $actionLinkRenderer and $context before each test.
	 */
	public function setUp() : void {
		parent::setUp();

		$this->linkRenderer = $this->createMock( LinkRenderer::class );
		$this->actionLinkRenderer = $this->createMock( ActionLinkRenderer::class );
		$this->context = $this->createMock( IContextSource::class );
	}
}
