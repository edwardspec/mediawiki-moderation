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
 * Unit test of TimestampFormatter.
 */

use MediaWiki\Moderation\TimestampFormatter;
use Wikimedia\TestingAccessWrapper;

require_once __DIR__ . "/autoload.php";

class TimestampFormatterTest extends ModerationUnitTestCase {
	/**
	 * Test the results of TimestampFormatter::format().
	 * @dataProvider dataProviderFormat
	 * @param string $timestamp First parameter passed to format().
	 * @param string $mockedAdjustedTimestamp Mocked value of "userAdjust($timestamp)".
	 * @param string $mockedAdjustedToday Mocked value of "userAdjust(wfTimestampNow())".
	 * @param bool $expectTimeOnly Whether format() should return date+time (false) or time (true).
	 *
	 * @covers MediaWiki\Moderation\TimestampFormatter
	 */
	public function testFormat( $timestamp, $mockedAdjustedTimestamp,
		$mockedAdjustedToday, $expectTimeOnly
	) {
		$context = $this->createMock( IContextSource::class );
		$lang = $this->createMock( Language::class );
		$user = $this->createMock( User::class );

		$context->expects( $this->once() )->method( 'getLanguage' )->willReturn( $lang );
		$context->expects( $this->once() )->method( 'getUser' )->willReturn( $user );

		// First call to $lang->userAdjust() is used to calculate $today from wfTimestampNow().
		$lang->expects( $this->at( 0 ) )->method( 'userAdjust' )->will(
			$this->returnCallback( function ( $param ) use ( $mockedAdjustedToday ) {
				// Ensure that $param is not too far away from NOW. Allow 2 seconds of difference.
				$secondsDiff = abs( (int)wfTimestamp( TS_UNIX, 0 ) - (int)wfTimestamp( TS_UNIX, $param ) );
				$this->assertLessThan( 2, $secondsDiff,
					'When calculating $today, timestamp passed to userAdjust() was too different from NOW.'
				);
				return $mockedAdjustedToday;
			} ) );

		// Second call to $lang->userAdjust() is used on first parameter of format().
		// Note: format() can accept any format of timestamp (e.g. TS_POSTGRES),
		// but it MUST be converted, as userAdjust() can only receive TS_MW.
		$lang->expects( $this->at( 1 ) )->method( 'userAdjust' )
			->with(
				$this->identicalTo( wfTimestamp( TS_MW, $timestamp ) )
			)->willReturn( $mockedAdjustedTimestamp );

		if ( $expectTimeOnly ) {
			$mockedResult = '{mocked result: time only ' . rand( 0, 100000 ) . '}';
			$lang->expects( $this->once() )->method( 'userTime' )
				->with(
					$this->identicalTo( $timestamp )
				)->willReturn( $mockedResult );
			$lang->expects( $this->never() )->method( 'userTimeAndDate' );
		} else {
			$mockedResult = '{mocked result: time AND date ' . rand( 0, 100000 ) . '}';
			$lang->expects( $this->once() )->method( 'userTimeAndDate' )
				->with(
					$this->identicalTo( $timestamp )
				)->willReturn( $mockedResult );
			$lang->expects( $this->never() )->method( 'userTime' );
		}

		'@phan-var IContextSource $context';
		'@phan-var Language $lang';

		$formatter = new TimestampFormatter();
		$result = $formatter->format( $timestamp, $context );
		$this->assertEquals( $mockedResult, $result );

		// Additionally test the internal cache of TimestampFormatter ($skippedToday).
		$skippedToday = TestingAccessWrapper::newFromObject( $formatter )->skippedToday;
		if ( $expectTimeOnly ) {
			$this->assertFalse( $skippedToday,
				"After today's timestamp \$skippedToday was incorrectly set to true." );
		} else {
			$this->assertTrue( $skippedToday,
				"After encountering a non-today's timestamp \$skippedToday wasn't set to true." );

			// Additionally test the behavior of cache.
			// In this mode userTimeAndDate() is always called, and all checks from isToday() are skipped.
			$context = $this->createMock( IContextSource::class );
			$lang = $this->createMock( Language::class );

			$context->expects( $this->once() )->method( 'getLanguage' )->willReturn( $lang );
			$context->expects( $this->once() )->method( 'getUser' )->willReturn( $user );

			$lang->expects( $this->never() )->method( 'userTime' );
			$lang->expects( $this->once() )->method( 'userTimeAndDate' )
				->with(
					$this->identicalTo( $timestamp )
				)->willReturn( $mockedResult );

			'@phan-var IContextSource $context';
			'@phan-var Language $lang';

			$result = $formatter->format( $timestamp, $context );
			$this->assertEquals( $mockedResult, $result );
		}
	}

	/**
	 * Provide datasets for testFormat() runs.
	 * @return array
	 */
	public function dataProviderFormat() {
		return [
			'today: expecting ONLY time' => [
				// $timestamp is "3 January 5:00", $today is "3 January 8:00", no time correction.
				'20120103050000', // $timestamp
				'20120103050000', // $mockedAdjustedTimestamp
				'20120103080000', // $mockedAdjustedToday
				true // $expectTimeOnly
			],
			'not today: expecting time AND date' => [
				'20120102050000', // $timestamp
				'20120102050000', // $mockedAdjustedTimestamp
				'20120103080000', // $mockedAdjustedToday
				false // $expectTimeOnly
			],
			'today without adjustment, but not today after adjustment: expecting time AND date' => [
				'20120103010000', // $timestamp
				'20120102210000', // $mockedAdjustedTimestamp: timezone UTC-4
				'20120103080000', // $mockedAdjustedToday
				false // $expectTimeOnly
			],
			'not today without adjustment, but today after adjustment: expecting ONLY time' => [
				'20120102210000', // $timestamp
				'20120103010000', // $mockedAdjustedTimestamp: timezone UTC+4
				'20120103080000', // $mockedAdjustedToday
				true // $expectTimeOnly
			],

			'today (timestamp in PostgreSQL format): expecting ONLY time' => [
				'2012-01-03 05:00:00+00', // $timestamp
				'20120103050000', // $mockedAdjustedTimestamp
				'20120103080000', // $mockedAdjustedToday
				true // $expectTimeOnly
			],
			'not today (timestamp in PostgreSQL format): expecting time AND date' => [
				'2012-01-02 05:00:00+00', // $timestamp
				'20120102050000', // $mockedAdjustedTimestamp
				'20120103080000', // $mockedAdjustedToday
				false // $expectTimeOnly
			],
		];
	}
}
