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
	@file
	@brief Tests the ModerationTestsuite itself.

	Used for debugging subclasses of ModerationTestsuiteEngine.
*/

require_once( __DIR__ . "/../ModerationTestsuite.php" );


class ModerationTestsuiteSelfTest extends MediaWikiTestCase
{
	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();

		$engineClass = get_class( ModerationTestsuiteEngine::factory() );
		echo __CLASS__ . ": using $engineClass.\n";
	}

	/**
		@brief Ensures that API response is correct.
		@covers ModerationTestsuiteEngine::query
	*/
	public function testEngineApi() {
		$t = new ModerationTestsuite();
		$data = [
			'action' => 'query',
			'meta' => 'siteinfo'
		];

		$ret = $t->query( $data );

		$this->assertNotEmpty( $ret, 'Emptry API response.' );
		$this->assertArrayHasKey( 'query', $ret );
		$this->assertArrayHasKey( 'general', $ret['query'] );
		$this->assertArrayHasKey( 'sitename', $ret['query']['general'] );

		global $wgSitename;
		$this->assertEquals( $wgSitename, $ret['query']['general']['sitename'] );
	}

	/**
		@brief Ensures that non-API HTTP response is correct.
		@covers ModerationTestsuiteEngine::executeHttpRequest
		@dataProvider methodDataProvider
	*/
	public function testEngineNonApi( $method ) {
		$t = new ModerationTestsuite();

		$url = wfScript( 'index' );
		$data = [
			'title' => 'Test page 1',
			'action' => 'edit'
		];

		if ( $method == 'POST' ) {
			$req = $t->httpPost( $url, $data );
		}
		else {
			$req = $t->httpGet( wfAppendQuery( $url, $data ) );
		}

		$this->assertEquals( 200, $req->getStatus(),
			'Incorrect HTTP response code.' );

		$html = $t->html->loadFromReq( $req );

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
		@brief Provide datasets for testEngineNonApi() runs.
	*/
	public function methodDataProvider() {
		return [
			[ 'POST' ],
			[ 'GET' ]
		];
	}

}

