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
 * Trait that provides assertConsequencesEqual(), which is useful for Consequence tests.
 */

use MediaWiki\Moderation\IConsequence;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use MediaWiki\Moderation\MockConsequenceManager;
use Wikimedia\TestingAccessWrapper;

/**
 * @method static assertEquals($a, $b, $message='', $d=0.0, $e=10, $f=null, $g=null)
 * @method static assertSame($a, $b, $message='')
 * @method static assertCount($a, $b, $message='')
 * @method static assertInstanceOf($a, $b, $message='')
 */
trait ConsequenceTestTrait {

	/**
	 * @var string|null
	 * Error message (e.g. "moderation-edit-conflict") thrown during getConsequences(), if any.
	 */
	protected $thrownError = null;

	/**
	 * @var User|null
	 * Moderator that will perform the action during getConsequences().
	 */
	protected $moderatorUser;

	/**
	 * Assert that $expectedConsequences are exactly the same as $actualConsequences.
	 * @param IConsequence[] $expectedConsequences
	 * @param IConsequence[] $actualConsequences
	 */
	private function assertConsequencesEqual(
		array $expectedConsequences,
		array $actualConsequences
	) {
		$this->assertEquals(
			array_map( 'get_class', $expectedConsequences ),
			array_map( 'get_class', $actualConsequences ),
			"List of consequences doesn't match expected."
		);

		array_map( function ( $expected, $actual ) {
			$class = get_class( $expected );
			$this->assertSame(
				$this->toArray( $expected ),
				$this->toArray( $actual ),
				"Parameters of consequence $class don't match expected."
			);
		}, $expectedConsequences, $actualConsequences );
	}

	/**
	 * Convert $consequence into a human-readable array of properties (for logging and comparison).
	 * Properties with types like Title are replaced by [ className, mixed, ... ] arrays.
	 * @param IConsequence $consequence
	 * @return array
	 */
	protected function toArray( IConsequence $consequence ) {
		$fields = [];

		$rc = new ReflectionClass( $consequence );
		foreach ( $rc->getProperties() as $prop ) {
			$prop->setAccessible( true );

			$value = $prop->getValue( $consequence );
			$type = gettype( $value );
			if ( $type == 'object' ) {
				if ( $value instanceof Title ) {
					$value = [ 'Title', (string)$value ];
				} elseif ( $value instanceof WikiPage ) {
					$value = [ 'WikiPage', (string)$value->getTitle() ];
				} elseif ( $value instanceof User ) {
					$value = [ 'User', $value->getId(), $value->getName() ];
				}
			} elseif ( $type == 'array' ) {
				// Having timestamps in normalized form leads to flaky comparison results,
				// because it's possible that "expected timestamp" was calculated
				// in a different second than mod_timestamp in an actual Consequence.
				$value['mod_timestamp'] = '<<< MOCKED TIMESTAMP >>>';
			}

			if ( $consequence instanceof InsertRowIntoModerationTableConsequence ) {
				# Cast all values to strings. 2 and "2" are the same for DB::insert(),
				# so caller shouldn't have to write "mod_namespace => (string)2" explicitly.
				# We also don't want it to prevent Phan from typechecking inside $fields array.
				$value = array_map( 'strval', $value );
				ksort( $value );
			}

			$name = $prop->getName();
			$fields[$name] = $value;
		}

		return [ get_class( $consequence ), $fields ];
	}

	/**
	 * Get an array of consequences after running $modaction on an edit that was queued in setUp().
	 * @param int $modid
	 * @param string $modaction
	 * @param array[]|null $mockedResults Parameters to pass to calls to $manager->mockResult().
	 * @param array $extraParams Additional HTTP request parameters when running ModerationAction.
	 * @return IConsequence[]
	 *
	 * @phan-param list<array{0:class-string,1:mixed}>|null $mockedResults
	 */
	public function getConsequences( $modid, $modaction,
		array $mockedResults = null, $extraParams = []
	) {
		if ( !$this->moderatorUser ) {
			throw new MWException(
				'Must set $this->moderatorUser before calling getConsequences().' );
		}

		// Replace real ConsequenceManager with a mock.
		list( $scope, $manager ) = MockConsequenceManager::install();

		// Invoke ModerationAction with requested modid.
		$request = new FauxRequest( [
			'modaction' => $modaction,
			'modid' => $modid
		] + $extraParams );
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( SpecialPage::getTitleFor( 'Moderation' ) );
		$context->setRequest( $request );
		$context->setUser( $this->moderatorUser );

		if ( $mockedResults ) {
			foreach ( $mockedResults as $result ) {
				$manager->mockResult( ...$result );
			}
		}

		$this->thrownError = null;

		$action = ModerationAction::factory( $context );
		try {
			$action->run();
		} catch ( ModerationError $error ) {
			$this->thrownError = $error->status->getMessage()->getKey();
		}

		return $manager->getConsequences();
	}

	/**
	 * Set "revision ID of last edit" in ApproveHook to a random number (and return this number).
	 * @return int
	 */
	public function mockLastRevId() {
		$revid = rand( 1, 100000 );

		$approveHook = TestingAccessWrapper::newFromObject( ModerationApproveHook::singleton() );
		$approveHook->lastRevId = $revid;

		return $revid;
	}
}
