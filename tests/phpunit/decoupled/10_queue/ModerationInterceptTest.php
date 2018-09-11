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
 * @brief Checks that automoderated users can bypass moderation and others can't.
 */

require_once __DIR__ . "/../../framework/ModerationTestsuite.php";

/**
 * @covers ModerationCanSkip
 */
class ModerationInterceptTest extends MediaWikiTestCase {
	/**
	 * @dataProvider dataProvider
	 */
	public function testIntercept( array $options ) {
		ModerationInterceptTestSet::run( $options, $this );
	}

	/**
	 * @brief Provide datasets for testIntercept() runs.
	 */
	public function dataProvider() {
		$sets = [
			[ [ 'anonymously' => true ] ],
			[ [ 'groups' => [] ] ],
			[ [ 'intercept' => false, 'groups' => [ 'automoderated' ] ] ],
			[ [ 'namespace' => NS_MAIN ] ],
			[ [ 'namespace' => NS_PROJECT ] ],

			// Special cases:
			// moderator-but-not-automoderated can't skip moderation of edits,
			// but rollback can (see ModerationCanSkip::canSkip() for explanation).
			[ [ 'groups' => [ 'moderator' ] ] ], // can't skip
			[ [ 'intercept' => false, 'groups' => [ 'rollback' ] ] ],
			[ [ 'action' => 'move', 'groups' => [ 'rollback' ] ] ],

			// $wgModerationEnable
			[ [ 'intercept' => false, 'anonymously' => true, 'ModerationEnable' => false ] ],

			// $wgModerationOnlyInNamespaces
			[ [ 'namespace' => NS_MAIN,
				'ModerationOnlyInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],
			[ [ 'namespace' => NS_PROJECT,
				'ModerationOnlyInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],
			[ [ 'intercept' => false, 'namespace' => NS_USER,
				'ModerationOnlyInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],

			// $wgModerationIgnoredInNamespaces
			[ [ 'intercept' => false, 'namespace' => NS_MAIN,
				'ModerationIgnoredInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],
			[ [ 'intercept' => false, 'namespace' => NS_PROJECT,
				'ModerationIgnoredInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],
			[ [ 'namespace' => NS_USER,
				'ModerationIgnoredInNamespaces' => [ NS_MAIN, NS_PROJECT ] ] ],

			// Uploads
			[ [ 'action' => 'upload' ] ],
			[ [ 'intercept' => false, 'action' => 'upload', 'groups' => [ 'automoderated' ] ] ],
			[ [ 'intercept' => false, 'action' => 'upload',
				'ModerationIgnoredInNamespaces' => [ NS_FILE ] ] ],
			[ [ 'intercept' => false, 'action' => 'upload',
				'ModerationOnlyInNamespaces' => [ NS_MAIN ] ] ],

			// Moves
			[ [ 'action' => 'move' ] ],
			[ [ 'intercept' => false, 'action' => 'move', 'groups' => [ 'automoderated' ] ] ],

			[ [
				// Source namespace being excluded is not enough for move to bypass moderation,
				// both source and target must be excluded from moderation.
				'intercept' => true,
				'action' => 'move',
				'ModerationIgnoredInNamespaces' => [ ModerationInterceptTestSet::DEFAULT_NS1 ]
			] ],
			[ [
				// Target namespace being excluded is not enough for move to bypass moderation,
				// both source and target must be excluded from moderation.
				'intercept' => true,
				'action' => 'move',
				'ModerationIgnoredInNamespaces' => [ ModerationInterceptTestSet::DEFAULT_NS2 ]
			] ],
			[ [
				'intercept' => false,
				'action' => 'move',
				'ModerationIgnoredInNamespaces' => [
					ModerationInterceptTestSet::DEFAULT_NS1,
					ModerationInterceptTestSet::DEFAULT_NS2
				]
			] ]
		];

		// Run each set via ApiBot and NonApiBot.
		$newSets = [];
		foreach ( $sets as $set ) {
			$newSets[] = [ $set[0] + [ 'viaApi' => true ] ];
			$newSets[] = [ $set[0] + [ 'viaApi' => false ] ];
		}
		return $newSets;
	}
}

/**
 * @brief Represents one TestSet for testIntercept().
 */
class ModerationInterceptTestSet extends ModerationTestsuiteTestSet {

	/**
	 * @const Namespace which is used when not selected by the test.
	 */
	const DEFAULT_NS1 = NS_USER_TALK;

	/**
	 * @const Second namespace which is used when not selected by the test.
	 * Only needed for moves.
	 */
	const DEFAULT_NS2 = NS_HELP;

	/** @var bool If true, this change is expected to be intercepted. */
	protected $intercept = true;

	/** @var int Namespace of the test page */
	protected $namespace = self::DEFAULT_NS1;

	/** @var int Namespace of the new title (for moves) */
	protected $namespace2 = self::DEFAULT_NS2;

	/** @var array|null Extra configuration, e.g. [ 'ModerationIgnoredInNamespaces' => [] ] */
	protected $configVars = [];

	/** @var array Groups of the test user, e.g. [ 'sysop', 'bureaucrat' ] */
	protected $groups = [];

	/** @var bool If true, the edit will be anonymous. ($groups will be ignored) */
	protected $anonymously = false;

	/** @var bool If true, edits are made via API, if false, via the user interface. */
	protected $viaApi = false;

	/** @var string Operation to test, one of the following: 'edit', 'upload', 'move' */
	protected $action = 'edit';

	/**
	 * @var ModerationTestsuiteApiBotResult|ModerationTestsuiteNonApiBotResult
	 * Result of edit(), move() or upload().
	 */
	protected $result = null;

	/**
	 * @brief Initialize this TestSet from the input of dataProvider.
	 */
	protected function applyOptions( array $options ) {
		foreach ( $options as $key => $value ) {
			switch ( $key ) {

				case 'ModerationEnable':
				case 'ModerationIgnoredInNamespaces':
				case 'ModerationOnlyInNamespaces':
					$this->configVars[$key] = $value;
					break;

				case 'action':
				case 'anonymously':
				case 'intercept':
				case 'groups':
				case 'namespace':
				case 'namespace2':
				case 'viaApi':
					$this->$key = $value;
					break;

				default:
					throw new Exception( __CLASS__ . ": unknown key {$key} in options" );
			}
		}

		/* Default options */
		if ( $this->action == 'upload' &&
			ModerationTestsuite::mwVersionCompare( '1.28.0', '<' )
		) {
			$this->getTestcase()->markTestSkipped(
				'Test skipped: MediaWiki 1.27 doesn\'t support upload via API.' );
		}
	}

	/**
	 * @brief Assert the state of the database after the edit.
	 */
	protected function assertResults( MediaWikiTestCase $testcase ) {
		$testcase->assertEquals(
			[ 'edit was intercepted' => $this->intercept ],
			[ 'edit was intercepted' => $this->result->isIntercepted() ]
		);

		$dbw = wfGetDB( DB_MASTER );
		$row = $dbw->selectRow( 'moderation', '*', '', __METHOD__ );

		$testcase->assertEquals(
			[ 'edit was queued' => $this->intercept ],
			[ 'edit was queued' => (bool)$row ]
		);
	}

	/**
	 * @brief Execute the TestSet, making an edit/upload/move with requested parameters.
	 */
	protected function makeChanges() {
		$testcase = $this->getTestcase();
		$t = $this->getTestsuite();

		foreach ( $this->configVars as $name => $value ) {
			$t->setMwConfig( $name, $value );
		}

		$title = Title::makeTitle( $this->namespace, 'Test page 1' );
		$page2Title = Title::makeTitle( $this->namespace2, 'Test page 2' );

		$user = User::newFromName( '127.0.0.1', false );
		if ( !$this->anonymously ) {
			$user = $t->unprivilegedUser;

			// Make sure that the test user doesn't have any groups
			foreach ( $user->getGroups() as $oldGroup ) {
				$user->removeGroup( $oldGroup );
			}

			// Add the user to groups requested by this test (if any)
			foreach ( $this->groups as $group ) {
				$user->addGroup( $group );

				if ( $group == 'rollback' ) {
					// Rollback group (users with 'rollback' right) is not defined
					// by default, so we need to configure it explicitly.
					global $wgGroupPermissions;
					$t->setMwConfig( 'GroupPermissions',
						[ 'rollback' => [ 'rollback' => true ] ] + $wgGroupPermissions );
				}
			}
		}

		if ( $this->action == 'move' ) {
			ModerationTestUtil::fastEdit( $title );
		}

		$t->loginAs( $user );
		$bot = $t->getBot( $this->viaApi ? 'api' : 'nonApi' );

		switch ( $this->action ) {
			case 'edit':
				$this->result = $bot->edit(
					$title->getFullText(),
					'New text',
					'Summary'
				);
				break;

			case 'move':
				$this->result = $bot->move(
					$title->getFullText(),
					$page2Title->getFullText()
				);
				break;

			case 'upload':
				$this->result = $bot->upload( $title->getText(), '', '' );
				break;

			default:
				throw MWException( 'Unknown "action" requested in TestSet of ' . __CLASS__ );
		}
	}
}
