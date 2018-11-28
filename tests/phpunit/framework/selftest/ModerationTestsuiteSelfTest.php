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
 * Tests the ModerationTestsuite itself.
 *
 * Used for debugging subclasses of ModerationTestsuiteEngine.
*/

require_once __DIR__ . "/../ModerationTestsuite.php";

/**
 * @group Utility
 */
class ModerationTestsuiteSelfTest extends ModerationTestCase {
	/**
	 * Ensures that API response is correct.
	 * @covers ModerationTestsuiteEngine::query
	 * @dataProvider engineDataProvider
	 */
	public function testEngineApi( ModerationTestsuiteEngine $engine ) {
		$ret = $engine->query( [
			'action' => 'query',
			'meta' => 'siteinfo'
		] );

		$this->assertNotEmpty( $ret, 'Emptry API response.' );
		$this->assertArrayHasKey( 'query', $ret );
		$this->assertArrayHasKey( 'general', $ret['query'] );
		$this->assertArrayHasKey( 'sitename', $ret['query']['general'] );

		global $wgSitename;
		$this->assertEquals( $wgSitename, $ret['query']['general']['sitename'] );
	}

	/**
	 * Ensures that login works (and/or login cookies are remembered).
	 * @covers ModerationTestsuiteEngine::loginAs
	 * @dataProvider engineDataProvider
	 */
	public function testEngineApiLogin( ModerationTestsuiteEngine $engine, ModerationTestsuite $t ) {
		# Try to login as test user.
		$user = $t->unprivilegedUser;
		$engine->loginAs( $user );

		# Check information about current user.
		$ret = $engine->query( [
			'action' => 'query',
			'meta' => 'userinfo'
		] );
		$this->assertArrayHasKey( 'query', $ret );
		$this->assertArrayHasKey( 'userinfo', $ret['query'] );

		$this->assertArrayNotHasKey( 'anon', $ret['query']['userinfo'],
			"User is still anonymous after loginAs()" );

		$this->assertEquals( $user->getName(),
			$ret['query']['userinfo']['name'] );
	}

	/**
	 * Ensures that non-API HTTP response is correct.
	 * @covers ModerationTestsuiteEngine::httpRequest
	 * @dataProvider engineAndMethodDataProvider
	 */
	public function testEngineNonApi( ModerationTestsuiteEngine $engine, $method ) {
		$url = wfScript( 'index' );
		$data = [
			'title' => 'Test page 1',
			'action' => 'edit'
		];

		if ( $method == 'POST' ) {
			$req = $engine->httpPost( $url, $data );
		} else {
			$req = $engine->httpGet( wfAppendQuery( $url, $data ) );
		}

		$this->assertEquals( 200, $req->getStatus(),
			'Incorrect HTTP response code.' );

		$html = new ModerationTestsuiteHTML( $engine );
		$html->loadFromReq( $req );

		/* Ensure that this is indeed an edit form */
		$this->assertStringStartsWith(
			wfMessage( 'creating' )
				->params( str_replace( '_', ' ', $data['title'] ) )
				->text(),
			$html->getTitle()
		);
		$this->assertNotNull( $html->getElementById( 'wpSave' ),
			'testEngineNonApi(): "Save" button not found.' );
	}

	/**
	 * Provide ModerationTestsuiteEngine objects for tests.
	 */
	public function engineDataProvider() {
		return [
			[ new ModerationTestsuiteCliEngine ],
			[ new ModerationTestsuiteRealHttpEngine ]
		];
	}

	/**
	 * Provide $method datasets for testEngineNonApi() runs.
	 */
	public function methodDataProvider() {
		return [
			[ 'POST' ],
			[ 'GET' ]
		];
	}

	/**
	 * Provide [ $engine, $method ] datasets for testEngineNonApi() runs.
	 */
	public function engineAndMethodDataProvider() {
		return $this->multiplyProviders( 'engineDataProvider', 'methodDataProvider' );
	}

	/**
	 * Provides dataset where some parameters are provided by $provider1, some by $provider2.
	 * @param $provider1 Name of DataProvider method, e.g. 'engineDataProvider'.
	 * @param $provider2 Name of DataProvider method, e.g. 'methodDataProvider'.
	 */
	public function multiplyProviders( $provider1, $provider2 ) {
		$sets1 = call_user_func( [ $this, $provider1 ] );
		$sets2 = call_user_func( [ $this, $provider2 ] );

		$sets = [];
		foreach ( $sets1 as $params1 ) {
			foreach ( $sets2 as $params2 ) {
				$sets[] = array_merge( $params1, $params2 );
			}
		}
		return $sets;
	}

}
