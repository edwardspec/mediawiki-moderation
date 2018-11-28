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
 * Verifies that modactions work via api.php?action=moderation.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ApiModeration
 */
class ModerationApiTest extends ModerationTestCase {

	/**
	 * Checks return value of api.php?action=moderation&modaction=...
	 * @note Consequences of actions are checked by other tests (e.g. ModerationApproveTest).
	 * @dataProvider dataProviderModerationApi
	*/
	public function testModerationApi( $action, array $expectedResult, ModerationTestsuite $t ) {
		/* Prepare a fake moderation entry */
		$entry = $t->getSampleEntry();

		/* Replace {{ID}} and {{AUTHOR}} in $expectedResult */
		$expectedResult = FormatJson::decode(
			str_replace(
				[ '{{ID}}', '{{AUTHOR}}', '{{TITLE}}' ],
				[ $entry->id, $entry->user, $entry->title ],
				FormatJson::encode( $expectedResult )
			),
			true
		);

		$ret = $t->query( [
			'action' => 'moderation',
			'modid' => $entry->id,
			'modaction' => $action,
			'token' => null
		] );

		if ( $action == 'show' && isset( $ret['moderation']['diff-html'] ) ) {
			/* Correctness of diff is already checked in ModerationShowTest,
				we don't want to duplicate the same check.
			*/
			$ret['moderation']['diff-html'] = '{{DIFF}}';
		}

		$this->assertEquals( [ 'moderation' => $expectedResult ], $ret );
	}

	/**
	 * Provide datasets for testModerationApi() runs.
	 */
	public function dataProviderModerationApi() {
		return [
			[ "approve", [
				"approved" => [ "{{ID}}" ]
			] ],
			[ "approveall", [
				"approved" => [ "{{ID}}" => "" ],
				"failed" => []
			] ],
			[ "reject", [
				"rejected-count" => 1
			] ],
			[ "rejectall", [
				"rejected-count" => 1
			] ],
			[ "block", [
				"action" => "block",
				"username" => "{{AUTHOR}}"
			] ],
			[ "unblock", [
				"action" => "unblock",
				"username" => "{{AUTHOR}}",
				"noop" => ""
			] ],
			[ "show", [
				"diff-html" => "{{DIFF}}",
				"title" => "{{TITLE}}"
			] ]
		];
	}
}
